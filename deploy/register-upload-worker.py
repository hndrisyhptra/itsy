#!/usr/bin/env python3
"""Register the Area 3 worker on the existing running Supervisor.

Run as root on Linux, never through the web. No database, .env or permission
rewrites. All application/PHP commands run under the explicit PHP-FPM user.
"""
import argparse
import configparser
import fnmatch
import glob
import json
import os
from pathlib import Path
import pwd
import re
import shlex
import shutil
import subprocess
import sys
import tempfile
import time


class SetupError(Exception):
    pass


def run(argv, check=True):
    result = subprocess.run([str(v) for v in argv], text=True, capture_output=True)
    if check and result.returncode:
        raise SetupError(f"Perintah gagal: {Path(str(argv[0])).name} {argv[-1]}. Periksa log layanan; antrean tidak dihapus.")
    return result


def parser(path):
    config = configparser.RawConfigParser(strict=True)
    with Path(path).open() as stream:
        config.read_file(stream)
    return config


def locate_supervisor(explicit=None, ctl=None):
    client = ctl or shutil.which('supervisorctl')
    if not client or not os.access(client, os.X_OK):
        raise SetupError('supervisorctl aktif tidak ditemukan. Isi --supervisorctl dengan path command yang sudah dipakai di server.')
    control = [client] + (['-c', Path(explicit).resolve()] if explicit else [])
    pid = run(control + ['pid']).stdout.strip()
    if not pid.isdigit() or int(pid) <= 1:
        raise SetupError('supervisorctl belum terhubung ke daemon aktif. Tidak ada daemon baru yang dibuat.')
    argv = Path('/proc', pid, 'cmdline').read_bytes().decode().strip('\0').split('\0')
    running_config = None
    for i, arg in enumerate(argv):
        if arg in ('-c', '--configuration') and i + 1 < len(argv):
            running_config = argv[i + 1]
        elif arg.startswith('--configuration='):
            running_config = arg.split('=', 1)[1]
    if explicit:
        candidates = [Path(explicit).resolve()]
    elif running_config:
        candidate = Path(running_config)
        if not candidate.is_absolute():
            raise SetupError('Daemon memakai path config relatif; tentukan --supervisor-config absolut.')
        candidates = [candidate]
    else:
        candidates = [Path('/etc/supervisord.conf'), Path('/etc/supervisor/supervisord.conf')]

    matches = []
    for config in candidates:
        if not config.is_file():
            continue
        response = run([client, '-c', config, 'pid'], check=False)
        if response.returncode == 0 and response.stdout.strip() == pid:
            matches.append((config, client))
    if len(matches) == 1:
        return matches[0]
    if len(matches) > 1:
        raise SetupError('Konfigurasi Supervisor ambigu. Tentukan --supervisor-config dan --supervisorctl.')
    raise SetupError('Config tidak cocok dengan daemon supervisorctl aktif. Isi --supervisor-config yang benar; layanan lain tidak diubah.')


def include_target(config_path, project, explicit_dir=None):
    config = parser(config_path)
    if config.has_section('program:area3-uploads') or config.has_section('group:area3-uploads'):
        raise SetupError('Nama area3-uploads sudah terdaftar langsung pada config utama. Tidak mengubah config utama layanan.')
    patterns = shlex.split(config.get('include', 'files', fallback=''))
    paths = []
    for pattern in patterns:
        pattern = pattern.replace('%(here)s', str(config_path.parent))
        if '%' in pattern:
            raise SetupError('Include Supervisor memakai interpolasi khusus; sesuaikan konfigurasi secara manual.')
        path = Path(pattern)
        if not path.is_absolute():
            path = config_path.parent / path
        paths.append(path.parent.resolve() / path.name)
    candidates = []
    for pattern in paths:
        if pattern.name not in ('*.ini', '*.conf') or glob.has_magic(str(pattern.parent)):
            continue
        folder = pattern.parent.resolve()
        if explicit_dir and folder != Path(explicit_dir).resolve():
            continue
        candidates.append(folder / ('area3-uploads' + pattern.suffix))
    candidates = list(dict.fromkeys(candidates))
    if len(candidates) != 1:
        raise SetupError('Folder include *.ini/*.conf tidak tunggal. Tentukan --include-dir yang cocok dengan config aktif.')
    target = candidates[0]
    for pattern in paths:
        for filename in glob.glob(str(pattern)):
            existing = Path(filename)
            content = parser(existing)
            if content.has_section('group:area3-uploads'):
                raise SetupError('Nama group area3-uploads sudah dipakai. Tidak mengubah group aplikasi lain.')
            if content.has_section('program:area3-uploads'):
                directory = content.get('program:area3-uploads', 'directory', fallback='')
                if existing.resolve() != target.resolve() or directory != str(project) or content.sections() != ['program:area3-uploads']:
                    raise SetupError('Nama area3-uploads sudah dipakai konfigurasi lain. Tidak menimpa atau menggandakan program tersebut.')
    if not any(fnmatch.fnmatch(str(target), str(pattern)) for pattern in paths):
        raise SetupError('File tujuan tidak masuk include aktif Supervisor.')
    if target.exists() and not parser(target).has_section('program:area3-uploads'):
        raise SetupError('File tujuan sudah ada dan bukan konfigurasi worker ini.')
    return target


def save_config(target, content):
    if target.exists() and target.read_text() == content:
        return False
    target.parent.mkdir(parents=True, exist_ok=True)
    if target.exists():
        backup = target.with_name(target.name + '.backup-' + str(time.time_ns()))
        # Root-private backup; never widen existing private application files.
        with backup.open('x') as stream:
            os.chmod(backup, 0o600)
            stream.write(target.read_text())
    descriptor, temporary = tempfile.mkstemp(prefix='.area3-uploads-', dir=target.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, target)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)
    return True


def validate_worker_config(content, project, php, user):
    config = configparser.RawConfigParser(strict=True)
    config.read_string(content)
    expected = {
        'directory': str(project),
        'command': f'{php} {project}/artisan area3:work-uploads --no-interaction',
        'user': user, 'numprocs': '1', 'autostart': 'true', 'autorestart': 'true',
        'startsecs': '5', 'startretries': '10', 'stopsignal': 'TERM',
        'stopasgroup': 'true', 'killasgroup': 'true', 'stopwaitsecs': '120',
        'umask': '0077', 'redirect_stderr': 'true',
        'stdout_logfile': f'{project}/storage/logs/uploads-worker.log',
        'stdout_logfile_maxbytes': '10MB', 'stdout_logfile_backups': '5',
    }
    if config.defaults() or config.sections() != ['program:area3-uploads'] or dict(config['program:area3-uploads']) != expected:
        raise SetupError('Konfigurasi tidak cocok dengan satu worker/user/folder its yang diminta. Tidak menulis konfigurasi Supervisor; storage kustom perlu konfigurasi manual.')


def start_program(client, config):
    prefix = [client, '-c', config]
    run(prefix + ['reread'])
    # Restrict update to this group; do not restart any other application.
    run(prefix + ['update', 'area3-uploads'])
    status = run(prefix + ['status', 'area3-uploads'], check=False)
    if not re.search(r'\b(RUNNING|STARTING)\b', status.stdout):
        run(prefix + ['start', 'area3-uploads'])
    for _ in range(20):
        status = run(prefix + ['status', 'area3-uploads'], check=False)
        if re.search(r'\bRUNNING\b', status.stdout):
            print(status.stdout.strip())
            return
        if re.search(r'\b(FATAL|EXITED|STOPPED|BACKOFF)\b', status.stdout):
            raise SetupError('Worker belum RUNNING. Periksa storage/logs/uploads-worker.log dan izin user PHP situs; konfigurasi tetap tersimpan.')
        time.sleep(1)
    raise SetupError('Worker belum RUNNING setelah 20 detik. Periksa status/log Supervisor; antrean tetap tersimpan.')


PREFLIGHT = r'''
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Support\Area3MigrationTarget::assertAllowed();
$paths = [storage_path('app/private'), storage_path('logs'), storage_path('framework/cache')];
$writable = true;
foreach ($paths as $path) $writable = $writable && is_dir($path) && is_writable($path);
foreach (['upload-worker.lock', 'upload-worker.heartbeat'] as $file) {
    $path = storage_path('app/private/'.$file);
    if (file_exists($path)) $writable = $writable && is_writable($path);
}
echo json_encode(['autostart' => (bool) config('uploads.autostart'), 'writable' => $writable,
    'extensions' => extension_loaded('pcntl') && extension_loaded('pdo_mysql') && extension_loaded('zip') && extension_loaded('fileinfo')]);
'''


def main(argv=None):
    options = argparse.ArgumentParser(description=__doc__)
    options.add_argument('--project', default='/www/wwwroot/itsy')
    options.add_argument('--user', required=True, help='OS user PHP-FPM situs, bukan akun login aplikasi')
    options.add_argument('--php', default=shutil.which('php'))
    options.add_argument('--supervisor-config')
    options.add_argument('--supervisorctl')
    options.add_argument('--include-dir')
    args = options.parse_args(argv)
    if sys.version_info < (3, 8):
        raise SetupError('Gunakan Python 3.8 atau lebih baru untuk setup CLI ini.')
    if sys.platform != 'linux' or os.geteuid() != 0:
        raise SetupError('Jalankan sebagai root dari Terminal Linux server aaPanel; bukan komputer lokal atau browser.')
    if args.user.lower() == 'root' or not re.fullmatch(r'[a-zA-Z_][a-zA-Z0-9_-]*', args.user):
        raise SetupError('--user harus user OS PHP-FPM non-root, misalnya www.')
    pwd.getpwnam(args.user)
    project = Path(args.project).resolve()
    if json.loads((project / 'composer.json').read_text()).get('name') != 'its/area3-prototype' or not (project / 'artisan').is_file():
        raise SetupError('Folder tujuan bukan aplikasi its Area 3. Tidak ada perubahan yang dilakukan.')
    if not args.php or not os.access(args.php, os.X_OK):
        raise SetupError('PHP CLI tidak ditemukan; isi --php dengan binary PHP situs.')
    runuser = shutil.which('runuser')
    if not runuser:
        raise SetupError('runuser tidak tersedia; pasang util-linux atau jalankan setup sesuai pengelola OS.')
    os.chdir(project)
    php = str(Path(args.php).resolve())
    identity = [runuser, '-u', args.user, '--', php]
    preflight = json.loads(run(identity + ['-r', PREFLIGHT]).stdout)
    if preflight['autostart']:
        raise SetupError('Set AREA3_UPLOAD_AUTOSTART=false di .env server lalu config:clear memakai PHP/user situs terlebih dahulu.')
    if not preflight['writable'] or not preflight['extensions']:
        raise SetupError('Periksa izin storage/cache/lock/heartbeat untuk user situs dan extension CLI pcntl,pdo_mysql,zip,fileinfo. Tidak ada chmod/chown otomatis.')
    content = run(identity + ['artisan', 'area3:supervisor-config', '--user=' + args.user, '--no-ansi']).stdout
    if not content.startswith('[program:area3-uploads]'):
        raise SetupError('Generator tidak menghasilkan konfigurasi yang valid. Pastikan kode/folder deploy sudah diunggah.')
    validate_worker_config(content, project, php, args.user)
    config, client = locate_supervisor(args.supervisor_config, args.supervisorctl)
    target = include_target(config, project, args.include_dir)
    changed = save_config(target, content)
    print(f"Konfigurasi {'disimpan' if changed else 'sudah sesuai'}: {target}", flush=True)
    start_program(client, config)
    print('Worker upload aktif. File yang sudah antre akan diproses; tidak perlu unggah ulang.')


if __name__ == '__main__':
    try:
        main()
    except (SetupError, OSError, ValueError, KeyError, configparser.Error) as error:
        print('SETUP DIHENTIKAN: ' + str(error), file=sys.stderr)
        sys.exit(1)

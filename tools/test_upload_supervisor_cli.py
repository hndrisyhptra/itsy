"""Offline deployment regressions: never touch Linux services or live DB."""
import configparser
from contextlib import redirect_stdout
import importlib.util
import io
import json
import os
from pathlib import Path
from subprocess import CompletedProcess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('worker_setup', ROOT / 'deploy/register-upload-worker.py')
setup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(setup)


class UploadSupervisorCliTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name).resolve()
        self.project = self.root / 'itsy'
        self.project.mkdir()
        self.config = self.root / 'supervisord.conf'
        self.includes = self.root / 'profiles'
        self.includes.mkdir()
        self.config.write_text('[include]\nfiles=%(here)s/profiles/*.ini\n')
        self.other = self.includes / 'dompis.ini'
        self.other.write_text('[program:dompis-import-worker]\ncommand=/unchanged/dompis\n[program:dompis-qe-worker]\ncommand=/unchanged/qe\n[program:wibsy-worker]\ncommand=/unchanged/wibsy\n')
        self.content = (ROOT / 'deploy/supervisor/area3-uploads.conf.stub').read_text()
        for key, value in {'project': str(self.project), 'storage': str(self.project / 'storage'), 'php': '/usr/bin/php', 'user': 'www'}.items():
            self.content = self.content.replace('{{' + key + '}}', value)

    def test_existing_include_selects_panel_ini_or_os_conf_and_leaves_other_programs_intact(self):
        original = self.other.read_bytes()
        target = setup.include_target(self.config, self.project)
        self.assertEqual(self.includes / 'area3-uploads.ini', target)
        setup.save_config(target, self.content)
        parsed = configparser.ConfigParser()
        parsed.read(target)
        self.assertEqual(['program:area3-uploads'], parsed.sections())
        self.assertEqual(original, self.other.read_bytes())
        self.assertEqual(0o600, target.stat().st_mode & 0o777)
        self.config.write_text('[include]\nfiles=profiles/*.conf\n')
        self.assertEqual(self.includes / 'area3-uploads.conf', setup.include_target(self.config, self.project))

    def test_conflicting_program_foreign_project_and_ambiguous_include_fail_closed(self):
        conflict = self.includes / 'foreign.ini'
        conflict.write_text(self.content.replace(str(self.project), '/different/project'))
        with self.assertRaises(setup.SetupError):
            setup.include_target(self.config, self.project)
        self.assertFalse((self.includes / 'area3-uploads.ini').exists())
        conflict.write_text('[group:area3-uploads]\nprograms=foreign-worker\n')
        with self.assertRaises(setup.SetupError):
            setup.include_target(self.config, self.project)
        conflict.unlink()
        (self.root / 'other').mkdir()
        self.config.write_text('[include]\nfiles=profiles/*.ini other/*.conf\n')
        with self.assertRaises(setup.SetupError):
            setup.include_target(self.config, self.project)
        self.assertEqual(self.includes / 'area3-uploads.ini', setup.include_target(self.config, self.project, self.includes))

    def test_idempotent_write_preserves_original_backup_and_other_configs(self):
        target = self.includes / 'area3-uploads.ini'
        self.assertTrue(setup.save_config(target, self.content))
        self.assertFalse(setup.save_config(target, self.content))
        self.assertEqual([], list(self.includes.glob('*.backup-*')))
        updated = self.content.replace('startsecs=5', 'startsecs=6')
        self.assertTrue(setup.save_config(target, updated))
        backups = list(self.includes.glob('*.backup-*'))
        self.assertEqual(1, len(backups))
        self.assertEqual(self.content, backups[0].read_text())
        self.assertEqual(0o600, backups[0].stat().st_mode & 0o777)
        self.assertEqual(updated, target.read_text())

    def test_root_extra_program_arbitrary_command_and_foreign_log_are_never_accepted_for_privileged_install(self):
        setup.validate_worker_config(self.content, self.project, '/usr/bin/php', 'www')
        for unsafe in [self.content.replace('user=www', 'user=root'), self.content + '\n[program:other]\ncommand=bad\n', self.content.replace('area3:work-uploads --no-interaction', 'different:command'), self.content.replace('stdout_logfile=' + str(self.project), 'stdout_logfile=/foreign')]:
            with self.assertRaises(setup.SetupError):
                setup.validate_worker_config(unsafe, self.project, '/usr/bin/php', 'www')

    def test_update_is_restricted_to_its_and_never_restarts_existing_dompis_or_wibsy_workers(self):
        commands = []
        def run(argv, check=True):
            commands.append([str(v) for v in argv])
            return CompletedProcess(argv, 0, 'area3-uploads RUNNING pid 555, uptime 0:00:05\n', '')
        with patch.object(setup, 'run', side_effect=run), redirect_stdout(io.StringIO()):
            setup.start_program('/usr/bin/supervisorctl', self.config)
        self.assertIn(['/usr/bin/supervisorctl', '-c', str(self.config), 'update', 'area3-uploads'], commands)
        for argv in commands:
            self.assertFalse(set(argv) & {'reload', 'restart', 'stop', 'all', 'dompis-import-worker', 'dompis-qe-worker', 'dompis-webhook-worker', 'wibsy-worker'})
        self.assertFalse(any('start' in argv for argv in commands))

    def test_running_daemon_config_must_match_the_pid_from_the_working_supervisorctl(self):
        def run(argv, check=True):
            return CompletedProcess(argv, 0, '1234\n', '')
        with patch.object(setup.os, 'access', return_value=True), patch.object(setup, 'run', side_effect=run), patch.object(Path, 'read_bytes', return_value=f'/usr/bin/python3\0/usr/bin/supervisord\0-c\0{self.config}\0'.encode()):
            self.assertEqual((self.config, '/usr/bin/supervisorctl'), setup.locate_supervisor(ctl='/usr/bin/supervisorctl'))
        with patch.object(setup.os, 'access', return_value=True), patch.object(setup, 'run', side_effect=[CompletedProcess([], 0, '1234\n', ''), CompletedProcess([], 0, '9999\n', '')]), patch.object(Path, 'read_bytes', return_value=b'/usr/bin/supervisord\0'):
            with self.assertRaises(setup.SetupError):
                setup.locate_supervisor(self.config, '/usr/bin/supervisorctl')

    def test_full_registration_is_simulated_without_services_db_env_or_other_program_writes(self):
        (self.project / 'composer.json').write_text(json.dumps({'name': 'its/area3-prototype'}))
        (self.project / 'artisan').touch()
        env = self.project / '.env'
        env.write_text('AREA3_UPLOAD_AUTOSTART=false\n')
        before = self.other.read_bytes()
        commands = []
        def run(argv, check=True):
            argv = [str(v) for v in argv]
            commands.append(argv)
            if '-r' in argv:
                output = json.dumps({'autostart': False, 'writable': True, 'extensions': True})
            elif 'area3:supervisor-config' in argv:
                output = self.content
            else:
                output = 'area3-uploads RUNNING pid 555, uptime 0:00:05\n'
            return CompletedProcess(argv, 0, output, '')
        cwd = Path.cwd()
        try:
            with patch.object(setup.sys, 'platform', 'linux'), patch.object(setup.os, 'geteuid', return_value=0), patch.object(setup.pwd, 'getpwnam'), patch.object(setup.shutil, 'which', side_effect=lambda name: '/usr/bin/' + name), patch.object(setup.os, 'access', return_value=True), patch.object(setup, 'run', side_effect=run), patch.object(setup, 'locate_supervisor', return_value=(self.config, '/usr/bin/supervisorctl')), redirect_stdout(io.StringIO()):
                setup.main(['--project', str(self.project), '--user', 'www', '--php', '/usr/bin/php'])
        finally:
            os.chdir(cwd)
        self.assertEqual(before, self.other.read_bytes())
        self.assertEqual('AREA3_UPLOAD_AUTOSTART=false\n', env.read_text())
        self.assertTrue((self.includes / 'area3-uploads.ini').is_file())
        self.assertTrue(any(argv[:4] == ['/usr/bin/runuser', '-u', 'www', '--'] for argv in commands))
        self.assertFalse(any(set(argv) & {'apt-get', 'dnf', 'systemctl', 'supervisord', 'migrate', 'queue:clear', 'chmod', 'chown'} for argv in commands))

    def test_preflight_rejection_leaves_supervisor_untouched(self):
        (self.project / 'composer.json').write_text(json.dumps({'name': 'its/area3-prototype'}))
        (self.project / 'artisan').touch()
        cwd = Path.cwd()
        try:
            with patch.object(setup.sys, 'platform', 'linux'), patch.object(setup.os, 'geteuid', return_value=0), patch.object(setup.pwd, 'getpwnam'), patch.object(setup.shutil, 'which', return_value='/usr/bin/runuser'), patch.object(setup.os, 'access', return_value=True), patch.object(setup, 'run', return_value=CompletedProcess([], 0, json.dumps({'autostart': True, 'writable': True, 'extensions': True}), '')), patch.object(setup, 'locate_supervisor') as locate:
                with self.assertRaises(setup.SetupError):
                    setup.main(['--project', str(self.project), '--user', 'www', '--php', '/usr/bin/php'])
                locate.assert_not_called()
            self.assertEqual([self.other], list(self.includes.iterdir()))
        finally:
            os.chdir(cwd)


if __name__ == '__main__':
    unittest.main()

# Worker upload aaPanel — 9 November 2026

Folder server yang diberikan pengguna: **`/www/wwwroot/itsy`**. Worker ini menangani upload **BOQ Excel** dan **eviden pekerjaan PDF/foto** yang sudah memakai koneksi `uploads`, queue `area3-uploads`, database aplikasi **its**. Tidak perlu Redis, migration baru, akun contoh atau import ulang dokumen. Supervisor menjaga worker hidup tanpa bergantung pada sesi Terminal.

`root@dompis` adalah akun SSH, bukan bukti user PHP-FPM. Worker harus memakai **user OS yang sama dengan PHP-FPM situs** agar staging/hasil privat yang diunggah melalui web tetap dapat dibaca. `www` pada contoh berikut harus diganti bila situs memakai user lain. Tidak menjalankan worker sebagai root atau membuka file privat untuk semua pengguna.

## Penambahan langsung dari CLI ke Supervisor yang sudah aktif

Pengguna mengonfirmasi `supervisorctl status` sudah menampilkan worker Dompis dan wibsy. Gunakan **Supervisor yang sama**, tanpa memasang paket/daemon baru atau restart semua program. FATAL pada program lain bukan bagian perbaikan ini.

### Langsung tempel di Terminal aaPanel, tanpa upload script baru

Kode generator Artisan dari pembaruan sebelumnya harus sudah ada di server. Blok berikut dapat ditempel langsung ke Terminal root; ganti `www` di awal jika PHP-FPM situs memakai user lain. Blok mendeteksi config/include dari daemon aktif, membuat satu file worker dan melakukan update terbatas. Tidak perlu git/pull tambahan hanya untuk menjalankan resep terminal ini. Resep ini memakai subset setup di bawah; jika config custom/permission/extension bermasalah, gunakan pesan error/log dan jangan menambah daemon kedua.

```sh
cd /www/wwwroot/itsy
python3 - <<'PY'
import configparser, glob, json, os, pathlib, shlex, shutil, subprocess, tempfile, time
project = pathlib.Path('/www/wwwroot/itsy')
user = 'www'
def check(ok, message):
    if not ok: raise SystemExit(message)
def cmd(*args):
    return subprocess.check_output([str(x) for x in args], text=True).strip()
def ini(path):
    p = configparser.RawConfigParser()
    with open(path) as f: p.read_file(f)
    return p
check(os.geteuid() == 0 and user != 'root', 'Gunakan Terminal root; user worker harus user PHP situs non-root.')
check(json.loads((project/'composer.json').read_text()).get('name') == 'its/area3-prototype', 'Folder bukan aplikasi its Area 3.')
ctl = shutil.which('supervisorctl')
check(ctl, 'supervisorctl tidak ditemukan pada PATH terminal.')
pid = cmd(ctl, 'pid')
check(pid.isdigit() and int(pid) > 1, 'Supervisor aktif tidak ditemukan.')
argv = pathlib.Path('/proc', pid, 'cmdline').read_bytes().decode().strip('\0').split('\0')
named = [argv[i+1] for i,a in enumerate(argv[:-1]) if a in ('-c','--configuration')]
named += [a.split('=',1)[1] for a in argv if a.startswith('--configuration=')]
choices = named or ['/etc/supervisord.conf','/etc/supervisor/supervisord.conf']
masters = []
for name in choices:
    p = pathlib.Path(name)
    if p.is_absolute() and p.is_file():
        result = subprocess.run([ctl,'-c',str(p),'pid'], capture_output=True, text=True)
        if result.returncode == 0 and result.stdout.strip() == pid: masters.append(p)
check(len(masters) == 1, 'Config aktif ambigu/tidak ditemukan; kirim lokasi config Supervisor.')
master = masters[0]
main = ini(master)
check(not any(main.has_section(s) for s in ('program:area3-uploads','group:area3-uploads')), 'Nama worker sudah dipakai di config utama.')
patterns = []
for item in shlex.split(main.get('include','files',fallback='')):
    item = item.replace('%(here)s', str(master.parent))
    check('%' not in item, 'Include memakai interpolasi khusus; perlu penyesuaian manual.')
    p = pathlib.Path(item)
    if not p.is_absolute(): p = master.parent/p
    patterns.append(p.parent.resolve()/p.name)
targets = {p.parent/('area3-uploads'+p.suffix) for p in patterns if p.name in ('*.ini','*.conf') and not glob.has_magic(str(p.parent))}
check(len(targets) == 1, 'Folder include ambigu/tidak didukung; kirim bagian include config Supervisor.')
target = targets.pop()
check(target.parent.is_dir(), 'Folder include aktif belum tersedia.')
for pattern in patterns:
    for filename in glob.glob(str(pattern)):
        p = ini(filename)
        check(not p.has_section('group:area3-uploads'), 'Nama group sudah dipakai aplikasi lain.')
        if p.has_section('program:area3-uploads'):
            check(pathlib.Path(filename).resolve() == target.resolve() and p.sections() == ['program:area3-uploads'] and p.get('program:area3-uploads','directory',fallback='') == str(project), 'Nama worker sudah dipakai konfigurasi lain.')
if target.exists(): check(ini(target).sections() == ['program:area3-uploads'], 'File tujuan milik konfigurasi lain.')
php = cmd('php','-r','echo PHP_BINARY;')
code = cmd('runuser','-u',user,'--',php,str(project/'artisan'),'area3:supervisor-config','--user='+user,'--no-ansi')+'\n'
p = configparser.RawConfigParser()
p.read_string(code)
check(not p.defaults() and p.sections() == ['program:area3-uploads'], 'Hasil generator tidak valid.')
s = p['program:area3-uploads']
allowed = set('directory command user numprocs autostart autorestart startsecs startretries stopsignal stopasgroup killasgroup stopwaitsecs umask redirect_stderr stdout_logfile stdout_logfile_maxbytes stdout_logfile_backups'.split())
check(set(s) == allowed and s['user'] == user and s['directory'] == str(project) and s['command'] == f'{php} {project}/artisan area3:work-uploads --no-interaction' and s['stdout_logfile'] == f'{project}/storage/logs/uploads-worker.log' and s['numprocs'] == '1' and s['umask'] == '0077', 'Konfigurasi berbeda dari worker/user/folder its yang diminta.')
if not target.exists() or target.read_text() != code:
    if target.exists():
        backup = str(target)+'.backup-'+str(time.time_ns())
        shutil.copy2(target, backup)
        os.chmod(backup, 0o600)
    fd, temporary = tempfile.mkstemp(prefix='.area3-uploads-', dir=target.parent)
    with os.fdopen(fd,'w') as f: f.write(code)
    os.replace(temporary,target)
print('Konfigurasi worker its:', target, flush=True)
cmd(ctl,'-c',master,'reread')
cmd(ctl,'-c',master,'update','area3-uploads')
result = subprocess.run([ctl,'-c',str(master),'status','area3-uploads'], capture_output=True, text=True)
if not any(word in result.stdout.split() for word in ('RUNNING','STARTING')): cmd(ctl,'-c',master,'start','area3-uploads')
for attempt in range(20):
    result = subprocess.run([ctl,'-c',str(master),'status','area3-uploads'], capture_output=True, text=True)
    if 'RUNNING' in result.stdout.split():
        print(result.stdout.strip())
        break
    time.sleep(1)
else: raise SystemExit('Worker belum RUNNING. Periksa: tail -n 40 /www/wwwroot/itsy/storage/logs/uploads-worker.log')
PY
```

Resep hanya mendaftarkan worker; tidak mengubah .env, izin file aplikasi, queue/gates atau database. Syntax blok Python diperiksa lokal tanpa menjalankannya; registrasi server nyata tetap menunggu operator. Generator dan script lengkap tetap memiliki bukti pengujian terpisah di bawah. Worker lain dipertahankan; tidak ada `update` global atau `restart all` dalam resep.

Setelah `deploy/register-upload-worker.py` ikut diunggah (commit/push di lokal lalu pull di server, atau unggah file tersebut), jalankan dari Terminal **root server**, bukan komputer lokal:

```sh
cd /www/wwwroot/itsy
python3 deploy/register-upload-worker.py --project=/www/wwwroot/itsy --user=www
```

Skrip membutuhkan Python3.8+, PHP CLI situs minimal8.2, dan `runuser` Linux. `www` adalah contoh user OS PHP-FPM yang harus sesuai situs. Bila PHP CLI default berbeda dari PHP situs, tambahkan `--php=/path/absolut/php-situs`. `.env` server tetap `AREA3_UPLOAD_AUTOSTART=false`; jika masih tercache true, skrip berhenti dan meminta `config:clear` dengan PHP/user situs. Tidak ada perubahan .env atau permission otomatis.

Skrip melakukan:

1. Memeriksa identitas proyek dari composer/artisan, user non-root, konfigurasi target database its melalui guard aplikasi, extension CLI, serta akses direktori storage/cache/heartbeat/lock sebagai user situs. Preflight membaca konfigurasi, tidak melakukan migration atau query tabel transaksi.
2. Menjalankan generator lama sebagai user situs, lalu memvalidasi secara ketat satu program/command/PHP/user/folder/log yang diminta; root, perintah tambahan atau storage di luar project/storage ditolak.
3. Mengambil PID dari **supervisorctl yang sudah bekerja** dan membaca argv daemon tersebut. Config `-c` diverifikasi kembali mengembalikan PID yang sama; tidak menebak daemon lain. Membaca include aktif, mendukung *.ini aaPanel dan *.conf OS, relative path/`%(here)s`; bila ambigu atau interpolasi khusus, berhenti dengan opsi eksplisit.
4. Menulis hanya `area3-uploads.ini` atau `.conf` ke include aktif, secara atomik/mode0600. Konfigurasi yang sama dilewati; revisi konfigurasi milik proyek yang sama mendapat backup0600. File/program/group milik proyek lain tidak ditimpa. Tidak mengubah config utama atau file worker Dompis/wibsy.
5. Menjalankan `reread`, **`update area3-uploads`**, lalu start/status khusus program itu sampai RUNNING. `reread` hanya membaca ulang; update dibatasi satu group. Tidak memanggil reload/restart/stop all, systemctl, package manager, queue clear atau reset database.

Hasil yang diharapkan:

```text
Konfigurasi disimpan: .../area3-uploads.conf
area3-uploads RUNNING pid ..., uptime ...
Worker upload aktif. File yang sudah antre akan diproses; tidak perlu unggah ulang.
```

Konfirmasi setelahnya memakai command yang sama dengan sebelumnya:

```sh
supervisorctl status
```

Program `area3-uploads` seharusnya muncul bersama worker yang sudah ada. Skrip tidak memperbaiki status FATAL worker aplikasi lain. Jika worker its gagal, lihat `storage/logs/uploads-worker.log`; script melaporkan belum RUNNING dan mempertahankan antrean. Jika worker lama masih memegang lock, hentikan worker **its yang lama** secara tertib sesuai langkah di bawah, bukan worker aplikasi lain.

Untuk instalasi dengan path/control khusus:

```sh
python3 deploy/register-upload-worker.py --project=/www/wwwroot/itsy --user=www --supervisorctl=/path/absolut/supervisorctl --supervisor-config=/path/absolut/supervisord.conf --include-dir=/folder/include/aktif
```

`--include-dir` harus cocok dengan include dalam config aktif, tidak menambahkan folder baru ke config utama. Script tidak menyediakan instalasi Supervisor/service baru, akses HTTP atau hak sudo bagi akun web. Root hanya mendaftarkan konfigurasi; proses PHP worker tetap non-root. Custom storage/path dengan spasi/interpolasi perlu konfigurasi manual. Service boot tetap memakai layanan Supervisor yang sudah diatur server.

Delapan regresi Python memakai temp directory dan subprocess palsu, memeriksa active PID/config, include OS/aaPanel, penolakan konflik/root/command/log, atomic/idempotent backup, pemanggilan user situs, preflight gagal tanpa perubahan, serta update its saja dengan file/status worker Dompis/wibsy dipertahankan. 225 PHP/4382 assertions,218 frontend/build dan sintaks PHP preflight lulus. Preflight/registrasi **belum dijalankan terhadap server nyata**; tidak ada daemon atau database lokal yang disentuh agen. Runtime web/queue SQL tidak bertambah; batas generator/aggregate lama tetap.

## 1. Unggah kode dan periksa PHP/user situs

Unggah perubahan aplikasi berikut ke folder proyek server, termasuk folder `deploy`:

- `app/Console/Commands/UploadSupervisorConfig.php`
- `deploy/supervisor/area3-uploads.conf.stub`

Worker yang dijalankan tetap perintah lama `area3:work-uploads` pada `routes/console.php`. Tidak ada worker baru yang memproses payload melalui jalur lain.

Di Terminal aaPanel:

```sh
cd /www/wwwroot/itsy
php -v
php -m
ps -eo user,args | grep '[p]hp-fpm: pool'
```

Gunakan PHP CLI yang sesuai dengan situs (minimal 8.2), dengan extension aplikasi termasuk `pdo_mysql`, `fileinfo`, `mbstring`, `zip`, `dom`/`SimpleXML`, dan **`pcntl` untuk timeout/sinyal worker Linux**. Bila perintah `php` bukan PHP situs, gunakan binary absolut dari instalasi aaPanel. Contoh **hanya untuk PHP 8.2**: `/www/server/php/82/bin/php`; untuk versi lain gunakan path versinya. Jangan menonaktifkan validasi PHP atau menaikkan timeout secara sembarang.

Pastikan folder `storage/app/private` dan `storage/logs` ada serta dapat ditulis user PHP situs; user itu juga harus dapat membaca kode/config dan memakai cache aplikasi. Sesuaikan owner direktori yang diperlukan jika hasil upload/copy sebelumnya milik root. Pertahankan file privat; jangan memakai `chmod 777`, memindahkannya ke `public` atau membuat storage link ke disk private.

## 2. Nonaktifkan autostart web pada server

Tambahkan/perbarui nilai berikut pada `.env` server, pertahankan DB/kredensial/APP_KEY yang sudah benar:

```dotenv
APP_ENV=production
APP_DEBUG=false
AREA3_UPLOAD_AUTOSTART=false
```

Koneksi upload tetap `uploads` (database `its`, queue `area3-uploads`) dan tidak bergantung pada `QUEUE_CONNECTION` default. Tidak perlu mengubah `QUEUE_CONNECTION=sync` menjadi default database hanya demi fitur ini. `AREA3_WORKER_PHP` hanya dipakai launcher lokal; Supervisor memakai binary absolut pada konfigurasi yang dihasilkan.

Bangun ulang cache config memakai PHP aplikasi dan user OS situs, contoh bila usernya `www` dan `php` sudah benar:

```sh
sudo -u www php artisan config:clear
sudo -u www php artisan config:cache
```

Jika `sudo` tidak tersedia, gunakan fasilitas Terminal/run-as aaPanel untuk menjalankan sebagai user situs. Jangan mengubah owner seluruh aplikasi tanpa memeriksa kebutuhan. Jika worker lokal/detached dari aplikasi yang sama masih berjalan, hentikan secara tertib sebelum menyalakan Supervisor: `sudo -u www php artisan queue:restart` meminta worker aplikasi yang memakai cache yang sama selesai dari job aktif lalu keluar; bukan menghapus antrean. Autostart web harus sudah false. Jangan melakukan `queue:clear`, `queue:flush`, `migrate:fresh` atau reset dokumen.

## 3. Hasilkan konfigurasi sesuai server

```sh
php artisan area3:supervisor-config --user=www
```

Perintah mendeteksi `base_path`, `storage_path` dan **PHP_BINARY yang menjalankannya**. Jadi menjalankannya dengan PHP situs menghasilkan path PHP absolut yang sesuai, tanpa perlu menyalin path XAMPP lokal. Perintah hanya menampilkan konfigurasi, tidak menanyakan/mengeluarkan password, membaca database, membuat file, memasang Supervisor atau menjalankan worker. `--user` wajib; root, karakter injeksi dan path yang tidak aman bagi format Supervisor ditolak. Nama user harus benar-benar user PHP-FPM situs; generator tidak dapat memverifikasi konfigurasi pool PHP-FPM jarak jauh.

Setelah hasil tampil benar, simpan di file sementara:

```sh
php artisan area3:supervisor-config --user=www > /tmp/area3-uploads.conf
```

Pastikan perintah berakhir sukses dan isi file dimulai `[program:area3-uploads]`, bukan pesan error. Template memuat:

- Satu proses `artisan area3:work-uploads --no-interaction`, memakai lock/heartbeat yang sama dengan upload UI.
- `autostart=true` dan `autorestart=true`, termasuk ketika worker keluar normal setelah `queue:restart`/batas memori.
- `stopasgroup=true`, `killasgroup=true`, SIGTERM, waktu tunggu shutdown **120 detik**, lebih panjang dari timeout job **90 detik**; retry database **180 detik** tetap.
- `umask=0077` untuk file yang dibuat worker dan rotasi `storage/logs/uploads-worker.log` maksimum 10 MB × 5 backup. Permission file privat yang sudah ada tidak dilebarkan.

## 4. Daftarkan pada Supervisor aaPanel

Pasang/buka pengelola **Supervisor** di aaPanel dan daftarkan program bernama **`area3-uploads`**. Gunakan konfigurasi hasil langkah 3 pada konfigurasi program/plugin yang aktif; lokasi include berbeda antara instalasi Supervisor OS dan plugin aaPanel, jadi jangan menyalin secara membabi buta ke `/etc/supervisor/conf.d` bila daemon aaPanel memakai folder lain.

Jika panel memakai form tambah daemon, isi dari hasil konfigurasi:

| Isian | Nilai |
| --- | --- |
| Nama | `area3-uploads` |
| User | User PHP-FPM situs, contoh `www` |
| Direktori kerja | `/www/wwwroot/itsy` |
| Perintah | Baris `command=` hasil generator, tanpa teks `command=` |
| Jumlah proses | `1` |
| Autostart / restart | Aktif |
| Konfigurasi lanjutan | Pertahankan stopwaitsecs 120, stop/kill group, umask dan rotasi dari file hasil generator |

Jangan memasukkan `&`, `nohup`, `--once`, `--stop-when-empty` atau menggantinya dengan `php artisan queue:work` queue default. Supervisor perlu mengawasi proses foreground yang bertahan ketika antrean kosong. Aktifkan service Supervisor pada boot melalui pengelola layanan server; autostart program berlaku setelah daemon Supervisor itu sendiri hidup.

Sesudah file terdaftar pada include Supervisor yang benar, gunakan tombol reload/start panel. Bila memakai CLI, arahkan `supervisorctl` ke daemon/config aaPanel yang benar:

```sh
supervisorctl reread
supervisorctl update
supervisorctl status area3-uploads
```

Hasil yang diharapkan **RUNNING**. Perintah CLI di atas bukan instalasi daemon dan belum dijalankan oleh agen di server. Jangan menjalankan worker manual lain bersamaan; lock eksklusif menolak proses kedua. Bila terlihat STARTING/FATAL karena worker lama memegang lock, hentikan worker lama aplikasi itu secara tertib lalu start ulang program ini.

## 5. Verifikasi dan pembaruan kode

1. Periksa status Supervisor dan `storage/logs/uploads-worker.log`. Jika FATAL, periksa binary PHP, extension CLI, user/direktori/izin serta koneksi database **its** dari config server; jangan menampilkan kredensial.
2. Buka upload BOQ/eviden dan kirim file nyata dari akun yang berwenang. Status harus bergerak Dalam antrean → Sedang diproses → Selesai (atau Gagal dengan alasan validasi). Upload BOQ selesai tetap perlu **Gunakan hasil** sesuai alur sebelumnya. Pending lama tetap tersimpan; tidak perlu upload ulang hanya karena worker sempat mati.
3. Indikator aplikasi memakai heartbeat 20 detik dari loop worker. Pemrosesan satu file yang lama dapat membuat heartbeat sementara terlihat kedaluwarsa sampai loop berikutnya; cek status Supervisor dan task, bukan menghapus job atau meluncurkan worker kedua. Fitur ini tidak mengubah indikator tersebut.
4. Setelah deploy kode berikutnya, restart **program ini** dari Supervisor agar PHP memuat kode terbaru. Shutdown menunggu job aktif sampai batas 120 detik. Jika memakai `queue:restart`, jalankan sebagai user situs dengan cache/config yang sama; Supervisor akan menghidupkan kembali proses yang keluar. Tidak perlu restart semua layanan server.

## Arsitektur, performa dan bukti

Tambahan hanya generator CLI dan template/runbook deployment. Worker/job/SQL/endpoint/otorisasi/status transaksi/timeout/retry/ledger yang sudah ada tetap. Generator membaca satu file stub, melakukan validasi format dan substitusi satu kali, dengan **0 query SQL**, tanpa katalog/workflow/account/credential read, tanpa shell command, detached process atau penulisan filesystem. Query/runtime antrean mengikuti [UPLOAD_QUEUE_PERFORMANCE.md](UPLOAD_QUEUE_PERFORMANCE.md); tidak ada optimasi throughput yang diklaim.

Pengujian memory memeriksa config/perintah queue yang benar, identitas OS privat, timing terhadap ProcessUpload/retry, autostart/restart/rotasi, 0 SQL/0 process, dan penolakan root/injeksi/path. Seluruh suite dan hasil aktual dicatat pada LARAVEL_QA/qa/server-upload-worker-20261109.json. Tidak ada perubahan `.env`, task/worker/live MySQL lokal maupun aaPanel oleh agen. Tidak perlu migration/backup data karena tidak ada perubahan workflow/data tersimpan. Instalasi Supervisor, service boot/restart, PHP-FPM user, upload nyata, browser dan beban produksi di server belum diverifikasi sampai operator menerapkan konfigurasi.

Referensi primer: [Laravel 12 — Supervisor dan queue](https://laravel.com/framework/docs/12.x/queues#supervisor-configuration), [Supervisor — program settings](https://supervisord.org/configuration.html#program-x-section-settings), [Supervisor — control actions](https://supervisord.org/running.html#supervisorctl-actions). Konfigurasi ini mempertahankan timeout lebih pendek dari retry_after dan stopwaitsecs lebih panjang dari pekerjaan terlama.

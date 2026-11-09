# Worker upload aaPanel — 9 November 2026

Folder server yang diberikan pengguna: **`/www/wwwroot/itsy`**. Worker ini menangani upload **BOQ Excel** dan **eviden pekerjaan PDF/foto** yang sudah memakai koneksi `uploads`, queue `area3-uploads`, database aplikasi **its**. Tidak perlu Redis, migration baru, akun contoh atau import ulang dokumen. Supervisor menjaga worker hidup tanpa bergantung pada sesi Terminal.

`root@dompis` adalah akun SSH, bukan bukti user PHP-FPM. Worker harus memakai **user OS yang sama dengan PHP-FPM situs** agar staging/hasil privat yang diunggah melalui web tetap dapat dibaca. `www` pada contoh berikut harus diganti bila situs memakai user lain. Tidak menjalankan worker sebagai root atau membuka file privat untuk semua pengguna.

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

Referensi primer: [Laravel 12 — Supervisor dan queue](https://laravel.com/framework/docs/12.x/queues#supervisor-configuration), [Supervisor — program settings](https://supervisord.org/configuration.html#program-x-section-settings). Konfigurasi ini mempertahankan timeout lebih pendek dari retry_after dan stopwaitsecs lebih panjang dari pekerjaan terlama.

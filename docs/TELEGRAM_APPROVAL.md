# Telegram Approval — 18 November 2026

Fitur ini mengirim pesan pribadi sebagai pengingat, bukan menerima keputusan approval lewat Telegram. Pengguna tetap login dan memeriksa berkas di Inbox aplikasi. Pengaturan default **nonaktif**. Tidak ada token bot bawaan atau pesan nyata yang dikirim saat migration/build.

## UI

- **Pengaturan → Telegram**, khusus Superadmin, setelah Peran Pengguna: pengaktifan, jam WIB/24 jam, interval tetap 60 menit, template dengan contoh, pemeriksaan koneksi, daftar pengguna, PIC notifikasi Finance per Branch/Unit, serta riwayat berhalaman 20 baris.
- **Profil & Preferensi → Telegram Approval**, masing-masing akun: buat tautan, buka bot dan Start, muat status, periksa nama Telegram, konfirmasikan, uji pesan ke diri sendiri, atau putuskan koneksi. YA/TIDAK mendahului perubahan/pengiriman uji. Token bot/secret/chat ID tidak ditampilkan.
- Scheduler/worker menampilkan **aktivitas terakhir**, bukan klaim proses RUNNING. Status `sent` berarti diterima API Telegram, bukan telah dibaca. `unknown` berarti hasil kirim tidak pasti.
- Template hanya memakai `{name}`, `{count}`, `{pp_count}`, `{payment_count}`. Pesan berupa teks biasa; tombol Inbox ditambahkan server. Tidak mengirim KTP, rekening, file, nominal, atau isi berkas.

## Tabel dan relasi

Migration `2026_11_18_000003_create_telegram_notifications.php` bersifat tambahan, dibatasi database **its** atau SQLite memory saat test. Tidak mengubah PP/BA KH/SPK/pembayaran/approval/nilai/arsip/pengguna lama. Enam tabel berikut memakai primary key bernama khusus:

| Tabel | Primary key | Isi / relasi |
|---|---|---|
| telegram_settings | id_telegram_setting | Satu konfigurasi, versi perubahan dan heartbeat; default enabled=false |
| user_telegram_accounts | id_user_telegram | Maksimal satu koneksi per users.id_user; chat dienkripsi APP_KEY, hash unik; binding UUID dan konfirmasi |
| telegram_link_tokens | id_telegram_link_token | Satu token hash per user, berlaku 10 menit, sekali pakai |
| telegram_finance_pics | id_telegram_finance_pic | Satu PIC Finance per branches.id_branch + work_units.id_work_unit, terkait users.id_user |
| approval_notification_tasks | id_approval_notification | Identitas giliran/penerima/versi, jatuh tempo satu jam, waktu kirim/tutup; target source ID hanya referensi notifikasi |
| telegram_notification_logs | id_telegram_notification_log | Audit perubahan/pengaitan/kirim/status tanpa token/chat ID/isi respons Telegram |

Koneksi dan token memiliki FK user; PIC memiliki FK user/Branch/unit. Task/log menyimpan ID user sebagai referensi audit, bukan FK cascade: riwayat tidak dihapus bersama koneksi. Rollback menolak jika sudah ada koneksi/token/PIC/task/log agar data notifikasi tidak terhapus. Pertahankan APP_KEY karena chat ID terenkripsi. Tidak ada backfill akun Telegram.

## Pemilihan penerima dan antrean

`area3:telegram-reminders` dijadwalkan setiap menit. Pemeriksaan pertama setelah aktivasi masuk antrean pada putaran berikutnya (sekitar satu menit, ditambah antrean); pengingat item yang masih sama baru layak satu jam setelah upaya kirim sebelumnya. Satu pesan merangkum item jatuh tempo untuk satu penerima. Tidak ada jam malam saat opsi 24 jam aktif; batas jam memakai Asia/Jakarta dan dapat melintasi tengah malam.

Penerima berasal dari run SQL terbaru yang masih pending dan keputusan/susunan aplikasi saat ini, bukan nomor urut SQL historis semata. Konstruksi lima peran, Operation lima peran, pembayaran baru ditambah Mgr Finance & Settlement dan Finance; keputusan pembayaran baru harus terikat kasbon UUID aktif. Penerima aktif, peran, Branch/Region dan unit diperiksa ulang oleh worker sebelum HTTP. PP yang sedang dikoreksi Head menahan reminder pembayaran turunannya. Penolakan/revisi, pergantian tahap/assignee, akun dicabut dan pembayaran selesai tidak menghasilkan pengingat approval lama.

Finance tahap terakhir belum menunjuk satu akun sebelum keputusan. Karena itu Superadmin memilih **PIC notifikasi Finance** per Branch/Unit. PIC hanya alamat penerima reminder; tidak memberi akses/menunjuk approver aplikasi. Tanpa PIC/assignee yang sah, tahap Finance tidak dikirimi pesan. Kasbon-v1 dengan supplemental Manager review memakai UUID dan keputusan aslinya; partial-payment-v1 yang Diajukan dan scan lama Menunggu Verifikasi diarahkan ke PIC Finance. Riwayat tidak diubah atau otomatis diadopsi ke flow baru. Dokumen sangat lama tanpa run/identitas approver tetap tidak direkayasa menjadi pengajuan baru.

Queue database khusus koneksi `notifications`, nama `area3-notifications`, memakai tabel jobs yang sudah ada. Tidak mengubah QUEUE_CONNECTION, worker upload, atau worker aplikasi lain. Worker `area3:work-notifications` memeriksa target its, satu job maksimum90detik, retry_after180detik, satu upaya. Lock per penerima, target key unik dan log test UUID mencegah duplikasi normal. HTTP connect5/timeout15detik; gangguan jaringan ambigu tidak diulang pada slot yang sama, sedangkan penolakan429 menggunakan retry_after. Kode400/403 menandai koneksi perlu dihubungkan ulang. Uji kirim yang berbenturan dengan pengiriman lain dibatalkan dan dapat diminta lagi secara eksplisit.

Pesan yang telah berada dalam perjalanan tidak bisa ditarik kembali. Recheck tidak merupakan transaksi atomik dengan Telegram; jangan mengklaim exactly-once atau membaca pesan. Task lama ditutup ketika penerima diperiksa kembali; audit/task tidak dihapus otomatis. Daftar UI20+1 dan query assignment berkelompok; katalog diproses chunk300, insert300, due receiver100, update200. Tidak ada pembacaan workspace archive, BOQ, file privat atau ciphertext kasbon pada resolver. Lihat bukti QA untuk ukuran fixture dan batas, bukan klaim uji beban produksi.

## Pengaitan dan keamanan

1. Pengguna login membuat token random48, hanya hash tersimpan, masa10menit.
2. Bot menerima `/start <token>` di private chat, chat ID harus sama dengan pengirim dan bukan bot.
3. Header webhook secret harus cocok. Callback tidak langsung mengaktifkan reminder: identitas masih pending.
4. Pengguna kembali ke aplikasi dan mengonfirmasi binding UUID yang sedang ditampilkan sebelum10menit. Token lama/pengulangan callback tidak mengganti binding yang sudah terkonfirmasi.
5. Satu Telegram tidak boleh dipakai dua user. Link baru membatalkan pending lama. Disconnect menghentikan kirim dan membatalkan token; worker memeriksa binding lagi.

Endpoint profil/admin berada di auth, auth.session, ActiveAccount, CSRF, no-store serta throttle. Webhook saja dikecualikan CSRF dan memakai secret header, private chat, token sekali pakai dan batas payload16KiB. Superadmin tidak dapat mengaitkan akun orang lain dari panel pengguna; setiap orang mengonfirmasi sendiri. Pemeriksaan koneksi hanya getMe/getWebhookInfo, tidak mengirim pesan ke pengguna.

## Penerapan aaPanel dan penghubungan bot

Panduan ini untuk proyek **/www/wwwroot/itsy**. Agen belum menjalankannya pada server, belum mengubah .env, belum melakukan migration database aktif, belum mendaftarkan webhook dan belum mengirim pesan nyata.

### 1. Buat bot

Di Telegram buka akun resmi **@BotFather**, pilih `/newbot`, isi nama dan username bot. Simpan token yang diberikan secara privat. Jangan mengirim token ke percakapan, commit, URL browser, screenshot atau perintah yang direkam shell. Username konfigurasi tanpa `@`.

Bot hanya dapat mengirim DM setelah pengguna membuka bot/Start. Integrasi ini menggunakan **webhook HTTPS**; jangan menjalankan polling getUpdates lain untuk bot yang sama. [Panduan bot](https://core.telegram.org/bots#how-are-bots-different-from-users), [deep link](https://core.telegram.org/bots/features#deep-linking), [webhook API](https://core.telegram.org/bots/api#setwebhook).

### 2. Siapkan kode dan PHP

Backup database its dan file storage privat sesuai prosedur server, lalu pull commit yang berisi fitur ini. Pertahankan .env, APP_KEY dan storage. Pakai binary PHP CLI yang **sama versinya dengan PHP situs** pada aaPanel, minimal sesuai composer.json. Contoh berikut menggunakan placeholder `XX`; ganti, misalnya `82` hanya jika situs memakai PHP8.2. User contoh `www` harus sama dengan user PHP-FPM situs.

```bash
cd /www/wwwroot/itsy
/www/server/php/XX/bin/php -v
/www/server/php/XX/bin/php artisan migrate:status
```

Pastikan migration prasyarat unit Operation/Jabatan dan semua migration terdahulu sudah diterapkan. Periksa Pending sebelum melanjutkan; panduan ini tidak memerintahkan reset/seed/truncate atau penerapan massal migration uang lama.

Untuk migration tambahan Telegram saja:

```bash
/www/server/php/XX/bin/php artisan migrate --path=database/migrations/2026_11_18_000003_create_telegram_notifications.php --force
```

Jalankan build frontend sesuai prosedur deployment (hasil public/build/manifest dan seluruh aset harus satu versi), misalnya:

```bash
npm ci
npm run build
npm run check:assets
```

### 3. Isi .env server

Edit file .env melalui editor privat aaPanel. Jangan mengganti APP_KEY. Tambahkan:

```dotenv
TELEGRAM_BOT_TOKEN=isi_token_dari_BotFather
TELEGRAM_BOT_USERNAME=username_bot_tanpa_at
TELEGRAM_WEBHOOK_SECRET=isi_random_64_karakter_hex
TELEGRAM_APP_URL=https://its.telkomakses-area3.id
```

Secret dapat dibuat sekali dengan `openssl rand -hex 32`, lalu salin hasilnya ke kolom secret dalam .env. Ini secret webhook, bukan token bot. Bila aplikasi di subfolder, masukkan subfolder pada TELEGRAM_APP_URL. Pastikan APP_URL dan HTTPS situs juga benar.

```bash
/www/server/php/XX/bin/php artisan config:cache
/www/server/php/XX/bin/php artisan area3:telegram-webhook
/www/server/php/XX/bin/php artisan area3:telegram-webhook --check
```

Perintah pertama webhook mendaftarkan URL dan secret tanpa mencetak token. `--check` membandingkan URL tersimpan pada Telegram; bukan bukti callback/pengiriman end-to-end. Domain harus bisa menerima HTTPS publik dan POST `/api/telegram/webhook`, sertifikat valid, tanpa redirect login/WAF/challenge. Tidak perlu mengekspos queue atau endpoint lain.

### 4. Tambahkan Supervisor worker notifikasi

Cari lokasi `[include] files=` pada konfigurasi Supervisor yang sedang dipakai aaPanel. Gunakan folder include tersebut; jangan membuat daemon Supervisor kedua. Salin stub [area3-notifications.conf.stub](../deploy/supervisor/area3-notifications.conf.stub) ke file baru **area3-notifications.conf** pada folder include, lalu ganti placeholder:

- `{{project}}` → `/www/wwwroot/itsy`
- `{{php}}` → `/www/server/php/XX/bin/php` yang telah diverifikasi
- `{{user}}` → user PHP-FPM situs, contoh `www`
- `{{storage}}` → `/www/wwwroot/itsy/storage`

Tidak mengubah file konfigurasi worker dompis/wibsy/upload lain. Pastikan user situs dapat menulis storage dan bootstrap/cache sesuai izin aplikasi. Selanjutnya:

```bash
supervisorctl reread
supervisorctl update area3-notifications
supervisorctl status area3-notifications
```

Harus **RUNNING**. Jika gagal, baca `storage/logs/notifications-worker.log` dan log Laravel; jangan membagikan token atau data pribadi. Sesudah pembaruan kode Telegram/config berikutnya, restart hanya program ini:

```bash
supervisorctl restart area3-notifications
```

### 5. Jadwalkan Laravel satu menit

Di **aaPanel → Cron → Shell Script → setiap 1 menit**, tambahkan satu jadwal untuk proyek ini dengan user situs yang sesuai:

```bash
cd /www/wwwroot/itsy
/www/server/php/XX/bin/php artisan schedule:run --no-interaction >> /www/wwwroot/itsy/storage/logs/scheduler.log 2>&1
```

Jika jadwal Laravel proyek ini sudah ada, gunakan jadwal tersebut tanpa menambah duplikat. Definisi scheduler memakai everyMinute dan withoutOverlapping. [Dokumentasi Laravel Scheduler](https://laravel.com/docs/12.x/scheduling).

### 6. Hubungkan akun masing-masing

Login aplikasi sebagai pengguna yang akan menerima reminder → Profil & Preferensi → Telegram Approval → Hubungkan Telegram → YA → Buka Bot Telegram → Start. Kembali ke aplikasi → Muat Ulang Status → periksa nama/username Telegram → Konfirmasi Akun → YA. Start hanya mencatat pending koneksi; bot belum harus membalas chat. Pilih **Uji Pesan ke Saya** → YA; pastikan pesan benar-benar masuk. Uji tetap bisa dilakukan saat reminder global belum aktif.

### 7. Aktifkan dan uji satu giliran

Login Superadmin → Pengaturan → Telegram. Cek Koneksi Bot, tentukan PIC Finance untuk Branch/unit yang diperlukan, atur template/jam, aktifkan reminder lalu simpan dengan YA. User yang belum terhubung tidak dikirimi pesan.

Tunggu satu putaran scheduler atau jalankan pemeriksaan secara eksplisit sebagai user situs:

```bash
/www/server/php/XX/bin/php artisan area3:telegram-reminders
```

Perintah ini membuat task/notifikasi untuk **approval aktif sungguhan**, sehingga jalankan setelah pengguna siap menerima pesan. Lihat Riwayat Pengiriman dan Telegram penerima. Setelah approver menyelesaikan giliran di aplikasi, penerima berikutnya akan diperiksa pada putaran scheduler berikutnya; item lama tidak diingatkan lagi. Tidak perlu refresh inbox terus-menerus karena polling inbox aplikasi yang sudah ada tetap berjalan.

### 8. Pemeriksaan bila pesan belum masuk

- Pengguna telah menekan Start **dan** mengonfirmasi akun di aplikasi, serta belum memblokir bot?
- Bot/username/token/secret cocok, webhook URL sesuai, HTTPS publik lolos WAF?
- Reminder aktif dan saat ini dalam jam pengiriman WIB?
- Pengajuan benar-benar menunggu user tersebut, akun/Branch/Region/unit masih aktif? Finance memiliki PIC yang sesuai?
- Program **area3-notifications** RUNNING, bukan hanya area3-uploads/default; cron schedule:run berjalan?
- Riwayat `queued` menunggu worker, `failed` adalah penolakan API, `unknown` tidak otomatis dicoba ulang pada slot sama. `sending` dapat tertinggal jika proses mati; jangan memaksa mengulang pesan ambigu.

Nonaktifkan reminder dari UI untuk menghentikan pengiriman berikutnya; koneksi dan audit tetap tersimpan. Tidak ada jaminan menarik pesan yang sudah dikirim.

## Verifikasi dan batas

Lihat [bukti QA](qa/telegram-approval-20261118.json) dan entri terbaru LARAVEL_QA. Pengujian menggunakan SQLite :memory:, HTTP Telegram palsu serta browser dengan API fixture dan koneksi eksternal diblokir. MySQL DDL aaPanel, token nyata, callback HTTPS publik, jaringan Telegram, delivery pribadi dan cron/Supervisor server belum diuji. Bukti pengujian bukan bukti bot server telah terhubung.

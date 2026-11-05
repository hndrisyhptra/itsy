# Migration Area 3 ke database its

**Update 5 November 2026:** Migration tambahan dan pemindahan transaksi sudah diterapkan pada `its`. Sumber aktif adalah tabel bisnis dengan snapshot per dokumen; JSON workspace menjadi arsip. Lihat [MYSQL_RUNTIME.md](MYSQL_RUNTIME.md). Uraian konsep/agregat sebelumnya di bawah bersifat historis bila bertentangan.

Migration Laravel siap untuk database MySQL/MariaDB **its** yang dibuat pengguna di phpMyAdmin. Migration belum dijalankan ke database tersebut. `.env`, cache konfigurasi aktif dan SQLite demo lama dipertahankan.

## Menjalankan

Dari folder `/Applications/XAMPP/xamppfiles/htdocs/its/area3-prototype`, ubah **hanya bagian koneksi database** pada `.env` yang sudah ada. Pertahankan APP_KEY dan konfigurasi aplikasi lainnya.

```dotenv
DB_CONNECTION=mysql
DB_URL=
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=its
DB_USERNAME=ISI_DENGAN_USER_DATABASE_ANDA
DB_PASSWORD=ISI_DENGAN_PASSWORD_DATABASE_ANDA
```

Isi username/password berdasarkan akun yang memang digunakan untuk database tersebut. Jika password akun Anda kosong, gunakan `DB_PASSWORD=`. Tidak ada akun atau password produksi yang dibuat migration ini. `mysql` juga dapat dipakai untuk MariaDB XAMPP yang sudah diuji.

Jalankan:

```sh
cd /Applications/XAMPP/xamppfiles/htdocs/its/area3-prototype
php artisan config:clear
php artisan migrate
```

Perintah Laravel yang benar adalah **migrate**, bukan migration. Bila PHP pada terminal bukan PHP XAMPP, gunakan `/Applications/XAMPP/xamppfiles/bin/php` menggantikan `php` pada dua perintah tersebut.

Tidak perlu `--seed` atau mengimpor file SQL manual. Menjalankan migrate sekali lagi tidak mengimpor data dua kali. Pemeriksaan hasil tersedia melalui `php artisan migrate:status` dan tabel-tabel pada phpMyAdmin.

## Hasil yang dibuat

- 49 migration baru (termasuk audit akun 4 November), ditambah empat migration infrastruktur Laravel/prototype yang sudah ada. Urutannya: selaraskan users → 45 tabel Area 3 lainnya → relasi file BOQ → impor master.
- **46 tabel bisnis** dengan primary key bernama khusus, termasuk users.id_user, areas.id_area, branches.id_branch, project_plans.id_pp dan boq_lines.id_boq. Tidak membuat area3_users. Tabel infrastruktur Laravel seperti sessions/jobs/cache dan workspace demo mempertahankan kolom standar mereka.
- **2.173 baris master**: 1 Area, 3 Region, 16 Branch, 392 Service Area, 2 Paket, 5 Tipe Designator, 724 Designator dan 1.030 Harga Paket. Seluruh ID, nilai, tanggal, NULL dan tujuh harga nol dipertahankan. DENPASAR/R502 tetap satu baris.
- branches.code tetap singkatan asal; branches.kode_branch berisi 16 kode yang diberikan pengguna. Format nomor PP pada rancangan menggunakan kode_branch.
- 86 foreign key, kombinasi bisnis UNIQUE, dan 52 CHECK bisnis pada MySQL/MariaDB. Collation utf8mb4_unicode_ci mendukung MySQL dan MariaDB XAMPP; SHA-256 memakai ascii_bin dan nomor APM ascii_general_ci.

## users dan pemeliharaan data

Migration bawaan Laravel membuat users dengan primary key numerik id. Migration baru **mengganti nama kolom itu menjadi id_user**, tetap BIGINT UNSIGNED auto-increment, dan menambahkan active. ID pengguna, password/hash, email, remember_token dan timestamp lama tidak diubah. Relasi pengguna operasional mengikuti BIGINT UNSIGNED tersebut, bukan UUID pada draf awal. Model User sudah menunjuk id_user dan menggunakan kolom password standar Laravel. Struktur users yang berbeda dari bawaan akan dihentikan untuk diperiksa, bukan ditimpa.

Tidak ada akun login yang dibuat otomatis. Halaman /login kini menyediakan penyiapan Superadmin pertama dari localhost memakai kata sandi pilihan pengguna, kemudian pengelolaan akun/Branch oleh Superadmin. DatabaseSeeder bawaan tidak lagi membuat Test User/password contoh. Angka audit created_by/updated_by dari master tetap referensi sumber; user sumber 2 belum dipetakan, dan tidak dianggap identitas login yang sah. Kategori designator seluruhnya NULL; FK kategori menunggu tabel sumbernya.

Snapshot data di `database/data/area3-master-data.json` disertakan bersama kode dan diperiksa SHA-256 sebelum impor. Prepared insert dijalankan dalam satu transaksi setelah memastikan kedelapan tabel master kosong. Jika ada data existing, migration berhenti tanpa menimpanya. Timestamp sumber diimpor dalam timezone sesi UTC, lalu timezone sesi sebelumnya dipulihkan. Jangan mengubah snapshot migration yang sudah terpasang; perubahan master berikutnya memerlukan migration/perintah khusus berikutnya.

## Target dan rollback

Migration Area 3 hanya menerima driver mysql/mariadb dengan nama database its. Pengecualian hanya SQLite :memory: dalam environment testing. Koneksi SQLite demo persisten atau nama database berbeda ditolak sebelum migration Area 3 mengubah tabel.

Panduan ini mengasumsikan database its baru, atau database dengan migration infrastruktur proyek ini yang sudah tercatat. Jika schema/seed dari SQL konsep sudah diimpor manual, jangan menjalankan migrate:fresh untuk menanganinya: itu menghapus data. Cocokkan terlebih dahulu schema existing dengan riwayat migration. Migration tidak memakai IF NOT EXISTS untuk menyembunyikan konflik schema dan tidak memakai insert-or-ignore/upsert untuk menyembunyikan konflik data.

Rollback mengikuti urutan terbalik: hapus baris master yang diimpor, lepas relasi BOQ, hapus tabel bisnis, lalu kembalikan kolom users.id. FK akan menolak penghapusan master yang sudah dipakai transaksi. Rollback bersifat destruktif terhadap tabel/data yang dibentuk migration; pengujian rollback hanya dilakukan pada database sementara. Migration DDL MySQL/MariaDB dapat melakukan implicit commit; kegagalan di tengah satu migration harus diperiksa, bukan ditangani dengan reset database otomatis.

## Batas aplikasi setelah migration

Migration menyiapkan tabel dan data; belum memindahkan data workspace JSON SQLite ke tabel bisnis ternormalisasi. File SQLite demo tetap utuh. Mengganti koneksi default ke MySQL membuat penyimpanan workspace demo menggunakan database yang baru; riwayat workspace SQLite sebelumnya tidak otomatis muncul pada MySQL.

Antarmuka/alur akun membaca users, roles, user_assignments, Branch, designator, paket dan harga dari tabel relasional. Login memakai peran akun; dokumen/export/scan dibatasi Branch dan approval mengikuti lima akun terpilih untuk dokumen versi 3. Transaksi tetap aggregate workspace, belum ditulis ke tabel workflow ternormalisasi. Import Excel sesuai layout OSP FO kiriman sudah membaca/menyimpan file privat serta provenance; lihat [BOQ_IMPORT](BOQ_IMPORT.md). Format lain dan metadata referensi/periode KHS masih perlu pemetaan. Tombol contoh terpisah tetap simulasi; scan pembayaran tetap nyata. Migration tidak membuat PP, approval, scan, APM, pembayaran atau tanda tangan rekaan.

## Bukti uji

- Laravel feature tests memakai SQLite in-memory dengan bootstrap fail-closed yang sudah ada. Enam tes migration mencakup seluruh PK/FK, kesamaan semua baris snapshot, rerun tanpa duplikasi, preservasi user/hash, penolakan tabel master berisi data, rollback/reapply dan penolakan target database yang salah tanpa koneksi.
- Migration asli diuji pada **instance MariaDB 10.4.28 XAMPP sementara**, terpisah dari server pengguna: seluruh 46 tabel/2.173 baris, 52 CHECK, penolakan UNIQUE/FK, tanggal KHS invalid, timestamp UTC, user lama, rollback 48 migration dan reapply berhasil. Instance dihentikan setelah uji. Database its pengguna tidak diakses. Oracle MySQL 8 belum diuji langsung.
- SQLite tidak menjalankan CHECK khusus MySQL; SQLite dipakai untuk uji struktur/relasi/data dan validasi bisnis Laravel. Penegakan CHECK diverifikasi pada MariaDB terpisah tersebut.

## Integrasi akun 4 November 2026

Pengguna mengonfirmasi its telah dimigrasikan. Sebelum perubahan akun, cadangan its dibuat privat pada storage/app/private/backups/its-before-auth-20261104-083449.sql. Migration 2026_11_04_000001_add_account_to_prototype_audits menambahkan actor_user_id nullable dengan FK users.id_user; riwayat lama tetap valid. Migration ini sudah dijalankan pada koneksi its yang dikonfigurasi pengguna, tanpa mengganti .env. Jumlah migration saat ini 53. Tidak ada akun uji atau transaksi QA yang ditambahkan ke database pengguna; uji browser antar akun memakai instance MariaDB disposable yang terpisah. Panduan penggunaan login terbaru ada di README.

## Username, penempatan pengguna dan approval v3 — 4 November 2026

Dua migration lanjutan sudah diterapkan pada its setelah backup privat: 000002_extend_users_identity_and_placement menambah username/nik/id_regions/id_branch/id_service_areas/role dengan tiga FK geografis, dan 000003_version_the_approval_chain menambah workflow_version pada approval_runs/approval_steps, memperluas expected_role ke 40 karakter, serta membatasi pasangan tahap/role menurut versi. Total 55 migration. Akun lama mendapat user-ID dan role dari penugasan tunggal yang sudah ada; password/kolom lama tidak diubah, NIK/penempatan tidak ditebak. users.role harus sesuai penugasan agar dapat login. Kolom metadata boleh NULL untuk kompatibilitas data lama; username wajib/unik pada form aplikasi. Region/Branch/Service Area divalidasi server.

Versi 3 memiliki lima approver PP/termin sebelum cetak, lalu approval Finance setelah upload scan termin. Finance dicatat terpisah dari lima keputusan pekerjaan. Versi 2 tetap valid dalam schema, dan rollback ditolak bila sudah ada approval versi 3 pada tabel ternormalisasi. Runtime workflow tetap agregat JSON, belum dipindahkan ke approval_runs/steps. Detail: WORKFLOW_V3.md. Pemeriksaan data sebelum/sesudah: docs/qa/username-approval-v3-preservation-20261104.json. Base SQL concept lama merekam versi 2; migration terbaru adalah otoritas struktur terpasang.


Update eviden pekerjaan 5 November 2026: migration `2026_11_05_000002_create_term_work_evidence_table` sudah diterapkan pada its, total 57 migration, tanpa mengubah transaksi lama. Detail penggunaan, path SQL dan bukti: [TERM_WORK_EVIDENCE.md](TERM_WORK_EVIDENCE.md).

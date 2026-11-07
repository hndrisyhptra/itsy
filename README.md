# it's Area 3 — Construction Workspace

Aplikasi mandiri Laravel 12/PHP 8.2 + React/TypeScript untuk administrasi konstruksi dan pembayaran mandor. Folder utama `/Applications/XAMPP/xamppfiles/htdocs/its/area3-prototype`. Tidak memakai atau mengubah proyek/database Dompis Prov.

Panduan struktur tersedia pada [buku saku relasi database](docs/DATABASE_RELATIONS.md): PDF/Word ukuran A5, visual relasi dan katalog seluruh tabel dengan pencarian.

Untuk audit dan melanjutkan pekerjaan pada percakapan baru, baca [catatan perubahan](docs/CHANGELOG.md), WORKPLAN.md, serta [bukti pengujian](docs/LARAVEL_QA.md). Setiap perubahan, termasuk tampilan, wajib dicatat beserta cakupan, file terkait dan batas verifikasinya. Lampiran BA KH memakai tabel ringkas dan cetak A4 horizontal; sel komponen yang tidak digunakan pada seluruh tabel tampil kosong, sedangkan nilai nol tetap terlihat. Rincian: [preview Lampiran BA KH](docs/BAKH_APPENDIX.md).

**Preview BA KH** utama mengikuti [template berita acara Word](docs/BAKH_TEMPLATE.md), memakai nilai dari Informasi Dokumen BA KH, tanggal terbilang dan identitas Head/Mandor tersimpan. Dokumen utama A4 vertikal, lampiran harga tetap terpisah/horizontal dan ruang TTD kosong untuk tanda tangan basah.

Pada **Preview BA KH** dan **Lihat Lampiran**, tombol **Unduh Word (.docx)** membuat file editable dari isi preview yang sama, termasuk draf, langsung di browser tanpa menyimpan/mengesahkan transaksi atau menambah query database. Rincian dan hasil verifikasi: [BAKH_WORD_EXPORT](docs/BAKH_WORD_EXPORT.md).

**Preview SPK Mandor** mengikuti surat pada template pengguna: logo, penerima/alamat, perihal/periode, ringkasan Material/Jasa/Total, Waspang/K3 dan penandatangan SPK dengan ruang TTD kosong. Tombol **Unduh Word (.docx)** membuat surat editable dari data preview yang sama, termasuk draf, tanpa menyimpan/mengesahkan. Lihat [SPK_TEMPLATE_WORD](docs/SPK_TEMPLATE_WORD.md).

Dashboard memakai [tampilan ringkasan awal](docs/DASHBOARD_SUMMARY.md): tiga kartu utama dan enam ringkasan kecil, tanpa kartu Progress Pekerjaan per PP, Aktivitas Terbaru, Monitoring Volume dan Inbox Approval. Dropdown **Semua Branch** mencakup seluruh Branch sesuai akses akun, termasuk yang belum mempunyai PP, dan menyaring seluruh angka dari data tersimpan Laravel.

**Pengesahan BA KH, SPK dan BA PP tidak lagi membutuhkan isian teks Referensi bukti dokumen bertanda tangan.** Isian/status referensi dan kolomnya di timeline dihapus; syarat dokumen lainnya tetap divalidasi Laravel. Referensi lama disimpan sebagai riwayat, sedangkan upload eviden pekerjaan serta scan TTD Pembayaran tetap mengikuti alur berkas privat. Tidak perlu migration. Rincian: [DOCUMENT_ATTESTATION](docs/DOCUMENT_ATTESTATION.md).

[Arsitektur aplikasi](docs/ARCHITECTURE.md) memetakan form → command → domain Laravel → SQL its serta keputusan perubahan. Setiap pembaruan berikutnya wajib mencatat dampak arsitektur dan tinjauan query/performa, termasuk bila tidak ada perubahan pada lapisan itu.

Pada **SPK → Negosiasi Baru**, cari **ID Project/nama pekerjaan**, pilih ID Project, lalu pilih PP di tabel dan tekan **Lanjut Negosiasi**. Daftar mengikuti Branch akun, maksimal 20 hasil per halaman, tanpa memilih PP pertama otomatis. PP yang belum memenuhi syarat menampilkan alasan; dokumen tersimpan tetap memakai PP aslinya. Rincian alur, query dan batas verifikasi: [NEGOTIATION_PROJECT_PICKER](docs/NEGOTIATION_PROJECT_PICKER.md).

**SPK baru/draf** mengisi otomatis nama, jabatan dan NIK Head of PMO dari PP terkait. Surat dan lampiran memiliki **Unduh Word (.docx)**; dokumen terbit mempertahankan identitas tersimpan dan NIK yang belum tercatat ditandai belum tersedia. [Perilaku dan verifikasi](docs/SPK_SIGNER_WORD.md).

**Lihat Lampiran SPK** mengikuti template Lampiran-SPK.xlsx: designator/uraian terpisah, harga satuan Material/Jasa, **nama kegiatan → Vol** (tanpa label NAMA PROYEK), Total Vol, nilai/rekap dan ruang TTD penandatangan SPK. Tabel yang sama tersedia di form; Cetak/Simpan PDF meminta A4 horizontal. [Pemetaan dan batas verifikasi](docs/SPK_APPENDIX_TEMPLATE.md).

## Menjalankan dan login

Database aktif menggunakan MySQL/MariaDB **its**. Transaksi sudah dipindahkan ke tabel bisnis pada 5 November 2026; PP yang ada tidak hilang. Lihat [penyimpanan aktif dan hasil verifikasi](docs/MYSQL_RUNTIME.md). Jalankan migration tambahan saat memperbarui kode agar kolom identitas akun dan versi approval terbaru tersedia:

```sh
cd /Applications/XAMPP/xamppfiles/htdocs/its/area3-prototype
/Applications/XAMPP/xamppfiles/bin/php artisan migrate
/Applications/XAMPP/xamppfiles/bin/php artisan area3:activate-storage
npm run build
/Applications/XAMPP/xamppfiles/bin/php artisan serve --host=127.0.0.1 --port=8013
```

Buka [Login Artisan](http://127.0.0.1:8013/login) atau [Login Apache XAMPP](http://localhost/its/area3-prototype/public/login). Apache memakai document root `public`; subfolder URL juga didukung. `.env` yang sudah digunakan jangan ditimpa. Panduan instalasi/schema: [MYSQL_MIGRATION](docs/MYSQL_MIGRATION.md). Perintah Laravel adalah `migrate`, bukan `migration`. Tidak perlu `--seed` atau impor SQL manual.

Saat tabel `users` kosong, halaman login dari komputer lokal menampilkan **Siapkan akses pertama**. Isi nama, username, email, kata sandi pilihan sendiri dan konfirmasinya. NIK/penempatan dapat diisi sesuai data pegawai. Kata sandi minimal 12 karakter dengan huruf serta angka, maksimal 72 byte; disimpan sebagai hash Laravel. Tidak ada password bawaan atau akun uji yang ditanam pada database pengguna. Penyiapan pertama hanya menerima alamat loopback `127.0.0.1`/`::1`, diserialkan dengan lock, dan tertutup setelah satu akun dibuat.

Akun pertama menjadi **Superadmin**, ditugaskan ke seluruh Branch aktif. Setelah masuk, halaman **Kelola akun** memungkinkan penambahan/perubahan akun, satu peran per akun, satu atau beberapa Branch, status aktif, dan pengaturan ulang kata sandi oleh administrator. Tidak ada penghapusan akun; nonaktifkan untuk mempertahankan riwayat. Akun sendiri harus tetap Superadmin aktif. Form akun ada melalui menu profil atau Pengaturan → Akun → Kelola akun pengguna.

Pada tambah/ubah akun melalui Kelola akun, kata sandi minimal **6 karakter**, mengandung huruf dan angka, maksimal 72 byte dan harus cocok dengan konfirmasi. Saat mengubah akun, kosongkan kedua kolom untuk mempertahankan kata sandi. Penyiapan Superadmin pertama tetap mengikuti aturan 12 karakter di atas.

**Mgr Project Support** tersedia pada form akun dan menjadi approver ketiga PP serta Pembayaran, sebelum Mgr Deployment. Dokumen lama tetap memakai assignment tersimpannya; lihat [PROJECT_SUPPORT_APPROVAL](docs/PROJECT_SUPPORT_APPROVAL.md).

Urutan PP/Pembayaran adalah PM → Admin Area → Mgr Project Support → Mgr Deployment → Head of PMO, termasuk pengajuan berjalan. Keputusan terdahulu tetap dihitung dan tidak ditimpa. Migration `2026_11_06_000003_version_support_first_approval_order` memperbarui CHECK untuk run baru sambil menjaga identitas langkah historis; sudah diterapkan pada its lokal. Instalasi lain menjalankan `php artisan migrate`.

Buat akun **Admin Konstruksi, PM Konstruksi, Admin Area, Mgr Deployment, Mgr Project Support, Head of PMO, dan Finance** sesuai kebutuhan pengujian. Login memakai **username**, lalu keluar/masuk bergantian sesuai peran. Peran mengikuti `users.role` yang konsisten dengan `roles`/`user_assignments`, bukan pemilih role. Kelima approver dipilih pada PP dan wajib memiliki penugasan Branch yang mencakup PP. Manager Konstruksi tersedia untuk dokumen alur lama saja. Akun tanpa penugasan aktif, akun nonaktif atau akun dengan beberapa peran aktif berbeda ditolak. Pemulihan kata sandi melalui email belum disediakan; hubungi Superadmin.

Login memiliki pembatasan percobaan, regenerasi sesi, CSRF dan opsi Ingat saya. Keluar hanya melalui POST dari menu profil. Endpoint workspace, laporan, cadangan dan scan memerlukan login. Respons pribadi memakai no-store/noindex. Jika data berubah dari pengguna lain, gunakan **Muat ulang data**; konflik revisi 409 tidak menimpa transaksi.

Dependensi dikunci dengan `composer.lock`/`package-lock.json`. Untuk mengubah React/CSS/logo sidebar di `resources/js/area3/App.tsx`, jalankan `npm run build` lalu muat ulang halaman. Saat pengembangan dapat memakai `npm run dev`. Login memakai Blade serta entry Vite `resources/js/area3/auth.ts` dan `auth.css`.

BA KH baru/draf dari PP versi 3 otomatis menampilkan nama dan NIK **Head of PMO terpilih pada PP** pada form dan preview. Identitas tidak diedit manual; NIK yang belum tersedia perlu dilengkapi pada akun. Dokumen Terbit/Sah mempertahankan identitas aslinya. Rincian: [BAKH_SIGNER](docs/BAKH_SIGNER.md).

## Antrean upload dan optimasi

Upload BOQ serta PDF/foto eviden pada layar aplikasi memakai antrean database Laravel. Status proses tampil pada dokumen; BOQ selesai dapat diambil kembali melalui **Gunakan hasil**. Upload terkait harus selesai sebelum pengajuan approval. Di XAMPP lokal, worker mulai otomatis saat upload dengan pengguna OS Apache agar akses file privat tetap sesuai. Worker manual khusus adalah `php artisan area3:work-uploads`; `php artisan queue:work` tanpa nama queue hanya memantau `default`. Bila berhenti, gunakan **Lanjutkan antrean**. Detail worker, tabel/endpoint, batas operasional dan bukti optimasi: [UPLOAD_QUEUE_PERFORMANCE](docs/UPLOAD_QUEUE_PERFORMANCE.md).

Penyimpanan kini menulis dokumen yang berubah saja, pembacaan layar dibatasi Branch di SQL, preview file memakai query langsung, dan modul halaman dimuat sesuai kebutuhan. Pada uji perubahan pengaturan dengan 20 PP: 1.330 → 8 query; JavaScript utama sekitar 27% lebih kecil. Angka ini bukan hasil uji beban serentak.

## Bulk Import master

Superadmin dapat memakai **Master Designator → Designator / KHS → Download Template**, mengisi Excel, lalu **Bulk Import → Periksa file → Simpan impor**. Maksimal 1000 baris/5 MB; tersedia tambah baru atau tambah dan perbarui data cocok. Seluruh batch dibatalkan jika ada baris salah. ID dibuat otomatis; template menyertakan panduan dan kode referensi aktif. Lihat [MASTER_BULK_IMPORT](docs/MASTER_BULK_IMPORT.md). Tidak perlu migration tambahan.

## Identitas akun terbaru

Migration menambahkan `username`, `nik`, `id_regions`, `id_branch`, `id_service_areas`, dan `role` pada users. Akun lama diberi username `user-ID` (akun existing saat pembaruan: **user-1**), dengan kata sandi tetap. Username dapat diganti Superadmin melalui Kelola akun; username baru disimpan dalam huruf kecil dan unik. NIK adalah teks agar nol di depan dipertahankan. Region → Branch → Service Area divalidasi server; Branch utama harus termasuk daftar Branch penugasan. Data yang belum diketahui tetap kosong, tanpa NIK/penempatan rekaan. Migration tambahan sudah dijalankan pada its setelah backup; total 59 migration setelah penambahan antrean upload.

## Alur kerja

Sembilan menu dipertahankan: Dashboard, Project Plan, SPK, Pembayaran, BA PP, Inbox Approval, Master Designator, Laporan, Pengaturan. BOQ menjadi langkah pertama PP; BA KH berada dalam proses SPK. Alur terbaru: [WORKFLOW_V3](docs/WORKFLOW_V3.md); versi sebelumnya tetap menjadi referensi historis.

1. Admin Konstruksi/Superadmin mengisi BOQ dan rencana PP, memilih lima approver, lalu mengajukan.
2. **PM → Admin Area → Mgr Deployment → Mgr Project Support → Head of PMO** menyetujui berurutan. PP Sah/BA KH/SPK memerlukan 5/5.
3. Termin baru menyalin kelima approver PP. Setelah 5/5, dokumen dapat dicetak untuk TTD fisik dan kuantitas terkunci.
4. Admin mengunggah scan privat, **Finance menyetujui/memverifikasi scan terbaru**, kemudian mencatat APM yang melunasi seluruh tagihan secara atomik. Keputusan Finance dan koreksi berkas memiliki riwayat tersendiri.
5. Maksimal tiga termin, termin ketiga wajib Final, tidak ada termin setelah Final lebih awal. APM valid tetap diperlukan sebelum termin berikutnya. BA PP Sah mendahului pelepasan/PP lanjutan dan anggaran root tidak dihitung dua kali.

Dokumen versi 1/2 yang sudah tersimpan mempertahankan riwayat/aturan lamanya. Untuk termin baru dari PP lama, tetapkan lima approver pembayaran berikutnya pada detail PP. Penanggung jawab termin yang sudah tersimpan tidak berubah. PP lanjutan baru memakai versi 3.

**Penolakan PP/termin versi 3** wajib disertai catatan. Admin Konstruksi dapat memperbaiki dan mengajukan ulang dokumen yang sama; lima approval dimulai kembali dari PM. Riwayat penolakan, nomor PP/termin, dan lampiran tetap dipertahankan. Pengajuan tidak otomatis masuk ke approver sampai admin mengirim ulang. Tidak perlu migration tambahan. Lihat [APPROVAL_RESUBMISSION](docs/APPROVAL_RESUBMISSION.md).

Header PP berurutan ID Project, Branch, Unit Kerja (Konstruksi/Operation), No PP otomatis. Penomoran global workspace diatur Superadmin melalui Pengaturan → Penomoran PP. Format bawaan `{SEQ}/LG.000/TA-03{BRANCH}/{MM}-{YYYY}`, contoh `0001/LG.000/TA-03R424/11-2026`. Kode diambil dari `branches.kode_branch`, waktu server Jakarta, urutan minimal empat digit tanpa reset otomatis. Nomor yang sudah diberikan tetap dipertahankan. Harga server, ceiling per komponen dan ledger volume maksimum tiga desimal tetap berlaku. Dokumen historis versi 1 tetap menggunakan aturan historisnya.

**Nomor BA KH dan SPK otomatis mengikuti No PP:** PP `0002/LG.000/TA-03R546/11-2026` menghasilkan BA KH `0002-01/LG.000/TA-03R546/11-2026` dan SPK `0002-02/LG.000/TA-03R546/11-2026`. Form nomor baca saja; Laravel menghitung ulang dari PP tersimpan. Dokumen Terbit/Sah mempertahankan nomor historis. Tidak perlu migration tambahan. Lihat [CONTRACT_NUMBERING](docs/CONTRACT_NUMBERING.md).

**Satu ID Project dapat dipakai beberapa PP**, masing-masing dengan No PP, BOQ, approval dan pembayaran sendiri. Klik ikon **riwayat/timeline** pada kolom Aksi di tabel Project Plan untuk membuka seluruh PP pada ID Project yang sama. Timeline menampilkan tahap approver yang ditunggu, BA KH/SPK, pengajuan termin, scan/Finance, APM, riwayat keputusan dan aktivitas. Filter PP tersedia; pengelompokan mengabaikan spasi tepi/perbedaan huruf besar-kecil dan tetap mengikuti akses Branch. Kolom Jenis PP di tabel utama dihapus; relasi induk/lanjutan tetap tersedia pada detail. Lihat [PROJECT_TIMELINE](docs/PROJECT_TIMELINE.md).

**Upload BOQ Excel sudah nyata** untuk format contoh OSP FO yang dikirim: sheet BoQ, header baris 6–7, kode B, satuan D, harga E/F, volume G. File asli disimpan privat dan baris positif dicocokkan ke paket KHS; Material/Jasa dipisahkan. Contoh 14 pekerjaan menghasilkan 26 komponen dan BOQ Owner Rp140.056.789. Harga Excel hanya pembanding; selisih memerlukan alasan/konfirmasi KHS sebelum pengajuan. Draf dapat disimpan setelah Header PP lengkap, tanpa BOQ, jadwal lengkap atau kelima approver. Jika BOQ sudah diisi, barisnya tetap harus valid; seluruh persyaratan wajib saat pengajuan. Tombol **Muat Contoh BOQ (Simulasi)** tetap memuat contoh terpisah. Format dan batas: [BOQ_IMPORT](docs/BOQ_IMPORT.md).

Pendahuluan & Tim serta Jadwal Proyek pada PP versi 3 kini mengikuti template Word kiriman: redaksi dan aktivitas terkunci, hanya HK jadwal yang dapat diubah. Total durasi dan tanggal tahap dihitung otomatis dari Saat Penggunaan. Kurva S dihilangkan dari form dan preview. Anggaran & Margin kini memuat tabel Owner dari BOQ Plan, BOQ Mandor per designator, baris Material Non Stock/BOP yang dapat ditambah atau dihapus, perizinan dan margin otomatis. Form serta validasi Batas maksimal BOP/Referensi Cost Structure sudah dihapus; rincian dan total BOP tetap divalidasi serta masuk COGS. Poin 5 otomatis merekap nilai Jasa Mandor, Material BOQ Mandor bila ada, Material Non Stock, BOP dan perizinan, tanpa memilih termin/tanggal atau membagi nilai manual. Kategori nol disembunyikan; reimbursement perizinan tetap terpisah dari COGS. Kolom designator, uraian pekerjaan dan satuan dipisahkan pada kedua BOQ, dengan tabel rapat bergaris pada form dan preview. Risiko dipilih melalui checklist; komunikasi, kualitas dan penutup mengikuti teks tetap. Tabel penutup PP baru memakai empat jabatan baku (PM, Mgr Project Support, Mgr Deployment, Head of PMO); lima approval tetap wajib termasuk Admin Area. Dokumen Business Support historis mempertahankan snapshot sebelumnya. Rincian anggaran: [PP_BUDGET_TEMPLATE](docs/PP_BUDGET_TEMPLATE.md). PP historis tetap dipertahankan. Rincian dan batas output: [PP_TEMPLATE](docs/PP_TEMPLATE.md).

Pada SPH Telkom Akses dan tab Harga Kesepakatan, harga Material/Jasa masing-masing dibatasi harga per satuan BOQ TA–Mandor pada PP yang dipilih; harga kesepakatan juga tetap dibatasi KHS. Nilai awal mengikuti harga Mandor; kelebihan memunculkan peringatan langsung dan ditolak Laravel saat simpan/terbit/pengesahan. Dokumen terbit serta PP historis tanpa rincian BOQ Mandor mempertahankan aturan lamanya. Rincian: [SPH_THRESHOLD](docs/SPH_THRESHOLD.md).

**Simpan Draft PP dan Preview:** setelah Header lengkap, **Simpan Draft** menunggu konfirmasi Laravel lalu kembali ke daftar Project Plan dengan status Draft. PP baru selalu membuka isian bersih; cache edit tetap per-ID PP. Pada Preview PP, kolom TTD menampilkan cap **APPROVED** hanya untuk keputusan approver pada siklus aktif; saat cetak/Simpan PDF browser cap dan status disembunyikan sehingga ruang TTD basah kosong. Lihat [PP_DRAFTS_PREVIEW](docs/PP_DRAFTS_PREVIEW.md).

## Database dan penyimpanan

| Data | Lokasi/aturan |
|---|---|
| Akun login | `its.users`: id_user, username, nik, id_regions, id_branch, id_service_areas, role, password hash Laravel |
| Peran/Branch pengguna | `roles`, `user_assignments`, `branches` |
| Branch dan kode PP | Master relasional `branches.kode_branch` |
| Harga/acuan BOQ | `designators`, `designator_types`, `packages`, `designator_package_prices` |
| Transaksi workflow | Tabel bisnis MySQL: `project_plans`, BOQ, kontrak, SPK, pembayaran dan approval; snapshot lengkap per dokumen |
| Arsip workspace | `prototype_workspaces.state` dipertahankan sebagai arsip sebelum pemindahan, bukan sumber transaksi aktif |
| Audit | `prototype_audits`: akun `actor_user_id`, role, perintah, request UUID, revisi dan hash |
| Eviden pekerjaan | `storage/app/private/work-evidence/`; path SQL pada `document_files.storage_key`, relasi `term_work_evidence` |
| Scan nyata | `storage/app/private/prototype-scans/`, tidak dilayani sebagai aset publik |
| BOQ Excel asli dan manifest | `storage/app/private/prototype-boq/`, metadata sumber tertaut pada PP; unduhan mengikuti akses Branch |
| Draft/tema | localStorage browser; draft dipisahkan per akun dan dibersihkan saat keluar |
| SQLite lama | `database/database.sqlite`, dipertahankan; tidak otomatis diimpor ke MySQL |

Workspace akun baru dimulai tanpa transaksi rekaan. Workspace sesi demo lama dan data SQLite tidak dihapus atau digabungkan otomatis. Hanya dokumen dari Branch penugasan yang dikirim ke akun, termasuk data terkait, aktivitas, CSV, cadangan JSON, serta akses file scan. Penetapan workspace berasal dari server, tidak menerima ID kepemilikan client. Approval/audit memakai identitas login pada setiap permintaan, bukan role terakhir yang tersimpan di workspace bersama. Perintah pergantian role, reset skenario, penggantian profil/pengguna demo dan perubahan master sintetis ditolak di jalur login.

Master yang sudah diimpor memuat 16 Branch, 724 designator dan 1.030 harga paket. Semua 2.173 baris sumber dan ID aslinya dipertahankan. Komponen Material/Jasa mengikuti jenis designator sumber (ID tipe 1/2), tidak digabung berdasarkan kemiripan nama; tujuh harga nol tetap nol. Master aktif kini dikelola Superadmin melalui **Master Designator → Designator / KHS / Paket KHS**, dengan tambah, edit, hapus arsip dan ikon bertooltip. Peran lain hanya melihat; identitas yang sudah dipakai BOQ dilindungi. Lihat [MASTER_DESIGNATOR](docs/MASTER_DESIGNATOR.md). Counter versi lokal membantu snapshot workflow dan bukan metadata kontrak KHS resmi. Referensi/periode KHS serta pemetaan audit user sumber belum tersedia.

CSV/JSON dibuat Laravel dari data sesuai akses. UUID permintaan mencegah transaksi ganda; revisi mencegah tab/akun menimpa data lebih baru. CSV menetralkan teks formula. Unduhan JSON tidak mencakup file scan/Excel asli atau hash kata sandi, cookie, session key dan kredensial database.

Tanggal Mulai dan Tanggal Selesai pada **Dokumen BA KH** otomatis mengikuti jadwal PP terpilih dan tampil baca saja untuk dokumen baru/draft. Laravel menghitung ulang saat simpan/generate/pengesahan; draft lokal lama tidak menimpa periode otomatis. Dokumen Terbit/Sah mempertahankan snapshot tanggalnya. Tidak perlu migration tambahan. Detail: [BAKH_SCHEDULE](docs/BAKH_SCHEDULE.md).

Pada detail **Pembayaran/Termin**, Admin dapat mengunggah **eviden pekerjaan PDF/JPG/PNG/WebP** saat Draft/Dikembalikan (maksimal 10 file aktif, 10 MB per file). Approver dapat preview/unduh langsung dari Inbox Approval. File privat tersimpan di server; path dicatat pada `document_files.storage_key` dan relasi termin pada `term_work_evidence`. Lampiran terkunci selama approval; penghapusan saat koreksi tetap menyimpan riwayat. Migration sudah diterapkan ke its. Detail: [TERM_WORK_EVIDENCE](docs/TERM_WORK_EVIDENCE.md).

## Struktur dan pengujian

| Lokasi | Fungsi |
|---|---|
| `routes/web.php`, `bootstrap/app.php` | Route dan perlindungan login |
| `app/Services/AccountAccess.php` | Peran dan penugasan akun dari SQL |
| `app/Services/AuthenticatedWorkspace.php` | Master SQL, identitas server dan batas Branch |
| `app/Http/Controllers/AuthController.php`, `AccountController.php` | Login, penyiapan awal dan pengelolaan akun |
| `app/Services/PrototypeDomain.php`, `PrototypeWorkflowV2.php`, `PrototypeApprovalChain.php` | Validasi bisnis otoritatif dan versi approval |
| `app/Services/PrototypeWorkspaceService.php` | Penyimpanan, transaksi, revisi dan audit |
| `resources/views/auth/`, `resources/js/area3/auth.css` | Halaman login dan kelola akun |
| `resources/js/area3/` | React, preview, form dan perhitungan |
| `database/demo/` | Fixture sintetis untuk regresi, tidak dimuat ke workspace akun |
| `tests/Feature/`, `tests/Frontend/` | Pengujian keamanan/alur dan invariant |

```sh
/Applications/XAMPP/xamppfiles/bin/php artisan test --compact
npm test
npm run build
python3 -m unittest discover -s tools -p test_mysql_concept.py -v
```

Tes PHP memakai SQLite `:memory:` dengan bootstrap fail-closed, termasuk bila cache konfigurasi tersedia. Jangan menjalankan tes terhadap database aktif. Bukti, hasil uji browser terisolasi dan batas yang belum diuji: [LARAVEL_QA](docs/LARAVEL_QA.md).

## Batas prototype

- Login/peran dan transaksi utama sudah memakai MySQL its. Rincian dinamis/historis masih memakai snapshot JSON per dokumen; beberapa tabel konsep belum menjadi sumber aplikasi. Lihat [MYSQL_RUNTIME](docs/MYSQL_RUNTIME.md). Audit login/logout dan perubahan akun belum terpusat; audit workflow sudah tersedia.
- Impor Excel mendukung layout OSP FO kiriman, harga komponen kosong dan hasil angka rumus yang sudah tersimpan. Aplikasi tidak menghitung ulang rumus; cache hilang/error dan master KHS yang belum tersedia tetap ditolak. Lihat [BOQ_IMPORT](docs/BOQ_IMPORT.md). Tombol contoh tidak tersedia pada aplikasi login. Retensi/pembersihan file unggahan belum otomatis.
- Scan disimpan nyata, namun keaslian TTD diverifikasi manusia. APM adalah pencatatan pelunasan manual, tidak memanggil bank atau membuktikan transfer. Reversal belum tersedia.
- Dokumen masih preview HTML/cetak browser, belum generator DOCX/PDF server atau tanda tangan elektronik. Tidak membuat tanda tangan rekaan.
- Reset/skenario sintetis hanya fixture/offline; tidak tersedia pada workspace akun bersama. Belum ada pemulihan/impor cadangan JSON melalui UI.
- Pengujian ini tidak mencakup beban produksi, MFA/SSO atau pengiriman reset email.

Referensi 46 tabel bernama ID khusus: [MYSQL_CONCEPT](docs/MYSQL_CONCEPT.md), [MYSQL_MASTER_MAPPING](docs/MYSQL_MASTER_MAPPING.md). SQL adalah referensi; gunakan migration Laravel. Cadangan sebelum username/approval v3: `storage/app/private/backups/its-before-username-approval-v3-20261104-112005.sql`. Data lama diverifikasi tetap identik (kolom lama users termasuk password, workspace/audit dan delapan master) pada `docs/qa/username-approval-v3-preservation-20261104.json`. Cadangan sebelum integrasi akun sebelumnya disimpan privat pada `storage/app/private/backups/its-before-auth-20261104-083449.sql`. Insiden pemulihan data demo sebelumnya tetap tercatat dalam QA dan tidak diklaim selesai oleh perubahan ini.

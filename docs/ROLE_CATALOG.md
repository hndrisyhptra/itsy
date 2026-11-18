# Katalog Peran Pengguna

## 18 November 2026 — Lingkup yang dikonfirmasi pengguna

Pengguna meminta tambah/edit/hapus Peran Pengguna di Pengaturan setelah Penyimpanan. Pada klarifikasi, pengguna memilih **kelola daftar peran dahulu; hak akses/approval baru dihubungkan saat pengembangan flow**. Dengan demikian peran tambahan adalah katalog SQL nyata, tetapi belum ditawarkan pada Kelola Akun atau diberikan kewenangan aplikasi. Flow Operation tetap belum dieksekusi.

Hanya Superadmin aktif dengan assignment SQL yang sah dapat membuka/mengubah katalog. Tab Peran Pengguna ditempatkan tepat sesudah Penyimpanan dan tidak ditampilkan untuk peran lain. Pencarian kode/nama memakai debounce 300ms, pembatalan respons lama, pagination 20 baris dan indikator loading/error/kosong. Ikon edit/hapus/muat ulang memiliki tooltip dan label aksesibel. Penggunaan akun menghitung semua akun, sedangkan penugasan menghitung aktif maupun historis.

Tambah meminta kode unik 2–40 karakter huruf besar/angka/garis bawah, diawali huruf, serta nama 1–100 karakter tanpa markup/control/newline. Kode tersimpan tetap; edit hanya nama katalog. Label otoritas workflow tetap berasal dari `AccountAccess::ROLES`, dan Jabatan resmi penandatangan tetap berasal dari akun/snapshot. Pengubahan nama katalog bukan penggantian role akun, urutan persetujuan atau penandatangan dokumen. Peran bawaan selalu dilindungi dari hapus; peran tambahan dapat dihapus hanya bila tidak dipakai `users.role` maupun `user_assignments.role_id`, termasuk assignment nonaktif. Tidak ada penghapusan/penulisan ulang keputusan atau transaksi.

Simpan meminta konfirmasi YA/TIDAK, termasuk submit melalui Enter. Hapus menggunakan dialog YA/TIDAK sendiri tanpa konfirmasi ganda. Batal tidak mengirim POST. Selama request berjalan, aksi ganda dicegah. Error validasi/konflik mempertahankan formulir; koneksi terputus/hasil tidak pasti memblokir pengiriman lagi sampai pengguna menutup dan memuat ulang. Tidak ada retry otomatis, optimistic success, localStorage atau penggantian aggregate workspace. Navigasi, cari, pagination dan muat ulang tetap langsung.

## Data, otorisasi dan konkurensi

- `GET /api/account/roles?q=...&page=...` dan `POST /api/account/roles` memakai auth, auth.session, ActiveAccount, Superadmin check dan throttle. POST dilindungi CSRF. Response private/no-store; transport memakai origin/basePath halaman, timeout 20 detik dan abort saat daftar tidak lagi relevan.
- `roles.id_role/code/name` existing tetap sumber katalog. Tidak ada kolom atau migration baru. ID baru lebih besar dari maksimum katalog maupun audit role, sehingga ID terbesar yang dihapus tidak dipakai ulang.
- `RoleCatalog` memakai lock `area3-account-provisioning` yang sama dengan simpan akun serta transaksi SQL. Actor aktif/role/assignment diperiksa kembali di dalam lock; role target dikunci dan fingerprint versi id/code/name dibandingkan sebelum edit/hapus. Kode unik, kapasitas unsigned-smallint dan referensi akun diperiksa ulang di server.
- Audit existing `master_data_changes` memakai entity `role`, actual actor_user_id, before/after, waktu dan UUID. `after_data` menyimpan hasil serta hash payload kanonis untuk replay terikat aktor/isi yang sama. UUID berbeda isi/aktor atau entity lain ditolak. Perubahan katalog dan audit atomik; audit gagal mengembalikan perubahan role. Tidak memakai command_receipts/workspace revision karena fitur ini mengubah katalog akun global, bukan state workflow.
- Tidak ada pembacaan/penulisan arsip workspace, dokumen, keputusan, nominal, payee/kasbon terenkripsi atau file privat. Tidak ada seeding otomatis, role alias baru, role switching atau credentials baru.

## Query dan batas performa

Daftar service memakai **3 query**: select 3 kolom roles dengan limit21, grouped users count untuk maksimal20 kode, grouped assignment count untuk maksimal20 ID. HTTP fixture **4 query** termasuk auth assignment. Tidak ada lookup per baris atau pemuatan seluruh akun/dokumen. Pencarian contains dapat memindai katalog; pagination membatasi respons, bukan klaim semua pencarian memakai indeks. Offset dibatasi maksimum3277 halaman sesuai kapasitas ID.

Fixture HTTP mutasi dengan30 akun tambahan: tambah **9 query**, edit **8**, hapus **9**, UUID replay **4**. Counts termasuk otorisasi, pemeriksaan actor/record/ref dan audit; tidak ada workflow/private reads. Ini bukti terisolasi, bukan benchmark MySQL/latensi/beban multi-proses. Lock bergantung pada konfigurasi cache bersama yang benar pada deployment, sama dengan fitur akun existing.

## Penerapan dan verifikasi

Tidak memerlukan migration tambahan. Deploy kode lalu build aset dan cek assets; pull saja tidak memperbarui public/build yang diabaikan Git. Backend/server perlu memuat kode terbaru seperti biasa; fitur katalog tidak membutuhkan restart worker upload. Pastikan tabel roles, users, user_assignments dan master_data_changes dari instalasi sebelumnya sudah tersedia. Agen tidak melakukan deploy atau menulis its aktif/.env/Dompis.

Bukti akhir dan batas pengujian tercatat pada [QA terstruktur](qa/role-catalog-20261118.json). Tes backend memakai SQLite memory fail-closed. Browser memakai React/settings/form asli dengan endpoint sintetis yang diintersep, tanpa login atau database aktif; pengujian ini dibedakan dari endpoint HTTP Laravel yang diuji dengan SQL memory. Tidak ada template Word/cetak yang berubah dan tidak dijalankan ulang render berkas. Server aaPanel/MySQL, akun nyata, concurrency multi-proses dan load test belum diverifikasi.

Verifikasi akhir: **335 tes PHP/8187 assertions**, **366 tes frontend**, TypeScript/Vite, assets42manifest/33preload×3profil, scoped Pint dan diffcheck lulus. Regresi katalog8tes/126assertions termasuk query mutasi konstan; enam skenario browser sintetis lulus. Tanpa penerapan migration/live SQL/server.

## 18 November 2026 — Operation

Alur Operation kini dihubungkan secara eksplisit untuk role bawaan Officer 1 SS/Manager SS dan role Operation lainnya. Paket memakai lima approver Operation, kasbon tujuh dengan Settlement/Finance, GM menggantikan Head dalam template dan identitas/jabatan aktual. Scope unit per PP dan Branch/Region akun berlaku; data serta rantai historis tetap. Katalog role tambahan lain belum memberi otoritas otomatis. Detail migration, kompatibilitas dan hasil QA ada di [OPERATION_WORKFLOW](OPERATION_WORKFLOW.md).

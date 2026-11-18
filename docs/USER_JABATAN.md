# Jabatan akun dan identitas penandatangan

## 18 November 2026

Pengguna meminta kolom Jabatan pada users agar penandatangan dokumen, khususnya VIII. PENUTUP PP, menampilkan jabatan resmi akun. Perubahan ini tidak mengeksekusi konsep flow Operation, menambah role/Unit Kerja, atau mengubah kewenangan approval.

## Data akun

Migration `2026_11_18_000001_add_jabatan_to_users.php` menambah `users.jabatan VARCHAR(250) NULL`, tanpa backfill atau perubahan transaksi. Migration menggunakan Area3MigrationTarget: hanya its MySQL/MariaDB atau SQLite memory saat testing. Rollback menolak jika ada jabatan terisi. users.id_user/password/role/placement/assignments tetap.

Superadmin mengisi Jabatan pada tambah/ubah akun; daftar akun memiliki kolom Jabatan. Isian opsional agar akun lama tetap dapat diedit: kosong/null memakai jabatan bawaan template. Maksimal250karakter, satu baris, bukan markup/control characters. Pengosongan eksplisit menyimpan null; request lama yang tidak mengirim field tidak menghapus jabatan. Tidak ada pengubahan jabatan dari Profil pengguna biasa. Jabatan adalah label penandatangan dan tidak dipakai untuk otorisasi; teks seperti General Manager tidak memberikan hak GM.

## Snapshot dokumen

AuthenticatedWorkspace memuat jabatan bersama query users yang sudah ada. Laravel mengabaikan identitas/jabatan client dan mengambil akun terpilih yang telah lolos validasi role/Branch. PP editable explicit save/submit menyimpan `preparedBy.job` dan `signatureUsers[role].job`; keputusan baru menyimpan `actorJob` aktual. PP lanjutan mencatat jabatan pembuatnya sendiri. Penutup menampilkan jabatan akun saat draf, snapshot saat submitted/Sah, dan fallback template untuk riwayat tanpa jabatan; snapshot harus cocok dengan userId penandatangan. Empat posisi dan EVP tidak berubah.

BA KH baru/draf menangkap `signerJob` dari identitas Head terpilih: snapshot PP, keputusan cocok, lalu akun yang sama. SPK menangkap jabatan itu ke `position` dan `signerJob`. Terbit/Sah mempertahankan snapshot awal, termasuk tidak menambah jabatan pada dokumen historis yang belum memilikinya. BARP menangkap `headJob` dan `deployment.job`/`support.job`; backend menolak penggantian melalui field client. BA PP dan lampirannya memakai identitas BARP tersimpan. Manual Waspang/Mandor tidak dicocokkan ke akun berdasarkan nama; jabatan fisik mereka tetap sesuai template. EVP tetap baris tetap yang telah diotorisasi pengguna.

Kasbon baru menyimpan jabatan pengaju pada `issuerJob`, serta jabatan enam akun terpilih pada `signers[role].job`, dalam snapshot privat terenkripsi existing. Kasbon yang sudah diterbitkan/arsip/lunas tidak mengikuti edit akun. Pilihan eksplisit penandatangan fisik kasbon lama dan routing Manager lama juga menangkap jabatan aktual ke annotation/version yang sudah diaudit. Tidak menulis ulang ciphertext lama, uang, penerima/rekening, keputusan, transfer/APM, status atau histori. Riwayat lama tanpa jabatan tetap memakai label bawaan. HTML/Word/print menggunakan model yang sama; SPK mempertahankan gaya uppercase. Mark approval hanya preview, Word/print tetap blank wetTTD.

## Query dan optimasi

Jabatan ditambahkan sebagai kolom pada query akun/batch enam approver yang sudah ada; tidak ada query baru per dokumen/baris BOQ atau per penandatangan. Resolver Bakh/Spk/Barp memakai data authorized yang telah dimuat dan regresi murninya0SQL. Daftar akun mengganti context-per-user dengan satu batch penugasan seluruh akun yang dimuat; fixture30akun tambahan membuktikan2assignmentSELECT total (1auth+1list), bukan N+1. Daftar akun masih memuat seluruh akun, dan command masih memuat aggregate sesuai arsitektur existing; ini bukan benchmark throughput MySQL.

## Penerapan

Migration belum dijalankan agen pada its aktif atau server. Backup database/file sebelum deployment. Setelah pull, operator dapat menjalankan hanya migration ini dari folder berisi artisan:

```sh
php artisan migrate --path=database/migrations/2026_11_18_000001_add_jabatan_to_users.php
npm run build
php artisan optimize:clear
```

Tinjau migration pending lain secara terpisah; perintah path di atas tidak menjalankan konversi uang atau flow lain. Reload worker proyek setelah pembaruan service sesuai UPLOAD_QUEUE_PERFORMANCE; jangan merestart worker aplikasi lain. Isi jabatan melalui Kelola Akun sebelum menyimpan/mengajukan dokumen baru. Penggantian jabatan akun tidak mengoreksi dokumen terkunci otomatis; koreksi historis memerlukan permintaan terpisah.

## Verifikasi dan batas

327PHP/8061assertions pada SQLite memory dan359frontend lulus; TypeScript/Vite,assets42manifest/33preloads pada3profil,Pint scoped dan diffcheck lulus. Cakupan: CRUD/izin/validasi/role-password-Branch unchanged, migration up/down/rollback refusal, canonical PP HTTP/actual decision/readback/no GET write, kasbon encrypted snapshot/client forgery/later account edit, selected identities/no arbitrary fallback, locked/legacy parity serta seluruh generator Word.

10DOCX sintetis (PP; BA KH/lampiran; SPK/lampiran; BARP/lampiran; BA PP/lampiran; kasbon) lulus ZIP/CRC/XML independen dan render13halaman memakai bundled LibreOffice. Semua PNG halaman diperiksa; jabatan panjang membungkus dalam ruang yang tersedia dan TTD kosong. Fixture Chrome modal PP1440px: closing4akun+EVP,0JSerror/0API, nativeWord127570byte dan PDF4halaman nonblank, tanpa cap pada cetak. Harness awal salah menempatkan preview langsung pada kolom sidebar; diperbaiki ke struktur modal sebelum verifikasi final, bukan perubahan CSS produksi. Text extraction PDF dinormalisasi whitespace untuk pemeriksaan jabatan yang membungkus baris. Browser memakai Chrome terpasang setelah bundled Playwright browser tidak tersedia; izin sandbox diberikan. Bukan pengujian akun/server/aaPanel/MySQL DDL/concurrent load/Microsoft Word desktop/cetak fisik. Backend tidak dihubungkan browser fixture. Backup SQLite demo privat233472byte dibuat sebelum edit; tidak menulis its aktif/.env/Dompis, deploy/migrate/restartworker, atau membuat akun/approval live. Lihat bukti qa/user-jabatan-20261118.json.


## Koreksi 18 November 2026 — PP Sah lama belum memiliki snapshot jabatan

Pengguna mengonfirmasi PP sudah Sah dan jabatan akun baru diisi. Aturan ini menggantikan fallback template-only untuk PP tanpa jabatan tersimpan pada bagian di atas, tanpa menulis ulang data. Resolver PP kini memakai snapshot.job → actual decision.actorJob → jabatan akun aktif dengan userId/role/Branch cocok → label template untuk PP Diajukan/Sah. Empty/whitespace dianggap belum diisi. Snapshot jabatan nonblank tetap dipertahankan. Draf tetap mengutamakan akun yang sama, lalu snapshot/decision/template. Nama/NIK/dates/approvals/status dan EVP tidak mengikuti fallback jabatan; tidak mencari akun pertama berdasarkan role atau nama. Berkas BA KH/SPK/BARP/BA PP/kasbon tetap mengikuti snapshot masing-masing. Preview PP, Word berbasis DOM dan cetak memakai nilai yang sama; tidak perlu menyimpan ulang PP Sah atau mengulang approval. Buka ulang halaman sekali setelah build untuk membaca metadata users.jabatan terbaru. Tidak ada migration/API/SQL/runtime dependency/backfill atau restartworker tambahan. Regresi360FE/327PHP dan build/assets lulus; browser/nativeWord/PDF render baru tidak diulang, existing export suites lulus. Lihat qa/pp-legacy-jabatan-20261118.json.

## 18 November 2026 — Operation

Alur Operation kini dihubungkan secara eksplisit untuk role bawaan Officer 1 SS/Manager SS dan role Operation lainnya. Paket memakai lima approver Operation, kasbon tujuh dengan Settlement/Finance, GM menggantikan Head dalam template dan identitas/jabatan aktual. Scope unit per PP dan Branch/Region akun berlaku; data serta rantai historis tetap. Katalog role tambahan lain belum memberi otoritas otomatis. Detail migration, kompatibilitas dan hasil QA ada di [OPERATION_WORKFLOW](OPERATION_WORKFLOW.md).

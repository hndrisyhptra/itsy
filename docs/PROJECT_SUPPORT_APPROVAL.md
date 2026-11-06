# Urutan approval Project Support — 6 November 2026

PP dan Pembayaran memakai urutan lima akun terpilih berikut:

1. PM Konstruksi
2. Admin Area
3. **Mgr Project Support**
4. **Mgr Deployment**
5. Head of PMO

Urutan ini berlaku untuk dokumen baru dan pengajuan berjalan, sesuai permintaan pengguna. Finance tetap memeriksa scan TTD fisik setelah lima approval Pembayaran, sebelum APM melunasi seluruh tagihan. Tidak ada akun, password atau tanda tangan yang dibuat.

## Pengajuan berjalan dan keputusan terdahulu

`nextApprovalRole` di PHP dan React mencari peran pertama dalam urutan di atas yang belum mempunyai keputusan Setujui pada siklus aktif. Sistem tidak lagi menentukan giliran hanya dari jumlah persetujuan. Jika PM, Admin Area dan Deployment sudah menyetujui sebelum perubahan, giliran berikutnya adalah Project Support, kemudian Head of PMO. Deployment tidak menyetujui dua kali. Array riwayat tetap menyimpan urutan waktu kejadian yang sebenarnya.

Pengguna juga secara eksplisit memilih akun Project Support pada PP terbaru untuk pengajuan berjalan yang masih menugaskan Business Support. Pada its, **1 PP dan 1 Pembayaran** disesuaikan melalui command tervalidasi `adoptApprovalOrder`, dengan identitas Admin pembuat PP, pemeriksaan akun aktif/role/Branch, kunci workspace, revisi, audit dan receipt. Dua PP berjalan kini menunggu Project Support; pembayaran berjalan menunggu Admin Area lalu Project Support. Tidak ada keputusan approval yang dijalankan oleh proses pemutakhiran.

Command tersebut tidak menerima penggantian state/path atau keputusan dari client. Penyesuaian Business Support otomatis ditolak bila sudah ada keputusan Business Support atau siklus penolakan/pengembalian sebelumnya, agar tidak mengalihkan persetujuan orang lain atau menulis ulang penugasan historis. Kondisi tersebut tidak terdapat pada dua pengajuan yang diperbarui. Dokumen Sah dan keputusan lama tetap utuh; Business Support tetap tersedia untuk sejarahnya. Untuk pembayaran baru dari PP Business Support lama, tetapkan lima approver pembayaran berikutnya secara eksplisit seperti sebelumnya.

Draft PP tanpa riwayat, PP baru, PP lanjutan baru dan Pembayaran baru diberi `approvalScheme=project-support-first` oleh server. Nilai client tidak dapat memaksa skema lama. PP yang sudah mempunyai riwayat mempertahankan marker penyimpanan lamanya saat diperbaiki/diajukan ulang; routing Project Support tetap memakai urutan terbaru. Penolakan tetap memerlukan note dan pengajuan ulang Admin dari PM.

## Penyimpanan SQL dan kompatibilitas

`workflowVersion=3` tetap berlaku. Marker `approvalScheme` membedakan Business Support, Project Support sebelumnya, dan `project-support-first` untuk run baru. Migration **2026_11_06_000003_version_support_first_approval_order** menambahkan `approval_steps.approval_order_version` dengan default 1 dan memperbarui CHECK dalam satu ALTER:

- Versi urutan 1 mempertahankan identitas/posisi langkah SQL run yang sudah ada: Deployment pada langkah 3, Support pada langkah 4. Run Business Support historis dan alur versi 2 tetap sah. Posisi ini adalah rekaman run asli, bukan sumber perhitungan giliran inbox terbaru.
- Versi urutan 2 untuk run baru membatasi Project Support di langkah 3 dan Deployment di langkah 4. PP/Pembayaran baru menulis versi ini. Peran/tahap lain yang salah tetap ditolak oleh database.
- `storedApprovalRoles` memilih posisi penyimpanan asli agar ID step/decision tidak berpindah saat pengajuan berjalan memakai urutan routing baru. `approvalRoles`/`nextApprovalRole` menjadi sumber giliran aktual di validasi server, inbox, timeline dan laporan. SQL keputusan tetap terkait role/akun yang benar.
- Rollback migration ditolak setelah versi urutan 2 digunakan. Migration sebelumnya **000002** tetap menjadi bagian instalasi dan tidak diedit ulang; instalasi lain menjalankan `php artisan migrate`.

Kedua migration sudah diterapkan pada its. Tidak diperlukan seed atau aktivasi ulang arsip. Cadangan sebelum perubahan urutan: `storage/app/private/backups/its-before-approval-order-20261106-141354.sql`, 2.020.568 byte, SHA-256 `c3feadbc839a0f4ed7c7ad266d6d7cb424afbc76bc04fdec1d0f32b7adf578f1`. Bukti migration, fingerprint, probe CHECK dan pemutakhiran pengajuan: `qa/approval-order-20261106.json`.

## Tabel VIII. PENUTUP

Empat baris tetap mengikuti template bersama; perubahan giliran approval tidak menambah Admin Area ke tabel cetak:

| Urutan | Peran akun | Keterangan | Jabatan |
|---|---|---|---|
| 1 | PM Konstruksi | Dibuat Oleh | Officer 1 Project Deployment Area Jawa Bali |
| 2 | Mgr Project Support | Diperiksa Oleh | Mgr Project Support Area Jawa Bali |
| 3 | Mgr Deployment | Diperiksa Oleh | Mgr Deployment Area Jawa Bali |
| 4 | Head of PMO | Disetujui Oleh | Head of PMO Area Jawa Bali |

Nama/NIK berasal dari akun terpilih/snapshot; tanggal/cap hanya berasal dari keputusan siklus aktif. Saat mencetak, cap/status disembunyikan dan area TTD basah tetap kosong. PP historis Business Support yang sudah Sah mempertahankan isi penutupnya.

## Query dan pengujian

Pemilihan giliran membaca paling banyak lima role/keputusan yang sudah dimuat, tanpa query SQL atau permintaan baru. Penyesuaian akun memakai referensi akun/Branch yang sudah dibaca oleh layanan command dan penulisan dokumen yang berubah saja. Tidak ada query per akun tambahan di routing; fixture performa metadata 20 PP tetap 1.330 query full versus 8 incremental, bukan klaim uji beban.

Regression mencakup urutan baru PP/Pembayaran, penolakan Deployment sebelum Support, pengajuan lama dengan Deployment sudah selesai, penyesuaian Business Support yang diizinkan, penolakan akun/peran salah, perlindungan keputusan terdahulu, role/posisi SQL dua versi dan rollback. MariaDB memakai CHECK yang benar-benar terpasang pada temporary table: 15 kombinasi sah diterima, 6 salah ditolak tanpa data uji di tabel bisnis. Semua artisan tests tetap SQLite :memory:. Pemeriksaan klik browser/cetak fisik belum dilakukan; TTD dan pencatatan APM tetap proses manual, tanpa integrasi bank.

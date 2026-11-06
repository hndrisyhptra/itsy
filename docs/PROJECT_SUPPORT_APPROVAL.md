# Approver Project Support dan penutup PP — 6 November 2026

Untuk PP baru serta pembayaran baru, urutan lima akun terpilih menjadi:

1. PM Konstruksi
2. Admin Area
3. Mgr Deployment
4. **Mgr Project Support**
5. Head of PMO

Persetujuan harus dilakukan oleh akun aktif yang dipilih pada Branch dokumen. Role Mgr Project Support sudah tersedia; tidak ada role/akun atau password baru yang dibuat. Mgr Bussiness Support tetap tersedia untuk dokumen historis. Finance tetap menyetujui scan fisik terbaru setelah lima approval pembayaran, sebelum APM melunasi tagihan.

## Tabel VIII. PENUTUP

Form dan preview PP dengan aturan baru menampilkan empat baris baku:

| Urutan | Peran akun | Keterangan | Jabatan |
|---|---|---|---|
| 1 | PM Konstruksi | Dibuat Oleh | Officer 1 Project Deployment Area Jawa Bali |
| 2 | Mgr Project Support | Diperiksa Oleh | Mgr Project Support Area Jawa Bali |
| 3 | Mgr Deployment | Diperiksa Oleh | Mgr Deployment Area Jawa Bali |
| 4 | Head of PMO | Disetujui Oleh | Head of PMO Area Jawa Bali |

Admin Area tetap wajib sebagai approval kedua meskipun tidak ditampilkan pada tabel penutup. Nama/NIK berasal dari akun terpilih/snapshot, tanggal/cap berasal dari keputusan pada siklus aktif. Empat baris ini tetap tampil meskipun konfigurasi penandatangan lama di draft berisi baris berbeda. Urutan/jabatan format baru tidak diedit pada form. Tidak ada tanda tangan rekaan. CSS cetak tetap menyembunyikan cap/status dan meninggalkan area TTD basah kosong.

Definisi empat baris berasal dari `resources/data/project-plan-template.json`, dibaca PHP dan React yang sama. Konfigurasi baris lama dalam snapshot tidak dihapus massal. Preview dokumen historis yang memakai Business Support tetap mengikuti baris/snapshot lamanya.

## Kompatibilitas dokumen

`workflowVersion=3` tetap berlaku untuk aturan finansial/lima approval. Field JSON server-owned **approvalScheme** membedakan `project-support` dan `business-support`. Constraint database tahap approval juga harus menerima kedua peran pada tahap keempat versi 3.

- PP baru dan draft yang belum mempunyai riwayat keputusan memakai `project-support`. Field/skema client tidak dapat memaksa kembali ke Business Support. Pilih akun Project Support yang benar; akun Business Support tidak otomatis dialihkan.
- Dokumen v3 lama tanpa marker dikenali dari assignment Business Support yang tersimpan. PP yang sudah diajukan/Sah tetap menggunakan assignment dan keputusan lama. Jika PP lama telah ditolak/dikembalikan, edit/pengajuan ulang mempertahankan chain lamanya agar siklus audit tidak ditulis ulang.
- Pembayaran yang sudah ada mempertahankan chain saat dibuat, termasuk draft historis. Pembayaran baru memakai Project Support. Untuk PP historis v1/v2 atau v3 Business Support, Admin harus menetapkan lima approver pembayaran berikutnya secara eksplisit pada detail PP yang Sah, sebelum membuat pembayaran baru. Perubahan ini tidak mengganti assignment/keputusan PP atau pembayaran lama.
- RelationalWorkflowStore menyimpan expected role/akun berdasarkan chain dokumen masing-masing. Laporan/inbox memakai pemilihan chain yang sama, sehingga tugas Business Support lama tetap dapat dilanjutkan dan tugas Project Support baru muncul pada akun yang tepat.
- Lima approval, penolakan dengan note/resubmission dari PM, validasi volume/harga, eviden, scan/Finance/APM dan dokumen v1/v2 tetap mengikuti aturan sebelumnya.

## Query, penerapan dan verifikasi

Pemilihan chain memakai metadata/assignment yang sudah dimuat, tanpa query role baru per baris/penandatangan. Penutup memakai template lokal dan akun/snapshot yang sudah tersedia; tidak meminta identitas tambahan lewat jaringan. Jumlah tahap SQL tetap lima. Uji query/performance yang ada tetap dijalankan; tidak ada klaim uji beban serentak.

Migration **2026_11_06_000002_allow_project_support_approval_steps** diperlukan dan sudah diterapkan pada its. Perubahan aplikasi sebelumnya belum menyesuaikan CHECK `two_approval_steps` di MariaDB, sehingga pengajuan gagal ketika menyimpan tahap keempat. Migration mengganti CHECK dalam satu ALTER, menerima Business Support historis maupun Project Support pada tahap 4 versi 3, dan tetap membatasi tahap/peran versi 2 serta tahap lainnya. Rollback ditolak jika Project Support sudah dipakai. Tidak perlu seed/aktivasi ulang atau pengajuan otomatis atas nama pengguna.

Backup sebelum perbaikan constraint: `storage/app/private/backups/its-before-approval-check-20261106-132833.sql`, 2.015.272 byte, SHA-256 `99e7a7dc04bbdd8376edb3e79eabeb56b7312f5caa3cafe59486839e51c97024`. Hash dan jumlah akun, project/PP, approval runs/steps/decisions, workspace, audit dan receipt tidak berubah sesudah migration. Probe temporary table memakai CHECK yang benar-benar terpasang di MariaDB: 9 kombinasi valid diterima dan 6 kombinasi salah ditolak, tanpa menambahkan data uji ke tabel bisnis. Bukti: `qa/approval-check-20261106.json`. Semua artisan tests tetap memakai SQLite :memory:; pengujian SQLite sebelumnya tidak menjalankan CHECK khusus MySQL sehingga ketidaksesuaian ini terlewat.

Backup pengembangan awal tetap tersedia: `storage/app/private/backups/its-before-project-support-20261106-104911.sql`. Worker upload sebelumnya diberi sinyal restart melalui file cache proyek agar proses berikutnya memuat pemilihan chain yang sama; perbaikan constraint tidak mengubah kelas worker.

HTTP/SQL memory tests memeriksa chain baru, penolakan role lama/skema client palsu, role/akun SQL tahap keempat, keputusan historis tetap utuh, serta konfigurasi pembayaran baru pada PP lama. Frontend memeriksa label Approval 4, tugas lama, assignment pembayaran baru, empat jabatan/urutan persis, cap 4/5 tanpa Admin Area, dan sel cetak kosong. Suite end-to-end lima approval/Finance/APM lama tetap dijalankan dengan role baru. Browser/print fisik tidak diuji manual; semua transaksi QA memakai SQLite :memory:.

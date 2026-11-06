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

`workflowVersion=3` tetap berlaku untuk aturan finansial/lima approval. Field JSON server-owned **approvalScheme** membedakan `project-support` dan `business-support`, tanpa perubahan tabel/schema.

- PP baru dan draft yang belum mempunyai riwayat keputusan memakai `project-support`. Field/skema client tidak dapat memaksa kembali ke Business Support. Pilih akun Project Support yang benar; akun Business Support tidak otomatis dialihkan.
- Dokumen v3 lama tanpa marker dikenali dari assignment Business Support yang tersimpan. PP yang sudah diajukan/Sah tetap menggunakan assignment dan keputusan lama. Jika PP lama telah ditolak/dikembalikan, edit/pengajuan ulang mempertahankan chain lamanya agar siklus audit tidak ditulis ulang.
- Pembayaran yang sudah ada mempertahankan chain saat dibuat, termasuk draft historis. Pembayaran baru memakai Project Support. Untuk PP historis v1/v2 atau v3 Business Support, Admin harus menetapkan lima approver pembayaran berikutnya secara eksplisit pada detail PP yang Sah, sebelum membuat pembayaran baru. Perubahan ini tidak mengganti assignment/keputusan PP atau pembayaran lama.
- RelationalWorkflowStore menyimpan expected role/akun berdasarkan chain dokumen masing-masing. Laporan/inbox memakai pemilihan chain yang sama, sehingga tugas Business Support lama tetap dapat dilanjutkan dan tugas Project Support baru muncul pada akun yang tepat.
- Lima approval, penolakan dengan note/resubmission dari PM, validasi volume/harga, eviden, scan/Finance/APM dan dokumen v1/v2 tetap mengikuti aturan sebelumnya.

## Query, penerapan dan verifikasi

Pemilihan chain memakai metadata/assignment yang sudah dimuat, tanpa query role baru per baris/penandatangan. Penutup memakai template lokal dan akun/snapshot yang sudah tersedia; tidak meminta identitas tambahan lewat jaringan. Jumlah tahap SQL tetap lima. Uji query/performance yang ada tetap dijalankan; tidak ada klaim uji beban serentak.

Tidak perlu migration/seed/aktivasi ulang. Backup its diverifikasi sebelum perubahan: `storage/app/private/backups/its-before-project-support-20261106-104911.sql`, 1.996.112 byte, SHA-256 `6ee4902c4a15175e6c0fa3433438d816948df58fb59020cf9f1f44a3b7d2217b`. Tidak ada transaksi atau assignment live yang diubah melalui perintah uji. Worker upload yang berjalan lama diberi sinyal restart melalui file cache proyek agar proses berikutnya memuat pemilihan chain yang sama; upload baru/Lanjutkan antrean menjalankan worker sebagai pengguna OS web seperti sebelumnya.

HTTP/SQL memory tests memeriksa chain baru, penolakan role lama/skema client palsu, role/akun SQL tahap keempat, keputusan historis tetap utuh, serta konfigurasi pembayaran baru pada PP lama. Frontend memeriksa label Approval 4, tugas lama, assignment pembayaran baru, empat jabatan/urutan persis, cap 4/5 tanpa Admin Area, dan sel cetak kosong. Suite end-to-end lima approval/Finance/APM lama tetap dijalankan dengan role baru. Browser/print fisik tidak diuji manual; semua transaksi QA memakai SQLite :memory:.

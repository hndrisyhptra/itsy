# Draft PP, cap preview dan penghapusan batas BOP — 5 November 2026

## Pembaruan terkini — 9 November 2026

PP baru dimulai **1. Header PP → 2. BOQ Plan**. Pada PP induk v3 yang masih editable, harga langsung mengikuti KHS SQL tanpa alasan/konfirmasi selisih; Header Total Nilai baca saja dan dihitung dari BOQ, Rp0 sebelum upload. Header lengkap cukup untuk draf; pengajuan tetap perlu BOQ/nilai positif dan seluruh persyaratan. Dokumen terkunci/v1/v2 serta alokasi lanjutan tetap dipertahankan. Migration CHECK draf Rp0 telah disiapkan, belum diterapkan agen pada its aktif. [Perilaku, migration, arsitektur/query dan bukti](PP_HEADER_BOQ_KHS.md). Penjelasan lama di bawah tetap menjadi catatan historis bila berbeda.


Pembaruan 6 November: approver keempat PP/Pembayaran baru menjadi **Mgr Project Support**; penutup PP baru memakai empat jabatan baku. Assignment/keputusan Business Support historis tetap berlaku. Aturan terkini: [PROJECT_SUPPORT_APPROVAL](PROJECT_SUPPORT_APPROVAL.md). Penjelasan sebelumnya di bawah bersifat historis bila berbeda.

## Simpan Draft dengan Header lengkap

PP baru mempunyai form bersih setiap kali dibuka. Isilah ID Project, Branch aktif sesuai akses akun, Unit Kerja, Nama Kegiatan, Total Nilai positif dan Saat Penggunaan yang valid. No PP diberikan server saat simpan pertama. Pilihan **Isi Header PP** tersedia dari langkah BOQ kosong; Header dapat dibuka tanpa BOQ.

**Simpan Draft** tidak lagi memerlukan BOQ, risiko, jadwal lengkap atau kelima approver. Server menyusun lima tahap jadwal dengan HK nol untuk bagian yang belum diisi; HK yang sudah valid tetap dipertahankan. Input negatif, presisi berlebih atau nilai BOQ/anggaran yang tidak sah tetap ditolak. Branch tetap mengikuti otorisasi akun. BOQ kosong berstatus Belum diimpor; PP tetap Draft dan belum mempunyai keputusan approval.

Tombol simpan menunggu konfirmasi Laravel sebelum membersihkan cache dan kembali ke daftar Project Plan. Database menyimpan PP, nomor, status dan snapshot; list menampilkan status Draft dan dapat dibuka kembali untuk dilengkapi. Simpan ulang memakai ID/No PP yang sama. **Ajukan PP** tetap memerlukan BOQ positif dan tervalidasi, jadwal/HK dengan total positif, anggaran/risk level serta lima approver. Draft tidak membuka BA KH/SPK atau pembayaran.

Form baru tidak membaca/menulis key browser bersama `pp-v2-new`, sehingga PP sebelumnya tidak terbawa ke tab lain. Isian baru berada pada form aktif sampai Simpan Draft; simpan ke database untuk melanjutkan dari daftar. Cache browser untuk edit PP tersimpan tetap dipisahkan per akun dan ID PP. Kesalahan simpan PP tidak mengirim navigasi global keluar editor; isian tetap terbuka dan cache edit tidak dihapus. Penyimpanan sukses baru menghapus cache.

## Cap APPROVED hanya pada preview

Pada tabel penandatangan bagian VIII, baris approver menampilkan cap teks APPROVED bila ada keputusan Setujui pada `approvals` aktif untuk role dan ID pengguna yang dipilih. Nama/NIK/tanggal berasal dari akun/snapshot dan keputusan yang tersimpan. Baris penyusun tidak otomatis dianggap sebagai approval. Keputusan historis di `approvalHistory`, akun berbeda atau status Draft/Dikembalikan/Ditolak tidak membentuk cap aktif. Setelah pengajuan ulang, cap mengikuti keputusan siklus baru.

Cap adalah indikator persetujuan aplikasi yang diturunkan saat render, bukan tanda tangan elektronik atau salinan cap perusahaan dari contoh Word. Tidak ada image/keputusan approval rekaan yang disimpan. Ketentuan lima persetujuan dan perubahan baris cetak tetap sama.

Untuk **Cetak / Simpan PDF browser**, CSS media print menyembunyikan seluruh cap dan status di dalam sel TTD. Container cetak kosong menyediakan ruang tanda tangan basah tanpa teks placeholder. Nama, jabatan dan tanggal yang benar tetap tercetak. Tidak mengubah dokumen asli maupun alur scan/Finance/APM.

## BOP tanpa batas/referensi Cost Structure

Form Batas maksimal BOP (Rp), Referensi Cost Structure dan teks plafon dihapus dari Anggaran & Margin dan preview. Laravel/React tidak lagi memeriksa plafon atau mewajibkan referensi saat pengajuan. Nilai field lama dari browser diabaikan dan tidak masuk snapshot draft baru; snapshot dokumen terkunci tetap dipertahankan saat dibaca.

Rincian BOP tetap memuat uraian, satuan, jumlah dan total. Angka nonnegatif, presisi, kelengkapan pada pengajuan, perhitungan total/COGS dan batas teknis total anggaran tetap berlaku. Harga/volume BOQ Mandor per komponen, ledger, approval dan reimbursement perizinan tidak berubah.

## Penyimpanan dan bukti uji

Tidak ada migration atau penulisan ulang data existing. Runtime tetap MySQL its melalui tabel bisnis dan snapshot dokumen. Header-only draft diuji pada HTTP, `project_plans`, jadwal dan tidak adanya BOQ/budget rows yang diada-adakan; draft tidak lengkap tidak mengonsumsi nomor/revisi/audit. Pengajuan kosong tetap gagal atomik. Skenario historis v1/v2 tetap memakai aturan lamanya.

- **140 tes PHP / 2.553 assertions**, database SQLite `:memory:` dengan bootstrap fail-closed.
- **110 tes frontend**, termasuk draft Header, validasi sumber, form baru tanpa cache lama, stamp per tahap/siklus/aktor, CSS cetak kosong dan konfirmasi/retry penyimpanan.
- TypeScript/Vite build berhasil. Pint lolos pada enam file PHP yang diubah.
- Tidak memasukkan transaksi atau akun uji ke MySQL aktif. Pemeriksaan engine MySQL terpisah, browser autentikasi, layout visual dan hasil PDF cetak belum diulang karena akses pratinjau sebelumnya ditolak. Pemeriksaan CSS/markup bukan pengujian visual hasil cetak.

Cadangan sebelum perubahan: `storage/app/private/backups/its-before-pp-drafts-preview-20261105-230244.sql`, 1.047.622 byte, SHA-256 `b2d77823569c92f2c3b5bd1a123efb45c3efe96936c27e6570d6e83082ef87c6`. Percobaan sandbox gagal diberi akhiran `.failed`. Bukti: `docs/qa/pp-drafts-preview-20261105.json`.

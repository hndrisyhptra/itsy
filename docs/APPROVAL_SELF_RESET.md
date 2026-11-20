# Pembatalan persetujuan oleh approver — 20 November 2026

## Perilaku pengguna

Kartu keputusan terpisah dari review berkas: **Approve Paket PP, BA KH, SPK** dan **Approve Pembayaran**. Tombol Tolak tidak ditampilkan lagi. **Kembalikan untuk Revisi** memerlukan catatan dan mengembalikan dokumen kepada Admin; pengajuan ulang mengikuti aturan siklus alur tersimpan, termasuk mulai lagi dari approver pertama untuk paket/pembayaran tujuh tahap. Data dan keputusan Tolak historis serta kompatibilitas command backend tetap tersedia.

**Batalkan Persetujuan Saya** hanya tersedia untuk pemilik keputusan Setujui aktif yang masih sesuai penugasan dan versi pengajuan. Contoh: Project Support membatalkan persetujuannya; PM dan Admin Area tetap berlaku, sedangkan Project Support, Deployment dan Head harus menyetujui lagi. Kasbon tujuh tahap juga mengulang Manager Finance/Finance bila keduanya berada setelah tahap yang dibatalkan. Pembatalan memerlukan alasan dan YA/TIDAK; pengguna tidak membatalkan atas nama akun lain, termasuk Superadmin.

Inbox PP menyediakan Menunggu Saya dan Sudah Saya Setujui. Pembayaran dapat dibuka melalui Sudah Diproses atau Detail Pembayaran. Target UUID tetap dipertahankan setelah keputusan keluar dari daftar menunggu. Riwayat pembatalan muncul pada detail/timeline, maksimum 20 baris per halaman. Catatan tetap berada pada kartu ketika server menolak perubahan selama dokumen masih termasuk akses akun; tidak mengulang permintaan secara otomatis.

## Batas yang disengaja

- PP yang sudah mempunyai pengajuan pekerjaan/pembayaran atau PP lanjutan tidak dapat dibatalkan mandiri. PP historis dengan BA KH/SPK terpisah juga ditahan. Koreksi Head historis khusus tetap memakai mekanisme koreksinya.
- Pada paket Sah tanpa proses lanjutan, BA KH/SPK kembali Draft dan PP Diajukan, hanya apabila semua isi/status/identitas persis sesuai manifest pengajuan. Dokumen tetap terkunci oleh status PP Diajukan; ini tidak membuka form Admin untuk mengubah paket yang sedang di-approve. Persetujuan Head selanjutnya kembali mengesahkan paket yang sama.
- Pembayaran dengan realisasi, APM, bukti transfer (termasuk metadata file privat yang belum terpasang pada snapshot), pembayaran berikutnya, pelepasan volume, atau pengajuan yang sudah dikembalikan Finance ditahan. Antrean unggah terkait harus selesai terlebih dahulu.
- Kasbon/Finance historis dapat membatalkan keputusan keuangan miliknya pada UUID aktif: supplemental Manager review, Finance kasbon, verifikasi pengajuan parsial, atau verifikasi scan. Lima keputusan pekerjaan lama tetap utuh. Ini bukan adopsi otomatis ke tujuh tahap, bukan pembatalan pembayaran bank dan bukan penghapusan dokumen.
- Konstruksi dan Operation memakai peran/Branch/unit/Region asli. Versi historis tanpa identitas aktor tidak memperoleh otorisasi baru. Nominal, PPh, volume aktual, data rekening, kasbon terenkripsi dan snapshot historis tidak dihitung ulang.

## Penyimpanan dan otorisasi

`ApprovalReset` adalah planner murni; `ApprovalResetWorkflow` mengeksekusi `resetProjectApproval` / `resetPaymentApproval`. Keduanya memakai autentikasi aktif, konteks Branch/unit terbaru, transaksi/lock workspace/revision dan audit/receipt existing. Fingerprint mengikat waktu keputusan, panjang history, jumlah reset, manifest/kasbon/request/scan UUID dan history Finance/review/request. Request UUID replay hanya sah untuk aktor/peran/command/payload sama; UUID dengan isi lain ditolak.

`project_plans.document_data` dan `payment_terms.document_data` menyimpan `approvalResets`: aktor/peran/waktu/alasan, keputusan terdampak, prefix yang dipertahankan, sumber siklus, offset history, serta metadata keputusan keuangan sebelumnya. `approvalHistory`/`history` asli tidak dihapus. Metadata pembatalan hanya dibuat server; simpan form tidak boleh menghapus/memalsukannya. `approvalResetVersion` pada pembayaran adalah counter server untuk identitas reminder, bukan status pembayaran.

Untuk reset alur utama, proyeksi menambah `approval_runs`/`approval_steps` siklus baru. Prefix membawa keputusan asli dengan aktor dan timestamp yang sama; bukan keputusan baru pengguna. Semua baris keputusan/siklus lama tetap utuh. `sourceCycleNo`, `historyOffset` dan `carriedApprovals` memberikan asal keputusan yang dipertahankan. Reset review keuangan lama hanya mengubah metadata gate aktif, tanpa menciptakan tujuh approval palsu.

Preview kasbon aktif membaca keputusan aktif, bukan mengambil ulang cap yang sudah dibatalkan dari history. Annotation Manager historis yang ter-cache juga harus masih cocok dengan keputusan supplemental aktif. Cap maker/EVP mengikuti aturan sebelumnya; Word/print tetap TTD kosong. Tidak ada pembacaan file KTP/rekening/dekripsi kasbon baru untuk reset.

## Optimasi dan reminder

Planner PHP/TS hanya memakai data yang dimuat; resolver hubungan memakai indeks in-memory. UI memoisasi planner agar input catatan tidak memindai seluruh graph per karakter. Daftar dan riwayat ditampilkan 20 baris; tidak ada polling atau endpoint GET baru.

Command reset menambah probe berindeks untuk upload; pembayaran juga memeriksa settlement dan metadata transfer privat. Proyeksi melewati siklus approval lama yang identik dan tidak menulis ulang komponen kontrak yang sumber/barisnya identik. Fixture HTTP 6 baris BOQ: reset PP 80 query dan pembayaran 71, untuk Konstruksi maupun Operation; sebelum optimasi 118/94. Ini total query fixture termasuk autentikasi/revision/response, bukan tambahan query maupun benchmark produksi. Suite existing 20 PP tetap full-write353/incremental8; 500 BOQ/1000 komponen59query.

Reminder alur utama mengikuti SQL step/siklus baru. Untuk supplemental Finance lama, kunci target menyertakan counter reset hanya setelah reset eksplisit; pengajuan tanpa reset mempertahankan kunci lama. Collector/scheduler/worker existing digunakan, tidak mengirim pesan langsung dari tombol. Tidak menambah SQL schema, migration, dependency, timer, atau mengubah konfigurasi Telegram.

## Penerapan dan batas verifikasi

Pull kode dan gunakan hasil build terbaru, muat ulang browser. Tidak ada migration baru untuk fitur ini. Restart worker **area3-notifications** agar resolver reminder baru dimuat; restart hanya worker upload Area 3 sesuai prosedur pembaruan domain/upload proyek, tanpa me-restart worker aplikasi lain. Deployment sendiri tidak mengubah status pengajuan, membatalkan approval, atau memigrasi data lama.

Verifikasi dan angka akhir berada pada [LARAVEL_QA](LARAVEL_QA.md) dan [bukti QA](qa/approval-self-reset-20261120.json). Pengujian memakai SQLite memory/fake storage dan fixture browser sintetis; bukan akun/database aktif, server aaPanel, beban produksi atau pengiriman Telegram nyata. Tidak menjalankan migration/seed/reset data/worker/server, tidak menyentuh `.env`, APP_KEY, Dompis atau data its aktif, tidak commit/push.

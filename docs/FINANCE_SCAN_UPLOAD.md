# Unggah hasil scan oleh Finance — 25 November 2026

Permintaan terbaru memindahkan unggahan **scan dokumen bertanda tangan fisik pembayaran** dari Admin ke **Finance**. Berlaku pada Konstruksi dan Operation. Eviden pekerjaan, pembuatan BA RP/BA PP/kasbon dan identitas Mandor tetap mengikuti wewenang sebelumnya; fitur ini tidak memindahkan seluruh jenis unggahan ke Finance.

## Alur yang berlaku

1. Admin menyiapkan BA RP/BA PP dan kasbon melalui alur existing, lalu pengajuan mengikuti approver Unit Kerja.
2. Pada alur tujuh approval `kasbon-settlement-v2`, Finance dapat mengunggah scan **saat giliran Finance**: enam keputusan pertama benar-benar Setujui, berurutan, dari akun terpilih dan terikat UUID kasbon aktif. Tidak cukup hanya jumlah keputusan atau riwayat siklus lama.
3. Tombol **Unggah Scan & Tandai OK** memerlukan konfirmasi YA/TIDAK dan file PDF/JPG/PNG maksimum10MB. Setelah file dan metadata berhasil disimpan serta respons canonical diterima, **Status Berkas menjadi OK**. Sebelum itu tetap NOK; persentase100% pengiriman bukan bukti transaksi sudah tersimpan.
4. **OK berarti file tersedia**, bukan pembayaran disetujui atau lunas. Scan baru berstatus Menunggu Verifikasi. Finance dapat memilih Verifikasi Scan, atau menandainya Perlu Koreksi dengan catatan lalu mengunggah perbaikan. Versi file sebelumnya tetap tersimpan.
5. Pada alur tujuh approval, verifikasi scan hanya mengubah metadata verifikasi file; **tidak membuat keputusan Finance tahap7, financeApproval atau payment settlement**. Finance tetap menekan persetujuan pembayaran secara eksplisit. Jika scan sudah ada tetapi belum Valid, keputusan Setujui Finance tetap ditolak seperti sebelumnya.
6. Pembayaran tanpa scan tetap diperbolehkan sesuai alur kasbon/Parsial yang sudah berlaku. Finance dapat melengkapi scan setelah pembayaran. Pelengkapan ini tidak menulis ulang nilai, bukti transfer, nomor APM, kasbon, keputusan, `documentsAtPayment` historis atau paymentHistory.

Untuk pengajuan lama yang tidak memakai tujuh approval, unggahan/verifikasi tetap menunggu seluruh approval dokumen lengkap dan mengikuti batas APM existing. Yang berubah adalah pengunggahnya menjadi Finance. Verifikasi Finance legacy tetap mempertahankan mekanisme tersimpan; tidak dikonversi diam-diam ke tujuh approval. Batas scan Valid yang sudah terkunci, status returned/rejected, Head correction/Cancel, source/revision/receipts dan akses Branch/unit/Region tetap.

## Otoritas dan penyimpanan

Controller upload menolak akun selain Finance dari context SQL middleware sebelum membaca graph workspace. Domain memeriksa ulang role Finance di dalam command terkunci, scope dan revisi melalui service existing. Saat masih Diajukan, Finance juga harus memenuhi penugasan aktual alur existing. Admin Konstruksi, Admin Operation, Superadmin dan Mgr Finance & Settlement tidak mendapat hak unggah scan melalui UI maupun endpoint ini. Hak baca/unduh file dalam cakupan dokumen tetap seperti sebelumnya.

Endpoint tetap POST `/api/prototype/payments/{term}/scan`, GET privat existing untuk unduhan. MIME isi diperiksa, nama dibersihkan, SHA256/ukuran dicatat; file berada di disk local privat `prototype-scans/{workspace}/{term}/{uuid}.{extension}`. Metadata berada di JSON payment term dan tabel `document_files`/`term_scans`; `uploaded_by` menunjuk akun Finance yang benar-benar mengunggah. Kegagalan/revisi konflik membersihkan file baru; replay mempertahankan scan awal dan menghapus duplikat file percobaan. Tidak menambah flag OK yang dapat dikirim bebas oleh client, tabel/kolom/migration baru atau endpoint bypass.

Scan lama yang diunggah Admin **tetap sah sebagai data tersimpan**: tidak perlu diunggah ulang, nama/path/hash/aktor/waktu/status dan histori tidak ditulis ulang saat GET/pull. UI menyebut Pengelola Berkas: Finance, bukan mengganti identitas pengunggah historis.

## UI dan efisiensi

Kartu **Berkas Hasil Scan · Finance** tersedia pada Detail Pembayaran dan bagian Finance di Inbox existing. Pada giliran Finance untuk tujuh approval, kartu sudah tersedia sebelum keputusan ketujuh. Akun lain melihat status/file/unduhan tanpa form atau tombol unggah. Ringkasan Kasbon dan caption alur Parsial menjelaskan Finance sebagai pengelola scan. Caption dashboard legacy yang menunggu berkas kini menunjuk Finance, tanpa mengubah hitungan/bucket/approval.

`scanReviewReady` PHP/TS adalah helper data term yang sudah dimuat, maksimal tujuh role; prefix baru memeriksa role/urutan/aktor/UUID. Nilai readiness dipakai bersama kontrol upload dan verifikasi UI. Tidak menambah query perrow, katalog/file privat fetch, dependency, poller atau boot data. Fixture HTTP unggahan pada tahap Finance: **55 query pada fixture (termasuk satu pembacaan revisi oleh helper tes)** untuk Construction Parsial/Final dan Operation; ini jumlah query fixture, bukan benchmark latency server atau klaim penurunan universal. Jalur unggahan scan tetap sinkron existing, bukan worker/queue baru.

## Pengujian dan batas

- Full **535 tes PHP /16.744 assertions**, XAMPP PHP8.2.4, SQLite`:memory:`,126.255s/142.50MB/process512M; **496 frontend** lulus. Build TypeScript/Vite/aset50manifest41preload×3 dan scopedPint/diffcheck lulus.
- Uji HTTP nyata pada memory database: enam approval sumber yang benar, Finance unggah/OK katalog/SQL uploadedBy, nonFinance403, fileinvalid/oversize/revision/replay/cleanup, koreksi+versi, verifikasi tidak mengubah SQL decisions/kasbon/history/paid, tahap7 eksplisit, Construction Parsial/Final dan Operation, latepaid scan menjaga settlement lama, serta akses Finance Branch lain404.
- Tiga regresi frontend baru: current-six prefix keduaunit vs UUID/aktor/urutan/status/paid salah, verifikasi metadata terpisah, Finance-only upload/readonly Admin/immutable render. Fixture lama scan disesuaikan ke Finance tanpa melemahkan guard. Perubahan label Referensi Cost yang sudah dibuat pengguna dipertahankan; assertion teks lama disesuaikan ke Total Budget/Material/Non Material/Operasional, tanpa mengubah formula.
- Browser actualApp dengan API mock: tombol Finance pada tahap6, TIDAK0POST/YA1scan, statusOK setelah respons, verifikasi eksplisit menghasilkan hanya `scan` dan `verifyTermScan` (tetap6keputusan/paid0), Admin tidak punya scanner tetapi file lama/unduhan tetap terlihat, Operation scanner enabled.375/768/1440×lightdark,0JSerror/root overflow; screenshotdesktoplight/mobiledark diperiksa.

Tidak menggunakan database aktif/aaPanel, tidak menguji fresh physical MariaDB/production concurrency/transfer bank/real worker/Telegram atau render Word/PDF. Source/generator dokumen tidak berubah. Tidak mengubah `.env`/APP_KEY/server/worker/Dompis, tidak menjalankan migration/seed/reset/push/deploy. Bukti terstruktur: [finance-scan-upload-20261125.json](qa/finance-scan-upload-20261125.json).

## Penerapan

Deploy kode PHP dan build/check aset terbaru agar UI dan backend menggunakan izin yang sama. **Tidak ada migration baru atau worker baru untuk fitur ini.** Tidak perlu mengembalikan status atau mengajukan ulang dokumen lama. Verifikasi server sesudah deployment menggunakan akun Finance yang ditugaskan pada Branch/unit terkait; backend tetap menolak akun yang hanya mengganti tampilan role.

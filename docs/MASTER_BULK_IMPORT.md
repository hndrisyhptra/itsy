# Bulk Import Designator dan KHS — 6 November 2026


**Pembaruan 9 November 2026 — progres upload:** seluruh upload nyata kini menampilkan persentase byte pengiriman dan konfirmasi server. BOQ/eviden memperlihatkan fase antrean/worker terpisah; scan/impor tetap sinkron, preview master tetap belum menyimpan. Tidak ada persentase pemrosesan rekaan, endpoint/query/migration baru atau perubahan batas/izin/approval. Banyak eviden mempunyai progres per file. [Arti angka, arsitektur dan hasil uji](UPLOAD_PROGRESS.md). Catatan sebelumnya tetap riwayat sesuai tanggalnya.

Login sebagai **Superadmin**, buka **Master Designator → Designator** atau **KHS**. Tersedia tombol berikon dan tooltip **Download Template** serta **Bulk Import**. Impor menggunakan master SQL aktif pada database `its`, bukan simulasi atau data contoh.

1. Download template dari submenu yang sesuai.
2. Isi sheet **Data** mulai baris 2. Header jangan diubah. ID database dibuat otomatis.
3. Klik **Bulk Import**, pilih `.xlsx` dan mode impor, lalu **Periksa file**.
4. Periksa nomor baris, data baru/perubahan/data tetap dan detail preview. Jika salah, perbaiki workbook lalu periksa ulang.
5. Klik **Simpan impor**. Daftar master dan katalog aplikasi diperbarui setelah Laravel mengonfirmasi penyimpanan.

Batas satu file **5 MB / 1000 baris berisi data**; alamat sel hingga baris 2.000. Batch 1000 baris Designator dan 1000 baris KHS diuji berhasil. Ini batas aplikasi; batas upload PHP/web server dapat lebih kecil. Hanya sheet Data dibaca, sehingga panduan dan referensi tidak ikut diimpor. Template Data sengaja tanpa data contoh agar tidak tersimpan tanpa sengaja.

## Kolom template

| Template | Kolom wajib pada sheet Data | Referensi |
|---|---|---|
| Designator | `kode_designator`, `uraian_pekerjaan`, `satuan`, `kode_jenis` | Sheet Jenis memuat kode/label jenis aktif; sheet Paket tersedia sebagai informasi |
| KHS | `kode_designator`, `kode_paket`, `harga` | Sheet Designator memuat kode/uraian/satuan jenis Material/Jasa; sheet Paket memuat kode/nama paket aktif |

Gunakan kode, bukan ID atau nama tampilan. Import Designator terlebih dahulu jika kode belum tersedia, lalu import KHS. Paket dibuat melalui submenu Paket KHS. Setiap KHS mempunyai satu designator dan satu paket; Material dan Jasa tetap terpisah sesuai jenis 1/2 designator. Jenis nonaktif/arsip tidak dapat dipakai.

Harga merupakan rupiah tanpa `Rp` atau pemisah ribuan: misalnya `141810` atau `141810.25`. Nol valid, kosong tidak valid; tidak negatif, maksimal dua desimal dan tidak melebihi batas DECIMAL(15,2). Untuk formula harga, hitung ulang dan simpan Excel dahulu; hanya hasil angka tersimpan dibaca. Aplikasi tidak mengevaluasi formula atau mengambil tautan luar. Formula identitas, hasil error/cache hilang ditolak. Workbook `.xls`, macro, DTD/entity XML, arsip terlalu besar dan struktur di luar template tidak didukung.

## Mode dan validasi

- **Tambah baru saja** (bawaan): kode designator/pasangan KHS yang sudah ada ditolak.
- **Tambah dan perbarui data cocok**: Designator dicocokkan menurut kode; KHS menurut pasangan kode designator dan kode paket. Harga atau atribut yang diizinkan dapat diperbarui. Data yang sama ditandai **Tetap** dan tidak ditulis ulang.
- ID yang ada tetap dipertahankan. Arsip tidak dipulihkan. Identitas Designator yang sudah digunakan BOQ tetap dilindungi seperti pada form edit satuan.
- Kode/pasangan duplikat dalam file ditolak, termasuk perbedaan kapital biasa; referensi/kolom wajib/harga dicek oleh aturan server yang sama dengan CRUD master. Kesalahan dilengkapi nomor baris Excel. Baris yang gagal bisa memiliki beberapa kesalahan; validasi referensi berhenti pada referensi pertama yang salah dalam baris tersebut.
- Seluruh batch diperiksa sebelum penulisan. Satu baris salah atau kegagalan SQL membatalkan seluruh batch; tidak ada hasil parsial. Penyimpanan memvalidasi ulang, sehingga preview bukan izin untuk melewati validasi terbaru.

Tidak ada harga KHS yang dibuat otomatis dari file BOQ. Bulk Import KHS adalah tindakan eksplisit Superadmin pada template master, dengan preview dan pilihan Simpan impor.

## Penyimpanan, konkurensi dan riwayat

GET `/api/masters/{designator|khs}/template` menghasilkan `.xlsx` dengan panduan dan referensi master saat diunduh. POST `/api/masters/{designator|khs}/import` menerima multipart `file`, `stage=preview|commit`, `mode=create|upsert`, `revision`; commit juga memakai UUID `request_id` dan SHA-256 hasil preview. Keduanya hanya Superadmin, menggunakan sesi aktif/CSRF dan rate limit 20 request/menit per route.

Preview tidak menulis master/audit/revisi. Commit memakai satu transaksi dan lock workspace yang sama dengan CRUD/workflow. Preview membaca master/revisi terkini meskipun revisi layar tertinggal. Commit wajib cocok dengan revisi hasil preview; perubahan setelah preview ditolak 409. Satu batch menghasilkan satu revisi, satu prototype audit dan satu command receipt, serta satu `master_data_changes` sebelum/sesudah untuk setiap baris yang benar-benar berubah. UUID baris diturunkan secara deterministik dari UUID batch dan nomor baris agar tetap unik. Identitas replay memasukkan entity, mode dan SHA-256 file lengkap dalam kolom command yang tersedia (base64url, maksimal 60 karakter); tidak perlu migration.

Retry UUID/file/mode yang sama mengembalikan hasil tersimpan tanpa mutasi kedua, meski revisi awal telah berubah. UUID yang dipakai akun/file/mode lain ditolak. Dialog mempertahankan UUID setelah kegagalan koneksi agar **Simpan impor** dapat dicoba kembali. Jika harus menutup dialog setelah status tidak pasti, muat ulang master sebelum memulai batch baru. Konflik 409 yang sudah dikonfirmasi server membatalkan preview lama; file tetap dipilih dan tombol Periksa file dipakai kembali. Tidak ada commit ulang otomatis setelah konflik.

Katalog dibaca/ditulis ulang hanya sekali per batch; tidak memanggil seluruh transaksi CRUD dan pembacaan graph workflow untuk setiap baris. Harga baru memperbarui versi referensi lokal. Data PP/BOQ/kontrak/approval/pembayaran serta snapshot dan arsip workspace tidak ditulis ulang. BOQ yang memakai harga/versi lama tetap memerlukan review sesuai aturan yang ada.

File master diproses dari upload sementara dan tidak diarsip permanen; audit menyimpan nilai sebelum/sesudah dan identitas batch/hash. Simpan salinan workbook sumber sesuai kebutuhan administrasi. Proses master bersifat langsung dengan batas batch; tidak memakai antrean BOQ/eviden yang tetap berjalan terpisah.

## Verifikasi

Pengujian SQLite memory meliputi template XLSX nyata/header kosong/referensi, preview tanpa penulisan, tambah dan update KHS, harga nol/cache formula, duplikat, arsip, identitas BOQ terpakai, harga/referensi invalid, stale revision/hash/replay, rollback SQL, hak akses/CSRF, struktur XML dan batch 1000/1001 baris. Frontend memeriksa tombol/URL/tooltip/role, dialog/mode/default/batas, preview bernomor/escaping/harga nol, multipart/CSRF/retry dan pembaruan state setelah konfirmasi.

Browser visual, pembukaan manual template di Excel dan uji beban serentak belum diulang. Tidak ada batch contoh atau master baru disisipkan pada database pengguna. Tidak ada migration/seed baru. Bukti suite/build/backup tercatat pada `qa/master-bulk-import-20261106.json` dan `LARAVEL_QA.md`.

## Koreksi unduh template di Apache/macOS

Log pada 6 November menunjukkan `tempnam(): file created in the system's temporary directory`: Apache mewarisi lokasi sementara pengguna CLI yang tidak dapat diakses. Pengujian CLI biasa sebelumnya tidak mencakup kondisi ini. Generator kini membuat ZIP/XLSX (deflate/CRC/central directory) langsung di memori, tanpa tempnam, file sementara atau pelebaran izin folder. Parser upload tetap memakai pembacaan OOXML berbatas yang ada.

Endpoint template memakai proyeksi khusus: **2 query referensi** untuk Designator (jenis/paket), **3 query referensi** untuk KHS (ditambah designator Material/Jasa aktif). Tabel harga, dokumen workflow dan lock workspace tidak diperlukan. Kolom yang tidak digunakan tidak diambil. Hitungan ini tidak mencakup query middleware autentikasi/sesi; bukan klaim throughput seluruh aplikasi.

Regresi baru menjalankan generator dalam proses PHP dengan `sys_temp_dir`/TMPDIR yang tidak tersedia, mengubah warning menjadi exception, lalu memeriksa konsistensi ZIP/CRC dan XML setiap entry melalui ZipArchive/DOM. Tes endpoint menetapkan anggaran query dan melarang pembacaan harga/workflow. Semua uji tetap terisolasi; tidak ada mutasi database pengguna, migration atau perubahan konfigurasi/izin global. Verifikasi klik browser/Excel manual masih belum dilakukan.

## Koreksi revisi multipart — 6 November 2026

FormData browser mengirim `revision` sebagai teks, misalnya `"4"`. Validasi Laravel `integer` hanya memeriksa isi dan tidak mengubah tipenya, sedangkan model workspace menggunakan integer. Perbandingan strict sebelumnya menganggap revisi sama sebagai konflik. Controller kini mengubah hasil validasi menjadi integer sebelum pengecekan; input tidak valid tetap ditolak. Helper pengujian multipart kini juga memakai string seperti browser, bukan integer PHP yang menutupi bug.

Preview bersifat baca-saja, menggunakan master/revisi terkini di dalam transaksi/lock yang ada, lalu mengembalikan revisi tersebut. Commit memakai revisi hasil preview, bukan revisi awal layar. Jika ada perubahan di antaranya, commit ditolak tanpa penulisan dan pengguna perlu meninjau preview baru. Pemulihan tidak membutuhkan unduhan katalog/state tambahan dan tidak melemahkan pemeriksaan role, file hash, UUID/replay, identitas terpakai, harga atau atomisitas batch.

File kiriman `template-designator.xlsx` berisi 435 baris pada Data tanpa formula. Salinan fixture hanya dipakai untuk preview/upsert pada SQLite memory; workbook asli dan database pengguna tidak diimpor/diubah. Mode tambah baru tetap menolak kode yang sudah ada; gunakan Tambah dan perbarui data cocok bila ingin memperbarui kode yang telah terdaftar, lalu tinjau preview sebelum Simpan impor.

## Batas 1.000 baris

Batas terbaru adalah **1.000 baris data** pada sheet Data untuk setiap impor Designator atau KHS; header tidak dihitung. Server menggunakan konstanta MAX_ROWS=1000. Pesan form dan panduan template sudah disamakan. Batas 5 MB, 20.000 sel dan alamat baris hingga 2.000 tetap cukup untuk header + 1.000 data. Batas impor BOQ terpisah tidak berubah.

Regresi menjalankan commit 1.000 Designator, preview/commit 1.000 KHS dan replay KHS tanpa duplikasi; setiap batch memakai satu revisi/receipt. Kedua jenis file 1.001 baris ditolak sebelum penulisan. Pada batch KHS 1.000 baris, pembacaan graph workflow tetap sekali, bukan per baris; anggaran query unduh template juga tetap berlaku. Tidak ada perubahan schema atau impor live untuk pengujian. Uji ini tidak menyatakan hasil uji beban serentak.

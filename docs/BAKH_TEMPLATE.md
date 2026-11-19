# Preview Berita Acara Kesepakatan Harga — 6 November 2026

## Tempat penandatanganan Surabaya — 15 November 2026

Sesuai penandaan pada screenshot pengguna, kota pada baris **di atas TTD** selalu **Surabaya, [tanggal BA KH]**. Berlaku pada semua preview/cetak/PDF/Word surat BERITA ACARA NEGOSIASI KESEPAKATAN HARGA, termasuk pembacaan BA lama. Tanggal tetap tanggal dokumen, bukan hari ini; tanggal tidak valid tetap elipsis. Field form menjadi Tempat penandatanganan baca saja/otomatis Surabaya. PEKERJAAN/LOKASI/WITEL tetap data aslinya. Lampiran yang tidak memiliki baris tempat tidak ditambah baris baru. SPK/PP/Pembayaran/kasbon tidak diberi aturan kota ini.

BAKH_SIGNING_PLACE pada modul kecil bakh-place.ts dipakai form serta bakhDocumentData, sehingga HTML dan native Word berbagi sumber teks. Laravel dan domain TS mengisi **signingPlace=Surabaya** untuk BA baru/draf saat save/generate/attest, mengabaikan signingPlace client. Field otomatis tidak lagi membutuhkan isian legacy place agar pengajuan lolos; semua syarat lain tetap. signingPlace tersimpan dalam contracts.document_data melalui proyeksi JSON yang sudah ada, tanpa kolom/migration baru.

Field legacy **place** dipertahankan terpisah karena blankSpk masih menggunakannya sebagai referensi awal SPK. Pada draf yang sudah tersimpan, gunakan legacy place lama; API kompatibilitas baru tetap menerima legacy place teks sebagaimana sebelumnya, tetapi nilainya tidak menentukan kota BA. Form baru tidak mengisi legacy place dengan Surabaya, sehingga SPK tidak ikut dipaksa Surabaya. New signingPlace tidak diteruskan ke SPK. Terbit/Sah tidak ditulis ulang massal; pengesahan Terbit tetap mengambil snapshot lama seluruhnya. Preview historis hanya menggunakan kota tetap yang diminta pengguna, bukan memutasi snapshot.

Review query: assignment scalar memakai lookup dokumen lama yang sudah ada, **0SQL/API/query/lookup tambahan**; enam kasus domain new/existingDraft × save/generate/attest terukur0SQL. Harga/volume/signers/nomor/jadwal/authorization/receipts/audit tetap. Cadangan demo SQLite privat sebelum edit: storage/app/private/backups/area3-before-bakh-surabaya-20261115-145544.sqlite (233472byte), sumber dibaca read-only; its aktif tidak diakses/diubah.

Verifikasi:280PHP/5906assertions,289frontend,TypeScript/Vite build,Pint,assets32manifest/25preload×3profil lulus. Existing regressions diperluas untuk form readonly, client-city override, blank legacy place, preview/Word exact city, lokasi/Witel/PP/SPK tetap serta Terbit tidak berubah. Dua fixture Draft/Sah menghasilkan native DOCX CRC/XML valid, masing-masing1halaman dirender dan keduaPNG diperiksa; teks Surabaya/tanggal sesuai, sumber fixture tidak dimutasi. [Bukti/hash](qa/bakh-surabaya-20261115.json). Browser akun/server/MySQL runtime integrasi khusus perubahan ini, desktop Microsoft Word/native print dialog/cetak fisik belum diuji. Tidak ada migration/live rewrite/env/worker/Dompis/commit/push/deploy. Ketentuan lama di bawah yang memetakan tempat langsung dari isian manual digantikan hanya untuk kota penandatanganan BA.

Tombol **Preview BA KH** pada BA KH membuka berita acara khusus mengikuti `BA KH.docx` yang diberikan pengguna, bukan tabel transaksi generik. Referensi berada pada `/Users/hendrisyahputra/Downloads/Kelengkapan Tagihan Swakelola (Feb 2026)/BA KH.docx`; satu halaman A4 portrait telah dirender dan diperiksa. Dokumen sumber hanya dibaca, tidak diubah dan data contohnya tidak disalin menjadi data aplikasi.

SHA-256 sumber: `36ef24684782c4acd05e9f7ca910b89d964f79cc39d4c221d3103a301ba0d1bb`.

## Isi dan pemetaan form

Preview mengikuti judul BERITA ACARA / NEGOSIASI KESEPAKATAN HARGA, nomor dokumen, tiga baris identitas pekerjaan, narasi tanggal, tiga poin hasil kesepakatan, kalimat penutup, tempat/tanggal dan dua blok TTD. Header utama berbeda dari header kecil [Lampiran BA KH](BAKH_APPENDIX.md).

| Bagian template | Sumber data yang sudah tersedia |
| --- | --- |
| NOMOR | deal.no, nomor BA KH pada form, tetap mengikuti penomoran server |
| PEKERJAAN | project.name dari PP terpilih, sama dengan field Pekerjaan pada Informasi Dokumen BA KH |
| LOKASI / WITEL | deal.location / deal.witel, bukan otomatis nama Branch |
| Hari, tanggal, bulan, tahun dalam narasi | deal.date, diubah menjadi hari/bulan Bahasa Indonesia dan terbilang tanggal/tahun |
| Tempat dan tanggal penutup | deal.place dan deal.date dalam format tanggal panjang |
| Tim FO / Jointer | deal.fo / deal.jointer sebagai teks persis dari form |
| Kurun waktu pelaksanaan | deal.start / deal.end, sama dengan form periode yang mengikuti jadwal PP untuk baru/draf |
| Penandatangan Telkom Akses | deal.signer / deal.signerNik / deal.signerRole yang sudah diselaraskan menurut BAKH_SIGNER.md |
| Mandor Project | deal.mandor, nama Mandor dari form; jabatan KETUA mengikuti template |

Poin pertama berisi Harga Penawaran dan Harga Kesepakatan, masing-masing merujuk lampiran Harga Satuan dan keterangan Belum Termasuk Pajak Penghasilan. Poin kedua berisi rekonsiliasi/Opname Realisasi Pekerjaan antara Waspang TA dan Pelaksana sampai perhitungan final/BAPP. Poin ketiga memuat FO/jointer serta periode pelaksanaan dan teks POW terlampir. Ini redaksi tetap dari template, bukan pembuatan otomatis berkas POW/BAPP atau perubahan aturan pembayaran aplikasi. Daftar harga dan total transaksi tidak ditambahkan ke berita acara karena rinciannya berada pada lampiran terpisah.

Field FO/Jointer yang ada berbentuk teks bebas, bukan dua pasangan angka tim/orang. Preview menampilkan isi tersebut tanpa menebak jumlah; pengguna dapat menulis misalnya jumlah tim beserta jumlah orang pada field yang ada. Isian kosong ditandai elipsis, tanpa mengambil nilai contoh, tanggal hari ini atau identitas lain. Tanggal tidak valid tidak dinormalisasi diam-diam. Hari dihitung dari tanggal kalender yang sama dengan form (UTC hanya untuk format kalender tanpa pergeseran zona waktu), termasuk tanggal kabisat. Snapshot BA KH Terbit/Sah tidak ditulis ulang oleh preview.

Blok Telkom Akses memuat nama/NIK Head tersimpan dan jabatan Head of PMO Area Jawa Bali jika role tersimpan memang Head of PMO. Dokumen historis tanpa role tersebut mempertahankan identitasnya tanpa diklaim sebagai Head. Nama organisasi ditulis PT TELKOM AKSES, memperbaiki typo PT TEKOM pada contoh. Dua ruang TTD benar-benar kosong untuk tanda tangan basah; tidak ada stempel/gambar tanda tangan rekaan.

## Tata letak, performa dan pengujian

Dokumen memakai tipografi naratif Times New Roman, judul tengah/sans serif, identitas dan harga dalam pasangan label–nilai, tiga poin bernomor dan TTD dua kolom. Halaman cetak khusus `bakh-document-portrait` meminta A4 vertikal dengan margin mendekati sumber: atas 25,4mm, kanan 15mm, bawah 22,6mm, kiri 28,7mm. Lampiran tetap memakai halaman horizontal tersendiri. Nama/teks panjang membungkus dan dapat menambah halaman, tanpa tinggi tetap yang memotong isi; dialog printer dapat menimpa pengaturan CSS.

Implementasi: resources/js/area3/bakh-document.tsx, bakh-content.ts, ui.tsx dan styles.css. Model isi/tanggal/identitas bersama digunakan React dan [unduhan Word native](BAKH_WORD_EXPORT.md). Rendering memakai prop PP/BA KH dari form yang sama, termasuk draft belum disimpan; helper tanggal/terbilang murni dan formatter dipakai ulang. Jalur berita acara utama tidak mencari root BOQ/KHS atau menghitung total baris yang tidak ditampilkan; diuji dengan katalog/root tidak dimuat. Tidak ada query SQL, pencarian akun/katalog per sel, permintaan jaringan data baru, migration, perubahan .env atau mutasi data/approval/harga/nomor. Laravel tetap berwenang memvalidasi dan menyimpan form melalui alur yang sudah ada.

Lima regresi preview memeriksa tanggal/terbilang dan tanggal tidak valid, seluruh pemetaan isian/state tidak berubah, tanpa data contoh/tanda tangan rekaan, identitas historis/kosong, integrasi tombol portrait versus lampiran landscape, serta escaping teks form. Regresi signer/nomor tetap berlaku; tes sel kosong disesuaikan karena BA KH kini naratif tanpa tabel. Selain HTML/cetak browser, tombol Unduh Word (.docx) kini menghasilkan file native editable dari model yang sama. Unduh tidak menerbitkan/mengesahkan transaksi. Reference DOCX dan hasil ekspor QA sudah diperiksa visual; preview/klik unduh pada browser terautentikasi, Microsoft Word desktop dan cetak fisik belum diperiksa manual.

Hasil setelah penambahan Word: 194 tes PHP/3.260 assertions, 156 tes frontend dan build TypeScript/Vite lulus. Semua pengujian transaksi memakai SQLite memory, tanpa data uji/mutasi di its. Riwayat angka sebelum perubahan tetap tersedia di CHANGELOG.md/LARAVEL_QA.md.


## 19 November 2026 — Tempat mengikuti Branch

Otoritas terbaru pengguna mengganti kota penandatanganan BA KH/SPK/BARP/BAPP dengan Branch PP terkait untuk form, preview, Word, cetak dan paket, termasuk output dokumen lama tanpa GET backfill. Tanggal/identitas/history/nilai tetap; tabel VIII.PENUTUP PP beserta kolom tanggal kosong tidak diubah sesuai konfirmasi pengguna. Tidak menambah kota/tanggal pada lampiran yang tidak memilikinya atau mengubah kasbon. Ini menggantikan kota tetap Surabaya/old place presentation hanya pada lingkup tersebut. Lihat [DOCUMENT_SIGNING_PLACE](DOCUMENT_SIGNING_PLACE.md) dan QA19November.

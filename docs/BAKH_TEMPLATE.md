# Preview Berita Acara Kesepakatan Harga — 6 November 2026

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

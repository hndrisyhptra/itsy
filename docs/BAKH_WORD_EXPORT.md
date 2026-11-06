# Unduh Word BA KH dan Lampiran — 6 November 2026

Pada **Preview BA KH** dan **Lihat Lampiran** terdapat tombol **Unduh Word (.docx)** di samping Cetak/Simpan PDF. Masing-masing menghasilkan file Word tersendiri yang berisi paragraf/tabel native dan dapat diedit. BA KH memakai A4 vertikal, lampiran A4 horizontal. Nama file menggunakan nomor BA KH yang dibersihkan dari karakter path/nama file terlarang.

## Sumber isi dan perilaku

- Isi berasal dari prop PP/BA KH dan referensi yang sama dengan preview yang sedang dibuka, termasuk form draf yang belum disimpan. Unduh tidak menyimpan draf, menerbitkan/mengesahkan BA KH, mengganti nomor atau membuat keputusan approval. Untuk menyimpan perubahan ke aplikasi, tetap gunakan alur simpan yang ada.
- Model bersama resources/js/area3/bakh-content.ts menjadi sumber tanggal terbilang, redaksi, metadata, pemetaan harga dan identitas untuk React serta Word. Identitas Head/NIK tetap memakai BA KH yang sudah diselaraskan oleh alur form/server; identitas dokumen terbit/historis tidak dihitung ulang dari akun lain.
- BA KH mengikuti [BAKH_TEMPLATE.md](BAKH_TEMPLATE.md), dengan tiga poin bernomor, lokasi/Witel, tanggal, tim FO/jointer sebagai teks form, periode, tempat, serta blok Head/Mandor. Nama kegiatan yang panjang membungkus sejajar dengan nilai; teks harga membungkus pada kolom penjelasannya.
- Lampiran mengikuti [BAKH_APPENDIX.md](BAKH_APPENDIX.md): judul kecil dua baris di kiri tanpa kedua nomor pada isi lampiran; 10 kolom dan tiga tingkat header, harga satuan Mandor/Telkom Akses/Kesepakatan dipisah M/J. Komponen tidak dipilih kosong, nilai nol tetap 0. Tiga baris header tabel diberi penanda repeat-header untuk halaman lanjutan; baris data dan blok TTD tidak dibelah di tengah.
- Nama/NIK dan jabatan nyata tetap tercantum; ruang TTD fisik kosong. Tidak ada gambar tanda tangan, stempel approve rekaan, nilai contoh, atau jumlah orang yang ditebak.
- Pengunduhan dibuat lokal di browser dari data yang sudah tersedia bagi akun/Branch tersebut. Tidak ada endpoint baru, query SQL/katalog global, upload ke layanan luar, path ekspor di database, atau berkas temporer di server. Akses data asal tetap melalui login dan state terotorisasi yang sudah ada; file Word merupakan salinan preview, bukan bukti keputusan baru di sistem.

## Implementasi dan performa

resources/js/area3/bakh-docx.ts membuat paket OOXML native melalui ooxml-zip.ts. ZIP berisi document/styles/numbering/content-types/relationships; tidak memakai HTML yang diganti ekstensi, altChunk, gambar hasil screenshot, makro atau external relationship. Teks di-escape dan karakter kontrol XML tidak valid dibuang. Tidak ada dependency/npm package baru atau converter Word/LibreOffice di runtime aplikasi.

Generator dimuat dengan dynamic import hanya ketika tombol ditekan. Build akhir chunk generator 8,76 kB (gzip 3,53 kB); ukuran ini pengukuran bundle, bukan klaim throughput. Tombol menunjukkan proses, menolak klik ganda selama pembuatan dan menampilkan kesalahan jika file tidak dapat dibuat. Blob unduhan memakai MIME DOCX, URL lokal direvoke setelah dipakai.

BA KH utama tidak membaca BOQ/KHS atau menghitung total yang tidak dicetak. Lampiran membangun Map BOQ/KHS sekali, O(BOQ + KHS + baris), tanpa pencarian master berulang per sel. ZIP memakai CRC32 dan data UTF-8 tanpa kompresi; ukuran file dapat lebih besar daripada DOCX terkompresi, tetapi paket tetap valid. Batas paket 64 MiB mencegah alokasi output yang berlebihan; jika melampaui batas, ekspor gagal dengan pesan, tanpa memotong baris. Ini bukan batas impor baru.

## Bukti pengujian dan batas

**194 tes PHP / 3.260 assertions, 156 tes frontend, build TypeScript/Vite lulus.** Tujuh regresi baru menguji paket DOCX/relasi native, kesesuaian model preview tanpa mutasi, orientasi/merge/repeat-header dan pemetaan harga, nol/sel kosong, escaping/Unicode/nama file, snapshot historis/kosong/PP lanjutan, kedua tombol dan fixture 1000 baris tanpa pencarian master per baris atau pemotongan. Regresi preview/penomoran/signer tetap lulus setelah pemindahan model bersama.

Output dibuat memakai generator aplikasi yang sama pada fixture QA lokal tanpa memasukkan data uji ke its. Python ZipFile memeriksa CRC, lxml memeriksa semua XML, python-docx memeriksa ukuran halaman serta tabel. Kedua hasil dirender memakai bundled LibreOffice: BA KH 1 halaman portrait, lampiran 32 item 3 halaman landscape. Seluruh 4 PNG akhir diperiksa; indent teks panjang dan keselarasan dua blok TTD diperbaiki sebelum render akhir. Tabel tetap bersambung, header berulang, sel kosong/nol terlihat, TTD tidak terpotong. Bukti ringkas/hash tersimpan di docs/qa/bakh-word-export-20261106.json; berkas fixture/render berada di direktori QA temporer dan bukan data pengguna.

Klik unduh pada browser terautentikasi, Microsoft Word desktop, dan cetak fisik belum diuji manual. Render LibreOffice tidak menjamin pemenggalan halaman identik di semua versi Word/font/printer; tabel panjang dapat memakai beberapa halaman. Pengujian tidak mengklaim latency browser/server atau uji beban multiuser. Tidak ada migration, perubahan .env, worker, transaksi live, arsip workspace maupun aplikasi/database Dompis Prov yang diubah.

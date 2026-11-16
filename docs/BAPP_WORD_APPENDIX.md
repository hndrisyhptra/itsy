# Word BA PP dan Lampiran BA PP — 16 November 2026

Preview BA PP dan Lampiran BA PP menyediakan **Unduh Word (.docx)** dengan konfirmasi YA/TIDAK, status menyiapkan, pencegahan klik ganda dan pesan kegagalan. File native OOXML dapat diedit di Word; bukan HTML yang diberi ekstensi DOCX atau screenshot. Word diizinkan pada pembayaran Diajukan/Disetujui seperti BARP, dengan penanda review bila approval belum lengkap. Cetak resmi tetap menunggu seluruh persetujuan nyata yang cocok dengan assignee. Tidak membuat BA PP baru pada Parsial atau mengubah proses Final/Finance/pengesahan/pelepasan/PP lanjutan.

## Surat BA PP

`bappDocumentData` membagi run pembuka, hasil dan penutup yang sama kepada HTML dan Word. Word memakai salinan package BA-PP.docx yang telah diaudit pada [kontrak sumber](artifacts/bapp/artifact.md): portrait A4, margin1inci, style/themeCalibri11pt/judul14pt, paragraf dan tabel TTD asli. Pembuka, pekerjaan, hasil, penutup, tempat/tanggal dan tiga nama adalah slot editable. Nomor BAPP tidak ditambahkan. FirstFinal1 tetap tanpa Tahap/Termin; bertahap tetap TahapN/TerminN. Nilai bruto tahap kini, tanggal/periode/identitas tersimpan dan lokasi yang sama dengan preview; tidak mengganti bruto dengan kasbon net atau mengubah monetary policy.

Native generator melakukan satu pass substitusi marker sehingga teks pengguna yang berisi marker tidak mengganti slot lain. XML di-escape dan kontrol ilegal dibuang; nama multiline/Unicode dipertahankan. Bookmark end yang kehilangan start pada paragraf slot dibuang agar tidak menggantung. Review marker hanya teks status nyata. Ketiga ruang tanda tangan tetap kosong, tanpa cap/gambar/TTD palsu.

Package18entries dipertahankan, termasuk directory entries. Di luar document.xml, hanya descriptive metadata core/custom disanitasi agar identitas/tanggal sumber contoh tidak terbawa.15entries preserve-only dibandingkan byte-exact dengan sumber; section/styles/theme/fonts/notes/settings/relationships tetap. SourceSHA256a919d6e4994acfc22ddc6fdff8d7bc153a971f2b04c8c881146b170f5afba9fb utuh. [Inventaris package](artifacts/bapp/word-source-inventory.json). Teks/nama/tanggal/nilai contoh tidak dipakai sebagai transaksi. Lazy `bapp-word-template.ts` menyimpan package yang sudah dinetralkan, bukan file private Mandor.

## Lampiran BA PP

Tabel **sama dengan Lampiran BARP**: No, Designator, Uraian, Satuan, Harga Satuan Material/Jasa, kolom aktual LOP per termin sampai tahap kini, TotalVol, Material/Jasa/Jumlah dan rekap. Single Final1 memakai LOP saja; bertahap LOP · Termin #1/#2/#3.11/12/13kolom, actual per komponen, split M/J bila komitmen/vektor aktual berbeda, excluded blank/zero visible, kumulatif dan tagihan saat ini dipisahkan. Nilai setiap termin mengikuti policy tersimpan; tidak melakukan cumulative rebilling, net repricing atau volume release.

`bappAppendixData` memakai model BARP yang sama dan hanya mengganti judul menjadi LAMPIRAN BERITA ACARA PENYELESAIAN PEKERJAAN serta memilih tiga snapshot penandatangan: **Head of PMO → Waspang Project → Ketua Mandor**. Deployment/ProjectSupport tidak ditampilkan pada lampiran BA PP. Lampiran BARP tetap lima penandatangan seperti sebelumnya. Identitas dan NIK tersimpan tidak diganti dengan akun sekarang.

HTML `ReconciliationAppendix` dan Word `createReconciliationAppendixDocx` dipakai bersama untuk menghindari duplikasi tabel/rumus/layout. Lampiran BA PP A4landscape10mm, native grid7pt, dua header berulang, rekap dan tiga ruang TTD kosong; signer widths dinamis membagi lebar yang sama untuk3/5slot. Judul/width/body/footer disatukan dalam model; HTML table dan native first table diuji identik dengan BARP pada tahap1–3. CSS mengubah jumlah kolom TTD khusus BAPP, tidak mengubah lima TTD BARP.

## Kinerja dan data

Tidak menambah SQL/API/schema/migration/runtimepackage atau request bank/file. Props yang sudah diizinkan dipakai untuk preview/export; tidak ada GET write, penggantian snapshot, kasbon, ledger atau archive. Indeks metadata/komponen/termin existing dipakai sekali, maksimal3tahap dan2komponen; tambahan adapter judul/3signers konstan. Native ZIP memiliki batas64MB existing dan memori mengikuti isi dokumen. Word main package55.70kB/8.21kBgzip berada pada lazy chunk; tidak dimuat saat sekadar membuka preview. Tidak ada temporary path OS/server pada runtime download. Tidak mengklaim load/latency umum dari fixture.

## Verifikasi dan batas

- 310PHP/7137assertions SQLite memory,329frontend (4testWord/appendix baru),TypeScript/Vite dan aset37manifest references/29preloads×3profil lulus. Tidak ada PHP produksi berubah sehingga tidak menambah Pint baru. Existing1000item/indexed/financial-policy/gate tests tetap lulus.
- 6DOCX sintetis (mainFinal1/Final2/longFinal3, appendixFinal1/longFinal3, regressionBARP) dirender1/1/1/1/3/1halaman; seluruh8PNG diperiksa. Native ZIP/CRC/seluruhXML/relationships/bookmarks/section/merged spans/sign widths dan15preserved entries valid. BARP tetap5signers, BAPP3. Sumber referensi tidak berubah.
- Browser headless fixture lokal memeriksa6preview dan PDF1/1/1/1/1/3halaman; seluruh8PNG diperiksa, nonblank, orientasi benar,3nama dan tanpa nomor internal/Manager yang dihapus. React harness menggunakan komponen aplikasi sebenarnya: TIDAK tidak mengunduh, YA masing-masing menghasilkan tepat1DOCX; main hasil klik byte-identical dengan fixture yang dirender, appendix grid sama dengan BARP regression. Semua networkGETlokal;0JSerror, tidak ada request API/transaksi. Konfigurasi harness awal belum mengganti NODE_ENV sehingga tidak mount; setelah build harness production ulang, pengujian klik lulus. Ini bukan error aplikasi produksi.
- LOP sangat panjang/banyak baris memerlukan beberapa halaman. NativeWord mengulang header; Chrome dapat tidak mengulang header sangat tinggi seperti batas BARP existing. Penandatangan tetap utuh pada halaman terakhir. Browser akun/aaPanel, Word desktop, cetak fisik dan loadtest belum diverifikasi.

Tidak menjalankan migration, seed/reset, live its write, env/archive/Dompis change, restartworker, commit/push atau deploy. Tidak memerlukan backup baru karena perubahan ini hanya output dokumen/UI. Detail bukti: [QA](qa/bapp-word-appendix-20261116.json).


## 16 November 2026 — Paket dokumen dan ringkasan alur terbaru

Lihat [DOCUMENT_PACKAGE](DOCUMENT_PACKAGE.md): BA PP Sah menyediakan satu Word/PDF PP–Lampiran BA PP termasuk kasbon aktif setiap pembayaran. Timeline/detail menampilkan alur aktual5/7approval, scan terpisah dan APM opsional; riwayat/otorisasi/pembayaran tetap. List Payment memakai banner putih solid tanpa gradient. Shared Lampiran SPK Word memperlebar TotalVol6%→8% (Uraian23%→21%) agar digit total tidak turun sendiri. Current-only kasbon tetap lazy/UUID-bound/3query tanpa file catalog atau dekripsi arsip; tidak ada migration/data live rewrite. Bukti dan batas pengujian dicatat pada [QA](qa/document-package-20261116.json).

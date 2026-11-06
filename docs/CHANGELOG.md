# Catatan perubahan it's Area 3

Mulai 6 November 2026, setiap perubahan dicatat di sini, termasuk perubahan tampilan. Riwayat lebih awal tersedia pada [WORKPLAN.md](../WORKPLAN.md) dan [LARAVEL_QA.md](LARAVEL_QA.md). Baca catatan terbaru bersama AGENTS.md sebelum melanjutkan pada percakapan baru. Dokumen perilaku menjelaskan kondisi saat ini; entri audit terdahulu tetap dipertahankan.

Setiap entri harus menyebut permintaan/hasil, file utama, dampak data/migration/query, pengujian yang benar-benar dijalankan dan batas verifikasi. Jangan menganggap tes markup sebagai bukti visual browser atau cetak fisik. Jangan mencatat kredensial atau data rahasia.

## 6 November 2026 — Unduh Word BA KH dan Lampiran

- Permintaan/hasil: tombol Unduh Word (.docx) pada kedua preview, BA KH A4 vertikal dan lampiran A4 horizontal, memakai data form/preview yang sama termasuk draf, tabel editable serta ruang TTD basah kosong. Isi dan data dipusatkan agar Word/React konsisten. Native DOCX/OOXML dihasilkan lokal saat diminta, tanpa library baru atau data contoh.
- File: resources/js/area3/bakh-content.ts, bakh-docx.ts, ooxml-zip.ts (baru); bakh-document.tsx, bakh-appendix.tsx, ui.tsx; tests/Frontend/bakh-docx.test.ts (baru), public/build. Dokumentasi: [BAKH_WORD_EXPORT.md](BAKH_WORD_EXPORT.md), BAKH_TEMPLATE.md, BAKH_APPENDIX.md, LARAVEL_QA.md, WORKPLAN.md, README.md, AGENTS.md dan qa/bakh-word-export-20261106.json.
- Dampak/performa: tanpa SQL/endpoint/server temp-file baru, migration atau mutasi its/arsip/alur/harga/approval. Generator lazy chunk 8,76 kB/gzip 3,53 kB; Map referensi sekali per lampiran dan formatter dipakai ulang. Menjaga isian kosong versus nol, identitas historis dan header kecil lampiran. Unduh bukan aksi simpan atau pengesahan.
- Verifikasi: **194 tes PHP/3.260 assertions, 156 tes frontend dan build lulus**. Tujuh regresi native package/isi/model/harga/Unicode/riwayat/tombol/1000 baris; CRC/XML/python-docx valid. Render akhir BA KH 1 halaman dan lampiran 32 item 3 halaman, seluruh 4 PNG diperiksa; wrapping label/nilai dan posisi TTD diperbaiki. Browser terautentikasi, Microsoft Word desktop dan cetak fisik belum diuji manual.

## 6 November 2026 — Preview BA KH mengikuti template Word

- Permintaan/hasil: Preview BA KH utama memakai redaksi/layout berita acara dari BA KH.docx, dengan nomor, pekerjaan/lokasi/Witel, tanggal terbilang, tiga poin kesepakatan, FO/jointer/periode dan dua blok TTD. Semua nilai dari PP/form Informasi Dokumen BA KH; tidak menyalin contoh nama/tanggal. Daftar harga tetap pada lampiran horizontal terpisah.
- File: resources/js/area3/bakh-document.tsx (baru), ui.tsx, styles.css; tests/Frontend/bakh-document.test.ts (baru) dan table-empty-components.test.ts. Pemetaan lengkap dan batas fitur: [BAKH_TEMPLATE.md](BAKH_TEMPLATE.md). Sumber DOCX satu halaman sudah dirender/diperiksa; file sumber tidak diubah.
- Dampak: tampilan saja, tanpa query/jaringan baru, migration, mutasi its/arsip/snapshot atau perubahan alur/validasi/penomoran. Head memakai identitas BA KH tersimpan; ruang TTD kosong. Form tim tetap teks bebas, jumlah orang tidak ditebak; isian kosong tidak diganti data contoh.
- Verifikasi: **194 tes PHP / 3.260 assertions**, **149 tes frontend**, build TypeScript/Vite lulus. Lima tes baru mencakup seluruh pemetaan, terbilang/tanggal invalid, riwayat/kosong, escaping, serta integrasi portrait/lampiran landscape. Jalur utama juga diuji dengan katalog/root BOQ tidak dimuat: cukup memakai data form, tanpa menghitung total baris yang tidak ditampilkan. Reference DOCX satu halaman dirender dan diperiksa; browser aplikasi dan cetak fisik belum diperiksa manual. Preview tetap HTML/cetak browser, bukan penerbitan DOCX.

## 6 November 2026 — Header Lampiran BA KH dua baris kecil di kiri

- Permintaan/hasil: judul besar dan kedua nomor diganti dua baris di kiri atas: `LAMPIRAN BERITA ACARA KESEPEKATAN HARGA` serta `PEKERJAAN: {nama kegiatan}`. Mengikuti ejaan pengguna, nama diambil dari PP terpilih; ukuran preview 10px/cetak 8pt, tanpa nomor PP/BA KH pada lampiran.
- File utama: resources/js/area3/bakh-appendix.tsx, styles.css dan tests/Frontend/bakh-appendix.test.ts. Perilaku: [BAKH_APPENDIX.md](BAKH_APPENDIX.md); bukti/progres: LARAVEL_QA.md dan WORKPLAN.md.
- Dampak: presentasi saja, tanpa query/jaringan baru, migration atau perubahan data live/penomoran/approval. Tabel horizontal, sel kosong, harga dan identitas Head/Mandor tetap memakai data yang sama.
- Verifikasi: **194 tes PHP / 3.260 assertions**, **144 tes frontend**, build TypeScript/Vite lulus. Regresi lampiran memeriksa dua baris dengan nama kegiatan aktual dan absennya kedua nomor, sambil mempertahankan tabel/identitas. Pemeriksaan visual browser/cetak fisik belum diulang manual.

## 6 November 2026 — Lampiran BA KH ringkas/horizontal dan sel komponen kosong

- Permintaan: perkecil tabel preview Lampiran BA KH untuk kertas horizontal dan kosongkan semua penanda N/A pada tabel; dokumentasikan setiap perubahan agar dapat diaudit dari percakapan baru.
- Hasil: minimum tabel 1040px → 760px, huruf layar 10px → 9px, padding lebih kecil, halaman cetak khusus A4 landscape/margin 10mm. Penanda komponen yang tidak digunakan diganti sel kosong pada BOQ, PP, negosiasi BA KH, SPK, master/riwayat KHS dan preview transaksi termasuk pembayaran/BA PP. Harga nol tetap terlihat; nama/NIK Head, kolom dan TTD basah tetap tersedia.
- File tampilan: resources/js/area3/{bakh-appendix,ui,management,projects,workflow-ui,contracts,plan-document}.tsx dan styles.css. Uji: tests/Frontend/bakh-appendix.test.ts serta table-empty-components.test.ts. Pedoman audit ditambahkan ke AGENTS.md/README.md; perilaku kini dijelaskan di [BAKH_APPENDIX.md](BAKH_APPENDIX.md), progres di WORKPLAN.md dan bukti di LARAVEL_QA.md.
- Dampak: hanya presentasi. Tanpa migration, perubahan .env, query/endpoint tambahan, akses katalog per sel baru, mutasi its atau perubahan workflow/harga/total/null/snapshot/ekspor. Menggunakan state dan indeks referensi yang telah tersedia; tidak ada klaim peningkatan beban akses server.
- Verifikasi: **194 tes PHP / 3.260 assertions**, **144 tes frontend**, serta build TypeScript/Vite lulus. Dua tes lintas-tabel merender sepuluh keluaran dengan harga nol/komponen tidak tersedia dan memastikan state tidak berubah; lima regresi lampiran tetap lulus. Pencarian pada resources tidak menemukan lagi penanda N/A bawaan. Browser terautentikasi, screenshot responsif dan cetak fisik belum diverifikasi manual. Orientasi dapat ditimpa melalui dialog printer pengguna.

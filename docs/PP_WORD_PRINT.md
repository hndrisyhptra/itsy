# Unduhan Word PP dan perbaikan cetak PDF — 13 November 2026

## BOQ lebih ringkas — 15 November 2026

Pengguna mengonfirmasi tabel persetujuan VIII. PENUTUP sudah sesuai dan tidak perlu dikembalikan. Perubahan ini hanya merapikan **BoQ TA - Telkom / Owner Project** dan **BoQ TA - Mandor** pada preview, cetak/PDF dan Word. Header yang mengikuti Total Anggaran poin5 tetap berlaku.

Preview memakai huruf9px, padding3px/4px, line-height1.3 dan tabel fixed100%. Lebar kolom Owner/Mandor diatur terpisah: uraian lebih lebar, satuan cukup dan persentase Mandor tidak dipecah terlalu banyak. Bold designator mewarisi ukuran BOQ; kode panjang membungkus di kolomnya sendiri, nominal tidak dipaksa terpecah di tengah digit. Print BOQ6pt/padding1.5pt2pt. Selector hanya dalam pp-full-plan/plan-budget-section/budget-boq-table; input editor dan tabel penutup tidak ikut dikecilkan.

Capture Word menandai hanya dua tabel BOQ dengan compactBoq. Native OOXML memakai font6pt, grid A4 yang sama dengan proporsi kolom preview dan margin sel25twip vertikal/40horizontal. word-ooxml.table mendapat opsi margin dengan default lama55/65; seluruh pemakai lain, termasuk tabel penutup dan ekspor BA KH/SPK/BARP, mempertahankan default. Border, repeated header, merged cells, nilai nol/komponen kosong, angka/volume dan identitas tidak dihitung ulang atau dipotong. Pagination dapat berubah karena BOQ lebih pendek; isi/format tabel penutup tetap.

Tidak ada SQL/API/backend/schema/migration/live write atau backup DB baru untuk perapian presentasi ini. Kompleksitas capture tetap O(isi DOM), tanpa lookup baru per baris.280PHP/5880assertions,289frontend,TypeScript/Vite build,assets32manifest/25preload×3profil dan diff check lulus. Existing Word regressions diperluas untuk compact9/10kolom, native grid/font/margin serta default closing tetap.

Fixture4/36BOQ: preview850px menghasilkan dua tabel668px tanpa horizontal overflow; screenshot diperiksa. ZIP CRC/XML valid, seluruh teks/nominal sama dan XML tabel penutup byte-identik sebelum/sesudah. Native Word kini3/4halaman (sebelumnya4/5);5halaman isi yang berubah diperiksa visual,2Header hash-identik dengan halaman yang sudah diperiksa. PDF browser current/nested4/5halaman dan375/768/1440 nonblank. Ini bukti fixture lokal, bukan jaminan semua panjang uraian atau uji beban. Server, desktop Microsoft Word, native print dialog dan cetak fisik belum diuji. [Bukti/hash](qa/pp-compact-boq-20261115.json).

## Pembaruan 15 November 2026 — Hapus tiga teks penutup

Sesuai permintaan pengguna, preview PP tidak lagi menampilkan judul **Dibuat, Diperiksa dan Disetujui**, paragraf **Baris EVP: persetujuan otomatis; tanggal referensi satu hari sebelum BA KH paling awal. Status persetujuan aplikasi tidak menggantikan tanda tangan fisik.**, serta footer **Dokumen prototipe. Tanda tangan fisik tidak dibuat otomatis.** Penghapusan dari komponen preview otomatis berlaku pada DOCX yang menangkap DOM yang sama dan cetak/PDF browser. Ketentuan sebelumnya bahwa catatan referensi EVP tetap ikut Word digantikan oleh pembaruan ini; catatan sebelumnya dipertahankan di bawah sebagai audit.

Judul tersebut tetap pada editor Risiko & Kualitas, sesuai cakupan permintaan khusus preview/download. Tabel penandatangan, empat identitas akun, baris EVP, tanggal referensi, cap preview dan kolom TTD cetak kosong tetap. Tidak mengubah data, perhitungan, role, lima approval, query atau alur ekspor/print.0SQL/API tambahan, tidak ada schema/migration/live writes atau backup DB baru untuk perubahan presentasi.

Verifikasi:280PHP/5877assertions,289frontend/build/assets32refs/25preload×3profil/diff check lulus. Assertion preview yang sudah ada ditambah pemeriksaan ketiga teks tidak muncul. Headless fixture4/36BOQ menghasilkan DOCX4/5halaman dan PDF4/6halaman; seluruh9PNG Word diperiksa. Independent CRC/XML/PDF extraction memastikan tiga teks hilang, EVP tetap ada, PDF current/nested dan375/768/1440 nonblank. Batas desktop Microsoft Word/native print dialog/akun server/cetak fisik tetap. [Bukti tindak lanjut](qa/pp-clean-copy-20261115.json).

Preview Project Plan memiliki **Unduh Word (.docx)** di sebelah **Cetak / Simpan PDF browser**. Konfirmasi YA/TIDAK tetap berlaku. PP terkini mengekspor Header–Jadwal dan lampiran Anggaran–Penutup dalam satu dokumen; PP historis yang masih memakai preview generik mengekspor isi preview historisnya, bukan diregenerasi menjadi template terkini.

## Isi dan penyimpanan

Unduhan dibuat dari salinan DOM khusus PP yang sedang ditampilkan. Salinan dibuat saat tombol ditekan, sebelum modul Word dimuat, sehingga tidak mengambil isi modal lain atau data yang berubah selama unduhan disiapkan. Bila preview belum siap/kosong, tampilkan error dan jangan mengunduh dokumen kosong. Busy/running guard mencegah unduhan ganda; error dapat dicoba ulang setelah pengguna mengonfirmasi.

`pp-docx.ts` mengubah teks/heading/list/metadata/tabel pada salinan tersebut menjadi paragraf dan tabel **OOXML native**, dikemas dengan ZIP/CRC yang sudah dipakai ekspor lain. File bukan HTML yang diganti ekstensi dan tidak memakai altChunk, macro, gambar tanda tangan atau resource eksternal. Teks UTF-8 di-escape oleh word-ooxml; nama berkas dibatasi dan dibersihkan dari karakter path/Windows yang tidak sah.

Tidak menghitung ulang harga/volume/anggaran/signature identity: nilai yang tampil pada preview menjadi sumber tunggal. Angka uang tanpa desimal, quantity/persentase, null/excluded/zero, jadwal historis dan hasil formula tetap mengikuti presentation/domain yang sudah ada. Tidak memuat ulang KHS, akun/global workspace atau dokumen privat. Tidak ada penyimpanan/pengesahan/approval/nomor baru ketika diunduh.

DOCX A4 portrait memuat tabel bergaris, header berulang, colspan/gridSpan, lebar kolom khusus uraian/identitas, alignment angka, ruang TTD kosong dan bagian Header–Jadwal terpisah halaman dari lampiran, sebagaimana preview. Baris EVP beserta tanggal referensinya ikut; cap/status sel TTD akun maupun EVP tidak masuk DOCX, sesuai aturan cetak. Lima baris tabel TTD dijaga bersama agar tidak terpotong antarhalaman. Catatan referensi EVP di luar sel TTD tetap ada. Word dapat memberi pergantian halaman berbeda dari PDF browser.

## Penyebab PDF kosong dan perbaikan

Aturan print sebelumnya menyembunyikan `.app > *:not(.modal-backdrop)`. Preview di dalam `.main-shell`/page-content/editor ikut tersembunyi oleh aturan itu, walaupun modalnya diatur display:block. Reproduksi fixture Chrome headless: tinggi dokumen pertama **0px** dengan aturan lama.

Aturan baru mempertahankan rantai ancestor yang memuat modal dokumen, menyembunyikan saudara di luar rantai itu, dan melepas overflow/height batas body serta modal saat print. Halaman/form/sidebar/toolbar tidak ikut dicetak. Modal preview di dalam modal review juga diuji. Selektor memakai `:has` yang sudah dipakai stylesheet proyek; browser lama tanpa dukungan itu tidak menjadi cakupan verifikasi.

Ukuran halaman A4 portrait hanya diterapkan pada wrapper PP `data-project-plan-export="true"`; named page BA KH/SPK/lampiran tetap miliknya sendiri. Tabel BOQ PP memakai font/padding cetak lebih ringkas dan mencegah pemotongan digit di tengah nominal pada fixture; tabel TTD dijaga bersama. CSS print tetap menyembunyikan `.pp-signature-screen`/cap dan menyediakan container TTD kosong.

## Arsitektur dan query

- Tambahan backend/SQL/API/migration/query akun **0**. Tidak mengubah data its aktif, role/approval, scan/Finance/APM, ledger, arsip, private paths, APP_KEY atau worker; tidak menjalankan migration nominal sebelumnya. Tidak mengambil backup database baru untuk perubahan presentasi tanpa write/alur penyimpanan.
- Module Word dimuat lazy saat unduhan, memakai utilitas OOXML/ZIP yang sudah ada dan tidak menambah dependency runtime. Proses membaca node/tabel PP yang sudah diotorisasi, dengan memori proporsional isi dokumen/ZIP; tidak ada pencarian katalog per baris atau model perhitungan baru. Batas ukuran ZIP yang sudah ada tetap berlaku; tidak memangkas baris diam-diam.
- Print CSS adalah perbaikan jalur ancestor modal dokumen bersama; gate cetak Pembayaran di tombol dan backend tetap. PP download hanya muncul pada kind Project Plan. Dua preview BA KH mempertahankan tombolnya sendiri.

## Verifikasi dan batas

Lima tes frontend baru mencakup ZIP/parts/relationships/OOXML native, portrait/styles/escaping/Unicode, header berulang/merged cells/blank TTD, filename/empty preview, toolbar current+legacy/confirmation/no state mutation serta selector print ancestor/overflow/PP page scope. Tes BA KH diperbarui agar memeriksa tombol BA KH tidak bocor ke PP sambil mengizinkan tombol PP yang baru.

QA memakai HTML dari komponen React sebenarnya dengan fixture empat dan 36 baris BOQ. Browser headless dengan profil sementara dan jaringan di-intercept ke file fixture/aset lokal mengambil salinan DOM dan memanggil **modul build produksi** untuk membuat dua DOCX. Preview biasa dan nested review menghasilkan PDF; capture tidak mengubah DOM, seluruh bagian I–VIII/identitas EVP ada, cap TTD dan konteks luar tidak tercetak. ZIP/CRC dan semua XML dibaca ulang secara independen dengan Python; native Word dirender melalui bundled LibreOffice/render_docx.py dan seluruh sembilan PNG halaman diperiksa. Ini tidak menggunakan akun/data live/profil browser pengguna.

Hasil final jumlah tes, halaman, hash dan cakupan visual tercatat pada [LARAVEL_QA](LARAVEL_QA.md) dan [bukti terstruktur](qa/pp-word-print-20261113.json). Peninjauan hasil LibreOffice bukan verifikasi desktop Microsoft Word. Klik native print dialog/penyimpanan lokasi PDF di akun server, Safari/Firefox, pengujian keyboard/akun/Branch live dan cetak fisik belum dilakukan. Tidak melakukan commit/push/deploy. Operator perlu deploy kode **dan build aset**; fitur ini tidak membutuhkan migration.

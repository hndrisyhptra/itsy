# Lampiran SPK — template Excel, 7 November 2026

Pada form SPK, **Lampiran Pekerjaan SPK** dan **Lihat Lampiran** memakai tabel yang sama sesuai Lampiran-SPK.xlsx: NO, DESIGNATOR, URAIAN, SATUAN, HARGA SATUAN (Material/Jasa), **nama kegiatan PP → VOL** (tanpa label NAMA PROYEK), TOTAL VOL, MATERIAL, JASA, JUMLAH. Header lampiran kecil di kiri atas memuat judul, PEKERJAAN dan WITEL. Tiga baris bawah mengikuti recap template: JUMLAH MATERIAL, JUMLAH JASA, JUMLAH HARGA; label merge empat kolom, nilai per proyek dan total serta komponen terkait tampil di kanan. Caption menyatakan satuan Rupiah. Garis horizontal/vertikal, angka rata kanan, pembungkusan uraian/header dan scroll lokal menjaga seluruh kolom tersedia.

Sumber layout: `/Users/hendrisyahputra/Downloads/Lampiran-SPK.xlsx`, Sheet1 A1:K25; ukuran12.928 byte, SHA-256 `f2934175527c40dc688eb3f949dece671c05ab821f596ecbeb92a32eeefd8487`. Workbook hanya dibaca; rumus dan external link tidak dieksekusi, data contoh/nama proyek/Witel/penandatangan tidak disalin ke transaksi. Tidak ada impor atau perubahan file sumber.

## Pemetaan dan integritas

| Bagian | Sumber |
| --- | --- |
| Pekerjaan / Nama Proyek | Nama kegiatan PP yang direferensikan BA KH/SPK, termasuk PP lanjutan; satu SPK saat ini terkait satu PP |
| WITEL | deal.witel tersimpan |
| Designator / Uraian / Satuan | Referensi BOQ root yang cocok dengan deal.rows.rowId, lalu metadata KHS yang sudah dimuat; harga katalog tidak digunakan |
| Harga satuan | deal.rows.pm/ps untuk komponen yang dipilih, harga kesepakatan BA KH, bukan harga Owner/SPH atau master terbaru |
| VOL / TOTAL VOL | qm/qs kesepakatan yang dipilih; karena hanya satu proyek, Total Vol per baris sama dengan Vol proyek itu |
| Nilai Material / Jasa / Jumlah | Kuantitas × harga komponen, pembulatan domain SPK/BA KH yang sudah ada |
| Penandatangan | spk.signer/position aktual; tidak mengganti SPK historis dengan identitas Head akun/BA KH/template terbaru |

Jika dua komponen dipilih dan qm=qs, satu baris menampilkan keduanya dengan satu volume. Jika qm≠qs, lampiran menampilkan dua baris dengan label Material/Jasa di sel Vol; komponen lainnya kosong. Ini mempertahankan grid sebelas kolom dan kuantitas masing-masing tanpa menjumlahkan volume Material+Jasa atau menggunakan salah satu untuk keduanya. Tidak ada total lintas unit yang dibuat. Baris dengan semua komponen tidak dipilih tidak ditampilkan; komponen tidak tersedia kosong, harga/nilai nol nyata tetap 0, tidak ada N/A. Referensi metadata yang hilang ditandai Referensi tidak tersedia, tanpa mengubah harga/volume tersimpan.

Jumlah Material adalah total pembulatan Material tiap baris sumber. Jumlah adalah total pembulatan gabungan Material+Jasa tiap baris sumber, identik dengan dealTotal. Nilai Jasa tiap sumber menerima sisa pembulatan dari Jumlah minus Material, sama dengan surat SPK utama; jika sumber dipecah menjadi dua baris, sisa sen tetap mengikuti pasangan aslinya. Tidak ada perubahan nominal domain/SQL/ledger. Footer Vol proyek/Total Vol menampilkan rekap **nilai rupiah** per kategori sebagaimana baris13–15 template; ini bukan agregasi kuantitas antar satuan.

Ruang TTD fisik tetap kosong; tidak ada tanda tangan/stempel/gambar rekaan. Blok kiri mengikuti posisi PT TELKOM AKSES pada template dengan nama/jabatan SPK sebenarnya. Input yang belum ada berupa elipsis, tanpa nama/NIK contoh. Surat utama SPK tetap portrait dan tombol Word surat tetap tersedia. Lampiran kini mempunyai Cetak/Simpan PDF browser dan Unduh Word (.docx); otoritas terbaru pengisian Head/NIK serta ekspor tersedia pada [SPK_SIGNER_WORD.md](SPK_SIGNER_WORD.md). SPK baru/draf v3 memakai Head PP otomatis; dokumen Terbit/Sah memakai snapshot tersimpan.

## Arsitektur, query dan performa

- spk-appendix-content.ts merupakan model presentasi murni. Metadata root BOQ dan KHS diindeks Map sekali, lalu baris kesepakatan diproses satu lintasan; kompleksitas O(BOQ root + KHS dimuat + baris kesepakatan). Satu lookup root pada PP lanjutan; tidak ada find per baris. Maksimal dua baris tampilan per baris sumber, tanpa pemotongan 1000 baris.
- SpkAppendixTable dipakai ulang oleh form dan SpkAppendix. Cabang khusus Lampiran SPK di DocumentPreview berjalan sebelum tabel/perhitungan preview generik, sehingga tidak membuat tabel duplikat atau menghitung total generik yang tidak digunakan. Surat utama/BA KH/Pembayaran tetap pada komponen masing-masing.
- Data adalah props workspace yang telah mengikuti akses Branch. Tidak ada GET/SQL/query/lock/endpoint tambahan saat membuka lampiran, master lookup server, perintah simpan, schema/migration atau dependency baru. Review query: tambahan jalur database **0**; tes1000 baris menolak penggunaan find pada katalog/BOQ. Ini bukti struktur lookup frontend, bukan pengukuran latensi/load test atau optimasi seluruh bootstrap aplikasi.
- CSS bernama spk-appendix-landscape meminta A4 horizontal margin10mm hanya untuk lampiran; surat utama tetap spk-mandor-portrait. Header tabel berulang, footer summary tidak diulang per halaman, blok TTD dijaga utuh. Pada layar tabel dapat discroll melalui area berlabel dengan keyboard. Pengecualian list BA KH tanpa garis tetap berlaku.
- Tidak ada perubahan backend/domain/validasi, harga/volume/penomoran, workflow/approver, private uploads, archived state atau transaksi MySQL its. Perubahan presentasi ini tidak memerlukan backup workflow baru dan tidak memasukkan fixture QA ke database aktif.

## Bukti dan batas verifikasi

203 tes PHP/3.796 assertions, 182 tes frontend dan build TypeScript/Vite lulus. Enam regresi baru memeriksa grid/rekap/pemetaan, volume M/J berbeda, nol/komponen kosong/pembulatan sen, root PP lanjutan/signer historis, pemakaian komponen form+preview tanpa duplikasi serta1000 baris tanpa find per baris/referensi kosong. Regresi tabel SPK disesuaikan dengan format angka Rupiah template (tanpa Rp di setiap sel); input state tidak dimutasi.

Fixture HTML privat dibuat dari komponen aplikasi dengan CSS penuh di direktori sementara; **browser menolak akses localhost karena izin tidak diberikan**. Pemeriksaan visual browser, keyboard/scroll aktual, ukuran375/768/1440, dark mode dan hasil print belum terverifikasi. Tidak ada upaya mengakses browser lain atau menghindari penolakan. Server fixture sementara dihentikan. Tidak ada screenshot atau klaim kesamaan piksel/cetak fisik; dialog printer/font dapat mengubah page break. Fixture QA bukan dokumen transaksi pengguna dan bukan output yang diberikan.

Audit: CHANGELOG.md, LARAVEL_QA.md, ARCHITECTURE.md, WORKPLAN.md dan qa/spk-appendix-template-20261107.json.

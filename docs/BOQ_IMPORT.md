# Impor BOQ Excel dan simpan draf — 4 November 2026

Pemilih file pada langkah pertama PP kini mengunggah dan membaca workbook nyata melalui Laravel. Tombol **Muat Contoh BOQ (Simulasi)** tetap terpisah dan tidak pernah mengaku membaca file pengguna.

## Format yang didukung

Contoh pengguna: workbook OSP FO berformat `.xlsx`, sheet **BoQ**, dua baris header tetap:

| Sel/kolom | Isi |
|---|---|
| B6 | DESIGNATOR |
| D6 | SATUAN |
| E6 | HARGA SATUAN (PAKET-10), atau nomor paket aktif yang tersedia |
| G6 | VOL |
| E7 / F7 | MATERIAL / JASA |
| Baris 8 dan seterusnya, B / D / E / F / G | Kode / satuan / harga Material / harga Jasa / volume |

Baris volume nol/kosong diabaikan. Footer MATERIAL/JASA/TOTAL bukan kuantitas. Nilai H/I/J dan total footer tidak dipercaya; jumlah dihitung ulang dari volume dan harga satuan. Volume positif maksimal tiga desimal, harga maksimal dua desimal. Template lain, `.xls`, macro, designator tidak dikenal, satuan berbeda, paket tidak tersedia, volume negatif/terlalu presisi dan komponen duplikat ditolak dengan pesan baris/sel. Batas aplikasi 10 MB, 500 komponen positif, 10.000 baris, 100.000 sel; ukuran arsip dibatasi sebelum pembacaan XML. Batas upload PHP dapat lebih kecil.

### Sel kosong dan rumus — 6 November 2026

- Harga E/F yang benar-benar kosong, termasuk sel tidak disimpan di XML, diterima sebagai pembanding nol. Ini tidak menciptakan komponen KHS baru. Komponen master yang tersedia tetap menggunakan harga resmi dan memerlukan review jika berbeda dari pembanding nol.
- Formula E/F/G boleh memiliki hasil angka tersimpan (cached numeric value). Importer hanya membaca angka tersebut dengan batas/ketelitian yang sama; tidak menghitung rumus atau mengakses tautan luar. Hitung ulang dan simpan workbook sebelum upload agar cache sesuai isian terbaru.
- Hasil formula berupa string kosong yang eksplisit (`t="str"`, `<v/>`) dianggap kosong: harga nol atau baris volume diabaikan. Formula tanpa hasil tersimpan, hasil error, teks nonkosong/angka teks, negatif atau terlalu presisi tetap ditolak; volume tidak ditebak menjadi nol. Pesan meminta hitung ulang dan simpan file, bukan melarang semua rumus.
- Formula pada kode/satuan tetap ditolak. Rumus total bebas dibiarkan karena total Excel tidak digunakan. Aturan berlaku pada endpoint lama maupun worker antrean.

File `3DMO_QERECOVERY_JL NIAS.xlsx` memiliki F43 benar-benar kosong (`<c r="F43" .../>`), bukan formula. Empat baris positif: 43, 44, 504, 505; total sumber Rp15.667.200. Pemeriksaan baca-saja database its pada 6 November menemukan J-AC-OF-SM-24D dan J-ODP Solid-PB-16 AS belum memiliki KHS Paket 5 (baru Paket 10). Nilai Excel masing-masing Rp4.321 dan Rp141.810 **bukan otomatis harga master**. Superadmin perlu melengkapi KHS Paket 5 melalui menu master berdasarkan acuan resmi sebelum file lengkap dapat diimpor. Material keduanya sudah cocok. Tidak ada master live yang diubah untuk menghilangkan validasi ini.

Paket dipilih dari header Excel dan dicocokkan tepat ke paket master aktif. Kode gabungan seperti SC-OF-SM-24 dipetakan menjadi **M-SC-OF-SM-24** dan **J-SC-OF-SM-24**. Kode yang sudah memiliki prefix M-/J- tetap memakai komponennya sendiri. Material/Jasa tidak digabung menurut kemiripan nama. Komponen yang tidak tersedia dan bernilai sumber nol tetap N/A; harga nol pada komponen master yang tersedia tetap valid. Kode positif yang memetakan komponen sama dua kali harus dirapikan pada sumber, bukan dijumlahkan diam-diam.

Contoh kiriman memiliki **14 pekerjaan positif → 26 komponen**, dua pekerjaan hanya Jasa. Harga sumber sama dengan Paket 10; total Material **Rp64.523.245**, Jasa **Rp75.533.544**, BOQ Owner **Rp140.056.789**. Ini hasil pembacaan file dan pencocokan master, tanpa mengganti data master.

## Penyimpanan dan review

- Laravel menyimpan file asli serta manifest hasil parsing di `storage/app/private/prototype-boq/{workspace}/{upload-uuid}/`. File tidak menjadi aset publik. Upload tidak membuat PP, keputusan approval atau audit transaksi workflow.
- Respons mengisi tabel BOQ pada draft formulir. Pemilihan file baru mengganti tabel hanya setelah semua baris berhasil divalidasi. Jika anggaran masih nol, nilai awalnya mengikuti jumlah KHS; anggaran yang sudah diisi tetap dipertahankan.
- Harga Excel disimpan sebagai pembanding. BOQ Owner/kontrak menggunakan KHS. Selisih harga memerlukan alasan dan konfirmasi harga KHS per komponen saat pengajuan. Perubahan KHS membuat review terhadap harga/versi lama tidak berlaku. Volume draft dapat diubah/dihapus secara eksplisit; nomor baris, kode dan volume sumber dipertahankan sebagai provenance.
- Saat simpan PP, server membaca kembali manifest privat. Nama/hash/harga sumber dari client tidak dipercaya. Upload belum tertaut hanya dapat dipakai pengunggah; setelah tertaut ke PP, administrator yang berhak pada Branch tersebut dapat melanjutkan edit. File dapat diunduh dari editor PP sesuai akses Branch.
- Runtime MySQL kini menyimpan metadata/baris BOQ pada tabel bisnis dan snapshot dokumen, bukan arsip `prototype_workspaces.state`; lihat `MYSQL_RUNTIME.md`. UI menggunakan antrean SQL dan file privat, lihat `UPLOAD_QUEUE_PERFORMANCE.md`. Perbaikan sel kosong/rumus tidak menambah migration.
- File yang diganti/belum ditautkan tidak dihapus otomatis. Kebijakan retensi/pembersihan dan pemulihan file bersama backup masih perlu tahap operasional. Ekspor JSON tidak menyertakan berkas asli.

## Draf versus pengajuan

Draf v3 boleh disimpan setelah Header lengkap tanpa BOQ/approver lengkap; lihat `PP_DRAFTS_PREVIEW.md`. BOQ yang sudah diunggah tetap diperiksa sumber dan strukturnya. **Kelima approver boleh belum lengkap saat simpan draf.** Pilihan yang sudah diisi tetap diperiksa role aktif/Branch; memilih PM sebagai Head of PMO tetap ditolak.

Saat **Ajukan PP**, seluruh rencana, review selisih dan kelima approver wajib lengkap. Setelah itu alur versi 3 tetap berurutan. Jika dropdown kosong, Superadmin perlu membuat/menugaskan akun pada Branch melalui Kelola akun; tidak ada akun, persetujuan atau kredensial rekaan yang ditambahkan otomatis.

## Bukti pengujian

`tests/Feature/BoqWorkbookImportTest.php` memakai salinan workbook kiriman di `tests/Fixtures/boq/osp-fo-paket10.xlsx`, isolated SQLite :memory: dan disk privat palsu. Bukti mencakup total 14/26, draft tanpa approver, wajib lima saat pengajuan, sumber privat, scope Branch/pengunggah, penolakan manifest client, harga/versi KHS, XML/arsip/angka invalid dan pengabaian cached total. Workbook asli di Downloads tidak diubah. Lihat `LARAVEL_QA.md` untuk hasil suite dan batas browser QA.

Regresi 6 November memakai salinan Paket 5 `osp-fo-paket5-blank-prices.xlsx`: validasi KHS yang belum tersedia tetap menolak; empat baris/total/hash/simpan draft berhasil setelah dua KHS ditambahkan **hanya pada database test memory**. Varian XML menguji harga kosong/hilang, formula angka, formula string kosong, cache hilang/error/tipe teks, review harga dan jalur antrean. Tidak ada eksekusi/recalculation rumus atau pengubahan workbook asli.

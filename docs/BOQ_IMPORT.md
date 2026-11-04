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

Baris volume nol/kosong diabaikan. Footer MATERIAL/JASA/TOTAL bukan kuantitas. Nilai H/I/J dan total footer tidak dipercaya; jumlah dihitung ulang dari volume dan harga satuan. Harga/volume harus angka tetap, bukan rumus atau angka teks. Rumus total H/I/J boleh tetap ada dan tidak dievaluasi. Volume positif maksimal tiga desimal, harga maksimal dua desimal. Template lain, `.xls`, macro, formula pada input, designator tidak dikenal, satuan berbeda, paket tidak tersedia, volume negatif/terlalu presisi dan komponen duplikat ditolak dengan pesan baris/sel. Batas aplikasi 10 MB, 500 komponen positif, 10.000 baris, 100.000 sel; ukuran arsip dibatasi sebelum pembacaan XML. Batas upload PHP dapat lebih kecil.

Paket dipilih dari header Excel dan dicocokkan tepat ke paket master aktif. Kode gabungan seperti SC-OF-SM-24 dipetakan menjadi **M-SC-OF-SM-24** dan **J-SC-OF-SM-24**. Kode yang sudah memiliki prefix M-/J- tetap memakai komponennya sendiri. Material/Jasa tidak digabung menurut kemiripan nama. Komponen yang tidak tersedia dan bernilai sumber nol tetap N/A; harga nol pada komponen master yang tersedia tetap valid. Kode positif yang memetakan komponen sama dua kali harus dirapikan pada sumber, bukan dijumlahkan diam-diam.

Contoh kiriman memiliki **14 pekerjaan positif → 26 komponen**, dua pekerjaan hanya Jasa. Harga sumber sama dengan Paket 10; total Material **Rp64.523.245**, Jasa **Rp75.533.544**, BOQ Owner **Rp140.056.789**. Ini hasil pembacaan file dan pencocokan master, tanpa mengganti data master.

## Penyimpanan dan review

- Laravel menyimpan file asli serta manifest hasil parsing di `storage/app/private/prototype-boq/{workspace}/{upload-uuid}/`. File tidak menjadi aset publik. Upload tidak membuat PP, keputusan approval atau audit transaksi workflow.
- Respons mengisi tabel BOQ pada draft formulir. Pemilihan file baru mengganti tabel hanya setelah semua baris berhasil divalidasi. Jika anggaran masih nol, nilai awalnya mengikuti jumlah KHS; anggaran yang sudah diisi tetap dipertahankan.
- Harga Excel disimpan sebagai pembanding. BOQ Owner/kontrak menggunakan KHS. Selisih harga memerlukan alasan dan konfirmasi harga KHS per komponen saat pengajuan. Perubahan KHS membuat review terhadap harga/versi lama tidak berlaku. Volume draft dapat diubah/dihapus secara eksplisit; nomor baris, kode dan volume sumber dipertahankan sebagai provenance.
- Saat simpan PP, server membaca kembali manifest privat. Nama/hash/harga sumber dari client tidak dipercaya. Upload belum tertaut hanya dapat dipakai pengunggah; setelah tertaut ke PP, administrator yang berhak pada Branch tersebut dapat melanjutkan edit. File dapat diunduh dari editor PP sesuai akses Branch.
- Metadata sumber dan baris BOQ disimpan pada `prototype_workspaces.state` bersama transaksi PP. Tabel impor/workflow ternormalisasi belum menjadi penyimpanan operasional. Tidak ada migration baru pada perbaikan ini.
- File yang diganti/belum ditautkan tidak dihapus otomatis. Kebijakan retensi/pembersihan dan pemulihan file bersama backup masih perlu tahap operasional. Ekspor JSON tidak menyertakan berkas asli.

## Draf versus pengajuan

Draf tersimpan memerlukan BOQ positif, Branch/Unit aktif, header wajib (ID Project, Nama Kegiatan, tanggal, anggaran) dan struktur formulir yang valid. **Kelima approver boleh belum lengkap saat simpan draf.** Pilihan yang sudah diisi tetap diperiksa role aktif/Branch; memilih PM sebagai Head of PMO tetap ditolak.

Saat **Ajukan PP**, seluruh rencana, review selisih dan kelima approver wajib lengkap. Setelah itu alur versi 3 tetap berurutan. Jika dropdown kosong, Superadmin perlu membuat/menugaskan akun pada Branch melalui Kelola akun; tidak ada akun, persetujuan atau kredensial rekaan yang ditambahkan otomatis.

## Bukti pengujian

`tests/Feature/BoqWorkbookImportTest.php` memakai salinan workbook kiriman di `tests/Fixtures/boq/osp-fo-paket10.xlsx`, isolated SQLite :memory: dan disk privat palsu. Bukti mencakup total 14/26, draft tanpa approver, wajib lima saat pengajuan, sumber privat, scope Branch/pengunggah, penolakan manifest client, harga/versi KHS, XML/arsip/angka invalid dan pengabaian cached total. Workbook asli di Downloads tidak diubah. Lihat `LARAVEL_QA.md` untuk hasil suite dan batas browser QA.

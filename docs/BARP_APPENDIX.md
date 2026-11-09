# Pekerjaan otomatis dan Lampiran Rekonsiliasi — 9 November 2026

Perubahan ini melanjutkan [BARP_TEMPLATE_WORD.md](BARP_TEMPLATE_WORD.md). Referensi visual adalah screenshot pengguna yang disimpan pada [artifacts/barp-appendix/reference.png](artifacts/barp-appendix/reference.png); kontrak tampilannya ada di [artifact.md](artifacts/barp-appendix/artifact.md). Nama, nilai, dan gambar tanda tangan contoh bukan data aplikasi.

## Form Informasi BA Rekonsiliasi Pekerjaan

Isian **Lokasi Pekerjaan** diganti **Pekerjaan**, read-only dan otomatis dari nama kegiatan PP terpilih. Laravel menetapkan `barp.job` dari PP melalui SPK/kesepakatan tersimpan; client/cache tidak boleh memasukkan nama proyek lain atau mengubah identitas dokumen. Tidak ada input lokasi manual dan tidak ada lagi syarat lokasi pada pengajuan baru.

Metadata baru menggunakan `barp.version=2`, berbeda dari `workflowVersion`. Versi ini menambahkan nama pekerjaan serta snapshot Manager Deployment dan Manager Project Support. Tanggal, tempat, periode dalam SPK, STO dan Homepass tetap mengikuti validasi sebelumnya. Input `location` lama diabaikan pada penyimpanan editable; nilai lama tetap disimpan untuk kompatibilitas/riwayat. Nama pekerjaan tidak menerima input bebas atau batas 500 karakter lokasi lama.

Draf baru memperoleh versi 2 saat dibuat. Dokumen versi 1 hanya mengadopsi versi 2 melalui penyimpanan eksplisit Admin pada status yang dapat diperbaiki. Dokumen Diajukan/Disetujui serta `barpHistory` siklus lama tidak ditulis ulang saat dibaca. Surat utama versi 1 mempertahankan label/nilai LOKASI lama; surat versi 2 menampilkan PEKERJAAN dari snapshot job. Form menunjukkan nama PP terkait. Riwayat penolakan/pengajuan ulang dan lima penanggung jawab approval tetap utuh.

## Lampiran sesuai Volume Aktual Pekerjaan

Buka **Pembayaran → detail pengajuan → Lampiran Rekonsiliasi**. Preview khusus menggantikan dokumen tabel generik. Tampil A4 horizontal, judul kecil di kiri atas, Pekerjaan + nomor termin, Witel dari kesepakatan tersimpan, garis vertikal/horizontal dan rekap di bawah tabel.

Tabel mengikuti ledger form, dengan kolom:

| Kolom | Data |
|---|---|
| No / Designator / Uraian–Komponen / Satuan | Metadata root BOQ/KHS yang sudah diizinkan dan dimuat; Material/Jasa pada baris terpisah |
| Volume SPK | Komitmen komponen pada kesepakatan SPK terpilih, termasuk PP lanjutan |
| Termin #1 / #2 / #3 | Aktual tahap ini dan termin sebelumnya yang Disetujui; tahap setelah dokumen ini kosong |
| Aktual Saat Ini | Aktual komponen pada termin yang sedang dibuka |
| Accepted Sebelumnya | Jumlah aktual komponen pada termin lebih awal yang Disetujui, untuk SPK yang sama |
| Sisa Proyeksi | Volume SPK − accepted sebelumnya − aktual saat ini; bukan pelepasan volume atau addendum |
| Harga Satuan | Harga kesepakatan komponen tersimpan, bukan harga master terkini |
| Nilai Saat Ini | Nilai komponen untuk tagihan tahap ini |

Ini adalah adaptasi eksplisit tabel contoh ke tabel Volume Aktual Pekerjaan yang diminta pengguna: kolom harga tunggal per komponen, accepted, sisa, dan aktual saat ini menggantikan susunan harga/volume gabungan pada gambar. Komponen dengan volume berbeda tidak digabung dan tidak ada penjumlahan volume lintas satuan. Data historis yang dikembalikan/ditolak, SPK lain, dan termin setelah tahap ini tidak ikut accepted atau rekap sebelumnya. Termin lama yang belum disetujui tidak dinyatakan accepted.

Nilai gabungan per baris sumber dibulatkan seperti `termTotal`; Material dibulatkan tersendiri, Jasa menerima sisa sen. Form dan lampiran memakai jumlah yang sama. Rekap memisahkan **Material tahap ini**, **Jasa tahap ini**, **tagihan tahap ini**, **termin sebelumnya yang Disetujui**, dan **kumulatif sampai tahap ini**. Kumulatif adalah informasi, bukan tagihan baru atau status terbayar/APM. Komponen tidak digunakan kosong; nilai nol tetap terlihat. Tidak ada input tambahan/kurangan harga yang melewati ledger.

## Lima penandatangan

Urutan yang ditampilkan:

1. HEAD OF PMO AREA JAWA BALI
2. MGR PROJECT DEPLOYMENT AREA JAWA BALI — akun approver `Mgr Deployment`
3. MGR PROJECT SUPPORT AREA JAWA BALI — akun approver `Mgr Project Support`
4. WASPANG PROJECT — snapshot Waspang SPK
5. KETUA MANDOR — snapshot Mandor kesepakatan

Nama dan NIK berasal dari snapshot BARP. Untuk dua Manager, server menangkap ID/nama/NIK berdasarkan assignee pembayaran, dengan keputusan yang cocok, snapshot PP yang cocok, kemudian akun terpilih pada pembuatan/adopsi editable. Cache/client tidak boleh mengganti snapshot. Perubahan nama akun sesudahnya tidak mengganti identitas tersimpan.

Untuk dokumen historis tanpa snapshot Manager, preview hanya memakai keputusan aktual atau signature snapshot PP yang cocok dengan ID assignee pembayaran. Tidak memakai akun saat ini atau mengalihkan Business Support menjadi Project Support. Identitas yang belum tercatat tampil ellipsis; NIK tidak dikarang. Ruang TTD basah selalu kosong dan tanda tangan pada screenshot tidak disalin. Penambahan lima ruang fisik tidak mengubah lima peran/alur approval server. Surat BARP utama tetap memiliki blok tiga penandatangan sesuai template sebelumnya.

## Word dan cetak

Lampiran juga menyediakan **Unduh Word (.docx)** setelah Diajukan/Disetujui. Pending approval diberi **SALINAN REVIEW — MENUNGGU PERSETUJUAN**. Generator dan tombol sama-sama memeriksa status. **Cetak / Simpan PDF browser** tetap menunggu seluruh persetujuan akun/peran yang ditugaskan. Scan TTD fisik, Finance, APM, maksimal tiga termin, volume release dan BA PP tidak berubah.

Word native editable memakai tabel, header gabungan/berulang, rekap dan lima ruang TTD; screenshot bukan gambar dokumen hasil. A4 landscape 10mm, tabel 7pt, jarak baris ringkas. Surat utama tetap portrait. Banyak baris/teks panjang dapat memerlukan beberapa halaman, termasuk halaman penandatangan tersendiri. Word yang editable bukan tanda tangan digital atau bukti antiubah.

## Arsitektur, query dan bukti

- `BarpTemplate.php` / `barp-form.ts`: canonical job dan snapshot dua Manager, tanpa query tambahan; server tetap otoritas. Semua metadata disimpan pada `payment_terms.document_data` melalui lock/revisi/receipt/audit yang sudah ada. Tidak ada migration, backfill atau perubahan arsip workspace.
- `term-volume.ts`: model ledger komponen yang dibagi form editor dan lampiran. Map root BOQ/KHS, termin sebelumnya, dan accepted dibangun sekali; tidak ada pencarian katalog per baris. Harga/volume selalu dari kesepakatan terpilih. Jumlah dihitung untuk tahap sekarang dan maksimal dua termin sebelumnya; tidak menghitung tagihan SPK lain.
- `barp-appendix-content.ts`, `barp-appendix.tsx`, `barp-appendix-docx.ts`: satu model tabel/rekap/identitas untuk HTML dan Word. `barp-signers.ts` mencocokkan identitas tanpa account fallback pada historical read. `barp-file.ts` membagi MIME/filename ringan sehingga Word lampiran tidak memuat template surat utama yang besar.
- Tidak menambah endpoint, SQL, dependency runtime atau akses global/private file. HTTP fixture satu PP tetap **59 query** termasuk GET revisi, batas regresi 60 dan dua pembacaan katalog bawaan; resolver default **0 SQL**. Pengujian melarang penulisan PP/kontrak/SPK/scan/APM tak terkait dan memeriksa arsip tetap. Aggregate boot/command references tetap batas runtime yang ada; tidak ada klaim latency/load umum.
- Fixture **1000 baris** memeriksa metadata tanpa `find` katalog/BOQ per baris, komponen nol/eksklusi, referensi PP lanjutan serta Word tanpa truncation.
- **219 pengujian PHP / 4287 assertions**, **208 frontend**, build dan scoped Pint lulus. Satu pengujian PHP baru meniru dokumen versi 1 di SQLite `:memory:` lalu memeriksa read/lock/koreksi/adopsi/pengajuan ulang dengan riwayat dan snapshot nama tetap; enam frontend baru menguji canonical job, shared ledger, lima identitas, historical matching, preview/Word/escaping/gates serta 1000 baris. Pengujian lama disesuaikan dari lokasi manual menjadi pekerjaan server-owned.
- Empat Word sintetis dibuat dari generator aplikasi: surat utama versi 2 (2 halaman), lampiran approved (1), lampiran review (1), lampiran panjang (4). Seluruh **8 PNG akhir** diperiksa; header accepted diperlebar dan padding diringkas agar contoh pendek muat satu halaman. Tabel panjang mengulang header, semua 33 baris komponen utuh dan lima penandatangan tetap bersama. CRC/XML/relationships serta pembaca python-docx diperiksa.
- Browser, klik unduh dengan akun, responsif/dark, Word desktop, cetak fisik, serta load test belum diuji manual. Izin browser yang sebelumnya ditolak tetap dihormati. Tidak ada transaksi/akun/data uji yang ditulis ke its aktif atau Dompis Prov.

Backup its sebelum perubahan: `storage/app/private/backups/its-before-barp-appendix-20261108-215109.sql`, **2044552 byte**, SHA256 `344c8cd76048cdb9e175d5bbb5e9558373418a0ddd08b3c341fb4c261827b7bc`. Tanggal perubahan mengikuti 9 November 2026 Asia/Jakarta; nama backup mencerminkan timestamp host. Backup awal terhalang sandbox; percobaan baca yang diizinkan berhasil. Detail bukti: `docs/qa/barp-appendix-20261109.json`.

# BARP Pembayaran — template, form dan Word

Berlaku 8 November 2026. Referensi pengguna: `BARP - 100%.docx`, disimpan tanpa perubahan di [artifacts/barp/reference/BARP.docx](artifacts/barp/reference/BARP.docx). SHA256 `9c08a9075bc7110bbe4315add0823d4a7676e3c28c282897610757d45b7f498f`. Kontrak pelestarian template ada di [artifact.md](artifacts/barp/artifact.md).

## Pembaruan 9 November 2026

[Pekerjaan otomatis dan Lampiran Rekonsiliasi](BARP_APPENDIX.md) menggantikan lokasi manual pada dokumen editable: `barp.version=2` menyimpan `job` dari nama PP, serta snapshot Manager Deployment/Project Support. Lampiran kini mengikuti ledger Volume Aktual Pekerjaan dengan lima penandatangan dan Word horizontal. Dokumen versi 1 dan siklus lama tetap utuh; uraian 8 November di bawah menjadi referensi implementasi awal untuk versi tersebut. Surat utama versi 2 menampilkan PEKERJAAN, bukan LOKASI. Syarat approval/scan/Finance/APM tetap.

## Pemakaian

Buka Pembayaran → pengajuan → Informasi BA Rekonsiliasi Pekerjaan. Admin Konstruksi/Superadmin dapat mengubah informasi saat Draft/Dikembalikan/Ditolak v3; dokumen Diajukan/Disetujui terkunci. Isi volume aktual seperti sebelumnya, simpan draf atau ajukan. Preview Dokumen/Preview BA dan preview inbox membuka surat yang sama untuk Parsial maupun Final. Lampiran Rekonsiliasi tetap tabel aktual terpisah; BA PP tetap melalui proses Final tersendiri.

Judul otomatis `BERITA ACARA REKON PEKERJAAN - TAHAP 1/2/3` mengikuti nomor termin tersimpan, bukan input bebas.

| Isian | Sumber dan validasi |
|---|---|
| Nama Proyek / Jenis Pekerjaan | Kegiatan PP terkait / SWAKELOLA MANDORAN tetap |
| Tanggal rekonsiliasi | Awalnya tanggal server ketika draf baru dibuat; Admin dapat mengubah; tanggal kalender valid wajib saat pengajuan |
| Tempat penandatanganan | Default tempat SPK; teks maksimal 150 karakter, wajib saat pengajuan |
| Periode mulai/selesai | Default periode SPK; Admin dapat mempersempit, tidak boleh keluar SPK atau terbalik; wajib saat pengajuan |
| Lokasi pekerjaan | Default lokasi BA KH/kesepakatan; maksimal 500 karakter, wajib saat pengajuan |
| STO | Teks Admin opsional maksimal 150 karakter; tidak ditebak dari Witel/Branch/Service Area |
| Homepass | Admin opsional, bilangan bulat 0–1.000.000.000; kosong berbeda dari nol |
| Waspang / Ketua Mandor | Snapshot Waspang SPK / Mandor kesepakatan; read-only, server mengabaikan penggantian client |
| Head of PMO / NIK | Head terpilih pada assignment pembayaran, memakai keputusan/snapshot yang cocok atau akun terkait saat pembuatan; read-only dan dibekukan; tidak mengambil Head sembarang |

## Nilai dan kesesuaian template

- Material/Jasa adalah **volume aktual termin ini × harga kesepakatan SPK**; tidak menggabungkan termin terdahulu atau harga KHS terkini. Total mengikuti pembulatan gabungan per baris pada `termTotal`; sisa sen dialokasikan ke Jasa agar rekap cocok. Komponen tidak digunakan kosong; komponen bernilai nol tetap 0.
- Baris “Harga Berdasarkan Surat Perintah Kerja — volume tahap N” merujuk porsi tahap ini, bukan seluruh nilai SPK. Tambahan/kurangan bernilai 0 karena aplikasi belum mempunyai proses addendum harga/volume. Tidak ada input tambahan tagihan yang dapat melewati ledger/ceiling.
- Kalimat 100% pada contoh tidak disalin untuk semua termin: surat menampilkan **porsi nilai tahap terhadap SPK**, bukan persentase progres fisik. Nominal terbilang berasal dari jumlah yang sama, termasuk pecahan sen.
- Judul/kalimat yang menyatakan BAPP dapat diterbitkan disesuaikan menjadi nilai rekonsiliasi tahap untuk pengajuan pembayaran. BARP tahap awal tidak menyatakan BAPP sudah sah. SOW, ketentuan pengembalian kelebihan bayar, dan penutup mengikuti template.
- Objek rekonsiliasi, empat kelompok harga, tabel rekap dengan header gabungan, Waspang/Mandor dan Head of PMO mengikuti source. Ruang TTD basah kosong; tidak ada cap persetujuan atau gambar tanda tangan buatan.

## Word, cetak dan sejarah

**Unduh Word (.docx)** aktif setelah status Diajukan atau Disetujui. Selama belum lengkap seluruh keputusan sesuai peran/akun terpilih, Word dan preview bertanda **SALINAN REVIEW — MENUNGGU PERSETUJUAN**. Draft/Ditolak/Dikembalikan harus diajukan kembali sebelum Word aktif. Generator juga memeriksa status, bukan hanya tombol. Berkas Word bersifat editable; penanda review bukan tanda tangan digital atau kontrol antipemalsuan.

Tombol Cetak/Simpan PDF tetap membutuhkan seluruh persetujuan dokumen. Scan TTD fisik, verifikasi Finance, APM dan pelunasan tetap mengikuti workflow server yang ada. Mengunduh Word tidak menyimpan keputusan, membuat scan atau mengesahkan dokumen.

Metadata `barp` versi 1 tersimpan di `payment_terms.document_data`; `barpHistory` menambah snapshot setiap pengajuan eksplisit, termasuk pengajuan ulang setelah ditolak. Riwayat sebelumnya tidak dapat diganti dari client. Proyeksi SQL/revisi/receipt/audit memakai transaksi yang sama. Tidak memerlukan migration, tidak menulis ulang arsip `prototype_workspaces.state`.

Dokumen lama tanpa `barp` tidak diubah saat dibaca. Preview memakai sumber SPK/kesepakatan yang sudah tersimpan; tanggal/STO/Homepass yang tidak tercatat tetap kosong/ellipsis. Tidak memasukkan tanggal hari ini atau identitas akun terkini ke surat historis. Draf lama bisa mengadopsi form BARP lewat penyimpanan eksplisit Admin; protokol lama tanpa metadata tetap kompatibel.

## Implementasi dan tinjauan performa

- `BarpTemplate.php` + `barp-form.ts`: default dan whitelist validasi tanggal/periode/teks/Homepass; hanya server menentukan snapshot identitas. `PrototypeDomain.php` dan `domain.ts` menyimpan metadata/riwayat yang sama.
- `barp-content.ts`: satu model jumlah/terbilang/prose/gates untuk preview dan Word. Model hanya memerlukan PP, kesepakatan, SPK dan termin terkait; tidak membaca root BOQ/master/termin terdahulu.
- `barp-document.tsx`, `payments.tsx`, `ui.tsx`, `styles.css`: form Admin, preview khusus BARP dan download lazy. Tabel aktual memakai Map metadata BOQ/KHS dan akumulasi accepted sekali di luar per-baris, mengganti nested find/filter sebelumnya.
- `build-barp-word-template.py` menyiapkan `barp-word-template.ts` dari reference checksum terkunci. OOXML native disalin dan diisi lewat satu kali escaping slot. A4 portrait, grid/font/theme/numbering asli dipertahankan; floating table/fixed heights yang menyembunyikan baris pada sumber diubah menjadi inline/fleksibel. Source parts tidak memuat dokumen contoh/sample pada surat hasil. Tidak ada dependency/CLI/temp path/endpoint baru saat runtime.
- Resolver metadata menambah **0 SQL**. Fixture HTTP satu PP: **59 query** termasuk pembacaan revisi, dengan dua pembacaan referensi bawaan GET/command dan batas regresi 60; tidak ada query per baris BARP atau penulisan proyeksi PP/kontrak/SPK/scan/APM yang tidak terkait. Ini bukan benchmark beban atau jaminan latency umum. Shared aggregate command/reference read tetap merupakan batas runtime yang ada.
- Word chunk dimuat saat diunduh; hasil build 117,06 kB / 12,25 kB gzip, bukan tambahan eager sebesar itu pada halaman utama. Preview tidak mengambil katalog tambahan.

## Bukti dan batas verifikasi

Pengujian PHP selalu SQLite `:memory:` fail-closed; tidak memakai its aktif. Pengujian meliputi persistensi JSON SQL, identity/history forgery, tanggal/periode/Homepass/batas teks, rollback revision pada error, penolakan→koreksi→snapshot siklus baru, role dan submitted lock, arsip tidak berubah serta query budget. Frontend memeriksa jumlah tahap/rounding, missing-vs-zero, history, gates akun/peran, escaping Unicode/slot, paket OOXML, label form, preview tanpa root/KHS dan sejarah tanpa tanggal palsu. Regression sebelumnya untuk dua/lima approver kini memeriksa blok TTD template tiga penandatangan, sementara dua/lima keputusan tetap menjadi syarat cetak.

Tiga DOCX sintetis dari generator aplikasi dirender: tahap 1 disetujui, tahap 2 review, tahap 3 Final teks panjang. Masing-masing 2 halaman, seluruh 6 PNG diperiksa: tabel harga lengkap dan tidak bertumpuk, header gabungan terbaca, signoff tetap utuh dan kosong. CRC/XML/relationship/theme/source geometry diperiksa offline. Fixture ini bukan transaksi live dan tidak dikirim sebagai surat pengguna.

Belum diuji manual melalui browser, klik unduh dengan akun, responsif/dark, Microsoft Word desktop, cetak fisik atau load test. Penolakan akses browser sebelumnya dihormati. Live its hanya dibaca untuk backup; tidak diisi fixture atau diubah skemanya. Data/TTD basah tetap memerlukan input/unggahan pengguna yang sebenarnya.

Backup sebelum perubahan: `storage/app/private/backups/its-before-barp-template-20261108-011917.sql`, 2044552 byte, SHA256 `07a5a29514c4393f5606464e518a642ef5c34c6bd90dd5b99b431fbf4962a114`.

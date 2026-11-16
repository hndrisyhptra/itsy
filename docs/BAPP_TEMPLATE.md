# Preview BA Penyelesaian Pekerjaan — 16 November 2026

## Word dan lampiran terbaru — 16 November 2026

Permintaan lanjutan menambahkan unduh nativeWord surat dan lampiran. Surat tetap tanpa nomorBAPP/conditionalstage dengan wording shared; lampiran sekarang mengikuti tabel BARP dan hanya Head of PMO/Waspang/Ketua Mandor. Ini menggantikan pernyataan “tidak menambah Word download” serta tabel generik lampiran pada audit sebelumnya, tanpa mengubah cumulative totals/ledger/status/approval. [Perilaku lengkap, template package dan QA](BAPP_WORD_APPENDIX.md). Ketentuan/audit awal di bawah tetap dipertahankan.

Preview BA PP mengikuti surat pada BA-PP.docx yang dikirim pengguna. [Kontrak template dan sumber](artifacts/bapp/artifact.md) menyimpan hash, geometri, style dan slot. Template referensi dibaca/render saja, tidak ditimpa atau diimpor sebagai data transaksi. Nama, tanggal, persentase dan nilai contoh tidak disalin.

## Susunan dan data

Judul BERITA ACARA PENYELESAIAN PEKERJAAN tanpa nomor BA PP, pembuka hari/tanggal terbilang, identitas Ketua Mandor, nama pekerjaan di tengah, uraian hasil pemeriksaan, penutup dan tiga ruang TTD: Waspang Project, Ketua Mandor, kemudian Head of PMO. Tabel generik, ringkasan approval/APM dan footer demo pada surat utama diganti oleh susunan template. Lampiran BA PP dan tabel detail tetap dengan rekonsiliasi kumulatif existing.

Nama pekerjaan mengikuti PP terpilih; tanggal pemeriksaan, periode dan identitas memakai metadata BARP tersimpan pada pembayaran yang sama. Data historis mengikuti fallback BARP existing (periode/SPK serta identitas tersimpan, tanpa akun terkini). Kota tanda tangan memakai BARP.place tersimpan, kemudian SPK.place; tidak menyalin Kupang dari contoh atau memaksakan aturan kota baru untuk semua dokumen. Tanggal kosong/tidak valid tetap belum tersedia, bukan hari ini/tanggal approval. Tidak menambahkan isian atau menulis ulang snapshot BA PP/BARP.

Sesuai slot template, **nilai yang disebut pada uraian adalah bruto tagihan tahap ini** dari `barpDocumentData`, bukan jumlah seluruh termin atau transfer net kasbon. Persentase otomatis adalah nilai tahap ini dibagi nilai SPK kanonis (harga/aktual tersimpan), bukan pengukuran fisik baru; persentase ini menggunakan pendekatan nilai yang sudah ada pada BARP. Tidak memaksakan100% karena Final, tidak menjumlah volume lintas satuan dan tidak mengubah tagihan/ledger. SPK bernilai0 menampilkan persentase belum tersedia. Nilai rupiah tampil tanpa desimal mengikuti format bersama. Rekonsiliasi kumulatif tetap tersedia pada detail dan Lampiran BA PP.

## Label pembayaran

- Nomor1 + jenisFinal: tidak ada Tahap1 atau Termin1 pada seluruh surat. Kalimat penutup menjadi “Demikian Berita Acara ini dibuat sesuai dengan kondisi lapangan yang sebenarnya sebagai dasar Rekonsiliasi, serta untuk dipergunakan seperlunya.” Slot nilai memakai kata pembayaran.
- Pembayaran bertahap: TahapN ditambahkan pada hasil dan penutup, TerminN di samping nilai. Final2/3 tetap bernomor sesuai pembayaran tersimpan; tidak menampilkan (Final)/(Parsial).

Aturan ini hanya presentasi. BA PP tetap dibuat/dihubungkan ke Termin Final oleh proses existing; bukan pembuatan BA PP pada setiap Parsial. Nomor internal, form nomor pengesahan, status Draft/Sah, keputusan, Finance/lunas, pelepasan volume dan sumber PP lanjutan tetap. Penghapusan nomor berlaku pada surat preview/cetak.

## Render dan pengamanan

`bapp-content.ts` adalah model pure dari PP/deal/SPK/term authorized yang sudah dimuat. `bapp-document.tsx` merender surat, terpisah dari lampiran. `DocumentPreview` memiliki cabang BA PP khusus sebelum tabel generik, tanpa akses master/akun/bank/file baru. Print memakai `canPrintBarp` dan YA/TIDAK: seluruh keputusan aktual harus cocok dengan assignee, bukan jumlah approval saja. Tidak membuat cap atau tanda tangan; tiga ruang fisik selalu kosong. Penanda draf/review dipertahankan bila pembayaran belum lengkap.

A4 portrait dengan margin25.4mm, font Calibri/Carlito/Arial11pt, judul14pt dan garis bawah, tanpa logo seperti referensi. CSS khusus BA PP mengatur latar cetak putih hingga root dan melepaskan clipping modal memakai mekanisme cetak existing. LOP>100karakter memperoleh jarak vertikal lebih ringkas tanpa mengecilkan teks atau memotong nama; blok tiga TTD dipertahankan bersama. Nama sangat panjang tetap dapat memerlukan beberapa halaman. Surat utama BARP/SPK/PP dan orientasi lampiran tidak diubah.

Tidak menambah Word download pada tugas ini; permintaan saat ini adalah preview template dan hasil cetak/PDF dari preview yang sama.

## Query, QA dan batas

Tambahan0SQL/API/schema/migration/dependency. Satu model membaca agreement secara linear (perhitungan BARP dan nilai SPK) dan DOM surat tetap ringkas; tidak mencari KHS/BOQ/akun per komponen. Fixture1000item melarang catalog/source/current-account find dan memastikan state unchanged. Tidak ada klaim load/latency umum atau perubahan query backend.

310PHP/7137assertions memakai SQLite memory,325frontend (5kasus BA PP baru),TypeScript/Vite dan assets36manifest/28preload×3profil lulus. Regresi total kumulatif existing tetap diuji pada Lampiran BA PP; surat utama diuji terhadap nilai tahap sesuai template. Browser headless sintetis nested menghasilkan3PDFportrait, masing-masing1halaman untuk Final1/Final2/nama panjangFinal3; seluruh halaman diperiksa. Label, nilai bruto, tiga nama dan nomor internal absent diverifikasi; viewport375/768/1440 tidak overflow dokumen. Referensi1halaman dirender/diperiksa dan SHA tetap sama. [Bukti](qa/bapp-template-20261116.json).

Tidak membuka akun server atau mengubah its aktif/env/arsip/Dompis, tidak menjalankan migration/reset/seed/restartworker/deploy. Tidak perlu backup database baru karena tidak ada workflow/storage write. Pengujian browser memakai data sintetis, bukan pengajuan nyata; browser akun,aaPanel,cetak fisik dan desktopWord tidak diverifikasi.

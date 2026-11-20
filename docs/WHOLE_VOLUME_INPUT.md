# Input volume bilangan bulat — 20 November 2026

Permintaan pengguna: volume pada Kesepakatan Harga, BA RP, pengajuan pembayaran dan dokumen lain yang mempunyai isian volume harus berupa angka bulat tanpa koma. Aturan ini menggantikan izin input tiga desimal pada catatan lama, khusus penyusunan/perubahan input baru.

## Perilaku

- Ketik `1000`, tanpa pemisah ribuan. Isian hanya menerima digit ASCII0–9, dengan keyboard angka pada perangkat mobile. Koma, titik, tanda negatif/positif, huruf termasuk notasi `1e3`, spasi dan angka lebih dari1miliar ditolak.
- Salin-tempel/input utuh `1,5` atau `1.5` ditolak; tidak dibersihkan menjadi15 dan tidak dibulatkan/dipotong menjadi1atau2. Tombol koma/titik/huruf/tanda ditahan dan pesan ditampilkan. Koreksi berikutnya dengan digit yang valid memulihkan isian.
- Nilai0 tetap boleh pada komponen yang tidak digunakan. BOQ dan komponen terpilih tetap membutuhkan volume positif; aktual membutuhkan tagihan positif. Batas ketersediaan/komitmen/BOQOwner tetap diperiksa terpisah oleh domain.
- Berlaku pada BOQ Plan (form dan uploadExcel), volume Material/Jasa BOQMandor, MaterialNonStock tambahan, jumlah BOP, volume penugasan/kesepakatan BA KH, aktual BA RP/pembayaran, pelepasan dan alokasi PP lanjutan. SPK/BA PP dan lampiran memakai volume sumber; tidak menambah isian volume baru pada dokumen tersebut.
- Homepass/HK/nominal/persentase mengikuti aturan masing-masing yang sudah ada. Perubahan ini khusus kuantitas fisik/rincian anggaran; tidak mengubah rumus harga, PPh, kasbon atau pelunasan.

## Validasi dan penyimpanan

`whole-volume.ts` dan `VolumeInput` membagi aturan parser/isian. `validVolume` dipakai pada mutasi frontend; Laravel `WholeVolume::number` menolak pecahan, string angka, nilai nonfinite, negatif dan >1miliar. JSON API tetap mengirim angka, bukan string berformat. Validasi tidak memakai pembulatan atau mengandalkan atribut HTML `step` saja.

Input BOQ disaring sebelum disimpan, kesepakatan sebelum save/generate/attest editable dan submit paket, aktual sebelum save/attest/pengajuan, pelepasan/alokasi sebelum perubahan ledger. `ProjectPlanBudget::apply(..., adoptMaterialRevenue:true)` yang dimiliki server memeriksa kuantitas editable; mode maintenance lama tetap presisi tersimpan. HargaMandor tetap memakai validasi harga sebelumnya, tidak dialihkan ke validasi volume atau dibulatkan baru. Worker BOQ memakai parser yang sama: hasil rumus tersimpan yang pecahan juga ditolak dengan posisi selG; tidak mengeksekusi rumus atau mengambil total Excel sebagai sumber.

Perubahan hanya scalar validation dan komponen React. Tidak ada SQL/API/schema/migration/indeks/dependency/poller baru. Helper volume0query; loop mengikuti jumlah baris yang sudah diproses dan referensi kapasitas existing. Kunci status, Branch/unit, private files, revision/receipt/audit, source hash, tujuh/lima approval, harga dan konservasi ledger tetap.

## Data lama

Tidak ada conversion/backfill/GET write atau perubahan tipe kolom volume SQL. Decimal yang sudah ada dibutuhkan untuk menjaga snapshot, saldo dan riwayat lama; tidak dipotong hanya agar tampilan terlihat bulat. Preview/Word/print lama tetap membaca sumber asli.

BA KH Terbit yang hanya disahkan dan paket pending yang sudah dibekukan dapat menyelesaikan approval menggunakan snapshot/manifest semula, termasuk volume pecahan historis. Validator baca/sumber SPK tetap mempertahankan presisi lama; ini tidak mengizinkan input baru memalsukan status Sah/Terbit untuk melewati pemeriksaan. Draf yang berisi pecahan dan disimpan ulang/diajukan harus diperbaiki secara eksplisit. Sisa pecahan historis tidak otomatis dilepas/dihapus/ditutup, dan tidak mengarang volume agar pembayaran Final menjadi cocok.

Skenario offline sintetis yang dibangun oleh `seed.ts` kini menghasilkan input bulat sebelum memanggil domain, agar contoh tidak gagal saat bootstrap. Fixture pengujian input baru juga memakai bilangan bulat; fixture JSON historis asli tidak diubah dan regresi terpisah memastikan pecahan tersimpan tetap terbaca. Tidak menyentuh database its aktif atau data akun pengguna.

## Penerapan

Deploy kode dan build aset seperti biasa (`npm run build`, `npm run check:assets`). Tidak perlu migration khusus fitur ini. Setelah memperbarui handler/domain upload, restart **worker upload Area3 saja** sesuai konfigurasi server, agar parser BOQ tidak memakai kode lama. Pada aaPanel yang memakai nama program terdahulu: `supervisorctl restart area3-uploads`. Agen tidak menjalankan deploy/restart atau mengubah .env/database aktif. Upload yang sudah gagal tetap sebagai riwayat; jangan reset approval/SQL atau antrean aplikasi lain.

## Verifikasi dan batas

Suite PHP terisolasi381tes/9330assertions lulus, ditambah regresi terakhir paket pending berisi1.5dan helper/HTTP tanpa rewrite. Frontend417tes termasuk parser, render komponen dan integrasi form nyata lulus; TypeScript/Vite/Pint/diffcheck dan pemeriksaan aset dicatat dalam [QA](qa/whole-volume-input-20261120.json).

Helper0query; penolakan HTTP18query (budget35), snapshot tabel bisnis/approval/volume/receipt/audit dan revision sebelum/sesudah identik. Regression500BOQ/1000komponen existing tetap59query; bukan jaminan bebas bottleneck atau benchmark MySQL produksi.

Chrome terisolasi memeriksa komponen yang sama pada5label×375/768/1440×light/dark: digit/batas0dan1miliar, tombol koma/titik/e/tanda, input utuh invalid termasuk pecahan/notasi/spasi, pemulihan dan disabled histori1.5. Tidak ada callback perubahan untuk input invalid/JSerror/pageoverflow. Screenshot mobilelight dan desktopdark diperiksa. Input utuh disimulasikan lewat browser fill; bukan uji clipboard OS nyata. Ini fixture komponen, bukan sesi login/aplikasi/server produksi; request font luar diblokir. Tidak mengirim command/API bisnis, login, pesan, transfer atau menulis its aktif. Tidak render ulang Word/PDF karena generator/template tidak diubah; tidak melakukan MySQLDDL/concurrency/loadtest.

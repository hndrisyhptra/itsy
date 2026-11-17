# Anggaran, margin dan penutup Project Plan

## 17 November 2026 — Material BOQ Mandor masuk Revenue pada margin PP

Pengguna meminta total **Jml_Mat pada BOQ TA–Mandor** masuk ke baris **1.1 Material** dan dijumlahkan menjadi Revenue. Aturan ini menggantikan Revenue hanya dari Jasa Owner untuk PP v3 baru/draf yang disimpan atau diajukan ulang.

| Baris | Rumus terbaru |
| --- | --- |
| 1.1 Material | Total volume Material Mandor × harga Material Mandor per baris, sesuai kebijakan nominal PP |
| 1.2 Jasa | Total Jasa BOQ TA–Telkom / Owner yang sudah berlaku |
| 1 Revenue | 1.1 Material + 1.2 Jasa |
| 2 COGS | Total IV.1 + IV.2 + IV.3, tetap; perizinan reimbursement terpisah |
| Gross Profit Margin (nilai) | Revenue − COGS |
| Swakelola / persentase setiap baris | Nilai ÷ Revenue ×100%; Revenue nol tetap “—” |

Sumber Material adalah **Mandor**, bukan Material BOQ Owner atau Material Non Stock. Baris Material nol pada rumus baru tampil Rp0. Material Mandor tetap masuk biaya IV.1/COGS satu kali; tidak menggandakan rincian, reimbursement atau anggaran. Harga/volume tetap dibatasi per komponen terhadap BOQ Plan. Owner BOQ, `project_plans.budget`, Header dari IV.5, kasbon/PPh/pembayaran/ledger dan alur approval tidak berubah.

Laravel membentuk `planBudget.marginPolicy=mandor-material-revenue-v1` pada save/submit v3 yang memang bisa diedit, termasuk pembuatan PP lanjutan. Nilai total dan policy milik server; client tidak bisa menolak/memalsukan rumus. React memakai policy yang sama untuk draf dan menampilkan baris Material serta rumus yang benar. Perubahan harga/volume otomatis memperbarui Revenue/Gross Profit/margin. PP lanjutan memakai Mandor dan alokasi PP itu sendiri, bukan anggaran induk penuh.

Preview PP, DOM sumber Word/PDF dan layar memakai `BudgetPlanSection` yang sama. PP Diajukan/Sah tetap memakai `planBudgetTotals` dan policy yang tersimpan, tanpa backfill atau read-time rewrite. Dokumen historis tanpa marker mempertahankan Revenue Jasa Owner/baris Material kosong. Setelah penolakan/pengembalian, penyimpanan eksplisit draf yang diizinkan mengadopsi rumus terbaru tanpa menghapus riwayat. Maintenance pemotongan pecahan tetap memakai policy yang tersimpan; tidak otomatis mengadopsi perubahan margin. Tidak ada kolom SQL atau migration baru; marker/totals memakai JSON dokumen existing dan rincian budget existing.

Contoh vector bersama: Owner Material1.000 (bukan sumber Revenue Material), Owner Jasa2.000, Mandor Material40, Mandor Jasa100, Non Stock25,03, BOP30,02. Revenue2.040; COGS195,05; Gross Profit1.844,95; margin90,44%. Perizinan7,03 tetap terpisah. Policy rupiah penuh memakai sumber terpotong yang sama seperti sebelumnya. Harga Material10→25 pada volume4 mengubah Material40→100 dan Revenue2.040→2.100; Gross Profit tetap1.845 pada fixture rupiah penuh karena komponen Material ikut pada biaya.

Performa: memakai akumulasi `mandorM` yang sudah ada, satu tambahan penjumlahan pada formula, tanpa SQL/endpoint/private lookup/katalog tambahan atau pencarian per baris. Uji layanan murni **0 query**. Fixture HTTP pembaruan draf dua komponen **83 query**, budget90 termasuk autentikasi/reference/incremental writes/audit/receipt/response; master price reads tetap batched (maksimal2), bukan per baris. Ini bukan benchmark latency/load produksi; tidak mengklaim total HTTP0 query.

Verifikasi: **313 tes PHP /7278 assertions**, **346 frontend**, TypeScript/Vite, assets41manifest refs/32preloads×3profil, Pint lima file dan diff check lulus. Vector lama dipertahankan, vector expectedMaterialRevenue baru dibagi PHP/TS. Tes mencakup canonical policy/forged totals, SQL document_data dan budget row readback, source edits, zero/Material-only, continuation, frozen history, margin negatif dan tanpa pembagian nol. Browser sintetis memeriksa perubahan harga/volume langsung, preview baru vs historis,375/768/1440 light/dark tanpa overflow,0JSerror/0API request; screenshot diperiksa. Selector tabel terakhir pada skrip browser diperbaiki setelah timeout; bukan kesalahan rumus.

Cadangan SQLite demo sebelum perubahan: `storage/app/private/backups/area3-before-mandor-revenue-20261117.sqlite`,233472byte,SHA256e2c39eba72710da656307627909f32267b6738c861509a3a70c78bddf8bb10bc. Tidak mengubah MySQL its aktif, .env, snapshot/arsip, Dompis, atau menjalankan migration/deploy/restart worker. Server/akun live, Microsoft Word desktop, render Word/PDF baru, cetak fisik dan concurrent load belum diverifikasi pada perubahan angka ini; pengujian export existing tetap lulus. [Bukti QA](qa/pp-mandor-material-revenue-20261117.json).


## Perapian BOQ preview dan unduhan — 15 November 2026

Kedua tabel BoQ Owner/Mandor pada preview, cetak/PDF dan Word kini lebih kecil: font/padding rapat, proporsi kolom terpisah dan kode panjang membungkus tanpa mengubah teks/nominal. Input editor tetap pada ukuran sebelumnya. Pengguna mengonfirmasi tabel persetujuan penutup tetap sesuai; tidak dikembalikan/diubah. Header mengikuti Total Anggaran IV.5 seperti sebelumnya. [Ukuran, arsitektur, verifikasi dan batas](PP_WORD_PRINT.md).

## Header mengikuti Total Anggaran — 15 November 2026

Total Nilai (Rp) Header PP kini mengambil **baris Total Anggaran terakhir IV.5**, termasuk perizinan bila ada, memakai helper yang sama dengan tabel. Form/summary/preview/Word/PDF konsisten; Rp0 sebelum rincian biaya terisi. Laravel tetap menjadi sumber rincian canonical saat simpan, tanpa field total client atau duplikat tersimpan. Proyeksi SQL BOQ/alokasi, COGS/subtotal/margin dan riwayat transaksi tidak diubah. [Pemetaan Header, penyimpanan dan bukti](PP_HEADER_BOQ_KHS.md). Aturan di bawah yang memisahkan subtotal dari grand total tetap berlaku.

## Total seluruh rincian poin IV.5 — 15 November 2026

Form, preview, cetak/PDF dan DOCX PP menambahkan baris terakhir **Total Anggaran**, yaitu jumlah setiap nominal rincian tabel satu kali, termasuk Perizinan bila ada. Baris total lama pada rekap versi2 diberi nama **Subtotal (IV.1 + IV.2 + IV.3)** untuk membedakan biaya proyek dari jumlah termasuk reimbursement. Contoh: subtotal Rp780.572 + perizinan Rp100.000 = Total Anggaran Rp880.572. Heading poin5, COGS, Gross Profit dan margin tetap memakai formula biaya proyek semula; perizinan tidak dimasukkan ulang ke COGS.

Pada tabel historis versi1, total terakhir menghitung setiap `usage.amount`, bukan menjumlahkan kolom total kategori yang dapat berulang pada beberapa termin. Baris tersimpan, jadwal dan snapshot historis tidak berubah. Jumlah dihitung sekali dengan skala integer `cents` yang sudah digunakan aplikasi, kemudian format rupiah yang sama; tidak mengubah kebijakan pemotongan uang/volume. Tabel kosong menghasilkan Rp0.

Presentasi saja di BudgetPlanSection: satu reduce O(jumlah rincian)/memori konstan dari data terotorisasi yang sudah dimuat,0SQL/API tambahan, tanpa backend/schema/migration/penulisan its atau backup DB baru. Word menangkap tabel preview yang sama tanpa kalkulasi ekspor terpisah.280PHP/5877assertions,289frontend/build/assets lulus; assertion existing mencakup total dengan/tanpa perizinan, dua termin kategori sama, posisi terakhir, preview/form dan COGS/margin/snapshot tetap. Fixture native Word4/5halaman (seluruh9PNG diperiksa), PDF4/6halaman current/nested dan375/768/1440 nonblank. [Bukti](qa/pp-budget-total-20261115.json). Akun server, desktop Microsoft Word, native print dialog dan cetak fisik belum diuji. Penjelasan historis di bawah dipertahankan; “total poin5” untuk rumus margin berarti subtotal biaya proyek, bukan grand total baru termasuk perizinan.

## Pembaruan terkini — 9 November 2026

PP baru dimulai **1. Header PP → 2. BOQ Plan**. Pada PP induk v3 yang masih editable, harga langsung mengikuti KHS SQL tanpa alasan/konfirmasi selisih; Header Total Nilai baca saja dan dihitung dari BOQ, Rp0 sebelum upload. Header lengkap cukup untuk draf; pengajuan tetap perlu BOQ/nilai positif dan seluruh persyaratan. Dokumen terkunci/v1/v2 serta alokasi lanjutan tetap dipertahankan. Migration CHECK draf Rp0 telah disiapkan, belum diterapkan agen pada its aktif. [Perilaku, migration, arsitektur/query dan bukti](PP_HEADER_BOQ_KHS.md). Penjelasan lama di bawah tetap menjadi catatan historis bila berbeda.


Pembaruan 6 November: approver keempat PP/Pembayaran baru menjadi **Mgr Project Support**; penutup PP baru memakai empat jabatan baku. Assignment/keputusan Business Support historis tetap berlaku. Aturan terkini: [PROJECT_SUPPORT_APPROVAL](PROJECT_SUPPORT_APPROVAL.md). Penjelasan sebelumnya di bawah bersifat historis bila berbeda.

Pembaruan 4 November 2026 mengikuti permintaan pengguna dan kolom/redaksi pada `01._PROJECT_PLAN_DIGITAL.v5.docx`. Dokumen Word sumber telah dibaca dan dirender tiga halaman; file sumber tidak diubah. Harga, nama proyek, nama pegawai, NIK dan stempel APPROVED pada contoh tidak dijadikan transaksi atau persetujuan nyata. Kurva S dihilangkan atas instruksi pengguna, walaupun masih ada dalam dokumen sumber.

## Anggaran & Margin — aturan terbaru 5 November 2026

1. **BoQ TA - Telkom / Owner Project** menampilkan BOQ Plan yang tervalidasi: Designator, **Uraian Pekerjaan**, **Satuan**, Material, Jasa, Volume, Jml_Mat, Jml_Jasa dan Total Harga. Ketiganya ditampilkan dalam kolom terpisah. PP lanjutan memakai alokasi komponen pada root, tanpa menggandakan BOQ. Jika volume Material/Jasa berbeda, sel Volume membedakan M/J. Harga nol tetap Rp0; komponen yang tidak tersedia ditandai “—”.
2. **BoQ TA - Mandor** memakai designator yang sama. Harga Material/Jasa dan volume per komponen diisi Admin; jumlah komponen, total serta persentase terhadap nilai Owner per baris dihitung. Harga dan volume masing-masing komponen tidak boleh melebihi sumber Owner. Ini perencanaan, bukan pembuatan BA KH/SPK atau reservasi ledger baru.
3. **Material Non Stock**: No, Material, Volume, Satuan, Harga Satuan dan Total Harga. Tambah/hapus baris tersedia; total = volume × harga satuan, dibulatkan per baris. Total dan % terhadap Total BoQ Mandor ditampilkan.
4. **BOP**: No, Uraian, Satuan, Jumlah dan Total Harga. Total Harga diisi langsung sesuai template; tidak dikalikan ulang dengan Jumlah. Tambah/hapus baris, total dan % terhadap Total BoQ Mandor tersedia. Sesuai permintaan terbaru, form Batas maksimal BOP dan Referensi Cost Structure beserta validasi wajib/plafonnya dihapus. Laravel mengabaikan nilai kedua field lama dari cache/payload dan tidak menyimpannya pada revisi draft baru. Nilai biaya tetap nonnegatif dengan presisi/batas teknis yang berlaku; rincian BOP wajib lengkap saat pengajuan. Field historis pada snapshot terkunci tidak ditulis ulang.
5. **Perizinan (Preliminary)**: input rupiah untuk reimbursement, terpisah dari rumus total poin IV.5.
6. **Total Kebutuhan Anggaran (IV.5)** = total BoQ Mandor (IV.1) + Material Non Stock (IV.2) + BOP (IV.3). Sesuai permintaan terbaru, Laravel membuat rekap otomatis dari rincian sumber: Jasa Mandor, Material BOQ Mandor bila ada, Material Non Stock, BOP dan Perizinan. Hanya kategori bernilai positif yang ditampilkan; nilai nol tidak menghasilkan baris. Kolom rekap: No, Kebutuhan Anggaran, Nilai dan Keterangan. **Tidak ada lagi pemilihan termin, tanggal, tambah/hapus atau pembagian nominal manual pada poin 5.** Perizinan diberi keterangan reimbursement terpisah dan tetap di luar total IV.1+IV.2+IV.3/COGS. Rekap bukan pengajuan Pembayaran, APM atau transfer.

Rekap memakai `planBudget.version = 2`. Setiap simpan/pengajuan v3 yang diizinkan membentuk ulang `usage` dari angka sumber dengan ID tetap per kategori/komponen. Laravel mengabaikan kiriman `usage`, `term`, `date`, total dan versi dari browser; draft lama yang memiliki baris kosong/tanggal salah tidak lagi terhambat validasi waktu penggunaan. Harga/volume per komponen, angka BOP, kelengkapan rincian sumber dan risiko tetap divalidasi. Perubahan source langsung diperhitungkan pada preview React dan dihitung ulang saat simpan oleh server.

Semua tabel form PP dibuat rapat, dengan garis vertikal/horizontal, angka rata kanan dan scroll di dalam panel. Kedua BOQ memisahkan designator, uraian dan satuan. Preview dokumen menggunakan grid tabel yang tercetak, header berulang dan teks yang dapat membungkus. Tabel historis tetap dapat dibaca; tidak dibuat tanda tangan atau data transaksi rekaan.

Nominal maksimal dua desimal; volume/jumlah maksimal tiga desimal. ID baris harus unik. Batas teknis 200 baris per tabel manual, 20 baris penandatangan, total anggaran/Owner sampai Rp1 triliun. Ini batas implementasi prototipe, bukan plafon bisnis resmi.

## Perhitungan margin

| Baris | Sumber/perhitungan |
|---|---|
| Revenue | Nilai Jasa pada BOQ Owner |
| Revenue Material | Kosong |
| Revenue Jasa | Nilai Jasa pada BOQ Owner |
| COGS | Total kebutuhan anggaran poin IV.5 |
| Material Stock | Kosong |
| Material Non Stock | Total IV.2 |
| Jasa | Nilai Jasa pada BOQ Mandor |
| Sewa | Kosong |
| Operasional | Total BOP IV.3 |
| Gross Profit | Revenue − COGS |
| Swakelola / Gross Profit Margin | Gross Profit ÷ Revenue × 100% |

Persentase setiap baris dihitung terhadap Revenue; Revenue nol menghasilkan “—”, tanpa pembagian nol. Margin negatif dipertahankan. Material pada BOQ Mandor, jika diisi, tetap termasuk total IV.1 dan COGS walaupun Material Stock/Sewa pada ringkasan kosong sesuai permintaan. Komponen rincian tidak ditambahkan lagi ke COGS. Tidak dibuat klaim margin lebih baik dibanding kemitraan karena data pembanding belum diberikan.

Laravel memakai BCMath dan half-up per komponen. React memakai skala integer BigInt untuk volume tiga desimal, harga dua desimal dan pembulatan rupiah, termasuk persentase negatif. Total tersimpan dari server menjadi acuan dokumen terkunci. Vector regresi bersama ada pada `tests/Fixtures/pp-budget-vector.json`: Owner Jasa 2.000; Mandor 140; Non Stock 25,03; BOP 30,02; Perizinan 7,03; COGS 195,05; Gross Profit 1.804,95; margin 90,25%. Perizinan tidak dihitung dua kali. Kasus margin −12,345% menjadi −12,35%.

## Risiko, teks tetap dan penandatangan

- V. Manajemen Risiko: checklist TINGGI, SEDANG, RENDAH dengan satu pilihan aktif, wajib saat pengajuan.
- VI. Komunikasi dan Dokumentasi: tujuh baris tabel stakeholder dari template, tidak dapat diedit.
- VII. Manajemen Kualitas dan VIII. Penutup: redaksi template tetap, disusun ulang oleh Laravel; perubahan client diabaikan.
- Tabel cetak berisi Keterangan, Nama/NIK, Jabatan, Tanggal, Tanda Tangan. Default penyusun Admin + lima approver. Pengguna dapat menambah/menghapus baris, mengubah label Dibuat/Diperiksa/Disetujui, memilih peran dari penugasan PP dan mengubah teks jabatan pada cetakan. Nama/NIK berasal dari akun, bukan nama pegawai contoh pada Word. Tanggal mengikuti penyusunan/keputusan aktual; sebelum keputusan tidak dibuat tanggal persetujuan.
- Nama/NIK pilihan disimpan sebagai snapshot server. Keputusan menyimpan nama/NIK aktor aktual; angka nol depan NIK dipertahankan. TTD tetap ruang kosong; status “Disetujui di aplikasi” hanya muncul bila keputusan role/user yang bersangkutan benar-benar ada. Tidak ada tanda tangan rekaan. Cap teks APPROVED pada preview sekarang mengikuti keputusan asli approver pada siklus aktif; cap/status kolom TTD disembunyikan saat cetak agar sel TTD basah benar-benar kosong.
- Menghapus semua baris cetak sekalipun tidak mengurangi lima tahap approval PP. Inbox dan otorisasi tetap mengikuti Header PP; pengajuan terkunci setelah dikirim.

## Penyimpanan dan kompatibilitas

Tidak ada migration atau perubahan konfigurasi database. Runtime aktif mengikuti MYSQL_RUNTIME.md: rincian disimpan ke `project_plan_budget_rows.row_data` dan snapshot `project_plans.document_data`, secara atomik bersama revisi/audit. Snapshot berisi: `planBudget`, `planBudgetTotals`, `riskLevel`, `closing`, `signatureRows`, `preparedBy`, `signatureUsers`. Ringkasan `costs` disusun dari rincian tervalidasi untuk kompatibilitas layar lama. Klien tidak dapat menetapkan total, identitas penyusun, snapshot pengguna atau status persetujuan.

Hanya v3 Draft/Dikembalikan/Ditolak yang dapat diperbaiki Admin mengadopsi rekap otomatis pada simpan/pengajuan berikutnya. Membaca PP tidak menulis ulang data. Draf v3 lama menyimpan biaya/catatan sebelumnya dalam `previousPlanning` saat adopsi; nilai tersebut tetap dapat dibaca sebagai catatan, bukan biaya aktif yang dihitung ganda. PP yang sedang Diajukan/Sah dan riwayat v1/v2 tetap dipertahankan. Budget versi 1 pada dokumen terkunci mempertahankan tabel termin/tanggal historis, termasuk saat dibaca dan disetujui, tanpa konversi otomatis. PP lanjutan baru sudah mendapat rincian dasar dari alokasinya; anggaran root/ledger tidak digandakan.

Cadangan sebelum perubahan: `storage/app/private/backups/its-before-pp-budget-v5-20261104-203240.sql`. Bukti kecocokan teks, hash cadangan dan hasil uji: `docs/qa/pp-budget-v5-20261104.json` dan `LARAVEL_QA.md`.

Output tetap HTML/cetak atau Simpan PDF browser. Belum ada generator DOCX/PDF server, validasi keaslian TTD atau pembayaran bank otomatis. Form nyata masih meminta login pada pemeriksaan browser; layout responsif dan pagination hasil cetak belum diverifikasi secara visual.

## Referensi untuk SPH Telkom Akses — 5 November 2026

Harga Material/Jasa pada BOQ TA–Mandor menjadi batas harga per satuan SPH Material/Jasa masing-masing designator. Nilai tepat pada batas boleh digunakan; SPH baru terisi dari harga BOQ Mandor. Kelebihan memunculkan peringatan dan ditolak server. Batas mengikuti PP yang dipilih, termasuk PP lanjutan. Rincian kompatibilitas dan pengujian: [SPH_THRESHOLD](SPH_THRESHOLD.md).


## Verifikasi rekap otomatis — 5 November 2026

Cadangan sebelum perubahan aturan: `storage/app/private/backups/its-before-automatic-budget-summary-20261105-211745.sql`, 1.019.899 byte, SHA-256 `b1fe8eac3610f42fbefac80b27555b73bdd4ebaf13fad227ef1be839b1617c58`. Percobaan sandbox yang gagal diberi akhiran `.failed` dan bukan cadangan yang digunakan.

**131 tes PHP / 2.369 assertions**, **96 tes frontend**, TypeScript/Vite build dan Pint pada ketiga file PHP yang diubah berhasil. Cakupan baru meliputi rekap sumber positif/zero, input usage palsu/usang, submit tanpa termin/tanggal, perubahan nilai sumber, alokasi PP lanjutan, snapshot historis, pemisahan kolom serta penyimpanan SQL rekap dan snapshot. Tes PHP tetap fail-closed SQLite `:memory:`; tidak menulis data uji ke its. Tidak ada migration atau penulisan ulang PP existing. Bukti: `docs/qa/automatic-budget-summary-20261105.json`.

Browser autentikasi, visual 375/768/1440, dark/light dan pagination cetak tidak diuji ulang karena izin akses pratinjau sebelumnya ditolak. Tes markup bukan verifikasi visual. Tampilan ini bukan simulasi data: akun tetap memakai rincian tersimpan MySQL melalui Laravel. Output tetap HTML/cetak browser; tidak mengklaim generator DOCX/PDF server atau integrasi pembayaran bank.


## Draft Header, BOP tanpa plafon, dan cap preview — pembaruan berikutnya 5 November 2026

Aturan terbaru supersede batas BOP dan persyaratan draft lama. PP v3 dapat disimpan Draft setelah Header lengkap meskipun BOQ kosong, HK belum lengkap, risiko atau approver belum dipilih. Pengajuan tetap memerlukan BOQ positif, lima HK yang valid dengan total positif, anggaran/risiko dan lima approver. Draft tersimpan ke tabel bisnis dan muncul di list; PP baru tidak memuat cache bersama dari PP sebelumnya. Cap preview menggunakan keputusan SQL nyata dan tidak tercetak. Rincian: [PP_DRAFTS_PREVIEW](PP_DRAFTS_PREVIEW.md).

Verifikasi terbaru **140 tes PHP / 2.553 assertions**, **110 tes frontend**, build dan scoped Pint berhasil. Bukti lama di atas adalah hasil iterasi sebelumnya; pemeriksaan visual browser/cetak belum diulang.

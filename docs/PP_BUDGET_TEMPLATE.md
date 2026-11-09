# Anggaran, margin dan penutup Project Plan

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

# Anggaran, margin dan penutup Project Plan

Pembaruan 4 November 2026 mengikuti permintaan pengguna dan kolom/redaksi pada `01._PROJECT_PLAN_DIGITAL.v5.docx`. Dokumen Word sumber telah dibaca dan dirender tiga halaman; file sumber tidak diubah. Harga, nama proyek, nama pegawai, NIK dan stempel APPROVED pada contoh tidak dijadikan transaksi atau persetujuan nyata. Kurva S dihilangkan atas instruksi pengguna, walaupun masih ada dalam dokumen sumber.

## Anggaran & Margin

1. **BoQ TA - Telkom / Owner Project** menampilkan BOQ Plan yang tervalidasi: Designator, Material, Jasa, Volume, Jml_Mat, Jml_Jasa dan Total Harga. PP lanjutan memakai alokasi komponen pada root, tanpa menggandakan BOQ. Jika volume Material/Jasa berbeda, sel Volume membedakan M/J. Harga nol tetap Rp0; komponen yang tidak tersedia ditandai “—”.
2. **BoQ TA - Mandor** memakai designator yang sama. Harga Material/Jasa dan volume per komponen diisi Admin; jumlah komponen, total serta persentase terhadap nilai Owner per baris dihitung. Harga dan volume masing-masing komponen tidak boleh melebihi sumber Owner. Ini perencanaan, bukan pembuatan BA KH/SPK atau reservasi ledger baru.
3. **Material Non Stock**: No, Material, Volume, Satuan, Harga Satuan dan Total Harga. Tambah/hapus baris tersedia; total = volume × harga satuan, dibulatkan per baris. Total dan % terhadap Total BoQ Mandor ditampilkan.
4. **BOP**: No, Uraian, Satuan, Jumlah dan Total Harga. Total Harga diisi langsung sesuai template; tidak dikalikan ulang dengan Jumlah. Tambah/hapus baris, total dan % terhadap Total BoQ Mandor tersedia. Pengguna mengonfirmasi batas berupa **nilai rupiah**. Admin mengisi batas maksimal dan referensi Cost Structure; server menolak kelebihan batas. Bila terdapat BOP, keduanya wajib sebelum pengajuan. Belum ada master plafon resmi yang diberikan.
5. **Perizinan (Preliminary)**: input rupiah untuk reimbursement, terpisah dari rumus total poin IV.5.
6. **Total Kebutuhan Anggaran (IV.5)** = total BoQ Mandor (IV.1) + Material Non Stock (IV.2) + BOP (IV.3). Admin mengisi baris rencana penggunaan: Kebutuhan Anggaran, Termin Bayar/Reimburse, Tanggal, Nilai. Tambah/hapus tersedia; Total Nilai per kategori dihitung. Draf boleh belum lengkap, tetapi tidak boleh melebihi alokasi kategori. Pengajuan memerlukan tanggal/termin/nilai positif dan jumlah rincian tepat sama dengan setiap kategori, termasuk reimbursement perizinan terpisah. Baris ini tidak membuat pengajuan Pembayaran, APM atau transfer bank. Saat dropdown memilih Material Non Stock, BOP atau Perizinan, Nilai otomatis diisi dari total kategori yang sudah diinput. Jika ada alokasi kategori yang sama pada baris lain, Nilai diisi sisa anggarannya, dengan pembulatan sen dan batas bawah nol. Baris yang sedang dipilih dikecualikan dari perhitungan alokasi agar pemilihan ulang tidak mengurangi nilainya sendiri. Nilai tetap dapat diedit untuk pembagian termin; pemilihan Jasa Mandor mengawali isian manual dari nol. Pengisian otomatis dipicu pemilihan dropdown dan tidak menulis ulang pembagian yang tersimpan saat PP dibuka.

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
- Nama/NIK pilihan disimpan sebagai snapshot server. Keputusan menyimpan nama/NIK aktor aktual; angka nol depan NIK dipertahankan. TTD tetap ruang kosong; status “Disetujui di aplikasi” hanya muncul bila keputusan role/user yang bersangkutan benar-benar ada. Tidak ada gambar/stempel tanda tangan otomatis.
- Menghapus semua baris cetak sekalipun tidak mengurangi lima tahap approval PP. Inbox dan otorisasi tetap mengikuti Header PP; pengajuan terkunci setelah dikirim.

## Penyimpanan dan kompatibilitas

Tidak ada migration atau perubahan konfigurasi database. Data tambahan tetap di agregat JSON PP: `planBudget`, `planBudgetTotals`, `riskLevel`, `closing`, `signatureRows`, `preparedBy`, `signatureUsers`. Ringkasan `costs` disusun dari rincian tervalidasi untuk kompatibilitas layar lama. Klien tidak dapat menetapkan total, identitas penyusun, snapshot pengguna atau status persetujuan.

Hanya v3 Draft/Dikembalikan yang disimpan pengguna mengadopsi format baru. Membaca PP tidak menulis ulang data. Draf v3 lama menyimpan biaya/catatan sebelumnya dalam `previousPlanning` saat adopsi; nilai tersebut tetap dapat dibaca sebagai catatan, bukan biaya aktif yang dihitung ganda. PP terbit/ditolak serta riwayat v1/v2 tetap dipertahankan. PP lanjutan baru sudah mendapat rincian dasar dari alokasinya; anggaran root/ledger tidak digandakan.

Cadangan sebelum perubahan: `storage/app/private/backups/its-before-pp-budget-v5-20261104-203240.sql`. Bukti kecocokan teks, hash cadangan dan hasil uji: `docs/qa/pp-budget-v5-20261104.json` dan `LARAVEL_QA.md`.

Output tetap HTML/cetak atau Simpan PDF browser. Belum ada generator DOCX/PDF server, validasi keaslian TTD atau pembayaran bank otomatis. Form nyata masih meminta login pada pemeriksaan browser; layout responsif dan pagination hasil cetak belum diverifikasi secara visual.

# Header PP → BOQ Plan dan harga KHS otomatis — 9 November 2026

## Otoritas Header terbaru — 15 November 2026

Sesuai permintaan pengguna, **Total Nilai (Rp) pada Header PP mengikuti baris Total Anggaran terakhir poin IV.5**, termasuk perizinan bila ada. Ini menggantikan pemetaan tampilan Header dari BOQ Owner pada ketentuan9November di bawah. Urutan Header→BOQ, harga KHS otomatis, validasi draft/pengajuan, penomoran dan approval tetap.

`budgetRecapTotal` menjumlahkan setiap `planBudget.usage[].amount` satu kali dengan skala integer yang sudah dipakai, dan dipakai bersama oleh tabel poin5 serta `projectPlanHeaderTotal`. Form menghitung rencana editable melalui applyBudgetPlan; Header/summary pengajuan/preview/Word/PDF memakai total tersebut. Saat belum ada rincian hasilnya Rp0; upload BOQ saja belum mengisi biaya Mandor. Perubahan Mandor/Non Stock/BOP/perizinan langsung memperbarui Header. PP lanjutan memakai rincian anggarannya sendiri, bukan anggaran root. Data terkunci dibaca dari rincian tersimpan tanpa repricing/snapshot rewrite. Dokumen lama tanpa planBudget mempertahankan nilai lamanya.

**Penyimpanan:** rincian poin5 sudah dikanonisasi Laravel saat save/submit dan disimpan pada document_data. Tidak menambah field Header dari client atau menyimpan duplikat total. `project_plans.budget` tetap proyeksi BOQ/alokasi untuk dashboard/ledger/lanjutan; tidak diubah menjadi total biaya, tidak ada backfill. Header setelah reload mengambil rincian server tersebut. COGS tetap IV.1+IV.2+IV.3 di luar reimbursement; rumus margin dan seluruh tagihan/approval/APM tidak berubah.

Review query:0SQL/API tambahan, satu passO(usage rows)/memori konstan, tanpa master/account lookup, schema/migration/live write atau backup DB baru untuk presentasi.280PHP/5880assertions/289frontend/build/assets32manifest/25preload×3profil lulus. Existing semantic regressions memeriksa form/preview/saved draft/zero/edit/legacy repeated category dan BOQ unchanged; PHP memastikan recap palsu tetap diganti canonical source. Native Word4/5halaman CRC/XML valid: Header=recap Rp880.572/Rp4.880.572;2halaman Header diperiksa visual,7halaman lain identik hash dengan render yang sebelumnya diperiksa. PDF current/nested4/6halaman dan375/768/1440 nonblank. [Bukti](qa/pp-header-recap-20261115.json). Akun server/desktop Word/native print dialog/cetak fisik belum diuji.

Permintaan pengguna terbaru menggantikan BOQ-first, Total Nilai manual positif saat Header, serta alasan/konfirmasi selisih untuk PP induk v3 yang masih dapat diperbaiki. Aturan historis v1/v2 dan dokumen terkunci tidak diganti.

## Form dan penyimpanan

1. **Header PP** menjadi langkah pertama dan terbuka saat form baru dibuka. ID Project, Branch, Unit, Nama Kegiatan dan Saat Penggunaan tetap wajib agar draf dapat disimpan; No PP tetap diberikan Laravel. Lima approver boleh dilengkapi kemudian.
2. **BOQ Plan** menjadi langkah kedua, tetap upload XLSX nyata melalui antrean privat. Sesudah hasil berhasil dibaca/digunakan, Total Nilai pada Header langsung mengikuti total BOQ pada form. Upload belum selesai bukan BOQ berhasil dan tidak menyimpan PP secara otomatis.
3. Total Nilai v3 baca saja. Sebelum BOQ nilainya Rp0; perubahan volume, penghapusan atau penggantian BOQ menghitung ulang nilai. **Simpan Draft/Ajukan PP** yang terkonfirmasi menyimpan total pada `project_plans.budget` dan snapshot `document_data`. PP lanjutan mempertahankan nilai alokasi tersimpannya.

Pada setiap simpan/pengajuan PP induk v3 yang editable, Laravel mengambil harga Material/Jasa serta versi KHS aktif dari katalog SQL saat ini. Harga/nilai total/flag review/alasan dari client tidak menentukan harga resmi. Harga Excel, baris/kode/volume sumber, hash dan file asli tetap menjadi pembanding/provenance dari manifest privat server. Tidak ada perubahan harga master atau workbook untuk menyamakan selisih. Selisih tidak membutuhkan alasan/checkbox dan tidak menahan pengajuan.

`boqPricingPolicy=khs-auto-v1` disimpan sebagai metadata server pada snapshot PP. `reviewed=true` dan catatan sistem pada baris berarti harga sudah dikanonisasi otomatis, **bukan** bukti pengguna melakukan review/approval. Parser mentah tetap menyediakan penanda perbandingan untuk kompatibilitas historis; form/save v3 menerapkan kebijakan otomatis. Tidak ada approval/TTD baru yang diada-adakan.

Material/Jasa tetap terpisah, `null`/komponen tidak tersedia berbeda dengan harga nol. Kuantitas/ketelitian/batas 500 komponen, KHS tidak dikenal/nonaktif atau belum tersedia tetap divalidasi. Rumus hanya dibaca cached numeric value-nya sesuai aturan BOQ_IMPORT, tidak dieksekusi. Harga/volume Mandor tetap dibatasi komponen Owner; perubahan master yang menurunkan plafon tidak otomatis memangkas isian Mandor.

**Ajukan PP** tetap membutuhkan BOQ dengan volume dan total positif, jadwal, rincian anggaran/risiko serta kelima akun aktif sesuai Branch. Draf Rp0 tidak membuka BA KH/SPK/Pembayaran. Revisi eksplisit draf/returned/rejected v3 dapat mengadopsi KHS terbaru; GET, perubahan master atau deploy tidak menulis ulang PP diajukan/Sah, riwayat approval, SPK atau pembayaran. v1/v2 mempertahankan syarat historisnya, termasuk review selisih v2.

React menghitung umpan balik dari katalog sesi yang sudah dimuat. Jika master diubah bersamaan di sesi lain, pratinjau sebelum refresh dapat sementara menampilkan versi lama; Laravel tetap mengambil versi SQL saat simpan dan mengembalikan snapshot resmi. Konflik revisi tetap harus diselesaikan, bukan di-commit otomatis.

## Migration dan deployment

Migration `2026_11_09_000001_allow_zero_budget_v3_project_drafts.php` mengganti CHECK `pp_budget_positive` dalam satu ALTER: `budget > 0 OR (budget = 0 AND status = 'DRAFT' AND workflow_version = 3)`. Target tetap MySQL/MariaDB **its** atau SQLite memory testing. Tidak ada update/backfill transaksi. Rollback menolak bila draf Rp0 masih ada; tidak menghapus atau mengarang nilai draf.

Setelah kode dipasang di lingkungan tujuan, jalankan dengan binary PHP situs yang sesuai:

```sh
php artisan migrate --force
php artisan queue:restart
```

Pasang juga hasil `npm run build` dari revisi yang sama. Migration ini **belum dijalankan agen pada its lokal/server**. Tidak mengubah `.env`, data master/akun/transaksi aktif atau aplikasi Dompis Prov. Cadangan its sebelum perubahan: `storage/app/private/backups/its-before-header-khs-auto-20261109-072825.sql`, 2.083.416 byte, SHA-256 `456f9bee49560f701c5f5ee95c8d6d812c457a3c53c2c8239016e4bba9eaed8c`.

## Arsitektur dan tinjauan performa

Alur tetap form → command idempotent/revision → manifest privat + katalog SQL → domain → RelationalWorkflowStore/receipt/audit atomik. Arsip `prototype_workspaces.state` tidak ditulis ulang. Tidak ada endpoint, tabel baru, query harga per baris, fetch per sel atau library runtime baru. Migration hanya mengubah CHECK.

PHP mengindeks KHS, sumber import dan ID baris sekali, serta baris Mandor pada validasi budget. React menggunakan Map/Set untuk pricing, BOQ editor dan referensi Owner/Mandor, menggantikan pencarian berulang per baris. Normalisasi data yang dimuat bersifat linear terhadap referensi/baris, dengan beberapa lintasan per render; belum merupakan virtualisasi tabel.

Regresi domain PHP/TS 500 komponen melarang linear KHS find/record per baris; PHP domain menambah **0 SQL**. Fixture HTTP 26 komponen, termasuk GET revision + simpan, dibatasi **220 query total** dan tepat **2 query katalog harga** (satu tiap pembacaan state/command), sambil menguji proyeksi total SQL dan sumber privat. Penulisan normalized BOQ/ledger tetap mengikuti banyaknya baris. Pembacaan aggregate/catalog penuh dan render layar lama tetap ada; tidak mengklaim semua request konstan, peningkatan throughput universal atau load test MySQL.

## Verifikasi dan batas

Lihat [LARAVEL_QA](LARAVEL_QA.md) dan [bukti terstruktur](qa/pp-header-boq-khs-20261109.json) untuk hasil akhir. Uji mencakup mismatch tanpa konfirmasi, client forging, KHS berubah saat draf, snapshot submitted tetap, total SQL setelah volume/hapus, Rp0 sebelum upload, urutan tab/read-only, legacy v2, PP lanjutan, ceiling Mandor, nullable/zero, formula/source serta guards SQL dan rollback.

Tes menggunakan fail-closed SQLite `:memory:` dan disk privat palsu. Ekspresi CHECK dieksekusi pada tabel test SQLite; SQL ALTER MySQL/MariaDB diverifikasi dengan mock, bukan migration pada engine produksi. Browser autentikasi/responsif/keyboard, Microsoft Word/cetak dan beban banyak pengguna belum diuji ulang. Upload/pricing/SQL merupakan implementasi nyata pada aplikasi login; contoh BOQ tetap simulasi offline saja dan tidak tersedia pada akun aktif.


## 19 November 2026 — Aturan tanggal dokumen terbaru

Lihat [DOCUMENT_ISSUANCE_DATES.md](DOCUMENT_ISSUANCE_DATES.md). Pengguna menetapkan PP ≤ BA KH ≤ SPK ≤ awal pekerjaan; tanggal sama boleh, jarak1hari hanya saran. Ini menggantikan aturan18November tentang tanggal SPK bebas untuk dokumen baru/draf tersimpan eksplisit. Jadwal PPv3 memakai Tanggal Mulai Pekerjaan, tidak lagi Saat Penggunaan, dan akhir dihitung HK inklusif. Sah/Terbit/paket unmarked yang sedang approval tidak ditulis ulang; PP VIII tanggal tetap kosong. Marker/dua field JSON dan tabel tahap existing, tanpa migration/SQLhelper/API baru. Bukti serta batas pengujian ada di dokumen terbaru dan QAJSON.

## 19 November 2026 — Koreksi Header tanpa Tanggal Terbit PP

Permintaan pengguna menghapus tambahan Tanggal Terbit PP pada Header. Pengguna juga menegaskan tanggal dokumen dapat disesuaikan selama tidak melampaui jadwal/HK; urutan wajib dan batas awal pekerjaan yang dikonfirmasi sebelumnya tetap berlaku. Form Header dan tabel Header pada preview/Word/cetak/paket PP tidak lagi menampilkan kolom tersebut. **Saat Penggunaan** menjadi acuan tanggal PP untuk penyusunan/revisi editable, sementara awal pekerjaan tetap dipilih terpisah pada Jadwal Proyek dan akhir dihitung HK inklusif.

Server/TypeScript `planning` memaksakan internal `issueDate = date` pada penyimpanan editablev3; `issueDate` client diabaikan dan tidak menjadi isian wajib tersembunyi. Validasi mengarahkan pengguna ke Saat Penggunaan atau awal pekerjaan, bukan form yang sudah dihapus. PP ≤ BA KH ≤ SPK ≤ awal pekerjaan, equality boleh, jarak1hari hanya saran. Tanggal BA KH/SPK tetap dapat dipilih manual dalam batas tersebut. Field internal lama tidak dihapus/backfill: Sah/Terbit/paket pending memakai snapshot/manifest/keputusan tersimpan, tidak dinilai ulang dari Saat Penggunaan pada GET/preview/Head. VIII.PENUTUP tetap kosong, Branch place dan blank wetTTD tetap.

Menggunakan JSON/tabel yang sama,0SQLhelper/API/schema/migration/dependency baru. Perubahan kode lokal, tanpa liveits/MySQL/.env/daemon/server/Dompis write. Hasil dan keterbatasan QA terbaru dicatat di [audit koreksi](qa/document-issuance-no-header-20261119.json); hasil penanggalan awal di bagian sebelumnya tetap sebagai riwayat.

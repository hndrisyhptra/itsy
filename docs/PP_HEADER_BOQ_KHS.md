# Header PP → BOQ Plan dan harga KHS otomatis — 9 November 2026

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

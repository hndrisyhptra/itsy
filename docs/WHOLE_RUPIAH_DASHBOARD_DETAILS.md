# Rupiah bulat dan rincian angka dashboard — 11 November 2026

## Digantikan untuk pemotongan nominal — 12 November 2026

Pengguna menegaskan nilai tersimpan harus dipotong pecahannya, bukan dibulatkan. [TRUNCATE_STORED_MONEY](TRUNCATE_STORED_MONEY.md) menggantikan aturan half-up dan guard yang hanya menolak pecahan pada bagian di bawah. Migration data baru berjalan sebelum BIGINT, dengan audit terenkripsi dan pemeriksaan konsistensi. Matriks/modal/scoping tetap; catatan lama berikut dipertahankan sebagai riwayat implementasi.

Pengguna meminta semua nominal termasuk preview berkas tanpa desimal, perubahan tipe penyimpanan, dan modal rincian saat angka pada matriks dashboard diklik. Setelah klarifikasi, permintaan mencakup penyimpanan, bukan hanya format tampilan. Aturan ini menggantikan ketentuan uang dua desimal untuk dokumen baru/penyimpanan operasional yang kompatibel; volume, persentase, bukti sumber dan sejarah pembayaran tetap dibedakan.

## Nominal, pembulatan dan riwayat

- `currency.ts` menyediakan satu formatter rupiah tanpa pecahan untuk halaman, daftar, dashboard, laporan, kasbon dan preview. BA KH/SPK/BARP/lampiran native Word menggunakan formatter sama pada model konten bersama. Terbilang mengikuti rupiah yang ditampilkan, tanpa kata pecahan. Tidak mengubah template sumber atau mengarang tanda tangan.
- Nominal baru memakai **half-up**: 100,49 → 100; 100,50 → 101. Untuk angka negatif, pecahan setengah dibulatkan menjauhi nol. Quantity tetap sampai tiga desimal; HK tetap aturan kalender penuh; persentase margin tetap dapat pecahan.
- Marker server-owned `moneyPolicy=idr-whole-v1` dipasang pada penyimpanan v3 PP yang dapat direvisi, BA KH baru/draf, pembuatan/penyimpanan termin v3 dan PP lanjutan. Tidak diterima dari client untuk mengubah dokumen terkunci. Penerbitan/pengesahan BA KH yang sudah Terbit mempertahankan kebijakan snapshot lama.
- Perhitungan baru membulatkan **masing-masing komponen Material/Jasa pada setiap baris**, lalu menjumlahkan. Contoh volume 0,005 × harga 100 pada kedua komponen: Material 1, Jasa 1, Total 2. BOQ/Header, anggaran, BA KH/SPK, tagihan, lampiran dan katalog pemilih SPK memakai kebijakan sama. Laravel menggunakan BCMath; frontend memakai skala integer/BigInt pada hasil baris. Volume, harga satuan dan nilai total adalah jenis angka berbeda.
- Harga satuan, BOP/permit/rincian anggaran uang pada save editable dinormalisasi; input harga/form uang memakai step1. KHS CRUD/bulk import menerima rupiah bulat (nol valid), menolak pecahan harga baru. Pemeriksaan harga/volume/batas Mandor/KHS dilakukan pada input asli sebelum pembulatan agar kelebihan 0,01 tidak lolos karena dibulatkan turun. Setelah pembulatan, validasi batas biasa tetap berjalan.
- Kasbon untuk termin dengan policy baru memerlukan rincian nominal bulat yang jumlahnya persis sama dengan tagihan kanonis. APM tetap melunasi penuh bersama bukti/otoritas yang sesuai; tidak membuat transfer bank otomatis.
- Dokumen submitted/Sah/terbayar tanpa marker mempertahankan perhitungan sen historis, keputusan dan jumlah pembayaran sebenarnya. Preview menampilkan rupiah bulat sesuai permintaan; GET tidak menulis ulang snapshot, rekening, jumlah terbayar, arsip workspace atau keputusan lama. Draf/returned yang disimpan ulang secara eksplisit memakai aturan baru melalui proses validasi/pengajuan yang sama. Tidak menyisipkan approval baru atau mengubah status.
- Raw Excel (file asli, `boq_import_rows.excel_material_price/excel_service_price`, harga pembanding/provenance pada JSON), histori audit/snapshot lama tidak dipotong atau ditulis ulang. Field sumber ini bukan otoritas harga operasional. Mereka tetap memakai presisi asal untuk audit. Tidak mengubah DECIMAL quantity, ledger, work_days atau persentase menjadi integer.

## Migration tipe kolom

`2026_11_11_000002_use_whole_rupiah_columns` mengubah **14 kolom uang operasional pada 10 tabel menjadi BIGINT signed**, mempertahankan nullable, identitas, relasi, CHECK/trigger dan nilai nol:

| Tabel | Kolom |
|---|---|
| designator_package_prices | price |
| khs_prices | material_price, service_price (nullable) |
| project_plans | budget |
| project_plan_costs | amount |
| boq_components | official_unit_price |
| contract_components | khs_ceiling_snapshot, sph_price, offered_price, agreed_price |
| payment_terms | approved_amount (nullable) |
| apm_settlements | amount |
| partial_payment_requests | amount |
| finance_kasbons | amount |

Tidak ada UPDATE pembulatan data lama, seed, import ulang atau penggantian JSON. Preflight memeriksa semua kolom tersebut **sebelum DDL pertama**. Tagihan historis SUBMITTED/APPROVED juga diperiksa dari snapshot agreement/aktual karena approved_amount pending masih NULL. Jika pecahan ditemukan, proses berhenti dengan daftar tabel/jenis konflik, tanpa mencoba mengubah tagihan atau memotong nilai. Perlu rekonsiliasi terpisah yang terikat pada transaksi dan audit sebelum menerapkan konversi; jangan memaksa ALTER/penghapusan data untuk melewati pemeriksaan.

SQLite perubahan kolom dapat membangun ulang tabel: migration mempertahankan dan memasang kembali trigger yang hilang, termasuk XOR otoritas APM. Relasi/aturan pembayaran tetap diuji. Pada MySQL/MariaDB DDL tidak seluruhnya transactional; jalankan saat maintenance, tanpa proses penulis lain. Jika DDL gagal setelah preflight, periksa kondisi aktual sebelum retry. BIGINT kompatibel dengan batas nominal aplikasi yang sudah ada. Down mengembalikan tipe DECIMAL; tidak menghapus policy atau transaksi.

## Deploy oleh operator

Kode/migration **belum diterapkan ke its aktif atau server oleh agen**. Urutan operator:

1. Cadangkan database server dan file upload privat. Pertahankan `.env`/APP_KEY dan data pengguna.
2. Saat maintenance, hentikan sementara penulis/worker its sesuai konfigurasi yang ada. Jangan menghentikan worker aplikasi lain.
3. Pull kode dan build aset lengkap; `public/build` diabaikan Git.
4. Pastikan migrasi kasbon sebelumnya tersedia. Pemeriksaan baca-saja: `php artisan area3:check-whole-rupiah`. Tidak menjalankan migration atau membulatkan data. Jika schema sebelumnya belum lengkap, perintah menjelaskan keterbatasan itu.
5. Sesudah backup dan pemeriksaan lulus, jalankan `php artisan migrate --force`. Migration memeriksa ulang; pending migration terdahulu dijalankan urut oleh Laravel. Jangan memakai migrate:fresh/reset/seed pada data aktif. Jika konflik pecahan dilaporkan, rekonsiliasi dahulu; jangan menganggap migration sudah berhasil.
6. Perbarui cache aplikasi sesuai prosedur deploy, muat ulang hanya worker its, buka maintenance setelah verifikasi. Uji akun/PP/termin/Finance dan klik matriks pada server.

Tidak menjalankan rencana koreksi Head PP0002 sebagai bagian deploy ini. Tidak mengganti password, identitas, role, nomor dokumen, master harga dari file contoh atau struktur Dompis.

## Modal rincian matriks

Semua enam angka pada Branch, subtotal Region dan TOTAL bisa diklik, termasuk nol. Accordion Region tetap memiliki tombol terpisah. Modal read-only menggunakan komponen Modal yang sama (Escape, trap/focus restoration), pencarian dan **20 baris per halaman**; tombol Detail membuka record yang tepat, bukan record pertama. Lihat PP dari ID Project memperlihatkan semua PP kode tersebut dalam scope yang dipilih, dengan tombol kembali ke daftar ID.

| Angka | Record modal |
|---|---|
| JMLH | Satu baris per kode ID Project unik, jumlah PP, OPEN/CLOSE, Branch, nilai |
| OPEN/CLOSE | ID Project unik yang sesuai klasifikasi matriks |
| Jumlah PP | Seluruh PP tersimpan termasuk draf dan lanjutan |
| Nilai Realisasi | Termin yang berkontribusi pembayaran tercatat, PP/SPK/jenis termin/APM/nilai dan link pembayaran |
| Total Nilai | PP induk sumber BOQ Plan tersimpan, termasuk nilai nol; lanjutan tidak menggandakan anggaran |

Mengikuti filter Branch dan scope Region/Branch/global. Klasifikasi ID melihat semua sibling PP yang boleh dilihat akun sebelum filter Branch, sesuai DASHBOARD_MATRIX. Kode kosong tidak dibuatkan ID baru; PP tanpa kode tetap tampil pada Jumlah PP. Kode lintas Branch/Region dideduplikasi pada subtotal/global. Tidak mencari atau menyimpulkan sibling tersembunyi. Pencarian hanya menyaring modal; tidak mengganti angka matriks yang dipilih.

## Arsitektur dan query

- `dashboardMatrix` menambahkan proyeksi metadata PP dan pembayaran kontributor pada read model berizin yang sama. Index Maps/Set tetap satu lintasan; `matrixDetailRows` memakai proyeksi itu, tidak membaca SQL/katalog/workspace/archive/private files. State/filter dimemoisasi; toggle accordion tidak menghitung ulang. Tidak ada API/SQL tambahan atau mutasi saat klik/pencarian/page. Modal tidak menampilkan rekening/NIK/path privat.
- Pemilih Sah SPK menambahkan relasi contract snapshot policy pada query amount yang sudah ada: whole policy menjumlah ROUND per component, legacy mempertahankan combined-line cent rounding. **Tetap4 service queries** pada fixture; tidak ada query per baris. Runtime validasi uang tidak menambah SQL.
- Preflight migration/CLI adalah operasi maintenance, bukan request web. Baseline kosong **11 SELECT** (10 exists tabel +1 query pending invoice). Snapshot tagihan dibaca dalam halaman200; halaman berikut diperlukan sesuai jumlah pending. Tidak memakai aggregate/katalog akun/arsip atau melakukan UPDATE/DDL dari perintah pemeriksaan.
- Model modal1000PP membuktikan indeks relasi dan batas20DOM; ini bukan throughput/latency/load benchmark produksi. Aggregate boot/refresh lama tetap batas arsitektur yang belum diganti.

## Bukti aktual dan batas

Backup lokal sebelum perubahan workflow: `storage/app/private/backups/its-before-whole-rupiah-20261111-094804.sql`, **2.119.431byte**, SHA256 `de70421ce9ed2b9d761b59b2f014f9eb8deeef7da288e3841892bef7d5eeedaa`. Dump tabel/data/triggers sukses setelah retry izin koneksi sandbox; satu attempt gagal ditandai.failed. Routines tidak disertakan, restore belum diuji; bukan backup database server pengguna.

**275 PHP / 5837 assertions,279 frontend,TypeScript/Vite build,31 manifest refs/24 preload deps ×3 profiles, scoped Pint dan diff check lulus.** Enam PHP/sembilan frontend baru; regression expectation format/current-save disesuaikan, pengujian sen historis tetap. Bukti meliputi schema/null/source-preservation, guard sebelum DDL, retry setelah konflik, trigger/XOR/FK APM, pending snapshot dengan approved_amount NULL, CLI read-only, per-component BCMath/BigInt/legacy, agreement caps, SQL catalog policy+4queries, modal scopes/counts/route/search/1000rows/20DOM/escaping.

Semua tes database memakai fail-closed SQLite memory/fake disk; frontend fixture/SSR. Tidak menjalankan migration, transaksi, preflight SQL terhadap its aktif/server, mengubah `.env`/APP_KEY/permission, worker live, master source workbook, atau Dompis. Browser klik akun/visual/dark/responsif/keyboard dan cetak fisik/desktop Word, MySQL/MariaDB DDL pada server, beban produksi belum diverifikasi. Modal memakai data tersimpan, bukan data simulasi yang ditanam pada runtime.

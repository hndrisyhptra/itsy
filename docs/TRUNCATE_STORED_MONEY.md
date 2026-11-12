# Pemotongan pecahan nominal tersimpan — 12 November 2026

Pengguna menegaskan: nilai tersimpan juga diubah, dengan **menghilangkan bagian desimal, bukan membulatkan**. Perubahan ini menggantikan aturan half-up pada implementasi 11 November di WHOLE_RUPIAH_DASHBOARD_DETAILS. Pengguna meminta lanjut; total turunan mengikuti rincian harga yang telah dipotong, sedangkan pembayaran yang benar-benar tercatat tidak dibuat ulang sebagai transfer baru.

## Aturan berlaku

- 15.286,99 → 15.286; 15.286,50 → 15.286; -15.286,99 → -15.286 (menuju nol). Tidak ada penambahan0,5 atau pembulatan naik. Nol dan null tetap dibedakan.
- Penanda baru server-owned **moneyPolicy=idr-trunc-v1**. Laravel memakai BCMath `bcdiv(value,1,0)`; frontend `Math.trunc` dan pembagian integer/BigInt pada hasil komponen. Harga/nilai input dipotong; setiap nilai komponen hasil quantity×price juga dipotong sebelum dijumlahkan. Volume tetap tiga desimal; persentase, HK, tanggal, ID/NIK/rekening dan nomor dokumen bukan sasaran konversi uang.
- Nominal halaman, dashboard, laporan, preview, Word dan terbilang menggunakan pemotongan yang sama. Penanda half-up terdahulu idr-whole-v1 tetap dikenali tersendiri agar arsip yang belum dikonversi tidak diam-diam memakai rumus lain hanya karena GET.
- KHS CRUD/bulk import menerima angka maksimal dua pecahan sebagai masukan dan menyimpan hasil potongnya. Batas/validasi tetap berlaku. PP/BA KH memeriksa input asli terhadap ceiling sebelum pemotongan, sehingga kelebihan batas tidak diloloskan dengan memotongnya. Kasbon baru menormalisasi rincian nominal lalu memastikan jumlah tepat sama tagihan; hasil potong nol tidak boleh menggantikan nominal kasbon positif.

## Konversi data lama

Migration baru **2026_11_11_000001a_truncate_stored_currency** berjalan setelah migration kasbon dan sebelum 000002 BIGINT. Nama diurutkan demikian agar instalasi yang belum menjalankan migration tipe dapat memotong data terlebih dahulu. Jika 000002 sudah pernah dijalankan, Laravel tetap menjalankan migration data baru yang masih pending; tidak perlu mengubah daftar migrations secara manual.

Konversi satu kali memperbarui:

- Empat belas kolom uang operasional pada sepuluh tabel yang tercantum di WHOLE_RUPIAH_DASHBOARD_DETAILS.
- Snapshot aktif PP/BA KH/termin (`document_data`), BOQ `source_data`, rincian anggaran `row_data`, metadata KHS dan permintaan pembayaran `request_data`.
- Jumlah/rincian uang dalam **private_data kasbon**, dengan dekripsi/enkripsi memakai APP_KEY yang sama. Identitas penerima, rekening, NIK, foto, path file dan selected approvers tidak berubah.
- Header otomatis BOQ, biaya, recap dan cache total anggaran dihitung ulang dari sumber yang telah dipotong. Header historis manual tidak diganti dengan BOQ. Contoh harga10,75×volume2: harga menjadi10 dan total turunan menjadi20; ini perhitungan dari harga baru, bukan pembulatan21,50 menjadi22. Persentase margin tetap hasil perhitungan, tidak dipotong sebagai uang.
- `paid`, rincian paymentHistory dan APM yang telah tercatat hanya dipotong pecahannya, bukan diganti dengan tagihan baru. Nominal transfer di bank dan file bukti transfer asli tidak berubah. Selisih terhadap tagihan yang berubah tetap perlu ditinjau pada audit/berkas, bukan diselesaikan dengan pembayaran otomatis. Status/nomor APM/keputusan/tanggal approval tidak disunting.

Konversi berhenti sebelum UPDATE bila budget/tagihan aktif menjadi nol yang melanggar workflow, nominal APM/request/kasbon positif menjadi kurang dari Rp1, kasbon aktif belum dibayar tidak sesuai tagihan hasil konversi, rincian kasbon tidak cocok total, atau jumlah rincian pembayaran berbeda dengan paid setelah pemotongan. Kasus itu membutuhkan rekonsiliasi dokumen terkait; tidak menghapus transaksi, membuat transfer, menandai lunas atau menciptakan keputusan baru untuk melewati penjagaan.

Source Excel/aslinya, angka pembanding Excel, prototype_workspaces.state (arsip), audit/receipt lama, keputusan approval, bank/proof/scans/eviden/akun dan quantity ledger tidak ditulis ulang. Snapshot aktif termasuk dokumen Sah berubah **hanya pada informasi nominal/kebijakan**, sesuai otoritas pengguna terbaru; sebelum/sesudahnya tetap tersedia pada audit terenkripsi.

## Atomicity, audit dan retry

Dua tabel audit baru:

| Tabel | Fungsi |
|---|---|
| currency_conversion_runs | id_currency_run, conversion_key unik, ringkasan jumlah record/workspace, waktu konversi sistem |
| currency_conversion_changes | id_currency_change, run_id FK, tabel/record sasaran, before_data dan after_data terenkripsi serta hash patch sesudah |

Seluruh rencana divalidasi sebelum penulisan nominal pertama. Apply memakai transaksi dan kunci workspace berurutan; sebelum/patch sesudah dicatat bersama perubahan SQL dan kenaikan revision. Revisi workspace yang terpengaruh/master berubah membatalkan preview impor/form lama, tanpa mengganti arsip state. Tidak memakai RelationalWorkflowStore.write untuk menghapus/membuat ulang child/ledger/approval. Record hanya diperbarui menurut primary key; tidak ada state replacement dari client atau endpoint web.

conversion_key=idr-trunc-v1 mencegah pengulangan: retry setelah sukses tidak memotong lagi atau menambah audit/revisi. Kegagalan SQL di tengah proses rollback perubahan, audit dan revisi. Rollback migration menolak jika audit perubahan atau dokumen policy baru sudah ada; pecahan tidak dapat diperoleh kembali dengan menambahkan ,00. Restore perlu backup dan rekonsiliasi eksplisit, bukan migrate:rollback otomatis. Jangan mengganti APP_KEY karena diperlukan untuk membaca kasbon dan audit.

## Operator server

**Agen belum menjalankan migration/konversi pada database its aktif atau server.** Backup lokal sebelum perubahan bukan backup server.

1. Backup database server dan file privat; pertahankan .env/APP_KEY.
2. Gunakan maintenance dan hentikan sementara penulis/worker its; jangan hentikan worker Dompis/aplikasi lain. Tidak ada jaminan aman terhadap penulis yang mengabaikan maintenance/lock.
3. Pull seluruh kode, build aset dan periksa hasilnya. Pastikan schema migration kasbon sebelumnya sudah tersedia bila ingin inspeksi terlebih dahulu.
4. Pemeriksaan rencana baca-saja (tanpa apply):

   ```bash
   php artisan area3:truncate-money
   ```

5. Sesudah backup dan review rencana, jalankan migration:

   ```bash
   php artisan migrate --force
   ```

   Migration melakukan konversi/audit lalu perubahan BIGINT otomatis. Jika konflik dilaporkan, jangan paksa ALTER/reset/import ulang; proses data berhenti dan dapat dicoba lagi setelah koreksi yang disetujui. Tabel audit yang baru dibuat mungkin sudah ada bila DDL MySQL sempat berjalan; guard hasTable mengizinkan retry tanpa menghapusnya. MySQL DDL tidak seluruhnya transactional, sedangkan perubahan data berjalan dalam transaksi InnoDB.

6. area3:check-whole-rupiah tetap pemeriksaan integer ketat; bukan perintah yang mengubah nilai. area3:truncate-money --apply hanya untuk apply eksplisit ketika schema audit tersedia. Tidak ada auto-apply dari endpoint atau saat halaman dibuka.
7. Periksa hasil, status pembayaran/dokumen dan log/audit, perbarui cache aplikasi serta muat ulang hanya worker its sebelum mengakhiri maintenance.

## Query/performance dan bukti

Rencana membaca empat koleksi dokumen aktif per workspace (PP/deal/SPK/termin), tanpa event/BA PP/arsip atau bind katalog akun/file private per record. Lookup relasi/overrides diindeks. Tabel proyeksi/JSON dibaca dengan kolom terpilih dalam halaman200 dan workspace20; penulisan hanya record berubah plus auditnya. Jumlah query maintenance bertambah menurut workspace/halaman/record berubah; bukan operasi web, bukan klaim throughput. Tidak menambah query pada save/approval/preview/dashboard atau jumlah query katalog SPK (tetap4service fixture). Pecahan negatif hanya berlaku pada hasil uang seperti gross profit; harga dan tagihan tetap aturan nonnegatif/positif.

Pengujian meliputi SQL+snapshot, audit sebelum/sesudah terenkripsi, preview rencana tanpa write, replay, rollback SQL terlambat/retry, archive/decisions/identity/quantity/provenance tetap, konflik nominal positif di bawah Rp1, policy lama vs truncation, harga KHS .99 tersimpan tanpa pecahan, Finance/APM/queue gates dan build. **280PHP/5877assertions,280frontend,TypeScript/Vite build,31manifest/24preload×3profil,scoped Pint/diff check lulus.** Lima PHP/satu frontend baru; bukti final pada LARAVEL_QA dan qa/truncate-stored-money-20261112.json.

Backup tabel/data/triggers its lokal: **its-before-truncate-rupiah-20261111-102406.sql**,2.119.431byte,SHA256 **2d2ec7bbbc44bf5be03c1799db61749d3a1de6647a528ce23af1cad1cf24e647**. Satu percobaan sandbox gagal ditandai.failed; retry baca-saja berhasil. Routines tidak termasuk dan restore belum diuji. Tidak menerapkan konversi/migration/DDL ke its aktif, mengubah env/key/permission, memulai worker, menyentuh Dompis, mengubah attachment atau melakukan commit/push/deploy. Browser/Word/cetak fisik dan DDL MySQL/server belum diverifikasi; pengujian database fail-closed SQLite memory. Tidak ada data simulasi yang ditanam ke runtime.

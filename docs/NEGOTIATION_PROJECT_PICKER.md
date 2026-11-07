# Pemilihan ID Project dan PP untuk negosiasi — 7 November 2026

Pada **SPK → Negosiasi Baru**, Admin Konstruksi/Superadmin mencari **ID Project atau nama pekerjaan**, memilih ID Project, memilih PP pada tabel ringkas, kemudian menekan **Lanjut Negosiasi**. Form tidak lagi memilih PP pertama atau menampilkan dropdown seluruh PP. Satu ID Project dapat memiliki banyak PP, termasuk pada Branch berbeda; hasil hanya mencakup Branch penugasan akun.

## Tampilan dan perilaku

- Pencarian minimal 2/maksimal 100 karakter, jeda 300 ms setelah mengetik. Hasil menampilkan ID Project, nama kegiatan perwakilan yang mengutamakan kecocokan pencarian, jumlah PP dan Branch dalam akses akun. Jika nama satu kegiatan cocok, jumlah PP tetap menghitung seluruh PP yang terlihat pada ID Project tersebut. Nama/Branch dari master projects yang mungkin berasal dari PP lain tidak digunakan sebagai sumber label kegiatan/akses.
- Hasil maksimal 20 per halaman dengan Sebelumnya/Berikutnya. Tabel PP diurutkan tanggal penggunaan terbaru lalu ID SQL sebagai urutan tetap. Kolom: No PP, Nama Kegiatan, Branch, Status dan Aksi. PP yang belum Sah, BOQ belum Final atau tidak mempunyai kapasitas baru diberi alasan dan tombol nonaktif.
- Pilihan PP harus eksplisit; tidak ada pemilihan otomatis. Ringkasan menampilkan nomor/nama/ID Project/Branch/status sebelum Lanjut Negosiasi. Permintaan lama dibatalkan dan respons usang diabaikan saat pencarian/pilihan berubah. Error mempunyai Coba lagi; hasil yang gagal dimuat tidak digunakan sebagai hasil baru. Memuat ulang daftar menghapus pilihan yang sudah tidak memenuhi syarat.
- Sebelum masuk form, akun aktif memuat ulang state tersimpan melalui refreshState agar nomor, BOQ, anggaran, jadwal dan identitas mengikuti data server terbaru. Pencarian sendiri tidak menyimpan/memesan volume. Harga, jadwal, penandatangan, PP Sah dan volume tetap divalidasi ulang oleh PrototypeDomain saat perintah simpan.
- Di form baru, **Ganti PP** membuka konfirmasi lalu kembali ke pencarian. Cache form tetap per PP di browser, tidak memindahkan isian Mandor/harga ke PP lain. Dokumen tersimpan mempertahankan PP read-only dan tidak menyediakan pindah PP. Tautan eksplisit dari detail PP (`?project=...`) tetap membawa PP yang memang dipilih pengguna, dengan pemeriksaan kondisi/akses melalui state berizin dan perintah server.
- UI memakai warna/primitif proyek, tombol keyboard, status loading/error/kosong dan tabel dengan scroll lokal; tidak menambah menu tingkat atas. Penjelasan alur SPK kini menyebut seluruh approver, bukan dua jabatan alur lama.

## Tindak lanjut SPK pada daftar BA KH — 7 November 2026

Daftar **SPK · BA KH & Negosiasi** mempunyai kolom **Tindak Lanjut SPK** selain status BA KH. Sumbernya dokumen BA KH/SPK tersimpan yang telah dimuat sesuai akses Branch, bukan status baru yang disimpan terpisah.

| Kondisi | Informasi tindak lanjut | Aksi tambahan |
| --- | --- | --- |
| BA KH Draft, belum ada SPK | Negosiasi Berlangsung; lengkapi negosiasi/BA KH | Buka BA KH |
| BA KH Terbit, belum ada SPK | Menunggu Pengesahan BA KH | Buka BA KH |
| BA KH Sah, belum ada SPK | Menunggu Pengajuan SPK; Negosiasi & BA KH selesai | Buat SPK, hanya Admin Konstruksi/Superadmin |
| Sudah ada SPK Draft/Terbit | SPK Draf/Terbit, nomor aktual dan arahan pengesahan | Buka SPK yang sudah ada |
| Sudah ada SPK Sah | SPK Sah, nomor aktual dan proses SPK selesai | Buka SPK yang sudah ada |

Buat SPK membuka form yang sudah ada dengan BA KH terpilih melalui `/spk/new?deal=...`; tidak mengajukan/mengesahkan otomatis atau membuat rantai approval baru. Semua baris tetap mempunyai Buka BA KH. SPK dicocokkan berdasarkan ID BA KH, bukan ID Project yang dapat dipakai banyak PP. Jika SPK sudah ada, tombol Buat SPK tidak muncul. Pengguna baca saja tetap dapat memantau informasi dan membuka dokumen yang terlihat, tanpa tombol buat. Daftar SPK pada tab kedua tetap enam kolom. Daftar BA KH mempertahankan class contract-list-plain tanpa garis tabel; rincian tindak lanjut dapat membungkus dan scroll tetap lokal.

Map PP/BA KH/SPK dibangun sekali per render untuk filter dan baris, menggantikan pencarian koleksi per baris. Filter juga menerima ID Project/nama kegiatan dari data yang sama. Tidak ada query/HTTP/endpoint baru, migration, write SQL atau perubahan otorisasi/domain/ledger. Uji merender variasi status dan role, memeriksa tidak adanya tautan pembuatan duplikat, dua PP dengan ID Project sama, tidak adanya find koleksi per baris dan state tetap utuh. **203 tes PHP / 3.796 assertions, 169 tes frontend dan build lulus.** Ini verifikasi data/markup, bukan pemeriksaan browser/responsif manual. Audit: CHANGELOG.md, LARAVEL_QA.md dan qa/contract-follow-up-20261107.json.

## API dan arsitektur

GET `/api/prototype/negotiation/projects?q=...&page=1` dan GET `/api/prototype/negotiation/projects/{UUID}/plans?page=1` melalui login, auth.session, ActiveAccount, PrivatePrototype dan throttle 120/menit. Server hanya mengizinkan Admin Konstruksi/Superadmin. Page 1–1000, ukuran tetap 20; SQL mengambil maksimal 21 untuk penanda hasMore, tanpa count query tambahan. LIKE memakai parameter dan escape literal `%`, `_`, `!`.

NegotiationProjectCatalog membaca workspace aktif tanpa state arsip, kemudian membatasi `project_plans.workspace_id/branch_id` sebelum pengelompokan, pencarian nama sibling dan pembacaan PP. Label kegiatan berasal dari document_data PP yang terlihat; akses tidak bergantung pada projects.branch_id. Project yang tidak terlihat menghasilkan 404 tanpa mengungkap nama/jumlah. Storage belum aktif menghasilkan 503, tanpa membuat workspace baru atau fallback demo.

Respons hanya metadata pilihan, tanpa BOQ/anggaran/path privat/identitas approver/snapshot penuh. Nama, status BOQ dan alokasi diekstrak sebagai field JSON yang diperlukan. Root memakai volume_balances positif melalui BOQ component; PP lanjutan memakai **allocations snapshot PP tersebut dikurangi komponen semua BA KH pada PP itu**, sesuai capacity domain. continuation_allocations adalah proyeksi historis yang tidak menjadi sumber aktif dan tidak boleh dianggap selalu terisi. Volume Material/Jasa tetap dihitung terpisah dan tidak dijumlahkan lintas satuan.

Tidak ada migration, perubahan model penyimpanan, aturan mutasi, penomoran, approval, harga, tanda tangan atau arsip. Kode pencarian akun tidak memakai localProjects/localPlans; kedua helper itu hanya untuk mode offline tanpa sesi akun. Shared boot aplikasi masih memuat aggregate Branch yang sudah ada, dan refreshState pada Lanjut Negosiasi juga memakai endpoint state yang sudah ada. Fitur ini membatasi jalur pencarian; belum mengubah seluruh aplikasi menjadi pemuatan dokumen satu per satu.

## Tinjauan performa dan bukti

- Layanan pencarian: **2 query** (workspace dan pengelompokan ID Project). Daftar root: **3 query** (workspace, PP, saldo root). Daftar hanya lanjutan: 3 query; campuran root/lanjutan maksimal 4. Seluruh referensi kapasitas dibaca per halaman secara batch, tanpa lookup SQL per PP/baris. Jumlah ini tidak termasuk query autentikasi/sesi/middleware HTTP.
- Tidak membaca workflow_metadata, event, approval history, master harga atau seluruh document_data; tidak mengambil workspace.state, tidak lock/write transaksi bisnis/revisi/audit. Query-budget root dan pencarian diuji pada fixture **120 PP/30 ID Project**, termasuk satu ID berisi 33 PP dengan pagination 20+13. Tidak ada klaim throughput/latensi umum; contains-search nama JSON tetap memerlukan pemeriksaan PP sesuai ruang akses, dan belum diuji beban serentak.
- Enam tes PHP baru mencakup grouping nama sibling/jumlah keseluruhan, PP Branch bersama dengan master Branch berbeda, kebocoran label/count, akses project asing, pagination/ukuran/query, kapasitas root/lanjutan versus ledger domain, lanjutan teralokasi penuh dan sisa 0,001, role, input array/pendek/panjang/page, wildcard literal dan fail-closed storage. Semua data fixture hanya SQLite :memory:.
- Enam tes frontend baru mencakup awal tanpa pilihan otomatis/dropdown, grouping dan pagination, alasan tidak tersedia/kapasitas lanjutan, tabel pilihan eksplisit/disabled/escaping, dokumen tersimpan read-only/state tetap, request GET dengan subpath/sesi/cancellation/error. SSR bukan bukti klik/keyboard/responsif browser.
- **203 tes PHP / 3.796 assertions, 166 tes frontend, build TypeScript/Vite dan scoped Pint lulus.** Pemeriksaan baca saja pada MySQL its memakai akun Admin aktif yang sudah ada: pencarian 1 ID Project dan 2 PP berhasil dengan batas halaman/Branch. Tidak membuat sesi login/akun/transaksi uji pada its. Browser terautentikasi, viewport 375/768/1440, keyboard dan jaringan lambat belum diverifikasi manual.

Backup: storage/app/private/backups/its-before-negotiation-project-picker-20261107-090820.sql, 2.033.657 byte, SHA-256 38448e63e1feef7e7f4796b9ae49ae55cbc5ce34ef5bf4c5cf40b4508b785db7. Tidak ada perubahan .env atau data live, selain backup privat. Aplikasi/database Dompis Prov tidak disentuh.

Arsitektur: [ARCHITECTURE.md](ARCHITECTURE.md). Audit kronologis: [CHANGELOG.md](CHANGELOG.md), WORKPLAN.md. QA: [LARAVEL_QA.md](LARAVEL_QA.md), qa/negotiation-project-picker-20261107.json.

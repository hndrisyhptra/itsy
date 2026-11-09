# Cari ID Project dan pilih SPK Sah untuk pengajuan pembayaran


**Pembaruan9 November2026 — Payment Parsial:** Admin dapat mengajukan Payment setelah lima approval/Head tanpa scan, Finance memverifikasi lalu mencatat APM/lunas. Scan dapat disusulkan dan berstatus NOK/OK terpisah; Final/v1/v2 dan histori tetap. Kasbon ditunda sampai format pengguna tersedia. Authority terbaru, migration baru (belum diterapkan pada its aktif), relasi/proyeksi SQL dan bukti: [PARTIAL_PAYMENT](PARTIAL_PAYMENT.md). Penjelasan scan wajib untuk semua dokumen di bawah merupakan aturan sebelumnya bila berbeda.

Pembaruan 7 November 2026: **Pembayaran → Pengajuan Baru** membuka pencarian ID Project/nama kegiatan, bukan dropdown semua SPK dengan SPK pertama otomatis terpilih. Alur baru hanya memilih sumber pengajuan; approval, eviden, scan, Finance/APM dan ledger tetap divalidasi oleh layanan Laravel yang sudah ada.

## Alur pengguna

1. **Cari ID Project**: ketik minimal 2/maksimal 100 karakter. Hasil menampilkan ID Project, nama kegiatan yang cocok, Branch dalam akses akun dan jumlah SPK Sah. Project yang belum mempunyai SPK Sah tidak muncul. Satu ID Project dapat mempunyai banyak PP/SPK, termasuk di Branch berbeda; hasil mengikuti akses masing-masing PP.
2. **Pilih SPK Sah**: setelah memilih Project, tampil tabel No PP/nama kegiatan, No SPK, Mandor/Branch, Nilai SPK, Pembayaran Terakhir dan Aksi. SPK Draft/Terbit tidak muncul. Hanya aksi Pilih SPK yang memilih sumber baru dan menampilkan ringkasan untuk konfirmasi; aksi pengajuan existing langsung memeriksa state terbaru lalu membuka dokumen itu.
3. **Buat Draf Pembayaran**: ringkasan mencantumkan SPK, PP, ID Project, kegiatan, Branch, mandor, nilai dan nomor termin berikutnya. Termin 3 ditandai Final. Pengguna menekan tombol; aplikasi memuat ulang state berizin dan memeriksa kondisi terkini, menjalankan createTerm melalui store serta menunggu konfirmasi server sebelum menuju form volume aktual/eviden.

| Kondisi pembayaran terakhir pada SPK | Aksi |
| --- | --- |
| Belum ada pengajuan, PP Sah | Pilih SPK → ringkasan → Buat Draf Pembayaran Termin1 |
| Draft, termasuk Draft Final | Lanjutkan Draf yang sama |
| Dikembalikan, atau Ditolak versi 3 | Perbaiki Pengajuan yang sama, tetap mengikuti resubmission dari PM |
| Ditolak versi 1/2 | Buka Pengajuan historis; tidak mengubahnya menjadi editable |
| Diajukan / menunggu approval | Buka Pengajuan yang sedang berjalan |
| Disetujui tetapi scan/Finance/APM belum selesai | Buka Pengajuan untuk melanjutkan berkas/APM |
| Parsial sebelumnya approved dengan APM Valid, kurang dari 3 termin | Pilih SPK → konfirmasi termin berikutnya, setelah validasi ulang |
| Final sudah dibuat atau maksimum 3 termin tercapai | Buka Pengajuan terakhir; alasan penutupan ditampilkan, tidak ada pembuatan termin baru |
| SPK Sah tetapi PP terkait belum Sah | Pilih dinonaktifkan dengan alasan PP belum Sah |

Status ringkas membedakan Draft/Dikembalikan/Ditolak, Menunggu approval, Menunggu scan TTD, Scan perlu koreksi, Menunggu Finance, Menunggu APM dan APM valid. Tampilan ringkas tidak menghitung ulang keputusan atau memindahkan approval; detail pengajuan tetap memperlihatkan role/actor dan riwayat lengkap. Versi2 scan Valid ditampilkan Menunggu APM, tanpa mengarang Finance approval versi 3.

Pencarian mempunyai debounce 300 ms, abort ketika input/proyek/halaman berubah, respons usang diabaikan, loading/error/kosong dan Muat ulang daftar. Ukuran 20 hasil per halaman dengan Sebelumnya/Berikutnya. Pergantian pencarian, Project atau halaman menghapus pilihan lama, tanpa memindahkan input pengajuan ke SPK lain. Ringkasan tidak menyediakan input transaksi. Perubahan status/akses server dapat membatalkan pilihan; pesan kegagalan tetap terlihat sampai reload/pencarian baru.

Deep link eksplisit `/termin/new?spk=<source-id>` tetap membawa SPK Sah yang memang dipilih, berapa pun posisinya dalam daftar, dengan ringkasan dan pemeriksaan ulang saat lanjut. ID tidak tersedia/belum Sah tidak pernah diganti dengan SPK pertama. Pengajuan tersimpan tetap terikat pada SPK asal. Menu tingkat atas tetap sembilan dan daftar Pembayaran existing tetap tersedia di `/termin`.

## Konfirmasi dan perlindungan draf ganda

`startPaymentFromSpk` di store menolak transaksi lain yang masih pending, memanggil refreshState dan mengevaluasi action dari state terbaru, bukan metadata pencarian lama. Jika ada draf/termin aktif/Final, fungsi mengembalikan ID existing tanpa create command. Jika baru boleh dibuat, nextTermReason memeriksa SPK/PP/penugasan approver/Final/batas/APM, lalu createTerm tetap memvalidasi lagi di Laravel di bawah lock/revisi/idempotency.

Tombol dikunci menggunakan running ref selama aksi. Navigation terjadi setelah waitForPersistence berhasil dan ID SPK/termin hasil ditemukan pada state terkonfirmasi. Jika request gagal/409, form pencarian tetap tersedia dan tidak menuju ID optimistis. Jika server sudah menyimpan tetapi respons terputus, pemulihan state serta percobaan eksplisit berikutnya menemukan pengajuan existing. Tidak membuat pengajuan pengganti untuk Ditolak/Dikembalikan atau menghabiskan slot baru. Tes mencakup respons pending, penolakan server, GET gagal, SPK hilang, transaksi ganda, respons hilang sesudah write dan pemulihan tanpa duplikasi.

## API, data dan otorisasi

Endpoint GET baru, melalui login/auth.session/ActiveAccount/PrivatePrototype dan throttle 120/menit:

- `/api/prototype/payment-selection/projects?q=...&page=1`
- `/api/prototype/payment-selection/projects/{project-uuid}/orders?page=1`

Hanya Admin Konstruksi/Superadmin. Page 1–1000, ukuran tetap 20; SQL limit 21 untuk hasMore, tanpa COUNT total tambahan. Project UUID yang tidak terlihat menghasilkan404 pada halaman1 dan halaman lain kosong; tidak mengungkap nama/count. Query q di-trim/divalidasi; `%`, `_`, `!` di-escape literal dan input di-bind. Storage yang belum aktif menghasilkan503, tanpa membuat workspace atau fallback archived/demo.

PaymentProjectCatalog membatasi workspace work_orders/contracts/project_plans serta `project_plans.branch_id` sebelum pengelompokan, pencocokan nama sibling dan pembacaan SPK. Jangan memakai projects.branch_id atau nama kegiatan master untuk akses/label: satu Project dapat memuat PP Branch lain. Hasil hanya metadata SPK Sah yang terlihat; pencocokan nama kegiatan PP tanpa SPK Sah juga tidak membuat group muncul.

Tabel sumber: prototype_workspaces (ID/penanda aktif saja), work_orders, contracts, project_plans, projects, branches; tambahan per halaman payment_terms untuk maksimum 3 termin per SPK dan contract_components/boq_components/boq_lines untuk nilai. Pengambilan termin memilih status/nomor/jenis/versi, status APM/scan serta timestamp ada-tidaknya Finance; tidak mengambil actuals, dokumen penuh, nama approver, catatan keputusan, file/path privat atau histori. Metadata pengajuan yang dikembalikan ke client hanya termin terakhir. Harga/nilai berasal dari kesepakatan, bukan master Owner/KHS.

Nilai SPK SQL mengikuti aturan jumlah SPK/BA KH: kelompokkan Material/Jasa dari baris BOQ sumber yang sama, bulatkan gabungan per baris ke 2 desimal, baru jumlahkan per kontrak. Subquery mengagregasi di database, sehingga hasil nilai maksimal 20 kontrak, bukan membawa seluruh komponen ke client. Tidak menjumlahkan volume lintas komponen/satuan. Test memeriksa half-cent dari kuantitas0,005/harga1+1 serta kesamaan dengan domain dealTotal.

## Arsitektur dan tinjauan performa

- PaymentSelection PHP dan payment-selection.ts berisi routing hint murni dengan 13 vektor pengujian bersama. Keduanya tidak mengganti domain createTerm atau memberikan izin approval/APM baru.
- payment-projects.ts menangani request SQL dan helper offline berindeks Map PP/BA KH/termin sekali. Helper offline hanya digunakan tanpa akun; akun aktif selalu menggunakan endpoint berizin. payment-project-picker.tsx memakai primitives/warna/aksesibilitas proyek dan tabel scroll lokal. Data dimuat per Project/halaman.
- Core routing dipisah dari pencarian supaya store yang digunakan aplikasi tidak menarik seluruh UI/kode pencarian ke entry utama. Build final main 367,86 kB/gzip 114,28 kB, payments 44,49 kB/gzip 13,66 kB; ukuran bundle ini bukan benchmark latensi.
- **Pencarian 2 query**: workspace aktif dan hasil group. **Daftar SPK 4 query** saat ada hasil: workspace, metadata 21 SPK, batch termin untuk 20 SPK dan agregasi nilai 20 kontrak. Halaman kosong 2 query. Tidak ada lookup SQL per SPK/termin/baris; fixture 120 SPK/30 ID Project membuktikan jumlah query konstan serta pagination 33 SPK menjadi 20+13. Angka ini tidak memasukkan query middleware/autentikasi/sesi atau refreshState/create command.
- Contains-search nama JSON masih memeriksa data sesuai ruang akses; offset dibatasi sampai halaman 1000. Shared boot aplikasi/refreshState masih memuat aggregate Branch yang sudah ada. Perubahan ini membatasi pencarian, belum mengubah seluruh aplikasi menjadi pemuatan dokumen satu per satu atau membuktikan throughput banyak pengguna.
- Tidak ada migration, perubahan schema/.env/worker/akun/master, rewrite data live/arsip, atau pengurangan guard harga/volume/branch/approval/APM. Operasi cari/pilih tidak menulis DB atau memesan volume; aksi Buat Draf memakai command createTerm yang sudah ada.

## Bukti dan batas

**215 tes PHP / 4.132 assertions**, **196 tes frontend**, TypeScript/Vite build, scoped Pint dan git diff --check lulus. Tujuh tes PHP baru mencakup grouping/Sah-only/sibling PP, Branch bersama yang master Branchnya tersembunyi, role/validation/503/literal wildcard, pagination/query budgets, jumlah nilai/pembulatan, follow-up history dan create command/replay/409/duplikat/role/Branch/approver nonaktif. Sembilan tes frontend baru mencakup awal kosong tanpa dropdown/otomatis, grouping/pagination/no-find,13 routing vectors, status/summary/escaping, deep link exact, callback buka existing versus pilih baru, transport cancellation/subpath/GET serta konfirmasi/ambiguity recovery.

Pemeriksaan baca-saja dengan akun aktif yang sudah ada pada MySQL its berhasil: 1 hasil Project dan 1 SPK; service 2/4 query; revisi dan byte arsip workspace tetap sama. Tidak membuat sesi login/akun/transaksi fixture pada live its. Pengujian transaksi hanya SQLite :memory: fail-closed.

Browser login/klik/responsif 375/768/1440/keyboard/dark serta load test serentak belum diuji manual. Penolakan akses browser sebelumnya tetap dihormati; SSR/callback/request tests bukan bukti visual browser. Tidak ada integrasi baru yang disimulasikan: akun aktif memakai SQL asli, hanya helper offline memakai state lokal; TTD basah, upload berkas aktual dan Finance/APM tetap proses yang sudah ada.

Backup privat sebelum perubahan: `storage/app/private/backups/its-before-payment-project-picker-20261107-153204.sql`,2.038.872 byte,SHA-256 `f478c6a7073ac93bf1625a3e329ff902445f22e546add20fa7aa82cf6669124d`. Dokumentasi audit: CHANGELOG.md, ARCHITECTURE.md, WORKPLAN.md, LARAVEL_QA.md, qa/payment-project-picker-20261107.json.

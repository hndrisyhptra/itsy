# Revisi PP dan timeline Operation — 20 November 2026

## Permintaan dan hasil

Pengguna melaporkan PP Operation yang dikembalikan masih menampilkan approval Konstruksi saat diedit dan meminta timeline mengikuti unit Operation. Pemeriksaan kode menemukan dua sumber: status revisi pada timeline menyebut Admin Konstruksi secara tetap, dan pemilihan skema revisi mempertahankan skema Konstruksi setiap kali PP mempunyai history. Selain itu, cache form dapat membawa unit/status lama ke tampilan meskipun PP tersimpan sudah Operation. Ini reproduksi kode dan fixture lokal; record server pengguna tidak diperiksa langsung.

Perbaikan:

- Editor memakai unit, UUID, nomor, root/parent/sumber, alokasi, status dan metadata workflow dari PP tersimpan. Cache hanya menyuplai isian yang dapat diedit; nama, anggaran, jadwal dan pilihan approver pengguna tidak dibuang. PP terkunci menampilkan sumber tersimpan tanpa mengambil cache sebagai dokumen aktif.
- Revisi PP v3 Operation menampilkan lima approver **Officer 1 Operation → Manager Branch Operation → Officer 1 SS → Manager SS → General Manager Operation**. Admin tetap harus memilih akun aktif yang sesuai Branch/unit/Region; tidak mengalihkan akun Konstruksi menjadi akun Operation dengan nama serupa.
- Setelah pengembalian, Admin Operation menyimpan revisi lalu mengajukan kembali dokumen yang sama, melalui paket PP/BA KH/SPK bila PP menggunakan paket. Pengajuan ulang dimulai dari Officer 1 Operation, dengan approval aktif kosong. Konstruksi tetap memakai alurnya sendiri. Catatan pengembalian kini terlihat pada status Dikembalikan dan draf revisinya, selain catatan penolakan historis.
- BA KH/SPK draf pada paket disinkronkan kepada GM yang dipilih ketika pengajuan paket dilakukan. Pengesahan tetap memerlukan lima keputusan nyata. Pembayaran baru yang dibuat sesudah paket Operation Sah mengikuti lima peran Operation, lalu Mgr Finance & Settlement dan Finance.
- Timeline mengelompokkan PP dengan ID Project dan unit yang sama, di dalam graph yang sudah diotorisasi Laravel. PP Konstruksi tidak ikut daftar, angka atau aktivitas Operation walaupun Project code sama. Pilihan awal tetap UUID PP yang diklik. Teks revisi/admin dan giliran mengikuti unit/skema dokumen; rute aktivitas `/ba-rp/:termId` ikut relasi term yang tepat.

## Batas riwayat dan penyimpanan

Tidak ada konversi pada GET, preview, build atau deployment. PP lama yang masih Diajukan/Sah tetap memakai skema dan penanggung jawab tersimpan. Data pembayaran lama juga tidak dialihkan. Riwayat dapat tetap menampilkan peran Konstruksi apabila peran itulah yang benar-benar memberi keputusan pada siklus lama; ini bukan approval aktif pada siklus Operation baru.

Untuk PP v3 Operation lama yang telah dikembalikan tetapi masih berskema Konstruksi, perubahan hanya diterapkan ketika Admin menyimpan revisi yang diizinkan. `operationApprovalRevision` server-owned disimpan pada `project_plans.document_data`, berisi:

- `version`, `firstCycleNo`: batas awal siklus Operation.
- `before`: routing lama (workflowVersion, approvalScheme, penanggung jawab dan metadata perubahan urutan bila ada).
- `actorUserId`, `at`: aktor dan waktu perubahan eksplisit.

Payload marker palsu diabaikan; marker tersimpan dipertahankan pada simpan berikutnya. Pengubahan unit PP tetap dilarang. Siklus sebelumnya harus sudah ditutup dengan pengembalian/penolakan dan approval aktif harus kosong. PP lama dengan pengajuan pekerjaan/pembayaran, PP lanjutan atau koreksi Head khusus ditahan dari perubahan skema melalui jalur ini. Tidak ada reset SQL atau mekanisme melewati pengamanan transaksi historis.

`OperationProjectRevision::cycleDocument` memasok routing asal untuk siklus sebelum `firstCycleNo`. `RelationalWorkflowStore` mempertahankan run/step/decision lama, termasuk peran, akun, urutan dan nomor siklus; siklus baru menggunakan lima tahap Operation dan `approval_order_version=4`. Referensi user dibaca dalam batch, bukan per baris. Pengujian membandingkan seluruh baris lama sebelum/sesudah revisi, pengajuan ulang dan persetujuan, termasuk proyeksi penuh pertama sesudah perubahan. Decision/history/nomor PP/arsip workspace tidak diganti dengan keputusan baru palsu.

Tidak menambah tabel, migration, endpoint, dependency, polling atau query pada timeline/editor. Budget, harga, PPh, volume, file privat, ledger, paid/scan/APM, manifest paket, receipt/revision/lock, Branch dan unit tetap mengikuti guard domain existing. Penyimpanan revisi tetap melalui command server; preview/timeline hanya membaca.

## Performa dan pengujian

Helper pemilihan skema/editor dan batas routing siklus menggunakan data yang dimuat. Timeline mengindeks relasi sekali, menampilkan aktivitas20/baris halaman, dan tidak membaca data privat baru. Pencarian parent PP tambahan per pembayaran dihindari dengan meneruskan parent yang sudah diketahui. Perhitungan/graph command existing tetap menjadi batas runtime; bukan klaim bottleneck-free.

Fixture HTTP paket satu baris BOQ: simpan revisi Operation bertanda **57 query**, revisi Operation historis **43 query**. Ini jumlah total fixture autentikasi/command/response, bukan tambahan query atau benchmark server. Guard transisi dengan pembayaran lama mengeluarkan0query pada helper. Query fixture existing 500 BOQ/1000komponen tetap59 dan incremental write20PP tetap8. [Bukti QA](qa/operation-revision-timeline-20261120.json).

Rincian hasil akhir tercatat pada LARAVEL_QA. Pengujian menggunakan SQLite memory/fake storage dan browser actual App dengan state/transport sintetis. Tidak membuka akun/server, mengirim Telegram, membuat transfer bank, menulis MySQL its aktif, menjalankan migration/seed/worker/deploy, menyentuh .env/APP_KEY/Dompis atau commit/push. Tidak merender ulang native Word/PDF karena generator tidak berubah; preview Operation diuji pada browser dan aturan ekspor existing diuji pada suite frontend.

## Penerapan

Tidak ada migration baru untuk perbaikan ini. Gunakan kode beserta build aset yang cocok, muat ulang browser, lalu restart hanya worker proyek Area 3 sesuai prosedur pembaruan domain. Jangan menjalankan migrate:fresh/reset/seed atau mengedit status/riwayat SQL untuk memaksa perubahan. Fitur ini tidak otomatis mengubah status/approval/dokumen lama saat pull.

Untuk revisi PP yang dilaporkan: buka PP Operation berstatus Dikembalikan → Edit → pilih lima akun Operation sesuai cakupan → simpan → lengkapi/periksa BA KH dan SPK bila memakai paket → ajukan kembali. History lama tetap dapat ditinjau pada timeline. Record yang terkunci oleh proses lanjutan memerlukan pemeriksaan tersendiri dan tidak dibuka otomatis oleh perbaikan ini.

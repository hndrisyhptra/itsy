# Unit kerja Operation — 18 November 2026

> **20 November 2026 — Revisi Operation:** PP v3 Operation yang dikembalikan menggunakan lima peran Operation pada penyimpanan/pengajuan ulang berikutnya; routing siklus Konstruksi historis tetap dicatat terpisah. PP Diajukan/Sah dan pembayaran lama tidak otomatis dikonversi. Lihat [OPERATION_REVISION_TIMELINE](OPERATION_REVISION_TIMELINE.md).

Implementasi ini menjalankan konsep yang disetujui pengguna: dokumen dan perhitungan swakelola tetap memakai template yang sama, sedangkan unit, pelaksana dan approval mengikuti Operation. Ketentuan ini menambah alur Operation; riwayat Konstruksi dan dokumen lama tidak dikonversi massal.

## Flow baru

Admin Operation menyimpan Header PP, mengunggah BOQ, melengkapi anggaran/jadwal/risiko, membuat BA KH dan SPK draf beserta identitas/rekening Mandor, lalu mengajukan **satu paket PP + BA KH + SPK**. Kelima akun nyata dipilih sebelum pengajuan:

1. Officer 1 Operation (`OFFICER_OPS1`).
2. Manager Branch Operation (`MGR_BRANCH_OPS`).
3. Officer 1 SS (`OFFICER_SS1`).
4. Manager SS (`MGR_SS`).
5. General Manager Operation (`GM_OPS`).

Persetujuan GM yang sebenarnya mengesahkan paket dan mengikat volume. Penolakan memerlukan catatan; Admin memperbaiki dan mengajukan ulang mulai Officer 1 Operation, dengan identitas dan riwayat siklus sebelumnya tetap. Tidak ada approval otomatis untuk menggantikan kelima akun tersebut.

Pembayaran baru memakai kasbon sebelum pengajuan, lima approver Operation di atas, lalu **Mgr Finance & Settlement → Finance**. Finance meninjau berkas, menyetujui, mengunggah bukti transfer privat dan secara eksplisit menekan Tandai Lunas. Nomor APM opsional dan dapat dilengkapi sesudah lunas. Scan fisik dapat menyusul sesuai kebijakan yang sudah ada; scan yang tersedia tetap harus diverifikasi. Potongan 2,5% hanya pada Jasa Mandor, Material utuh, nilai bruto tagihan tetap tercatat. BA PP dan pelepasan volume mengikuti gate pembayaran/penyelesaian sebelumnya.

Inbox, timeline, detail, pemilihan approver, kasbon, dokumen BA KH/SPK/BARP/BA PP dan penandatangan PP memakai skema dokumen tersimpan. Tidak menambahkan role Officer 2/3 Operation atau Admin Operation Area ke approval/create; peran tersebut tetap terdaftar tanpa otoritas baru dari fitur ini.

## Pemisahan data dan akun

- **`project_plans.work_unit_id` adalah flag SQL untuk setiap PP**, dengan `document_data.unit` sebagai unit dokumen tervalidasi. Satu `projects.project_code` dapat memiliki beberapa PP; jangan menggunakan `projects.work_unit_id` sebagai otoritas seluruh PP berkode sama.
- Akun memiliki `users.work_unit_id` opsional dan `users.access_scope` (`BRANCH`/`REGION`). Akses selalu merupakan irisan unit yang diizinkan role, penugasan aktif, Branch efektif dan relasi dokumen. Jabatan adalah teks penandatangan, bukan hak akses.
- Role Konstruksi hanya Konstruksi; role Operation hanya Operation. Superadmin, Finance dan Mgr Finance & Settlement dapat kedua unit bila pilihan unit akun kosong. Memilih salah satu unit membatasi mereka ke unit itu; tidak memperluas Branch.
- Manager SS dan GM wajib `REGION` serta satu `users.id_regions` aktif. Officer 1 SS dapat `BRANCH` dengan daftar Branch eksplisit atau `REGION`. Role lain tetap `BRANCH`.
- Scope Region dihitung saat membaca context: seluruh Branch aktif dalam Region tersebut, termasuk Branch aktif yang ditambahkan kemudian. Harus ada penugasan role aktif; penempatan Region saja tidak memberi akses. Menonaktifkan semua assignment mencabut akses. Primary Branch/service area tetap mengikuti validasi hierarki dan tidak memperluas akses.
- Akun Branch yang lama tetap `BRANCH`. GM lama perlu dikonfigurasi menjadi Region melalui Kelola Akun sebelum dipilih untuk paket Operation baru. Tidak ada migrasi massal penugasan akun.
- SQL scope diterapkan sebelum daftar/count/picker Finance/SPK/Pembayaran, hydration graph, private document dan sumber BOQ. Worker mengecek ulang akun/status/revisi/UUID/unit/Branch; tidak mempercayai role dari unggahan.
- Dashboard menambah filter Unit Kerja sesuai akun; angka dan matriks dihitung dari graph authorized unit terpilih, sehingga ID Project sama di dua unit tidak digabung dalam matriks unit tersebut.

## Skema dan kompatibilitas

Marker server-owned `approvalScheme=operation-v1` mengaktifkan lima/tujuh role Operation pada PP/pembayaran baru dan PP lanjutan Operation. SQL menyimpan `approval_order_version=4`; order 1–3 Konstruksi/historis dipertahankan. Unit PP yang sudah disimpan tidak dapat diganti untuk memindahkan dokumen antarunit. Input marker/client role/unit tidak dapat menggantikan keputusan akun atau validasi Laravel.

PP lama yang sudah diajukan/Sah/berriwayat mempertahankan skema, assignee, keputusan, status dan snapshot. Khusus PP Operation lama yang belum memiliki marker Operation dan telah menugaskan approver Konstruksi, hanya akun approver asli tersebut tetap dapat mengakses/menyelesaikan tugas lamanya dalam Branch yang diizinkan. Pengecualian ini tidak membuka seluruh Operation untuk Admin Konstruksi atau approver Konstruksi lain, dan tidak berlaku pada paket Operation baru. Pembayaran baru dari PP Operation historis memerlukan **penetapan lima approver Operation berikutnya secara eksplisit**; pembayaran lama tidak dialihkan otomatis.

Arsip workspace, snapshot kasbon terenkripsi, files, uang/PPh/APM, numbering, ledger dan keputusan lama tidak ditulis ulang saat GET/deploy. Draft yang belum punya riwayat mengikuti validasi editable saat disimpan eksplisit; tidak ada GET backfill. Unknown legacy free-text unit diperlakukan Konstruksi untuk kompatibilitas lama; v3 wajib unit kanonis dan migration menolak v3 unit tidak valid sebelum DDL.

## Dokumen dan penandatangan

Template tetap sama. Judul PP baru bertanda Operation menjadi **PROJECT PLAN SWAKELOLA OPERATION**, Header menampilkan unit Operation. General Manager Operation menggantikan Head of PMO; nama/NIK/jabatan diambil dari akun terpilih/snapshot aktual. BA KH/SPK memakai source `pp-gm-operation`. BARP dan lampirannya mengganti slot Support dengan Officer 1 SS, Deployment dengan Manager SS dan Head dengan GM; Waspang/Mandor tetap dari dokumen pekerjaan.

Tabel fisik VIII PP mempertahankan susunan empat slot yang setara template: Officer 1 Operation, Officer 1 SS, Manager SS, GM; Manager Branch tetap approval digital kedua sebagaimana Admin Area pada Konstruksi. EVP tetap baris administratif terpisah. Kasbon mempertahankan tujuh slot fisik 2/3/2: Officer 1 Operation/pengaju; Officer 1 SS/Manager SS/GM; Mgr Finance & Settlement/EVP. Manager Branch dan Finance tetap approver aplikasi tanpa slot fisik, sesuai aturan template sebelumnya.

Jabatan akun mengikuti aturan snapshot USER_JABATAN; identitas historis tidak diganti dengan akun saat ini. Semua sel tanggal VIII PP kosong; cap tanpa tanggal hanya pada preview. Kasbon maker/EVP tetap mengikuti UUID/keputusan Manager Finance aktual sesuai KASBON_PREVIEW_STAMPS. Word/print/paket gabungan mengosongkan ruang tanda tangan. Tidak membuat tanda tangan atau keputusan EVP/GM palsu.

## Migration dan penerapan

Migration baru: `2026_11_18_000002_enable_operation_workflow.php`.

- Guard target `its` dipertahankan. Menambah dua kolom akun, FK unit PP serta index `(workspace_id,work_unit_id,branch_id)`.
- Menambahkan master work unit bila belum ada dan role SS bila belum ada; kode/ID/nama katalog yang sudah tersedia dipertahankan. ID role tidak memakai ulang ID audit yang pernah dihapus.
- Preflight JSON/unit per PP, page500; isi hanya flag SQL PP yang masih NULL dari unit PP itu sendiri. Tidak memakai unit row Project bersama, tidak mengubah JSON/status/keputusan/nilai/archive.
- MySQL/MariaDB memperluas CHECK approval untuk order4; SQLite menggunakan validator/trigger yang sudah ada dan memulihkan trigger setelah rebuild kolom. Rollback ditolak setelah order4/scope akun/role SS digunakan. Role katalog tetap dipertahankan saat rollback kosong.
- DDL MySQL dapat melakukan implicit commit. Backup SQL/file privat dan uji staging lebih dulu; tidak menjanjikan semua DDL dapat rollback transaksional. Migration diuji SQLite memory, belum diuji pada aaPanel/MySQL nyata.

Urutan operator server setelah backup: maintenance singkat, pull kode lengkap, `php artisan migrate --force` memakai PHP situs, build `npm ci && npm run build && npm run check:assets` (atau deploy build yang cocok), `php artisan optimize:clear`, lalu restart **hanya worker upload Area 3** lewat konfigurasi Supervisor yang sudah terpasang. Jangan menjalankan `migrate:fresh`, reset demo atau seeder akun pada server berdata. Restart worker memuat handler baru; worker lama dapat menolak upload yang valid. Atur akun SS/GM Region dan Jabatan sebelum membuat paket baru, lalu tes akun/Branch Operation di staging.

Agen tidak menjalankan migration pada its aktif, tidak mengubah .env/APP_KEY, tidak restart worker atau deploy. Backup SQLite demo baca-saja disimpan privat sebelum perubahan. Pengajuan Konstruksi dan pembayaran final lama bukan sasaran perubahan finansial ini.

## Query, pengujian dan batas

Context akun Branch/Region memakai satu UNION query (kolom eksplisit, batched IDs); tidak ada query per approver. Metadata bind tetap5query. Unit filter SQL memakai subquery/index, tidak memuat seluruh transaksi sebelum pemfilteran. Pembacaan signers kasbon baru membatch User dan effective assignments (2query, mendukung Region). Pure resolver/dashboard/document menambah0query; grafik operasional yang authorized masih dapat membesar bersama workspace, bukan klaim pagination seluruh graph.

Bukti suite: **341PHP/8448assertions** SQLite memory, **370frontend**, TypeScript/Vite, assets42references/33preloads×3profil, scoped Pint dan diffcheck. Tes baru mencakup provisioning/scope Region dinamis, unit silang403, PP shared Project flag, legacy original-assignee exception, real5approval paket, real7approval pembayaran terikat kasbon, transfer worker tanpa session, lunas tanpa APM, BA PP, KTP/bank queue dan private read.

Fixture query aktual setelah fitur: BARP metadata save60; bind5; Finance list4/100pending; kasbon issue45; transfer sessionless43; settlement/APM edit40; KTP/bank worker39; current-only kasbon3service/6HTTP; revision1service/2HTTP; incremental write8 vs full1330 pada20PP. Angka adalah fixture query, bukan jaminan latency/load test. Kenaikan +1 pada beberapa jalur berasal dari fresh account/assignment batch yang mengerti Region; tidak ada query per item BOQ/per role signer. Migration membaca500snapshot per page dan satu UPDATE per flag PP NULL; biaya maintenance bertambah sesuai jumlah PP.

Browser sintetis18kasus (Header interaktif/PP/kasbon ×375/768/1440 ×light/dark) lulus tanpa JS error; Header tanpa page overflow, pilihan unit/role Operation, request GET fixture saja. Native Word enam dokumen (PP, BA KH, SPK, BARP, BA PP, kasbon), XML/ZIP/CRC serta hasil render diperiksa. PP dan kasbon browser print menghasilkan PDF nonblank tanpa cap. Template BARP fixture dua halaman tetap utuh, bukan dipaksa satu halaman. Bukti terstruktur: [qa/operation-workflow-20261118.json](qa/operation-workflow-20261118.json).

Belum diuji: login akun live/aaPanel, MySQL DDL aktual, beban/concurrency besar, Microsoft Word desktop, tanda tangan/cetak fisik dan transfer bank nyata. Semua fixture identitas/uang untuk QA sintetis; tidak ada transaksi bank atau produksi yang disimulasikan sebagai keputusan nyata. Data aktif lokal/server tetap menjadi tanggung jawab penerapan operator dengan backup.

## 18 November 2026 — BA PP sejak BA RP disimpan

Pengguna menegaskan BA PP dapat disahkan sebelum seluruh approval, sejak BA RP Final disimpan. Final pada alur baru/diadopsi eksplisit memakai source BARP server-owned dan menahan pengajuan pembayaran sampai BA PP Sah; perubahan BARP memerlukan pengesahan ulang. Dokumen utama/lampiran BA PP Sah dapat Word/print lebih awal, ruang TTD kosong; tujuh approval/ledger/release dan full-package export gate tetap. Tidak ada migration atau perubahan massal data lama. Lihat [BAPP_BEFORE_PAYMENT](BAPP_BEFORE_PAYMENT.md) untuk cakupan, data historis, query, QA dan deploy.

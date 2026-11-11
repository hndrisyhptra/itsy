# Penyimpanan aplikasi MySQL — 5 November 2026

**Pembaruan 11 November 2026:** data pribadi Mandor SPK dan kasbon Finance sudah diimplementasikan untuk pengajuan baru `kasbon-v1`; Parsial/Final boleh dibayar sebelum scan, dengan kasbon terbit, persetujuan Finance, bukti transfer dan APM. Riwayat lama tetap. Migrasi baru belum diterapkan pada its aktif; lihat [alur, tabel, privasi, deploy dan batas pengujian](FINANCE_KASBON.md). Aturan lama di bawah tetap berlaku pada dokumen historis bila berbeda.


**Pembaruan9 November2026 — Payment Parsial:** Admin dapat mengajukan Payment setelah lima approval/Head tanpa scan, Finance memverifikasi lalu mencatat APM/lunas. Scan dapat disusulkan dan berstatus NOK/OK terpisah; Final/v1/v2 dan histori tetap. Kasbon ditunda sampai format pengguna tersedia. Authority terbaru, migration baru (belum diterapkan pada its aktif), relasi/proyeksi SQL dan bukti: [PARTIAL_PAYMENT](PARTIAL_PAYMENT.md). Penjelasan scan wajib untuk semua dokumen di bawah merupakan aturan sebelumnya bila berbeda.

Aplikasi akun sekarang membaca dan menyimpan transaksi pada database **its** (MySQL protocol, XAMPP MariaDB 10.4.28). Sebelumnya database ini sudah digunakan untuk akun/master, tetapi seluruh transaksi berada pada satu JSON `prototype_workspaces.state`. Itulah sebabnya PP terlihat di aplikasi sementara `project_plans` kosong.

Migration `2026_11_05_000001_enable_relational_workflow_storage` dan perintah `area3:activate-storage` sudah diterapkan pada database aktif. Data yang dipindahkan: **1 PP Sah**, **20 baris/20 komponen BOQ**, **1 BA KH/11 komponen kontrak**, **5 keputusan approval**, 27 rincian anggaran dan 1 referensi file BOQ. Nomor PP tetap `0001/LG.000/TA-03R502/11-2026`. Belum ada SPK/pembayaran pada data pengguna, sehingga tabel terkait memang masih kosong.

## Tabel yang dipakai aplikasi

| Data | Tabel |
|---|---|
| ID Project; satu Project dapat memiliki banyak PP | `projects` (`project_code`, `id_project`) |
| PP, nomor, status dan relasi Project | `project_plans` (`id_pp`, `pp_no`, `project_id`) |
| BOQ dan harga/volume komponen Owner | `boq_lines`, `boq_components` |
| Jadwal, tim, ringkasan kategori biaya | `project_plan_schedule`, `project_plan_team`, `project_plan_costs` |
| Rincian Mandor, Non Stock, BOP dan waktu penggunaan | `project_plan_budget_rows` |
| BA KH/SPH/kesepakatan mandor | `contracts`, `contract_components` |
| SPK | `work_orders` (`id_spk`) |
| Pengajuan pembayaran dan volume aktual | `payment_terms`, `term_actuals` |
| Siklus, penugasan dan keputusan approval PP/pembayaran v2/v3 | `approval_runs`, `approval_steps`, `approval_decisions` |
| Eviden pekerjaan termin (PDF/foto), path dan riwayat | `term_work_evidence`, `document_files`; lihat [TERM_WORK_EVIDENCE](TERM_WORK_EVIDENCE.md) |
| File asli dan scan privat, verifikasi Finance | `document_files`, `term_scans` |
| Pengajuan Payment Parsial/siklus/verifikasi Finance | `partial_payment_requests` (migration baru perlu deploy), snapshot `payment_terms.document_data` |
| APM dan jumlah pelunasan | `apm_settlements`: otoritas scan historis atau request Payment Parsial, sesuai migration baru |
| BA PP beserta riwayat pelepasan | `ba_completions` |
| Saldo dan perpindahan volume | `volume_balances`, `volume_movements` |
| Penomoran, konfigurasi dan aktivitas | `numbering_rules`, `number_sequences`, `workflow_metadata`, `workflow_events` |
| Identitas pelaku, revisi dan idempotensi per perintah | `prototype_audits`, `command_receipts` |

ID SQL transaksi berupa UUID deterministik dengan primary key bernama `id_pp`, `id_boq`, `id_contract`, `id_spk`, dan seterusnya. `source_id` menyimpan ID lama yang dipakai URL aplikasi, sehingga tautan dan file privat tetap berlaku. Akun tetap `users.id_user`; akun/master kiriman tidak diganti. `boq_lines.source_price_id` menunjuk harga kiriman `designator_package_prices`; aplikasi tidak mengarang kontrak/tanggal KHS untuk mengisi `khs_prices`.

Penyimpanan menggunakan kombinasi kolom relasi dan snapshot JSON **per dokumen**, bukan lagi satu JSON seluruh transaksi. Kolom `document_data` menyimpan seluruh rincian formulir, teks cetakan, pemilihan approver, data historis, dan riwayat yang belum mempunyai kolom tersendiri. Laravel menyusun state aplikasi dengan membaca baris dokumen tersebut. Kolom relasi/status, BOQ, anggaran, keputusan dan saldo diperbarui pada transaksi yang sama. Perubahan dilakukan melalui aplikasi; mengubah kolom proyeksi secara manual di phpMyAdmin tidak menyunting snapshot dokumen.

`prototype_workspaces.state` menjadi **arsip sebelum pemindahan** dan tidak diperbarui/dipakai sebagai fallback. Tabel workspace masih digunakan untuk kunci bersama, revisi dan identitas direktori file. `workflow_metadata` hanya menyimpan konfigurasi/referensi dan metadata; koleksi transaksi/aktivitas tidak disalin ke sana. Workspace lama milik sesi lain dan SQLite lama tidak diimpor otomatis.

## Jaminan simpan dan pemindahan

- Laravel tetap memvalidasi seluruh aturan dan izin Branch. Kelima approver mengikuti akun login; Finance memverifikasi scan aktual sebelum APM.
- Kunci workspace, transaksi SQL, revisi, receipt dan audit diproses bersama. Kegagalan SQL membatalkan header, rincian, nomor urut, revisi dan audit. Permintaan ulang menggunakan UUID yang sama tidak menggandakan transaksi.
- Saldo diturunkan dari ledger yang sama. Pembukaan/perubahan stok BOQ draft tercatat dalam receipt/audit; `volume_movements` mencatat transfer antar saldo dengan jumlah delta nol.
- Pemindahan hanya mengambil workspace akun yang dipilih server, mempertahankan nomor/keputusan/revisi, dan membandingkan hasil baca ulang. Pengulangan setelah aktif tidak mengimpor arsip kembali. Workspace yang belum diaktifkan mengembalikan 503, bukan data contoh.
- Rollback migration ditolak jika sudah ada transaksi/metadata aktif. Cadangkan database **dan direktori file privat** sebelum pemulihan terencana; jangan memakai `migrate:fresh`, reset atau seed pada data aktif.

Untuk instalasi yang diperbarui, jalankan `php artisan migrate`, kemudian `php artisan area3:activate-storage`. Pada instalasi tanpa workspace, penyimpanan tabel diinisialisasi saat login pertama. Kedua langkah tersebut **sudah dijalankan** pada instalasi pengguna ini. Konfigurasi koneksi/kredensial yang ada dipertahankan; nama aplikasi dan debug disesuaikan (`APP_DEBUG=false`).

Cadangan sebelum penerapan: `storage/app/private/backups/its-before-relational-workflow-20261105-101741.sql`, 650.930 byte, SHA-256 `9fc7ad0b0c1a4d40c19ee0051001a0bc92eb08df477213b35585cf3f0368b639`. Pemeriksaan setelah penerapan membuktikan akun/master, byte arsip workspace, nomor PP dan revisi 13 tidak berubah. Database/proyek Dompis Prov tidak digunakan.

## Batas yang masih berlaku

Aplikasi login tidak menyediakan reset, pergantian peran demo, tombol contoh BOQ, atau fallback halaman tanpa sesi. Upload BOQ tetap file `.xlsx` sesungguhnya dengan layout yang didukung. Fixture/skenario sintetis hanya dipertahankan untuk pengujian terisolasi.

Snapshot per dokumen masih digunakan untuk rincian dinamis, riwayat v1 tanpa identitas SQL, Finance history, pelepasan dan alokasi lanjutan. Tabel konsep `volume_releases`, `continuation_allocations` dan `boq_import_*` belum menjadi sumber baca aplikasi; data prosesnya tetap disimpan pada dokumen, baris BOQ/manifest privat dan ledger yang relevan. Retensi file otomatis belum tersedia. Cetak memakai HTML/browser; TTD basah dan penyerahan dokumen dilakukan manual. APM dicatat Finance sebagai referensi pelunasan; belum ada integrasi bank/ERP yang memindahkan dana. MFA/SSO, uji beban dan deployment internet belum diuji.

## Bukti pengujian

104 pengujian PHP / 1.406 assertion pada SQLite `:memory:`; 67 pengujian frontend; build produksi dan Pint lulus. Pengujian tambahan membuktikan pembacaan tetap berjalan setelah arsip tidak digunakan, satu ID Project memiliki dua PP, nomor dan ID unik, serta rollback SQL menyeluruh.

Pada server MariaDB sementara terisolasi, salinan cadangan berhasil dimigrasikan/dipindahkan dua kali tanpa duplikasi. Uji perintah server berhasil menjalankan PP → lima approval → BA KH/SPK → termin Final → lima approval → scan → verifikasi Finance → APM, menghasilkan 20 receipt, 3 movement, 1 SPK, 1 scan dan 1 settlement. Scan dalam uji MySQL ini berupa metadata sintetis; validasi upload file aktual tetap diuji pada suite HTTP terisolasi. Tidak ada transaksi/akun uji yang ditambahkan ke database pengguna. Browser setelah login belum diuji manual pada sesi ini.

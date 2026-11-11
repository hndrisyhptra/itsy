# Koreksi approval Head PP0002 — 11 November 2026

Permintaan pengguna: PP `0002/LG.000/TA-03R546/11-2026` kembali menunggu Head of PMO karena terlanjur disetujui; empat persetujuan sebelumnya tetap. Ini koreksi administratif eksplisit, bukan tombol Kembalikan/penolakan biasa. Alur penolakan biasa tetap mengulang lima tahap setelah pengajuan ulang.

## Bukti yang tersedia

Snapshot lampiran menunjukkan lima keputusan Setujui pada v3/project-support-first. Pengguna kemudian membagikan SELECT server: PP APPROVED/revision40, workspace `01a1be23-4385-7274-a9b7-27be80ec00f3`; BA KH dan SPK Sah; termin1 FINAL REJECTED/Ditolak, APM/nilai settlement NULL. Run pertama APPROVED berisi lima keputusan, Head akun34 pada 11 November 2026 04:09:30.727574 (waktu SQL). Tidak mengakses phpMyAdmin/server atau membuka tautan hasil SQL pengguna.

Identitas target dan keputusan terdapat pada `deploy/corrections/pp0002-r546-head.json`; file tidak memuat password, NIK, rekening atau isi BOQ. PP/run/step/decision IDs sesuai algoritme UUID deterministik aplikasi. Revisi40 adalah revisi **PP**, bukan otomatis revisi workspace terakhir. Perintah memeriksa ulang keadaan server saat dijalankan.

## Perilaku koreksi

- CLI `area3:reopen-pp-head` default hanya memeriksa. Memerlukan username Superadmin aktif dan penugasan Branch PP. Target koneksi harus its; workspace harus workspace akun resmi. Tidak ada endpoint web/perintah domain publik yang membuka ulang approval.
- Apply eksplisit memerlukan revisi workspace dari pemeriksaan terakhir. Workspace dan akun operator dikunci; Head terpilih harus tetap aktif/berperan Head dan memiliki Branch tersebut. Seluruh identitas, lima keputusan, run, PP/revisi, satu BA KH/SPK Sah dan satu termin Final pertama ditolak harus cocok. Pembayaran/APM, proses kasbon/scan, lanjutan/BA PP, atau upload terkait yang masih berjalan membuat koreksi ditolak.
- Sebelum write, simpan dan verifikasi SHA256 cadangan privat `storage/app/private/head-corrections/{workspace}/{request}.json`. Isinya snapshot sebelum koreksi, baris PP/run/step/decision/dokumen turunan serta rencana/revisi. File privat; bukan berkas untuk dibagikan/publik. Bila SQL gagal, cadangan tetap ada dan data/revisi/audit/receipt di-rollback bersama. Retry dengan isi sebelum yang sama dapat memakai cadangan itu; isi berbeda ditolak.
- PP menjadi Diajukan/SUBMITTED dengan empat approval aktif yang sama persis. Nomor, BOQ, anggaran, jadwal, assignee, identitas signer, BA KH/SPK/termin, berkas dan ledger tidak ditulis ulang. `prototype_workspaces.state` tetap arsip.
- Semua keputusan Head lama dan siklus SQL pertama tetap utuh. `headApprovalReopen` menyimpan offset history, empat keputusan asli, ID keputusan sumber, keputusan Head lama, operator/alasan/waktu/request/hash rencana. Writer memproyeksikan siklus kedua: empat keputusan asli dibawa beserta identitas/tanggalnya, Head belum memutuskan. Salinan proyeksi tersebut bukan aksi persetujuan baru oleh empat akun.
- Head asli memutuskan sendiri lewat aplikasi. Setujui menambahkan keputusan baru ke history/siklus kedua dan PP Sah lagi. Tolak/Kembalikan tetap wajib catatan, menghapus approval aktif dan mengikuti pengajuan ulang normal dari PM; marker historis dipertahankan server saat penyimpanan berikutnya. Penanda koreksi tidak diadopsi dari input form/HTTP.
- Selama PP yang memiliki marker belum Sah, Laravel menolak mutasi BA KH/SPK/termin, scan/eviden, Finance/kasbon/APM, BA PP/pelepasan/lanjutan yang merujuknya. React memberi feedback sebelum mutasi optimistis melalui recorder yang sama. Dokumen turunan tetap dapat dibaca dengan status tersimpannya; timeline/approval PP menjelaskan koreksi dan penahanan proses, sedangkan cap Head aktif tidak muncul sebelum keputusan baru.
- UUID/hash/operator yang sama mengembalikan hasil replay tanpa koreksi ganda, termasuk sesudah Head menyetujui lagi. Isi/akun lain dengan UUID sama ditolak. Satu marker per PP; koreksi ulang atas PP yang sama membutuhkan kajian baru dan tidak dilakukan otomatis.

## Menjalankan di server

Cadangkan database its dan file privat server sesuai prosedur operator. Deploy **seluruh kode fitur** dan build, pertahankan `.env`, APP_KEY, data dan file privat; tidak ada migration baru untuk koreksi ini. Jangan menerapkan marker memakai SQL manual atau menjalankan kode lama setelah apply. Worker its juga harus memuat kode baru; jangan restart program aplikasi lain.

Di terminal server:

```sh
cd /www/wwwroot/itsy
php artisan area3:reopen-pp-head deploy/corrections/pp0002-r546-head.json --operator=superadmin
```

`superadmin` adalah username akun yang dinyatakan pengguna, bukan akun/password baru. Gunakan akun Superadmin aktual bila username berbeda. Jalankan dengan PHP CLI yang sama dengan situs jika `php` default server berbeda.

Hasil harus menyatakan `applied:false`, target PP yang benar, `nextApprover:Head of PMO`, `retainedApprovals:4`, dan `workspaceRevision`. Setelah hasil sesuai, gunakan angka `workspaceRevision` itu untuk apply:

```sh
php artisan area3:reopen-pp-head deploy/corrections/pp0002-r546-head.json --operator=superadmin --workspace-revision=ANGKA_HASIL_PEMERIKSAAN --apply
```

Placeholder harus diganti angka; jangan menyalin revisi PP40 ke opsi ini tanpa pemeriksaan. Konflik revisi berarti jalankan pemeriksaan lagi. Perubahan target/dokumen/keputusan bukan alasan melewati guard atau mengedit rencana asal-asalan. Perintah tidak push/pull/build, migrate, login browser, menyetujui Head atau memproses transfer.

Setelah `applied:true`, muat ulang aplikasi: PP harus Diajukan/4 dari5, menunggu Head akun34. Head masuk melalui akunnya. BA KH/SPK tetap Sah, termin tetap Ditolak, APM tetap kosong; proses berikutnya ditahan sampai PP Sah lagi. Ulangi SELECT status serta run/step/decision yang sebelumnya dipakai: run1 APPROVED/Head lama tetap; run2 PENDING dengan keputusan Head kosong sebelum approval ulang.

## Arsitektur, query dan batas verifikasi

`ProjectHeadCorrection` adalah service maintenance terpisah, menggunakan kunci workspace/revisi, incremental RelationalWorkflowStore, audit/receipt atomik dan file privat. Tidak menerima replacement state. `ProjectHeadReopen::cycles` menambahkan satu batas siklus administratif pada history lama tanpa menghapus keputusan; dokumen tanpa marker dan seluruh pembayaran memakai pemisahan siklus lama. Gerbang domain dijalankan sebelum mutasi dan hanya aktif bila ada PP marker yang belum Sah. Tidak ada endpoint, tabel, migration, katalog master penuh atau lookup per baris BOQ baru.

Fixture apply satu PP/lima tahap: **84 query**, budget140, tanpa SQL katalog harga; term/contract/BOQ/biaya/ledger/arsip tetap. Biaya CLI ini bukan benchmark waktu respons web atau beban produksi. Gerbang web menambah0 SQL; jalur aggregate yang sudah ada tetap membaca state untuk validasi silang.

Backup tabel its lokal sebelum kode: `its-before-head-correction-20261111-064125.sql`, 2.118.857 byte, SHA256 `415691e3c9b0a12e08a43fcdf01abb55c49b90c334dbd3fe956f016335ddcfce`. Dua percobaan sebelumnya ditandai `.failed`: koneksi sandbox ditolak dan dump dengan routines terkena metadata mysql.proc lama. Dump berhasil mencakup tabel/data/triggers; **stored routines tidak tercakup**, restore belum diuji. Tidak memperbaiki metadata MariaDB, mengganti .env atau mengubah transaksi its. Backup lokal bukan backup server pengguna.

Pengujian aplikasi memakai SQLite memory fail-closed/disk palsu, termasuk key-order JSON MySQL yang disimulasikan, bukan apply pada engine MySQL produksi. Pemeriksaan browser akun/visual/keyboard, operasi CLI server aaPanel dan status server setelah apply belum diverifikasi agen. Tidak ada approval fiktif/TTD/gateway bank atau simulasi pembayaran baru. Koreksi masih memerlukan eksekusi operator pada server.

# Penolakan dan pengajuan ulang PP dan pembayaran

Pembaruan 5 November 2026 berlaku untuk dokumen **workflowVersion 3**, termasuk PP/termin versi 3 yang telah berstatus Ditolak. Penolakan dari salah satu lima approver wajib mempunyai catatan. Admin Konstruksi memperbaiki dan mengajukan ulang dokumen yang sama; persetujuan dimulai kembali dari PM Konstruksi, Admin Area, Mgr Deployment, Mgr Bussiness Support, lalu Head of PMO.

## Perilaku aplikasi

1. Approver terpilih pada tahap yang sedang aktif menekan Tolak dan mengisi alasan. Catatan kosong/spasi ditolak tanpa mengubah state/revisi/keputusan SQL.
2. Status dokumen menjadi Ditolak. Approval aktif dikosongkan, sedangkan keputusan, akun, waktu dan catatan sebelumnya tetap ada dalam riwayat. Dokumen tidak menjadi tugas approver sampai diajukan lagi.
3. Admin membuka **Project Plan → Edit & Ajukan PP**, atau detail **Pembayaran** untuk termin yang ditolak. Form menampilkan catatan penolakan. Field yang biasa dapat diubah pada draft kembali tersedia; aturan template, harga/volume, penugasan dan Branch tetap berlaku. Superadmin tetap dapat membantu tindakan operasional sesuai izin lama.
4. Admin dapat menyimpan Draft dahulu atau langsung mengajukan ulang setelah perbaikan valid. Nomor PP, ID Project, identitas dokumen, nomor termin dan SPK dipertahankan; tidak membuat dokumen/termin pengganti atau memakai slot termin tambahan.
5. Pengajuan ulang memulai **0/5** di PM terpilih. Approver lain tidak dapat melompati PM; semua lima persetujuan harus diberikan kembali pada siklus baru. Persetujuan dari siklus yang ditolak tidak dipakai untuk memenuhi siklus baru.

Eviden PDF/foto termin versi 3 yang ditolak dapat ditambahkan atau dikeluarkan sebelum pengajuan ulang. Penghapusan bersifat logis; file/metadata lama tetap ada di riwayat. Saat diajukan ulang lampiran terkunci kembali. Scan TTD fisik berbeda dari eviden pekerjaan: penolakan tidak membuka scan/Finance/APM, tidak menciptakan BA PP, tidak melunasi tagihan dan tidak mengizinkan termin baru. Seluruh batas tiga termin, Final, APM, BA PP dan ledger tetap berlaku.

Keputusan Finance setelah scan tetap melalui verifikasi/koreksi scan yang sudah ada; pembaruan ini menangani penolakan pada lima approval dokumen PP/termin. Dokumen historis versi 1/2 mempertahankan aturan penolakan lamanya; tidak ditulis ulang atau dipindahkan versi otomatis.

## Database

Tidak ada perubahan struktur atau migration. Snapshot per dokumen menyimpan status dan history. Proyeksi `project_plans`/`payment_terms` tetap konsisten dengan snapshot; `approval_runs` menyimpan siklus lama berstatus `REJECTED`, sedangkan pengajuan ulang membuat siklus baru `PENDING`. `approval_steps` berisi lima tahap baru dan `approval_decisions` lama tetap utuh. Seluruh simpan, revisi, receipt dan audit dijalankan dalam transaksi SQL yang sama.

Cadangan sebelum perubahan: `storage/app/private/backups/its-before-rejection-resubmit-20261105-144618.sql`, 1.019.899 byte; SHA-256 `1541823e46757029416eb9fab285eab65bbd534316b716efaaf50a2e774aae7c`. Tidak ada data uji atau keputusan sintetis yang dimasukkan ke database pengguna.

## Verifikasi

**127 pengujian PHP / 2.282 assertion**, **86 pengujian frontend**, TypeScript/Vite build dan Pint lulus. Pengujian Laravel tetap memakai SQLite `:memory:` yang fail-closed, termasuk pemeriksaan cached config; tidak dijalankan pada MySQL aktif.

Sepuluh kasus HTTP/SQL baru mencakup penolakan PP dan termin Final di setiap tahap, catatan wajib, larangan role lain, simpan Draft/pengajuan ulang, histori/nomor/identitas yang dipertahankan, metadata approval/pembayaran client yang diabaikan, siklus baru, urutan lima approver serta BA PP yang baru muncul sesudah persetujuan Final lengkap. Satu uji HTTP tambahan memeriksa penggantian eviden setelah penolakan dan penguncian kembali saat pengajuan ulang. Frontend memeriksa alur yang sama, tampilan alasan, batas dokumen historis dan kontrol lampiran.

Build aktif `main-CrxKAb5G.js`. Tidak perlu migration atau reset data; muat ulang browser untuk mengambil bundle baru. Browser dengan login pengguna dan mesin MySQL terisolasi tidak diulang pada perubahan ini; aturan SQL/snapshot diuji pada suite HTTP SQLite. Alur baru merupakan penyimpanan server sesungguhnya, bukan simulasi; TTD fisik dan pencatatan APM tetap manual seperti sebelumnya.

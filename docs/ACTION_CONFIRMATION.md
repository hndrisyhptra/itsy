# Konfirmasi tombol YA / TIDAK — 9 November 2026

Tombol yang menjalankan tindakan meminta konfirmasi sebelum bekerja. **YA** melanjutkan tindakan yang disebutkan, **TIDAK** membatalkan tanpa menjalankan handler tindakan. Dialog konfirmasi yang sudah tersedia memakai dua label yang sama agar tidak ada konfirmasi berulang untuk satu tindakan.

## Cakupan

- PP dan Pembayaran: simpan draf, ajukan, keputusan approval, penugasan approver dan pembuatan termin/draf pembayaran berikutnya.
- BA KH/SPK/BA PP: simpan, terbitkan, sahkan, pelepasan volume serta pembuatan/reservasi PP lanjutan.
- Finance: ajukan Payment, verifikasi/kembalikan Payment atau scan, APM dan pencatatan pembayaran; rincian nominal/APM pada dialog lama tetap tersedia.
- BOQ/eviden/scan: konfirmasi sebelum upload dimulai, menghapus baris/file, menggunakan hasil antrean atau melanjutkan antrean. Pemilihan scan sendiri hanya menyiapkan file; tombol Unggah Scan meminta konfirmasi.
- Master Designator/KHS/Paket KHS: simpan form termasuk melalui Enter, hapus, upload pemeriksaan dan commit impor. Pemeriksaan tidak berubah menjadi commit otomatis.
- Profil/akun: simpan profil/pengguna, simpan akun SQL/peran/Branch, pembuatan Superadmin pertama, keluar akun. Draf sesi baru dibersihkan setelah YA saat logout. Login biasa dan kontrol tampil/sembunyikan password tidak diberi konfirmasi.
- Tambah/hapus baris anggaran, anggota tim lama dan penandatangan; unduhan Word/CSV/template/scan/eviden/sumber BOQ/cadangan, salin CSV, cetak, reset filter, ganti tema dan muat ulang data.
- Reset/skenario demo tetap hanya pada mode offline yang diizinkan, dengan konfirmasi; tidak diaktifkan pada workspace akun.

Navigasi menu, halaman/tab, pencarian/filter, pemilihan PP/SPK, preview, validasi baca-saja, membuka form, Batal dan Tutup tetap langsung bekerja. Ganti PP yang sudah mempunyai dialog tetap meminta YA/TIDAK. Perubahan input biasa tidak memunculkan konfirmasi pada setiap ketikan.

## Implementasi dan aksesibilitas

`confirmation.ts` membuat satu native modal `<dialog>` hanya saat dibutuhkan, bukan satu dialog per baris tabel. Pesan dipasang sebagai textContent, bukan HTML. Role alertdialog memiliki judul/deskripsi, fokus awal TIDAK dan pemulihan fokus ke kontrol asal. Native dialog menangani modalitas/fokus keyboard; Escape, klik backdrop, pergantian route atau keluar halaman menyelesaikan permintaan sebagai TIDAK. Keyboard dialog tidak menutup Modal React di bawahnya. Jika native dialog tidak dapat dibuka, tindakan dibatalkan.

Hanya satu permintaan konfirmasi aktif; klik ganda selama dialog tidak mengantrikan tindakan tambahan. Setelah YA, kontrol asal harus masih terpasang dan aktif. `Button confirm="..."` menunda callback; `ActionLink` menunda unduhan asli dengan mempertahankan href/download/target. Form submit memakai konfirmasi pada onSubmit, bukan hanya klik tombol, sehingga Enter tetap melalui konfirmasi. Form akun tetap memakai validasi browser, submitter, CSRF dan rute asli. Styling bersama mengikuti warna #9FA1FF dan mode gelap.

Untuk pengembangan berikutnya, gunakan `Button confirm` untuk aksi baru. Tombol submit harus dikonfirmasi pada submit form agar Enter juga tercakup. Aksi yang sudah mempunyai dialog konfirmasi spesifik cukup memakai YA/TIDAK; jangan menambah dialog kedua. Upload yang dimulai oleh file input memakai confirmAction sebelum transport. Konfirmasi browser bukan pengganti validasi/otorisasi Laravel.

## Arsitektur, query dan batas

Perubahan ini hanya UI/Blade; tidak mengubah command/domain Laravel, peran/Branch, kelima approval, aturan penolakan, Finance/APM, berkas privat, ledger atau transaksi tersimpan. Tidak ada endpoint, query SQL, fetch/poll, worker, paket atau migration tambahan. TIDAK tidak memanggil handler, upload, request simpan atau unduhan. Setelah YA, jalur penyimpanan/revisi/idempotensi sebelumnya tetap digunakan.

Tidak ada penulisan ke its aktif, perubahan .env, seed, reset demo bersama atau akses Dompis. Tidak dibuat backup workflow baru karena kontrak server/state/database tidak diubah. Migration Payment Parsial dari perubahan sebelumnya tetap terpisah dan status deploy-nya tidak diubah fitur ini.

**Verifikasi:** 239 tes PHP / 4.944 assertions, 243 tes frontend, TypeScript/Vite build dan diff check lulus. Enam regresi baru menguji pembatalan/YA sekali saja, klik ganda, Escape/backdrop/route/pagehide, teks literal, kegagalan dialog, kontrol nonaktif, native submit/Enter/validasi serta audit titik masuk mutasi/upload/form. Pengujian memakai fake DOM dan SQLite memory fail-closed. Query-budget/ledger/Branch/pembayaran yang sudah ada tetap lulus; tidak mengklaim optimasi throughput dari fitur konfirmasi.

Browser nyata, fokus native dialog/responsif/dark/keyboard, unduhan/cetak dan klik akun/server belum diuji manual; pengujian fake DOM/markup tidak membuktikan tampilan aktual. Build produksi sudah dibuat lokal; deploy harus membawa kode dan hasil build terbaru. Bukti: [qa/action-confirmation-20261109.json](qa/action-confirmation-20261109.json).


## Perbaikan CSS preload subfolder — 9 November 2026

Laporan sesudah fitur konfirmasi: Unable to preload CSS for /build/assets/confirmation-CPS8TDLk.css. Pemeriksaan build menemukan file lokal lengkap, tetapi generated helper memakai /build/ dari akar origin. Pada akses Apache subfolder, path CSS berada di luar aplikasi. Vite sekarang memakai base ./ agar dependency CSS/JS dihitung relatif terhadap import.meta.url modul yang benar. Laravel @vite/manifest tetap menentukan entry dan host aset; tidak perlu mengganti APP_URL/ASSET_URL atau .env.

Build lokal sudah dibuat ulang. npm run check:assets memeriksa manifest/file dan menjalankan helper produksi atas 23 dependency pada 3 profil URL root/subfolder/CDN (30 manifest file references). Pengujian ini memakai fake link/load events; belum merupakan browser/HTTP/server deployment check. Tidak ada query/database/flow change.

Setelah menarik kode di server, jalankan npm run build dari folder aplikasi (contoh /www/wwwroot/itsy), lalu npm run check:assets. public/build diabaikan Git sehingga pull saja tidak membuat file CSS/JS baru. Jika mengunggah hasil build, kirim manifest dan seluruh assets dari build yang sama. Muat ulang penuh halaman setelah build; tab lama bisa masih merujuk hash chunk terdahulu. Jangan menghapus data/database atau menjalankan ulang aktivasi penyimpanan untuk memperbaiki error aset.

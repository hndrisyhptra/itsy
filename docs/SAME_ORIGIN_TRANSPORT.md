# Failed to fetch saat simpan/ajukan PP — 10 November 2026

Pengguna melaporkan simpan draf dan Ajukan PP gagal dengan `Failed to fetch` di `https://its.telkomakses-area3.id/`. Masalah dropdown approver sebelumnya telah selesai setelah pengguna membuat ulang akun; perbaikan ini hanya menyentuh alamat/penanganan koneksi browser.

## Temuan dan perbaikan

Kode sebelumnya mengirim command dan pembacaan ulang state ke URL absolut dari `route(...)` pada boot. Upload sudah memakai path aplikasi relatif. Bila Laravel menerima HTTP dari upstream/proxy sementara halaman publik HTTPS, atau host/port yang dihasilkan berbeda dari halaman browser, request command dapat menuju origin/skema yang keliru. Browser bisa menolak mixed content/CORS sebelum ada respons Laravel. Pesan UI tersebut sendiri belum membuktikan mixed content sebagai penyebab spesifik server; Console/Network terautentikasi dan konfigurasi proxy server belum diperiksa.

Sekarang:

- Boot `urls` dan `accountUrls` hanya berisi path dari root origin, termasuk prefix aplikasi jika dibuka lewat Apache subfolder. Path dibentuk dari request base URL dan named route relatif; tidak memasang domain, port atau protokol.
- Command, refresh dan recovery GET memakai `workspaceUrl` dengan endpoint yang dikenal dan `basePath` aplikasi. Boot lama yang berisi URL HTTP/host internal tidak menentukan tujuan API. Browser tetap memakai HTTPS/host/port halaman sebenarnya, bukan alamat hardcoded situs pengguna.
- Ekspor CSV mempertahankan filter melalui URLSearchParams pada path aplikasi yang sama; cadangan JSON memakai endpoint privat yang sama. File privat/unduh/otorisasi tidak dibuka ke origin lain.
- `credentials: same-origin`, token CSRF, revisi, UUID request dan header JSON tetap. Tidak mengaktifkan CORS permisif, mempercayai seluruh proxy, memaksa HTTPS secara global atau mengubah .env.
- Respons command yang bukan JSON menjelaskan status HTTP, misalnya 413 dari proxy, tanpa menampilkan HTML mentah. Kegagalan koneksi dan timeout diberi pesan Indonesia yang menjelaskan bahwa status penyimpanan belum pasti.

## Respons terputus bukan bukti transaksi belum tersimpan

Setelah kegagalan POST, aplikasi tetap melakukan satu recovery GET yang sudah ada. Jika GET berhasil, state/revisi/CSRF diperbarui dari server; bila ikut gagal, state terkonfirmasi terakhir dipertahankan. Form PP tidak dinavigasikan/dibersihkan pada kegagalan. Cache draf yang sebelumnya ada tidak dihapus; ini tidak menambah klaim autosave lokal untuk PP baru yang sengaja tidak memakai cache bersama.

Tidak ada POST otomatis kedua. Respons dapat hilang setelah server menyimpan transaksi; pengguna harus memeriksa daftar Project Plan sebelum mengirim ulang. Pengujian mensimulasikan kasus ini dan menegaskan GET menemukan state tersimpan tanpa menggandakan POST. Validasi 422/konflik dan keputusan otorisasi tetap milik Laravel, bukan diubah menjadi sukses koneksi.

## Deploy

Perbaikan baru harus di-commit/push, lalu di-pull ke checkout situs dan dibangun di sana. Pull sebelumnya, sebelum perbaikan ini selesai, belum memasukkan perubahan ini. Contoh setelah kode terbaru tersedia di server:

```sh
cd /www/wwwroot/itsy
npm run build
npm run check:assets
```

Gunakan kode PHP dan hasil build yang sama, kemudian muat ulang penuh halaman agar boot/bundle lama tidak tersisa. Tidak ada migration baru, reset/seed/aktivasi ulang storage, perubahan akun atau queue yang diperlukan untuk perbaikan koneksi ini. Migration lain dari commit terdahulu tetap mengikuti runbook-nya sendiri.

Jika masih gagal sesudah deploy, buka Developer Tools → Network, tekan Simpan Draf sekali, lalu periksa URL dan status request `/api/prototype/commands`: mixed content/CORS, koneksi terputus, redirect ke login, 413, 419, 422, 500/502/504 perlu dibedakan. Kirim pesan Console/URL/status saja; jangan mengirim password, cookie, header CSRF, .env atau seluruh payload PP. Server-side log perlu diperiksa operator jika browser menerima 5xx. Perbaikan path tidak mengklaim memperbaiki kegagalan jaringan/proxy/database yang belum terbukti.

## Arsitektur, query dan verifikasi

Perubahan pada `PrototypeController.show`, `laravel.ts`, `store.ts` dan helper cadangan di `ui.tsx`. Command/schema/validasi PP, lima approver, penomoran, volume/harga, scan/Finance/APM, role Operation dari commit terbaru dan transaksi MySQL tetap. Tidak ada endpoint, query SQL, polling, dependency atau retry POST tambahan. Recovery masih satu GET, timeout command tetap 20 detik. Query-budget/ledger/auth suites yang ada tetap menjadi regresi; bukan uji latensi/load server.

Tes baru PHP memeriksa boot root dan Apache subfolder meski URL generator memakai upstream HTTP. Tes frontend menjalankan transport PP dengan boot HTTP host internal dari halaman HTTPS/subfolder: path API, CSRF/revisi/UUID, network/timeout/HTML413/422, cache/pending, response-lost-after-save/recovery dan success. Dua mock test terdahulu diperbarui agar memakai endpoint state/command sebenarnya; jumlah request/progres upload tidak ditambah. Assertion jumlah migration disesuaikan 63 → 64 karena commit role Operation yang sudah ditarik, tanpa mengubah migration.

Hasil akhir dan batas dicatat pada LARAVEL_QA.md dan `qa/same-origin-transport-20261110.json`. Semua tes PHP memakai SQLite memory fail-closed. Tidak ada akses/perubahan its aktif, migration live, .env, permission atau Dompis. Browser/SSL/proxy/Network/server pengguna belum diverifikasi langsung; tidak mengklaim remote sudah terdeploy atau bug server sudah teruji selesai.

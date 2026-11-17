# Ganti kata sandi dan tema topbar

## Alur pengguna — 17 November 2026

Setiap akun SQL aktif dengan role/Branch yang valid dapat membuka avatar → **Profil & Preferensi → Ganti Kata Sandi**. Form menggantikan modal profil, meminta kata sandi saat ini, kata sandi baru dan konfirmasi baru. Aturan mengikuti akun managed yang sudah ada: minimal6karakter dengan huruf+angka, maksimum72byte sesuai bcrypt; konfirmasi harus sama, password baru harus berbeda dari saat ini, karakter NUL ditolak. Ikon mata hanya menampilkan/menyembunyikan isian sementara.

**Simpan Kata Sandi**, termasuk Enter, meminta YA/TIDAK. TIDAK membatalkan tanpa request. YA mengirim satu request, menonaktifkan input/submit selama berlangsung dan menampilkan hasil. Berhasil mengosongkan semua password/mode-visible; kembali/Escape menuju profil. Salah password saat ini atau isian tidak valid tidak menulis akun. Form tidak menyimpan password ke draft/localStorage/sessionStorage/workspace/boot atau log. Browser password manager tetap memakai autocomplete current-password/new-password yang sesuai. Jika koneksi/timeout membuat hasil belum pasti, tidak retry otomatis; pengguna diminta memeriksa login memakai password baru sebelum mengulang.

Fitur hanya akun terautentikasi, bukan simulasi password demo. Mengelola/mengganti password akun lain tetap kewenangan Superadmin melalui Kelola akun existing. Tidak ada forgot-password/email/SMS baru atau perubahan login username.

## Server dan sesi

Endpoint **PUT /api/account/password** dalam grup web private/auth/auth.session/ActiveAccount, dengan CSRF dan throttle5percobaan/menit per akun. Account ID tidak dikirim frontend; controller memakai getAuthIdentifier/primary users.id_user dari auth saja. Body yang memalsukan akun/role/Branch tidak dapat mengubah target atau assignment. Validation response JSON tidak mengirim ulang password.

Satu transaksi memilih user PK dengan row lock, memeriksa active dan Hash::check terhadap hash SQL terbaru (bukan hanya model auth yang mungkin sudah stale), lalu menyimpan hash melalui cast hashed existing dan remember_token baru. Jika reset bersamaan mengganti password sebelum lock, password lama gagal dan tidak menimpa reset itu. Tidak mengubah username/nama/NIK/placement/role/Branch atau akun lain.

SessionID saat ini dimigrasikan; CSRF token tetap agar form/workspace aktif tidak terganggu. Guard user/session password hash diperbarui sehingga sesi saat ini tetap login; auth.session existing menolak hash lama pada akses berikutnya dari sesi lain. Remember token lama tidak lagi berlaku. Ini bukan pembatalan tab lain secara real-time sebelum request. Password/token/hash tidak masuk JSON atau security log; Log notice hanya user_id sebenarnya dan timestamp bawaan logger. Tidak menulis audit/revision/aggregate workflow demi mengganti password.

## Mode gelap/terang

Ikon Bulan/Matahari dipindah dari modal profil ke **sebelah ikon notifikasi** pada topbar. Label/tooltip menyebut mode tujuan, aria-pressed menandai mode gelap. Sesuai instruksi eksplisit pengguna, klik/keyboard langsung mengganti tema **tanpa konfirmasi**; ini menggantikan cakupan tema pada aturan9November di ACTION_CONFIRMATION.md. Tombol ganti kata sandi tetap memakai konfirmasi. Pemilihan disimpan pada key area3-theme browser existing; tidak menulis SQL/preferensi akun server. Ketika storage browser diblokir, tema masih dapat berubah pada halaman aktif tanpa crash.

## Penyimpanan, performa dan penerapan

Menggunakan users.password/remember_token dan sesi existing. Tidak perlu migration, perubahan .env, seed akun, reset demo, worker baru atau restart queue. Data operasional, decisions/approvals, ledger, PPh, archive dan Branch scopes tetap. Tidak menghubungi Dompis/database lain. Agen tidak mengganti password akun nyata atau melakukan deploy.

Fixture HTTP SQLitememory semua18role aktif: **3query**, budget5 (satu ActiveAccount role/Branch join existing, satu user PK lock/select, satu update). Tidak ada query master prices/workspace/PP/payment/audit atau scan daftar akun. Jumlah SQL sesi/auth di deployment dapat berbeda sesuai driver/config; angka ini bukan load/throughput benchmark atau pengujian lock MySQL nyata. Tema0SQL/HTTP. Frontend request memakai appUrl relative origin/basePath, same-origin cookies, JSON/CSRF dan no-store; tidak memakai URL APP_URL absolut yang rentan salah HTTP/HTTPS.

[Hasil pengujian dan batas](qa/profile-password-theme-20261117.json) dan [audit QA](LARAVEL_QA.md). Browser menggunakan data/respons fixture lokal, sedangkan endpoint diuji terpisah melalui Laravel pada SQLite memory; bukan bukti penggantian password/konfigurasi sesi server aaPanel nyata.

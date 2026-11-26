# Pagination Pengaturan dan pencarian akun

Pembaruan 26 November 2026.

## Aktivitas

Tab Pengaturan → Aktivitas menampilkan 20 aktivitas per halaman secara default, dengan pilihan 10, 20, 50, atau 100 baris. Bagian bawah tabel berisi jumlah total, rentang yang tampil, halaman aktif, serta tombol Sebelumnya/Berikutnya. Mengubah jumlah baris kembali ke halaman pertama; halaman aktif dibatasi ulang jika jumlah aktivitas menyusut. Daftar kosong tetap menampilkan informasi yang jelas.

Komponen `ActivityLog` pada `resources/js/area3/management.tsx` memakai daftar `s.events` yang telah disaring backend sesuai hak akses dokumen/Branch/unit. Urutan, isi, dan format waktu existing dipertahankan. Footer memakai CSS responsif khusus di `resources/js/area3/styles.css` agar kontrol tetap tersusun dalam kartu pada ponsel. Pagination ini membatasi baris DOM, bukan pengambilan seluruh riwayat workspace; tidak menambah API, query, atau polling. Tampilan demo memakai komponen sama dengan tautan rekaman dan waktu WIB existing.

## Kelola akun pengguna

Halaman `/akun` tetap hanya dapat diakses Superadmin. Pencarian eksplisit melalui tombol **Cari / Terapkan** mencakup nama, username, NIK, email, jabatan, serta kode/label peran. Panjang pencarian maksimal 150 karakter. `%`, `_`, dan `!` dicari sebagai karakter literal memakai parameter SQL, bukan wildcard yang diberikan pengguna; hasil HTML tetap di-escape.

`AccountController::index` melakukan COUNT sesuai pencarian dan mengambil hanya halaman terpilih dengan LIMIT/OFFSET, urutan nama kemudian ID. Pilihan ukuran 10/20/50/100 (default 20); parameter lain ditolak. Kolom password/remember token tidak diambil. `AccountAccess::contexts()` hanya menerima koleksi halaman tersebut dan membaca penugasan secara batch. Pencarian dan ukuran halaman bertahan pada tautan navigasi; pencarian baru kembali ke halaman pertama. Reset menghapus pencarian sambil mempertahankan ukuran halaman.

Tampilan Blade `resources/views/auth/accounts.blade.php` dan CSS akun menyediakan jumlah total, rentang, keadaan kosong dan batas halaman. Tabel lebar tetap digulir di dalam wadah pada layar kecil. Form tambah/edit akun dan seluruh akses/approval tidak berubah. Pencarian contains masih dapat memindai data; jumlah query konstan bukan jaminan latensi server atau bebas bottleneck.

## Pengujian aktual

- PHP terfokus: `AccountPaginationTest`, `AuthenticationTest`, `UserJabatanTest`: **17 tes / 296 assertions**, lulus, SQLite `:memory:`; 2,843 detik, 58,50 MB. Tidak menjalankan suite PHP penuh ulang untuk perubahan read-only ini.
- Fixture 105 akun: **4 query HTTP** untuk setiap ukuran 10/20/50/100, termasuk konteks aktor, COUNT, halaman akun, dan batch konteks halaman. Halaman terakhir 100 baris berisi 5 akun. Pencarian literal/label peran, parameter salah, escaping, akses terlarang dan guest diuji.
- Frontend penuh: **512 tes**, lulus; dua tes baru mencakup rendering Aktivitas 105 baris menjadi 20 serta keadaan kosong/demo tanpa mutasi.
- TypeScript/Vite build dan pemeriksaan aset: **53 manifest references / 44 preload dependencies × 3 profil**, lulus.
- Browser Chrome lokal: React App sebenarnya dengan 105 aktivitas sintetis dan API mock; default 20, lanjut, ukuran 100/halaman terakhir 5, ukuran 10/reset halaman. Ukuran 375/768/1440, mode terang/gelap, tanpa overflow halaman atau error JS.
- Browser akun memakai HTML Blade sebenarnya yang dirender melalui HTTP test dalam database memory, lalu navigasi GET disimulasikan: default 20, ukuran 100, halaman terakhir 5, pencarian satu akun, hasil kosong, reset, pencarian menghapus halaman lama. Ukuran 375/768/1440, tanpa overflow halaman atau error JS; tidak ada request tulis. Screenshot diperiksa.

Audit: `docs/qa/settings-pagination-20261126.json`. Ini bukan pengujian MariaDB/aaPanel, beban produksi, dokumen ekspor, atau transaksi data aktif. Tidak mengubah database `its`, .env, worker, server, maupun Dompis.

## Penerapan

Deploy controller, Blade dan hasil build terbaru; jalankan pemeriksaan aset setelah build. Tidak ada migration atau restart worker khusus untuk fitur ini. Data akun/aktivitas lama tidak ditulis ulang.

# Ringkasan dashboard awal — 5 November 2026

**Tambahan 9 November 2026:** dashboard kini menambahkan [matriks Region → Branch](DASHBOARD_MATRIX.md) sesuai permintaan terbaru. Tiga kartu, enam ringkasan dan filter tetap. Catatan penghapusan tabel analitik di bawah adalah keputusan 5 November; penambahan matriks baru dijelaskan terpisah, termasuk JMLH/OPEN/CLOSE, realisasi tercatat, BOQ induk dan query/QA.

Menggantikan tampilan analitik sebelumnya sesuai permintaan pengguna terbaru. Judul Dashboard, toolbar Ringkasan operasional, tiga kartu utama dan enam ringkasan kecil memakai komponen/gaya awal.

Kartu utama: **Proyek Aktif**, **Total Nilai SPK**, **Outstanding Pembayaran**. Ringkasan kecil: PP Lanjutan, Mandor Aktif, Total Nilai BOQ, Tagihan Disetujui, Pembayaran Terealisasi dan Approval Menunggu.

Kartu **Progress Pekerjaan per PP**, **Aktivitas Terbaru**, **Monitoring Volume** dan **Inbox Approval** di dashboard dihapus. Panel analitik/tabel portofolio tambahan serta filter ID Project juga dihapus. Menu Inbox Approval, notifikasi, timeline pada Project Plan dan sembilan menu utama tetap tersedia.

## Filter dan perhitungan

- Dropdown berlabel **Filter Branch dashboard**, pilihan awal **Semua Branch**. Daftar menggabungkan master Branch tersaring dari Laravel dan nama Branch pada PP yang tersedia, tanpa duplikasi dan diurutkan alfabetis. Branch tanpa PP tetap dapat dipilih; angkanya nol. Daftar tidak diambil dari pengguna lain atau database di luar akses akun.
- Memilih Branch menyaring seluruh sembilan angka melalui relasi PP → BA KH → SPK Sah → termin. Memilih Semua Branch menggabungkan kembali seluruh data yang boleh dilihat akun. Filter tidak menyimpan perubahan transaksi.
- Proyek Aktif mempertahankan definisi awal: jumlah PP berstatus Sah yang belum mempunyai BA PP Sah; bukan jumlah ID Project unik. BA PP draft tidak menutup hitungan aktif dan relasi yang hilang tidak menyebabkan halaman gagal tampil.
- Total Nilai SPK menggunakan nilai kesepakatan BA KH terkait SPK Sah. Mandor Aktif adalah jumlah nama mandor unik pada SPK Sah.
- BOQ hanya PP induk dengan BOQ Final; PP lanjutan tetap subalokasi dan tidak dijumlah ulang.
- Tagihan dan outstanding hanya termin Disetujui. Nilai saat ini tidak memasukkan termin sebelumnya dua kali. Pembayaran memakai `paid` tersimpan; APM historis tidak dianggap otomatis lunas. Outstanding adalah jumlah sisa tagihan per termin, dibulatkan dua desimal.
- Approval Menunggu tetap mengikuti peran/akun approver terpilih, ditambah tugas verifikasi/pencatatan Finance yang belum ber-APM valid, seperti dashboard awal. Angka ini bukan panel Inbox tambahan.

## Berkas dan hasil pemeriksaan

- `resources/js/area3/dashboard.tsx`: tampilan ringkasan awal dan dropdown.
- `resources/js/area3/dashboard-summary.ts`: perhitungan baca saja dan daftar Branch.
- `styles.css`: menggunakan kembali `metrics-grid`, `metric-main`, `mini-metrics` dan toolbar awal, dengan penanganan lebar angka. Gaya khusus dashboard analitik sebelumnya dihapus.
- `tests/Frontend/dashboard-summary.test.ts`: delapan tes ringkasan, filter, master tanpa PP, status BA PP, tagihan/pembayaran historis, deduplikasi BOQ, penugasan approver dan data kosong; menggantikan sepuluh tes panel analitik sebelumnya.

**127 tes PHP / 2.282 assertions**, **94 tes frontend**, dan TypeScript/Vite build berhasil. PHP memakai SQLite `:memory:` terisolasi dengan bootstrap fail-closed. Bukti build: `docs/qa/dashboard-summary-20261105.json`.

Perubahan hanya tampilan/perhitungan ringkasan, tanpa migration, penggantian data, perubahan alur atau transaksi live MySQL. Angka akun berasal dari data tersimpan Laravel, bukan simulasi. Tidak mengubah Dompis Prov. Muat ulang dashboard untuk mengambil aset build terbaru; **Muat ulang data** mengambil perubahan transaksi dari pengguna lain.

Pemeriksaan visual/browser tidak diulang karena akses pratinjau sebelumnya ditolak; layout responsif/tema dan klik dropdown belum diverifikasi langsung di browser untuk revisi ini. Tes markup/perhitungan tidak diklaim sebagai uji visual.

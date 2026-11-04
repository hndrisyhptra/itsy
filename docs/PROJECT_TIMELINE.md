# Satu ID Project, beberapa PP dan timeline

ID Project adalah kode bisnis (`projectCode` pada runtime), berbeda dari identitas internal PP (`id`) dan No PP. Kode bisnis boleh sama pada beberapa PP. Setiap PP tetap memiliki No PP unik, BOQ/root, status, approver, SPK dan termin sendiri. Menyetujui satu PP tidak menyetujui PP lainnya. PP lanjutan tetap memakai alokasi/root yang ada, sehingga tidak menggandakan anggaran atau volume root.

Schema relasional yang tersedia sudah mendukung **projects → project_plans (1:N)**: projects.project_code unik untuk master proyek, sedangkan project_plans.project_id adalah FK yang boleh dipakai banyak PP; pp_no tetap unik. Tidak perlu menghapus unique pada master projects atau menambah migration. Transaksi aktif masih memakai agregat JSON yang tervalidasi, belum tabel workflow relasional.

## Membuka dan membaca timeline

1. Pada tabel **Project Plan**, klik ikon riwayat di kolom **Aksi**, dengan tooltip Timeline ID Project.
2. Halaman `/project-plan/{id-pp}/timeline` menampilkan seluruh PP dengan ID Project yang sama, mengabaikan spasi tepi/huruf besar-kecil untuk pengelompokan saja. Nilai tersimpan tidak ditulis ulang. ID kosong historis tidak digabungkan dengan ID kosong lain.
3. Ringkasan menunjukkan jumlah PP, PP Sah, pengajuan pembayaran dan tagihan lunas. Filter dapat membatasi tampilan ke satu PP; ringkasan tetap untuk seluruh ID Project.
4. Per PP terlihat status, jumlah persetujuan dan urutan approver yang selesai/menunggu/belum mendapat giliran. Riwayat keputusan mempertahankan alasan pengembalian/penolakan dan keputusan sebelum pengajuan ulang.
5. BA KH dan SPK ditautkan ke PP internal masing-masing. Pembayaran menunjukkan posisi approval dokumen, tahap unggah/koreksi scan, Finance yang terkait scan saat ini, APM serta nilai tagihan/terbayar. Riwayat scan, keputusan Finance dan pembayaran dapat dibuka. BA PP dan jumlah aktivitas pelepasan ikut ditampilkan.
6. Aktivitas terkait seluruh PP ditampilkan terbaru dahulu. Pencocokan memakai identitas dokumen persis melalui PP → BA KH → SPK → termin → BA PP, bukan pencarian substring nomor/kode.

Timeline hanya membaca state yang telah dibatasi Branch oleh Laravel. Pengguna tidak memperoleh PP, dokumen atau aktivitas dari Branch lain hanya karena kode bisnisnya sama. Tautan ke PP yang tidak tersedia menampilkan keadaan tidak ditemukan pada React; shell Laravel tetap hanya mengirim data yang berhak diakses.

Kolom **Jenis PP** di tabel utama dihapus. Aksi detail/edit dipertahankan. Jenis induk/lanjutan dan hubungan Parent/Root tidak dihapus dari data/detail.

## Riwayat dan batas

- Versi 3 memakai lima approval dokumen, lalu Finance setelah scan untuk pembayaran. Versi 2 mempertahankan dua approval, sedangkan termin versi 1 mempertahankan tiga role historis dan pencatatan pembayaran terpisah.
- PP historis yang tidak memiliki catatan keputusan tidak diberi approval/nama/waktu rekaan. Waktu yang tidak tersedia ditandai demikian. Nama keputusan memakai snapshot aktor bila tersedia; log aktivitas lama yang hanya merekam role tetap menampilkan role tersebut.
- Halaman menampilkan riwayat yang tersedia dalam workspace, bukan klaim pemulihan log lama yang tidak pernah tersimpan. Riwayat keputusan terpisah tetap ditampilkan bila aktivitas generiknya tidak tersedia. Perbarui halaman melalui Muat ulang data untuk perubahan dari akun lain.
- APM/TTD tetap proses manual seperti sebelumnya. Timeline tidak mengeksekusi approval, tanda tangan, pembayaran atau perubahan ledger.
- Pengujian relasi, rendering dan batas akses dijalankan otomatis. Pemeriksaan visual browser belum selesai karena akses berkas pratinjau lokal diblokir kebijakan browser; tidak ada bypass atau perubahan akses aplikasi.

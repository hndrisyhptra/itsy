# Cancel paket Project Plan

## Otoritas pengguna — 23 November 2026

Pengguna meminta tombol Cancel pada menu PP: **Admin Area** untuk Konstruksi, **Officer 1 Operation** untuk Operation, dan **Superadmin**. Pengguna mengonfirmasi pembatalan berlaku pada **Draf, revisi, menunggu approval, atau Sah selama belum ada BA RP/pengajuan pembayaran**. Pemeriksaan memakai unit PP tersimpan, bukan nama role pada cache atau input formulir; Branch/unit/Region penugasan akun tetap berlaku. Tidak mensyaratkan aktor menjadi assignee PP, karena wewenang yang diminta adalah peran dan cakupan penugasan.

Tombol ikon larangan dengan tooltip **Cancel paket PP** berada di Aksi daftar PP dan detail PP. Pengguna membuka dialog, memeriksa nomor/nama paket, mengisi alasan wajib maksimal1000karakter, lalu memilih Cancel Paket PP dan YA/TIDAK. Membuka/menutup/preview tidak menulis; TIDAK tidak membatalkan paket. Riwayat tetap, sehingga Cancel berbeda dari Kembalikan untuk Revisi dan Batalkan Persetujuan Saya. Status Cancel bersifat akhir; tidak menyediakan restore atau pengajuan ulang paket tersebut.

## Guard dan akibat pembatalan

- Peran lain, akun tidak aktif, Branch/unit berbeda, snapshot status/approval yang berubah, alasan kosong/terlalu panjang dan UUID replay yang berbeda isi/aktor ditolak server.
- Adanya satu BA RP/pengajuan pembayaran pada SPK mana pun dalam PP mengunci Cancel, termasuk draf, ditolak, berjalan atau lunas. PP lanjutan dan koreksi khusus Head yang masih berjalan juga menahan pembatalan.
- Antrean unggah paket PP/BA KH/SPK yang queued/processing harus selesai terlebih dahulu. Pemeriksaan satu query indexed, dalam transaksi dengan lock workspace dan revision existing, sehingga tidak berlomba dengan createTerm atau unggahan baru.
- PP berubah menjadi **Cancel**. Semua jalur mutation PP/BA KH/SPK, approval/reset/pengajuan ulang dan pembuatan pekerjaan/pembayaran menolak parent Cancel, termasuk path worker internal dan perubahan identitas/rekening privat. Data tersimpan tetap dapat diperiksa sesuai akses.
- Keputusan approval aktual, history, nomor/sumber/identitas/manifest, BA KH/SPK dan data privat tidak dihapus/ditulis ulang. Status Sah historis BA KH/SPK dipertahankan sebagai jejak dokumen; parent Cancel menghentikan proses berikutnya. Penanda/label UI menyatakan paket Cancel.
- Tidak menghitung ulang uang atau PPh, tidak mengubah volume accepted/reserved/committed/available, tidak mengarang pelepasan volume atau pembayaran. Cancel adalah penandaan penghentian paket; pelepasan volume bukan bagian permintaan ini.
- Daftar/filter PP tetap memuat Cancel. Matriks Status Approval mempunyai bucket **Cancel**, tanpa tugas menunggu approver. Summary operasional/matriks Project per Region mengecualikan paket Cancel dari jumlah/nilai aktif agar tidak dianggap masih Open. Timeline tetap memuat paket, keputusan terdahulu dan event pembatalan dengan aktor/peran/waktu/alasan.
- Resolver Telegram existing mensyaratkan PP SUBMITTED dan run PENDING. Paket Cancel tidak lagi menjadi target approval, termasuk task lama yang diperiksa ulang saat worker berjalan. Tidak mengirim Telegram nyata dalam QA.

## Penyimpanan dan proyeksi SQL

`project_plans.status = CANCELLED`, sedangkan JSON `document_data.status = Cancel`. `document_data.cancellation` mencatat version1, previousStatus, actorUserId/name/role, waktu aktual, alasan, sourceCycleNo, requestId dan payloadHash server-owned. Tidak menerima penggantian marker dari form simpan. Receipt/audit before-after/revision existing terpakai; replay memeriksa aktor, peran, UUID dan hash target/fingerprint/alasan.

Untuk paket Diajukan, hanya **approval_runs siklus aktif** menjadi CANCELLED. Step dan approval_decisions tetap identik, tanpa fake RETURN/REJECT/APPROVE. Siklus sebelumnya dan run Sah tetap status historisnya. Full projection membentuk proyeksi semula lalu menerapkan penutupan current run, sehingga tidak menghidupkan kembali approval pada maintenance explicit. Tidak ada read-time adoption/backfill.

Migration **2026_11_23_000001_allow_cancelled_project_packages** memperluas CHECK `pp_status` dan `approval_run_status` untuk string CANCELLED pada kolom varchar existing. CHECK `pp_budget_positive` juga mengizinkan nilai nol khusus DRAFT/CANCELLED workflow3, sehingga draf Header tanpa BOQ bisa dibatalkan tanpa mengarang nilai; approved/submitted nol, historical nol dan semua nominal negatif tetap ditolak. Dua CHECK PP diubah dalam satu ALTER. Tidak menambah tabel/kolom, tidak mengubah payment status, tidak melakukan UPDATE data bisnis. Target guard hanya database its MySQL/MariaDB atau testing SQLite memory. DROP CHECK MySQL / DROP CONSTRAINT MariaDB dipilih sesuai versi. Rollback menolak jika sudah ada PP/run CANCELLED untuk menjaga data/audit.

## Performa dan file implementasi

`ProjectCancellation` memvalidasi graph loaded, tanpa query helper. Cancel command memakai satu indexed pending-upload lookup tambahan. Aktor/relasi dimuat oleh pipeline akun/workspace existing, tidak ada per-role query baru. Cancel tidak memproyeksikan ulang siklus/step/decision identik; pending run hanya satu UPDATE. Fixture satuBOQ: Construction43–44HTTPquery, Operation42–43, Superadmin44; bukan benchmark MySQL/load. Existing500BOQ/1000komponen59query tetap.

UI `projectCancellationPlans` membangun Map/Set relasi sekali per state, diteruskan ke tombol pada delapan baris/page existing. Dialog hanya dibuat ketika dibuka, fingerprint/UUID target tetap, busy/persistence guard dan YA/TIDAK bersama. Gagal/409 tetap pada dialog dengan alasan tersimpan; tidak menavigasi Dashboard. Tidak menambah endpoint (memakai command endpoint existing), dependency, timer, polling, fetch PII pada daftar atau cache otorisasi lintas-request.

File inti: `app/Services/ProjectCancellation.php`, domain/auth/command/relational guards, `resources/js/area3/project-cancellation.ts`, `project-cancel-ui.tsx`, daftar/detail/timeline/dashboard/contract UI existing. Styling dialog scoped untuk tema terang/gelap; model/generator berkas fisik tidak diubah. Bukti aktual dan batas ada di [QA JSON](qa/project-cancellation-20261123.json) dan [LARAVEL_QA](LARAVEL_QA.md).

## Penerapan di server

1. Pull kode sesuai prosedur rilis proyek, lalu jalankan migration memakai PHP situs, dari `/www/wwwroot/itsy`: `/www/server/php/84/bin/php artisan migrate --force`.
2. Jalankan `npm run build` dan `npm run check:assets`; `public/build` tidak ikut Git. Gunakan build sebelum mengaktifkan fitur Cancel dan muat ulang halaman.
3. Muat ulang worker upload Area3 yang sudah berjalan agar memakai guard domain/PII terbaru, melalui konfigurasi Supervisor proyek yang ada. Jangan restart worker aplikasi lain. Resolver notifikasi tidak berubah; filter status existing menghentikan target Cancel.

Migration ini **belum diterapkan agen ke its aktif/server**. Pull/migrate tidak otomatis meng-Cancel paket lama, menghapus keputusan atau mengubah pembayaran. Tidak memerlukan perubahan .env/APP_KEY/reset/seed. MySQL/MariaDB DDL sebenarnya, aaPanel, akun live dan konkurensi beban belum diverifikasi di server; tes DDL memakai mock dan seluruh HTTP memakai SQLite memory. Tidak commit/push/deploy oleh agen.

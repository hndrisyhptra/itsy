# Dashboard ringkasan pimpinan

## Pembaruan terbaru — Nilai Pengajuan Pembayaran

**Sisa Budget PID diganti Nilai Pengajuan Pembayaran.** Card menampilkan tagihan bruto sebelum PPh, jumlah pengajuan dan jumlah PID unik; klik membuka modal20baris/pencarian/detail/timeline. Sumber gross sama dengan Nilai Pengajuan di matriks, berdasarkan harga SPK tersimpan dan volume aktual masing-masing pengajuan. Pengajuan Diajukan/Disetujui dan sudahdibayar dihitung; Draf/Revisi/Ditolak/Cancel dikecualikan. Shared predicate juga mengecualikan pengembalianFinance/koreksi belumlunas. Pembayaran sudahlunas dengan koreksi scan belakangan tetap dihitung; nilai pembayaran tercatat tidak dihapus atau dikoreksi.

Card tidak memakai nilai kasbon bersih untuk total bruto, tidak menghitungulang PPh atau mengubah nominal transfer/approval. Semua filter UnitKerja/Branch mengikuti sumber graph terotorisasi. Dashboard tidak lagi memanggil GET budget atau menampilkan modal/refreshbudget; masterbudget dan endpoint lama tetap tersedia sesuai akses existing. **Sembilan card dan tabel monitoring tetap, diagram masih disembunyikan.** Bagian dokumentasi budget/diagram lama di bawah dipertahankan sebagai riwayat.

Tanpa query/API/dependency/schema/migration/poller/worker baru atau perubahan dataaktif. Pengujian perbaikan ini:523frontend/build/checkassets54refs45preloads×3; browser actualApp/API mock memeriksa gross3juta/3pengajuan/1PID, pencarianTermin2, filterBranch/unit,9cards/nochart/noBudgetGET dan3width2themes. Tidak mengulang PHPsuite atau menguji aaPanel/DBfisik untuk perubahan frontend-only ini. Hasil aktual dan batas terdapat pada [audit](qa/dashboard-requested-card-20261126.json).

## Pembaruan terbaru — diagram disembunyikan sementara

Sesuai permintaan pengguna berikutnya, **diagram batang pembayaran bulanan beserta pilihan tahun sementara tidak ditampilkan**. Sembilan card, pencarian/pagination rincian, filter Unit Kerja/Branch dan tabel Summary/StatusApproval tetap. Keterangan diagram di bawah merupakan desain/implementasi sebelumnya, bukan tampilan aktif. Helper perhitungan historis tetap disimpan; data pembayaran dan backend tidak berubah. Build/checkassets diperlukan, tanpa migration/workerrestart.

26 November 2026. Menggantikan tiga card dan enam mini-ringkasan lama; tabel Summary dan Status Approval tetap tersedia. Permintaan terakhir menghapus **Pengeluaran Bulan Ini** dari konsep. Tidak ada role EVP baru atau perubahan hak approval.

## Sembilan card

| Card | Dasar perhitungan |
| --- | --- |
| Total ID Project | PID ternormalisasi unik dalam unit dan Branch terpilih; menampilkan Open/Close. Memakai klasifikasi matriks existing: seluruh PP yang dapat diakses pada PID harus menyelesaikan pembayaran Final sebelum Close. PP Cancel dikecualikan. |
| Paket PP Berjalan | PP non-Cancel yang belum menyelesaikan seluruh SPK/pembayaran Final. Draf, Revisi, menunggu approval dan Sah masih dapat termasuk. PP yang sudah selesai tidak ikut hanya karena PP lain dengan PID sama masih berjalan. |
| Total Anggaran PP | Total Anggaran IV.5 tersimpan pada PP induk Sah, memakai `projectPlanHeaderTotal`; izin tetap masuk sesuai rekap. PP lanjutan tidak dijumlah ulang sebagai anggaran konsolidasi. Legacy tanpa rincian menggunakan nilai Header existing. Bukan BOQ Owner atau plafon PID. |
| Total Sudah Dibayar | Jumlah `paid` yang benar-benar tercatat pada pembayaran Disetujui dengan `settlementRecorded`, SPK Sah, dan parent non-Cancel. Nomor APM boleh kosong pada settlement yang sah. Tidak menghitung sekadar upload bukti atau nominal kasbon yang belum dibayar. |
| Outstanding Pembayaran | Pengajuan aktif Diajukan/Disetujui yang belum terbayar: `max(paymentDue(saved kasbon, gross) − recordedPaid, 0)`. Revisi finansial, Draf, Ditolak/Dikembalikan dan parent Cancel dikecualikan. Rincian menunjukkan bagian masih diproses dan menunggu pelunasan. Ini definisi card baru; tidak mengubah definisi lama di laporan/domain. |
| Menunggu Approval | Paket PP dan pembayaran yang resolver current-cycle existing tempatkan pada role approval. Mengikuti lima/tujuh tahap masing-masing unit dan alur historis; bukan hanya tugas akun yang sedang login. Menampilkan posisi terbanyak dan umur antrean tertua hanya jika tanggal referensi tersedia. |
| Menunggu Pelunasan | Bagian Outstanding pada bucket `settlement` existing, dengan jumlah pengajuan. Approval belum lengkap tidak dimasukkan. |
| Nilai Pengajuan Pembayaran | Gross pengajuan aktif dan sudah dibayar yang membentuk Nilai Pengajuan matriks; Draf/Revisi/Ditolak/Cancel dikecualikan, termasuk pengembalian Finance belum lunas. Jumlah pengajuan dan PID unik ditampilkan. Bukan kasbon bersih atau nominal transfer. |
| Berkas Belum Lengkap | Pengajuan aktif tanpa `scan.id`, termasuk pembayaran yang sudah dibayar tetapi belum memiliki scan bertanda tangan. Jumlah yang sudah dibayar ditonjolkan. OK menunjukkan file scan tersimpan, bukan keputusan Finance atau unggahan eviden pekerjaan. |

Nominal pembayaran menggunakan kebijakan kasbon **tersimpan**, termasuk policy lama tanpa PPh/net baru. Tidak melakukan reprice, rounding baru, koreksi data, resubmission atau perubahan status saat membaca. Card dan modal tidak mengambil identitas rekening/NIK/foto Mandor atau dokumen privat.

## Diagram bulanan dan rincian

Diagram batang solid Januari–Desember mengikuti tahun terpilih, Unit Kerja dan Branch. Data berasal dari jumlah pembayaran yang telah dicatat, dengan bulan kalender **Asia/Jakarta**. Riwayat pembayaran valid dipertahankan untuk pembayaran historis bertahap. Jika history tidak lengkap tetapi tersedia `financePayment.settledAt`/`paymentRequest.paidAt` terverifikasi, nominal tercatat ditempatkan pada bulan tersebut. Tidak memakai tanggal PP, submission, upload bukti, atau hari ini sebagai pengganti tanggal pembayaran.

Nilai historis yang belum memiliki tanggal terverifikasi tetap termasuk Total Sudah Dibayar dan dijelaskan sebagai belum masuk diagram; tidak membuat bulan fiktif. Jumlah bulanan tidak melebihi pembayaran tercatat. Bulan kosong tetap nol. Tooltip/fokus keyboard menampilkan nominal, jumlah pengajuan unik dan PID. Klik batang membuka kontribusi pembayaran bulan tersebut.

Klik seluruh card membuka modal rincian, pencarian dan pagination 20 baris. Total ID Project dapat dibuka lagi ke PP dengan PID yang tepat; detail dan timeline memakai UUID dokumen sebenarnya. Pilihan modal menyimpan identitas filter, bukan salinan baris yang tertinggal ketika state berubah. Mengganti unit/Branch/aktor/peran menutup detail sebelumnya. Modal Budget mengambil SQL page 20 secara lazy dengan pencarian debounce 300 ms. Tampilan memakai surface terang/gelap existing, tanpa gradient; Outstanding melebar dua kolom pada ponsel.

## Arsitektur dan otorisasi

- `dashboard-executive.ts`: pure read model, maps/sets atas graph terotorisasi existing; helper bulan WIB dan bucket bulanan. Matrix/approval resolver existing dipakai ulang tanpa perubahan keputusan atau klasifikasi tabel.
- `dashboard-executive-view.tsx`: sembilan card, diagram, modal; `Dashboard` tetap memiliki filter Unit Kerja/Branch dan tab Summary/Status Approval existing.
- `dashboard-budget.ts`: satu GET ringkasan per scope/revisi; no-store/same-origin, timeout 20 detik, AbortController, pemeriksaan exact unit/Branch/query/page, maksimum 20 baris. Tidak ada poller/retry otomatis/cache lintas akun. Tombol **Perbarui budget** tersedia untuk membaca ulang setelah perubahan master oleh pengguna lain.
- `GET /api/dashboard/budget`: authenticated active-account middleware existing, controller validasi allowlist unit dan parameter, `DashboardBudget` memeriksa unit akun serta Branch aktif/nondeleted yang ditugaskan. Branch di luar akses →403. Unmapped tidak dimasukkan dan tidak diberikan ke Branch lain. Semua role viewer yang memiliki akses unit/Branch boleh membaca ringkasan ini; master CRUD/impor tetap hanya Finance/Superadmin.
- Query: satu batch Branch, satu aggregate dengan derived grouped reserved allocations dan empat category joins. Detail menambah satu SQL LIMIT/OFFSET20. Tidak membaca seluruh master ke React, tidak query per PID, tidak mengambil workflow/archive/private cashbon. GET hanya membaca. Jika schema belum tersedia, tampilkan pesan 503 dan card “—”, bukan nol palsu atau membuat tabel otomatis.
- Sisa Budget mengikuti kebijakan **PP baru saja**: PP lama tanpa marker tidak tiba-tiba menjadi alokasi. Nilai defisit tidak disembunyikan. Metrik ini hanya referensi, bukan pengganti pengecekan atomik saat submit PP.

Tidak ada schema/migration/dependency/worker baru, perubahan uang/volume/PID ledger/approval/source dokumen, atau write ke its aktif/.env/server/worker/Dompis.

## Pengujian dan penerapan

Lihat [audit](qa/dashboard-executive-20261126.json) dan [QA](LARAVEL_QA.md). Aktual: **573 tes PHP /17.709 assertions**, SQLite memory, 129,415 detik/146,50 MB dengan batas proses512M; **521 frontend**, build TypeScript/Vite dan aset54refs/45preloads×3 lulus. Fokus PHP baru+Region6/154, 105PID summary/detail3/4HTTPqueries; 19focused frontend termasuk kebijakan historis, pembayaran tanpaAPM, closure PID/PP, Cancel, revisi Finance, Operation dan timezone WIB.

Browser memakai React App sebenarnya dengan API mock/data sintetis, bukan database aktif: sembilan card, 12 bulan, modal20/last7/search, PID→PP, klik November paid tanpaAPM, Budget20/search/error/retry, Branch/reset/Operation, 375/768/1440×light/dark. Screenshot diperiksa,0JSerror/0request tulis. Fixture API browser bukan bukti endpoint server; endpoint diuji terpisah melalui HTTP PHPUnit memory.

Tidak menguji aaPanel/MariaDB fisik, benchmark beban produksi, Telegram nyata, atau ekspor/cetak pada perubahan ini. Tidak menjamin bebas bottleneck: existing full authorized workspace graph masih dimuat dan contains-search Budget dapat memindai data; SQL counts di atas adalah ukuran fixture.

Deploy source PHP/routes serta hasil build terbaru; jalankan `npm run check:assets`. Jika server memakai route cache, bangun ulang cache sesuai prosedur deployment existing agar endpoint baru terdaftar. **Tidak ada migration atau restart worker khusus**. Tidak perlu mengajukan ulang PP/pembayaran lama.

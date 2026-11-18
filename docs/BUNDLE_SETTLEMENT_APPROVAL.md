# Paket PP dan kasbon sebelum tujuh approval

## 17 November 2026 — Preview paket untuk approver sebelum keputusan

Sebelumnya halaman detail sudah memiliki empat preview BA KH/lampiran/SPK/lampiran pada kartu terpisah sesudah approval, dan Preview PP di judul halaman. Kini bagian **Berkas Paket untuk Ditinjau** berada langsung dalam panel approval, sebelum catatan dan tombol keputusan. Semua lima approver terpilih dapat membuka tombol tersendiri:

- Preview PP
- Preview BA KH
- Preview Lampiran BA KH
- Preview SPK
- Preview Lampiran SPK

Inbox Approval Project Plan tetap20baris/halaman dan mempertahankan Tinjau PP/Preview BOQ Mandor; tambahan **Preview Paket** membuka pemilih lima dokumen yang sama. Nomor BA KH, Mandor dan nomor SPK mengelompokkan dokumen bila satu PP memiliki beberapa kontrak. Tidak otomatis memilih dokumen pertama. PP header-only dapat dipreview; BA KH/SPK yang belum tersedia diberi keterangan, bukan berkas kosong yang dianggap tersedia. Link melanjutkan/membuka dokumen operasional tetap untuk Admin/Superadmin pada konteks detail, sedangkan picker Inbox hanya untuk review. Riwayat PP tanpa contractBundle tetap memakai tampilan sebelumnya.

Target preview memuat projectId/kind/dealId/spkId. Resolver hanya menerima PP target yang sama, BA KH milik UUID PP itu, dan SPK milik BA KH yang sama. ID Project yang sama, PP induk/lanjutan maupun urutan koleksi tidak menjadi fallback; target hilang/tidak cocok tidak merender dokumen lain. Preview memakai sumber tersimpan yang sudah dibaca melalui cakupan Branch Laravel. Tidak menambah hak akses/API private, membaca KTP/bank, mengubah approval, memberi cap baru, menandai sudah diperiksa atau mewajibkan pengguna membuka semua berkas untuk menyetujui.

Dalam picker Inbox, modal pemilih diganti sementara dengan DocumentPreview, bukan ditumpuk. Escape/Tutup dari dokumen kembali ke pemilih; Escape/Tutup pemilih kembali ke Inbox. Ini menjaga satu pemilik focus/Escape/print. Halaman detail cukup memiliki satu section tombol sebelum keputusan, tanpa kartu duplikat setelah approval. Word/print/stamp/orientasi memakai komponen existing dan aturan aslinya; tidak ada generator/template baru. Notice paket memperjelas BA KH/SPK dapat ditinjau sebelum Sah, sedangkan pengesahan mengikuti Head PMO.

Performa: closed Inbox action hanya tombol/state, tidak membangun preview atau memindai semua dokumen setiap baris. Saat dibuka/detail, existing bundleDocuments memfilter satu PP dan indeks Map mengelompokkan SPK sekali (linear), menggantikan pencarian SPK per BA KH; target resolve hanya saat preview terpilih.0SQL/API/private fetch/schema/migration/new runtime dependencies, tidak membuat command atau mengubah live data; pemuatan aggregate awal yang sudah ada tetap. Ini bukan klaim benchmark produksi. [Bukti dan batas](qa/project-bundle-preview-20261117.json).


## 16 November 2026 — Tandai Lunas dengan APM opsional

Finance dapat menekan Tandai Lunas setelah bukti transfer aktif selesai diunggah dan approval/review Finance lengkap, tanpa nomor APM. Upload sendiri tidak membayar otomatis. APM dapat diisi saat pelunasan atau ditambah/diedit sesudah lunas; format/keunikan dan konfirmasi YA/TIDAK tetap. Koreksi nomor menyimpan audit before/after/actor/waktu dan tidak mengubah nilai/tanggal/penerima/bukti/keputusan maupun menambah pembayaran kedua. Berlaku pada alur kasbon-v1/kasbon-settlement-v2; pra-kasbon historis tetap.

Nomor APM dipisahkan dari pelunasan; next-term/CLOSE/inbox/List Payment memakai settlement nyata. Migration nullable APM disiapkan tanpa rewrite data lama dan belum dijalankan pada its aktif. [Aturan, penyimpanan, query dan hasil verifikasi](PAYMENT_SETTLEMENT_OPTIONAL_APM.md).

## 16 November 2026 — Penandatangan fisik kasbon tanpa Admin Area dan Finance

Preview/Word/print template3 menghapus seluruh slot Memeriksa Admin Area dan Menyetujui Finance sesuai permintaan pengguna. Susunan menjadi2/3/2: PM + pengaju; Project Support + Deployment + Head; Mgr Finance & Settlement + EVP. HTML dan Word berbagi spans enam kolom dasar sehingga tiap baris tetap selebar tabel. Slot Mgr Finance & Settlement tetap, nama penerima dan maker tidak berubah. Ketentuan lama di bawah mengenai PM/Admin Area/pengaju dan Mgr Finance/Finance/EVP adalah audit historis yang telah digantikan untuk keluaran dokumen.

**Approval aplikasi tetap tujuh**, Admin Area tahap2 dan Finance tahap7 tetap wajib. Keputusan keduanya tidak dihapus dari database, inbox, timeline, riwayat atau pengikatan kasbon; hanya tidak menjadi sel tanda tangan fisik pada berkas. Nama/mark penandatangan lain tetap berdasarkan saved version/selected actual decisions. Maksimum5cap nyata pada preview, Word/print0cap dan ruang TTD kosong. Template1/2 tetap susunannya; maker Finance historis tidak dihilangkan. Nominal net/PPh, APM/proof/scan/Branch dan SQL snapshots tetap.0SQL/API/schema/migration baru. [Bukti render dan pengujian](qa/kasbon-signatures-20261116.json), [audit](CHANGELOG.md).

## 16 November 2026 — Net Jasa Mandor pada kasbon baru

Kasbon/transfer/APM baru memakai Material +97,5%Jasa Mandor aktual, bruto tetap tersimpan. Semua tujuh approval/version binding tetap; kasbon terbit/paid tanpa policy baru mempertahankan nominalnya. Payable net melunasi pengajuan tanpa outstanding pajak/blocked next-term. List Payment hanya Finance/Settlement mengikuti Branch. Lihat [KASBON_PPH_PAYMENT_LIST](KASBON_PPH_PAYMENT_LIST.md) untuk canonical quote, data, akses, query dan bukti; tidak perlu migration baru.

## Otoritas 15 November 2026

Pengguna mengubah PP menjadi satu pengajuan bersama BA KH dan SPK. Untuk pembayaran, pengguna mengonfirmasi kasbon **diisi sebelum pengajuan pertama**, kemudian PM → Admin Area → Mgr Project Support → Mgr Deployment → Head of PMO → Mgr Finance & Settlement → Finance. Ketentuan ini menggantikan kasbon setelah Head untuk dokumen baru. Dokumen lama tetap memakai marker/alur tersimpan; tidak ada adopsi massal pengajuan berjalan, penggantian keputusan, akun atau pembayaran historis.

## Paket PP

1. Admin menyimpan Header PP, BOQ dan perencanaan sebagai draf. PP baru melalui HTTP, termasuk PP lanjutan baru, mendapat marker server `contractBundle.version=1`.
2. Admin lanjut mengisi BA KH, menyimpan draf, kemudian membuat SPK dan melengkapi profil Mandor/foto privat. BA KH/SPK paket boleh disusun sebelum PP Sah. Nomor -01/-02, jadwal, Head terpilih dan batas harga tetap berasal dari PP tersimpan.
3. Dari SPK, **Ajukan Paket PP, BA KH & SPK** menyimpan SPK terlebih dahulu, menunggu konfirmasi server, lalu mengajukan paket. Laravel memeriksa kembali PP lengkap, BOQ privat bila ada, lima assignee aktif/Branch, harga/volume, dokumen lengkap, profil Mandor dan antrean upload.
4. Server mencatat UUID/waktu dan hash isi BA KH/SPK dalam `contractBundle.submissions`. PP Diajukan, BA KH/SPK tetap Draft dan tidak boleh diedit/disahkan secara terpisah. Profil/foto Mandor juga terkunci selama pengajuan paket.
5. Kelima approver meninjau PP dan berkas BA KH/SPK dari detail PP. Setelah keputusan Head yang sebenarnya, PP, BA KH dan SPK menjadi Sah dalam transaksi yang sama; volume baru menjadi committed saat itu. Status aplikasi tidak membuat tanda tangan basah.

Pengembalian/penolakan wajib catatan. PP dan dokumen paket dapat dikoreksi Admin dan diajukan ulang dari PM; riwayat keputusan, identitas dokumen dan manifest hash sebelumnya dipertahankan. Manifest adalah bukti hash versi, bukan arsip lengkap setiap revisi isi formulir. Satu BA KH/satu SPK per PP tetap mengikuti nomor tetap -01/-02 dan aturan unik sebelumnya. Tidak menambah suffix atau mengubah ledger/pelepasan volume.

`saveProject` generik tidak dapat melewati kelengkapan paket pada PP baru/bertanda. Marker/hashes/status pengesahan milik server, bukan isian klien. PP v1/v2 dan dokumen yang sudah mempunyai riwayat tidak diubah melalui `saveProjectBundle`. PP v3 Draft lama tanpa riwayat dapat diadopsi melalui perintah eksplisit, tetapi editor dokumen lama tidak melakukannya otomatis. CLI koreksi Head khusus PP historis menolak paket baru agar tidak memisahkan persetujuan PP dari BA KH/SPK.

## Pembayaran baru

Pembayaran baru melalui HTTP memakai `paymentFlow=kasbon-settlement-v2`, workflowVersion3. Lima assignee dari PP dibekukan seperti sebelumnya. Admin menyimpan aktual dan informasi BARP, lalu **Ajukan Termin** (Parsial) atau **Ajukan Payment** (Final) membuka form kasbon sebelum pengajuan. Form juga dapat dibuka dari bagian kasbon pada detail termin.

Kasbon memerlukan Cost Center, WBS, jenis kegiatan, tempat/tanggal, rincian akun/keperluan dan nominal, maksimum20baris. Default tempat Surabaya dan EVP I GEDE WAHYU DANYNDRA tetap. Nominal seluruh baris harus sama dengan tagihan aktual; penerima/rekening/bank/foto berasal dari versi profil Mandor tersimpan yang ditampilkan. Admin memilih satu akun **Mgr Finance & Settlement** aktif yang mempunyai Branch PP; identitasnya menggantikan slot Business Support pada kasbon baru, tanpa alias nama akun lama.

POST privat menyimpan kasbon terenkripsi templateVersion3 lalu memulai tujuh approval secara atomik dengan lock/revision/UUID receipt. Kasbon mengikat versi profil penerima dan setiap keputusan mengikat `kasbonId`. Submit generik/submitFinanceTerm tidak dapat mengajukan alur ini tanpa kasbon. Upload eviden yang masih antre menghalangi pengajuan.

| Tahap | Peran | Penugasan |
| --- | --- | --- |
| 1 | PM Konstruksi | Akun terpilih dari PP |
| 2 | Admin Area | Akun terpilih dari PP |
| 3 | Mgr Project Support | Akun terpilih dari PP |
| 4 | Mgr Deployment | Akun terpilih dari PP |
| 5 | Head of PMO | Akun terpilih dari PP |
| 6 | Mgr Finance & Settlement | Akun terpilih pada kasbon |
| 7 | Finance | Akun Finance aktif pada Branch PP; identitas pelaku dibekukan pada keputusan |

Finance baru dapat menyetujui setelah tahap6. Sesudah tahap7 Setujui, pengajuan Disetujui/Diverifikasi, kemudian Finance mengunggah bukti transfer privat untuk kasbon yang sama dan mencatat APM unik sebagai pelunasan penuh. Transfer bank tetap dilakukan di luar aplikasi. Bukti transfer dan APM tidak dibuat otomatis. Termin berikutnya menunggu pelunasan/APM sebelumnya, maksimum tiga termin tetap.

Parsial maupun Final boleh dibayar sebelum scan TTD lengkap; NOK/OK tetap terpisah dari status pembayaran. Scan yang sudah tersedia harus diverifikasi sebelum APM; scan dapat disusulkan tanpa mengubah pembayaran. Eviden pekerjaan tidak dihitung sebagai scan TTD. Penolakan/pengembalian oleh salah satu dari tujuh peran mewajibkan catatan, menyimpan siklus lama dan mengulang dari PM setelah kasbon versi baru diajukan. Tidak ada tombol pengembalian pasca-persetujuan Finance7 yang melewati rantai ini; alur historis tetap memakai tombol lamanya.

## Review dan keluaran kasbon

Inbox dan timeline memakai tujuh role dokumen, termasuk akun/tanggal keputusan aktual, tanpa menampilkan Finance7 dua kali pada riwayat gabungan. Lima approver pertama dapat membaca kasbon dari pengajuan baru yang memang menugaskan akunnya setelah diajukan; endpoint/foto identitas Mandor tetap tidak dibuka untuk mereka. Mgr Finance & Settlement dan Finance dapat membuka review berkas sesuai Branch. Profil/bank/kasbon terenkripsi tidak dimasukkan ke bootstrap/export umum/localStorage.

Blok TTD Mandor dihapus dari kasbon, termasuk keluaran historis. Identitas penerima tetap ada. Kasbon baru menampilkan tiga baris signers: PM/Admin Area/pengaju; Support/Deployment/Head; Mgr Finance/Finance/EVP. EVP/pengaju bukan approval aplikasi tambahan. Cap APPROVED hanya berasal dari keputusan nyata pada `kasbonId` yang sedang dilihat dan user yang cocok; tidak ada cap fiktif untuk EVP/pengaju.

Preview menyediakan konfirmasi Unduh Word (.docx) dan Cetak/Simpan PDF. Native Word memakai model isi kasbon yang sama, logo publik tetap, tabel editable, kepala tabel berulang, rekening nol awal, nama Finance dari keputusan yang cocok, dan ruang TTD basah kosong. Semua cap hilang di Word maupun cetak. Word dibuat lazy di browser tanpa paket runtime baru/konverter/server query; tidak memakai HTML berakhiran .doc. CSS menjaga ancestor modal biasa/nested tetap terlihat saat print, satu Grand Total pada akhir tabel, rincian rekening tidak terpotong dan halaman khusus A4 portrait.

## SQL dan deployment

Migration `2026_11_15_000001_add_finance_settlement_approval` menambah kode role `MGR_FINANCE_SETTLEMENT` dan memperluas CHECK `approval_steps` untuk workflow3/orderVersion3 dengan tujuh posisi yang tepat. Order1/2 dan identitas keputusan lama tetap. Tidak membuat akun, tidak mengganti role pengguna lama dan tidak mengubah transaksi berjalan. Marker/manifests memakai project_plans.document_data; metadata pembayaran/keputusan memakai payment_terms.document_data, approval_runs/steps/decisions dan finance_kasbons yang sudah ada. Rollback menolak role/order baru yang sudah digunakan.

**Belum dimigrasikan ke its aktif/server oleh agen.** Saat deploy: backup database/storage/APP_KEY sesuai prosedur proyek, jalankan migration yang tertunda pada its, bangun aset dan periksa aset, bersihkan cache aplikasi dan mulai ulang worker Area3 saja. Jangan fresh/reset/seed/generate APP_KEY. Setelah migration, Superadmin membuat/menetapkan akun Mgr Finance & Settlement beserta Branch; tidak ada akun/password contoh. Migration uang terdahulu yang masih tertunda mempunyai preflight/auditnya sendiri; jangan menganggap pull kode otomatis menerapkannya. Lihat panduan deployment/runtime yang sudah ada.

## Query dan bukti

Resolver approval SQL sekarang membaca ID/nama seluruh selected/history actors dalam batch200 sekali per dokumen; tidak melakukan lookup nama per tahap. Indeks keputusan dibangun sekali per siklus. Resolver identitas dan manifest memakai state terotorisasi yang sudah dimuat. Validasi enam signer kasbon menggunakan satu join akun/role/assignment/Branch, bukan query per role. Picker tetap20+1 dan query count sebelumnya; tambahan hanya kolom marker minimal. Dokumen Word/preview tidak meminta katalog/workflow global.

Fixture satu pembayaran/tujuh tahap mencatat54,56,57,58,59,60,64query per keputusan HTTP Parsial; Final7 mencatat66query termasuk pembuatan BA PP Draft, budget80; lookup nama batch≤2. Ini bukti fixture SQLite kecil, bukan uji beban MySQL. Jumlah SQL writes approval masih mengikuti jumlah siklus/keputusan; boot/command masih memuat aggregate terotorisasi seperti arsitektur sebelumnya. Tidak ada klaim seluruh aplikasi bebas bottleneck.

Verifikasi akhir dan batas aktual ada di [LARAVEL_QA.md](LARAVEL_QA.md) dan [bukti JSON](qa/bundle-settlement-20261115.json). Backup demo privat sebelum perubahan: `area3-before-bundle-settlement-20261115-171303.sqlite`,233472byte/mode0600. its aktif, arsip workspace, .env, server dan Dompis tidak dimutasi oleh implementasi ini.


## 16 November 2026 — Alihkan payment lama dari Finance ke Mgr Finance

Pengguna meminta pengajuan lama yang belum disetujui Finance dipindahkan dahulu ke inbox Mgr Finance. Finance/Superadmin kini dapat memilih maksimal50 kasbon-v1 yang memenuhi syarat melalui tombol **Alihkan Payment Lama ke Mgr Finance** pada Inbox Approval, mencari ID Project/LOP/PP/Branch (20baris/halaman), memilih akun aktif yang mencakup semua Branch, lalu konfirmasi YA/TIDAK. Pemindahan atomik dan eksplisit; tidak otomatis saat deploy. Lima approval pekerjaan/SQL lama tetap, sementara review tambahan kasbon aktif harus disetujui akun Manager yang dipilih sebelum Finance dapat melanjutkan approve/upload transfer/APM/lunas. Kasbon dikembalikan dengan catatan wajib ke Admin dan versi baru memerlukan review Manager kembali, tanpa mengulang lima approval pekerjaan historis.

Metadata penugasan/keputusan aktual dan riwayat disimpan server-side pada payment_terms.document_data melalui lock/revision/receipt/audit. Inbox/count, List Payment, timeline dan kasbon aktif mengikuti marker; cap Manager hanya dari keputusan nyata dengan UUID kasbon cocok, Word/cetak TTD tetap kosong. Original ciphertext/rekening/nominal/paid/APM/proof/approval_runs/steps/decisions/volume/archive tidak diubah. Tolak status/version/Branch/role/nonaktif/duplikasi/Head lock, pengajuan tujuh approval, Finance sudah Setujui, proof/APM/pembayaran dan queued proof. Tidak ada migration baru/live its/.env/Dompis/server write. [Aturan, data, deployment dan query](LEGACY_FINANCE_ROUTING.md).


## 18 November 2026 — Cap tanpa tanggal dan cap otomatis pengaju/EVP kasbon

Tanggal cap PP/kasbon tidak ditampilkan. Pengaju kasbon otomatis ditandai setelah penerbitan UUID tercatat; EVP kasbon hanya setelah persetujuan nyata Manager untuk UUID aktif dengan assignee sesuai. Dua cap administratif terpisah dari keputusan, Word/cetak tetap kosong; tanggal/audit/tahapan dan uang tidak diubah. Ini menggantikan batas lama lima cap preview kasbon saja: kini lima keputusan nyata + dua otomatis, tanpa menambah approval aplikasi. PP EVP tetap memakai aturan PP sebelumnya. Rincian gate/version/legacy, kinerja dan verifikasi aktual ada pada [KASBON_PREVIEW_STAMPS](KASBON_PREVIEW_STAMPS.md).

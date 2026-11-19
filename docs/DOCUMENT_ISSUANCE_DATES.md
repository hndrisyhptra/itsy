# Tanggal terbit dokumen dan jadwal pekerjaan

## 19 November 2026 — Koreksi Header tanpa Tanggal Terbit PP

Permintaan pengguna menghapus tambahan Tanggal Terbit PP pada Header. Pengguna juga menegaskan tanggal dokumen dapat disesuaikan selama tidak melampaui jadwal/HK; urutan wajib dan batas awal pekerjaan yang dikonfirmasi sebelumnya tetap berlaku. Form Header dan tabel Header pada preview/Word/cetak/paket PP tidak lagi menampilkan kolom tersebut. **Saat Penggunaan** menjadi acuan tanggal PP untuk penyusunan/revisi editable, sementara awal pekerjaan tetap dipilih terpisah pada Jadwal Proyek dan akhir dihitung HK inklusif.

Server/TypeScript `planning` memaksakan internal `issueDate = date` pada penyimpanan editablev3; `issueDate` client diabaikan dan tidak menjadi isian wajib tersembunyi. Validasi mengarahkan pengguna ke Saat Penggunaan atau awal pekerjaan, bukan form yang sudah dihapus. PP ≤ BA KH ≤ SPK ≤ awal pekerjaan, equality boleh, jarak1hari hanya saran. Tanggal BA KH/SPK tetap dapat dipilih manual dalam batas tersebut. Field internal lama tidak dihapus/backfill: Sah/Terbit/paket pending memakai snapshot/manifest/keputusan tersimpan, tidak dinilai ulang dari Saat Penggunaan pada GET/preview/Head. VIII.PENUTUP tetap kosong, Branch place dan blank wetTTD tetap.

Menggunakan JSON/tabel yang sama,0SQLhelper/API/schema/migration/dependency baru. Perubahan kode lokal, tanpa liveits/MySQL/.env/daemon/server/Dompis write. Hasil dan keterbatasan QA terbaru dicatat di [audit koreksi](qa/document-issuance-no-header-20261119.json); hasil penanggalan awal di bagian sebelumnya tetap sebagai riwayat.

Verifikasi koreksi: **366PHP/8870assertions**, **397frontend**, TypeScript/Vite/Pint/assets45refs/36preloads×3profil dan diffcheck lulus. Fixturecreate98query termasukrevision, helper0query tanpa tambahanSQL. Browser6case Konstruksi/Operation×375/768/1440×light/dark,0JSerror/overflow/write; input/row terhapus, tanggalworkstart/HK/usage tetap konsisten. DOCXPP2dokumen6halaman diperiksa visual seluruhnya, browserPDF2dokumen8halaman nonblank/contactsheet; XML/ZIPvalid, tidak ada rowTanggalTerbitPP, kolomtanggal/TTDVIII kosong. Datafiktif/inmemory saja, tanpa server/loginnyata/load/MicrosoftWord/printfisik atau renderpaketgabungan ulang.

### Riwayat implementasi sebelum koreksi Header

## 19 November 2026 — Aturan terbaru

Pengguna menetapkan urutan wajib **Tanggal Terbit PP ≤ Tanggal Terbit BA KH ≤ Tanggal SPK ≤ Tanggal Mulai Pekerjaan**. Tanggal sama diperbolehkan; jarak satu hari hanya saran, bukan validasi wajib. Aturan ini menggantikan aturan tanggal SPK bebas pada 18 November untuk dokumen baru/draf yang secara eksplisit disimpan. Tidak ada pembatas hari ini: tanggal lampau atau mendatang tetap dapat dipilih selama urutan dan batas awal pekerjaan terpenuhi.

Contoh pekerjaan 4–5 November 2026: PP 2 November, BA KH 3 November, SPK 4 November. Semua terbit 4 November juga sah. Tanggal SPK 5 November ditolak karena telah melewati awal pekerjaan, walaupun masih dalam periode pekerjaan.

## Form dan hasil dokumen

- Header PP mempunyai **Tanggal Terbit PP** tersendiri. **Saat Penggunaan** tetap ada dan tidak lagi menentukan jadwal baru.
- Jadwal Proyek mempunyai **Tanggal Mulai Pekerjaan** yang dipilih Admin. Lima HK tetap mengikuti tahap template; tanggal tahap dan akhir proyek dihitung otomatis dengan hari kalender inklusif. Tahap 0 HK dilewati. Contoh mulai 4 November dan total 2 HK berakhir 5 November.
- PP baru pada form mengusulkan tanggal terbit hari ini; Admin menyesuaikannya sebelum pengajuan bila pekerjaan lampau. Tanggal mulai kosong, tanpa mengambil Saat Penggunaan. BA KH mengusulkan sehari sebelum awal pekerjaan, paling awal tanggal PP bila diketahui; SPK mengusulkan awal pekerjaan. Ini hanya default, pilihan valid tidak diganti diam-diam.
- Draf Header dapat disimpan sebelum jadwal/tanggal terbit lengkap. Tanggal yang sudah diisi harus valid dan tidak melanggar batas yang telah diketahui. Pengajuan formal memerlukan tanggal terbit PP, awal pekerjaan dan lima HK valid dengan total positif.
- BA KH baru/draf tetap mengambil periode pekerjaan dari jadwal PP yang dipilih pada penyimpanan eksplisit. SPK tetap harus berada dalam **periode BA KH tersimpan**, dengan diagnostik nomor/rentang exact BA KH. Mengedit PP tidak otomatis menyimpan BA KH/SPK; Admin perlu membuka dan menyimpan draf terkait sebelum mengajukan paket.
- Preview PP baru menampilkan Tanggal Terbit PP pada Header dan periode pekerjaan pada III. Jadwal Proyek. Word/cetak/PDF/paket mengambil HTML/model yang sama. **Semua sel tanggal VIII. PENUTUP tetap kosong**, termasuk EVP; cap approval tetap tanpa tanggal dan tidak ikut Word/cetak. Tanggal aktual keputusan/audit tidak diubah.
- Tempat dokumen tetap mengikuti Branch PP sesuai pembaruan sebelumnya; kasbon dan tanggal pembayaran tidak diubah oleh fitur ini.

## Penyimpanan, keamanan dan kompatibilitas

`DocumentIssuance` PHP dan `document-issuance.ts` memakai kebijakan server `pre-work-issue-v1`, `issueDate` dan `workStartDate` pada `project_plans.document_data`. Proyeksi tahap memakai tabel `project_plan_schedule` yang sudah ada. `project_plans.usage_date` tetap Saat Penggunaan. **Tidak ada kolom/migration baru** dan tidak ada konversi massal.

Penyimpanan PP v3 editable memaksakan marker server, mengabaikan opt-out client, mempertahankan field tersimpan yang tidak dikirim (fallback hanya dari jadwal PP lama tersimpan, bukan tanggal tahap kiriman client), lalu menghitung ulang nama/tanggal tahap dari awal pekerjaan dan HK. Dokumen BA KH/SPK baru atau draf disimpan dengan marker yang sama. Validasi tanggal kalender dan urutan berjalan di PHP serta TypeScript, termasuk submit paket dan penyelesaian Head/GM untuk paket bertanda. Angka nominal, BOQ, ledger, batas volume, manifest, Branch/unit/Region, akun terpilih, private uploads, revision/receipt dan kunci Terbit/Sah tetap.

PP lanjutan v3 memiliki tanggal mulai kosong untuk ditentukan Admin sendiri, bukan menyalin awal pekerjaan induk. HK awal tetap template; tidak ada perubahan reservasi/pelunasan/release.

Dokumen **Sah/Terbit** mempertahankan snapshot. Paket lama **tanpa marker** yang sudah berjalan tidak dipaksa mengikuti tanggal baru ketika approver menyelesaikan pengajuan. Dokumen historis v1/v2 memakai alurnya sendiri. Pengajuan ulang/revisi editable v3 secara eksplisit mengadopsi aturan baru. BA KH/SPK baru pada PP lama mengikuti batas awal pekerjaan/urutan BA–SPK; bila PP historis tidak punya Tanggal Terbit PP, sistem tidak mengarangnya. Membaca/preview/deploy tidak menulis ulang tanggal atau histori lama.

## Performa dan verifikasi

Resolver tanggal murni tidak melakukan SQL/API. Validasi menggunakan PP/deal/SPK yang sudah dimuat; tidak menambah lookup per item, endpoint, poller, dependency atau perubahan schema. Kompleksitas jadwal tetap lima tahap dan pemilihan awal legacy memakai jadwal yang telah dimuat.

Hasil otomatis: **366 PHP / 8870 assertions**, SQLite `:memory:`; **397 frontend**, TypeScript/Vite lulus. Empat belas vektor bersama mencakup jarak opsional/equality, urutan terbalik, tanggal terlewat, kalender invalid/missing/leap-year; diuji pula anti-opt-out, rollback invalid tanpa perubahan revision/ledger, persisted JSON/tahap SQL, Saat Penggunaan independen dan penyelesaian paket historis di Head. Helper terukur **0 query**. Fixture HTTP create PP lengkap termasuk pembacaan revision dan BOQ/budget/lima tahap **98 query** (budget100); update anggaran existing suite **84** (budget90). Angka ini fixture kecil, bukan benchmark beban server.

Browser terpisah menguji Konstruksi/Operation: form PP interaktif, bounds SPK dan modal preview asli pada375/768/1440px, tema terang/gelap, tanpa errorJavaScript atau pageoverflow. Mengubah tanggal mulai/HK memperbarui akhir, mengubah Saat Penggunaan tidak menggeser jadwal; tanggal sebelum PP menunjukkan warning/batas input. Dua DOCX PP hasil capture DOM dirender menjadi6halaman, seluruh halaman diperiksa visual; dua browserPDF menghasilkan8halaman nonblank dan diperiksa lewat contact sheet. ZIP/XML valid, tanggal terbit/periode sesuai, tanggal dan TTD VIII kosong. Template lain dan paket gabungan memakai model yang sama tetapi tidak dirender ulang pada perubahan ini; MicrosoftWord desktop/print fisik/akun nyata/server belum diuji.

Bukti tambahan browser/Word/PDF dan batas pengujian dicatat pada [QA JSON](qa/document-issuance-20261119.json). Tidak ada migration, `.env`, database its aktif, daemon, server aaPanel atau proyek Dompis yang diubah oleh agen. Operator tetap perlu deploy kode dan build sesuai prosedur proyek; ini tidak otomatis terjadi lewat percakapan.

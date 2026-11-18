# Laravel conversion checklist

## 16 November 2026 — Paket dokumen lengkap, timeline terbaru dan List Payment putih

Pengguna meminta satu Word/PDF dari PP sampai Lampiran BA PP, dan mengonfirmasi kasbon setiap pembayaran ikut. Ikon pada Aksi BA PP hanya muncul setelah Sah; tersedia pula di detail/timeline. Preview menggabungkan PP, BA KH/lampiran, SPK/lampiran, BARP/lampiran/kasbon aktif tiap pembayaran lalu BA PP/lampiran, lewat exact UUID lineage. Native Word memakai generator asli dengan section portrait/landscape, style/numbering/media terisolasi dan header kosong untuk mencegah logo diwariskan. Print menyimpan satu PDF nonblank; cap/status sel TTD tidak ikut hasil unduhan/cetak. Unggahan privat tetap terpisah. Kasbon hilang/akses gagal/versi berubah menahan ekspor; histori tanpa kasbon tidak dibuatkan snapshot baru.

Timeline/detail PP/pembayaran/BA PP/inbox memperlihatkan paket5approval, kasbon sebelum7approval, transfer/lunas, status scan terpisah dan APM opsional. Keputusan current-user/current-kasbon saja mendapat tanda selesai; riwayat lama tetap. List Payment mengganti banner gradient dengan putih/solid dan memperbaiki keterangan APM opsional. Lampiran SPK Word memperlebar TotalVol agar digit total tidak terpisah.

Query/performa: indeks relasi/total BA PP sekali, list20baris, grouping timeline sekali dan aktivitas20baris per halaman. Kasbon current_only pada GET existing memilih satu UUID aktif/3kolom tanpa file catalog/arsip decryption;3queryservice/6HTTP pada fixture, maksimum3request per paket. Tanpa mutasi SQL/schema/migration/dependency/its aktif/.env/Dompis; data, uang, approval, ledger, encrypted snapshots dan arsip tetap. Rincian/pengamanan/deploy: [DOCUMENT_PACKAGE](docs/DOCUMENT_PACKAGE.md).

Verifikasi:311PHP/7193assertions memory,336frontend,TypeScript/Vite,Pint dan assets41refs/32preloads×3profil lulus. Native Word2paket/30halaman, browserPDF2paket/32halaman, ZIP/CRC/XML/rel/style/section/blankTTD/nonblank serta klik YA/TIDAK Word/print lulus pada fixture. Server/akunlive/Worddesktop/cetakfisik/loadtest belum diverifikasi; tidak deploy/migrate/restart. [Bukti](docs/qa/document-package-20261116.json).

## 16 November 2026 — Unduh Word BA PP dan Lampiran BA PP mengikuti BARP

Kedua preview kini memiliki Unduh Word (.docx), konfirmasi YA/TIDAK, busy/duplicate guard dan penanda review sesuai pembayaran. Surat nativeWord memakai package template BA-PP.docx dengan slot dari model HTML yang sama, tanpa nomor BAPP, conditional Tahap/Termin dan tiga ruang TTD kosong.18packageentries;15preserve-only byte-exact, metadata core/custom dinetralkan, body editable/unused bookmarkend dibersihkan; sumber asli tetap.

Lampiran BA PP menggantikan tabel generik dengan grid Lampiran BARP yang sama (11/12/13kolom, LOP/Termin, actuals, split M/J, rekap dan cumulative/current bill), tetapi tiga signer Head of PMO/Waspang/Ketua Mandor. Shared HTML/nativeWord renderer menjaga tabel identik; BARP tetap5signer. Tidak mengubah nilai/status/approval/SQL/ledger/archive/Finance/release/continuation.

Tambahan0SQL/API/schema/migration/dependency, indeks existing/max3tahap dan lazyWord package.310PHP/7137assertions,329frontend/build/assets37refs/29preloads×3profil lulus.6nativeWord/8halaman dan6browserPDF/8halaman diperiksa; ZIP/XML/CRC/section/spans/rel/bookmark/source fidelity valid. React button QA: TIDAK0unduh, YA1DOCX per tombol,0JSerror dan hanyaGETfixturelokal. Browser akun/server/desktopWord/cetak fisik/loadtest belum diuji. [Perilaku](docs/BAPP_WORD_APPENDIX.md), [bukti](docs/qa/bapp-word-appendix-20261116.json).

## 16 November 2026 — Preview BA PP mengikuti template surat

Preview BA Penyelesaian Pekerjaan kini mengikuti BA-PP.docx: judul tanpa nomor BA PP, pembuka tanggal terbilang, identitas Mandor, nama pekerjaan, hasil, penutup dan tiga TTD (Waspang, Ketua Mandor, Head of PMO). Single Final1 menghilangkan Tahap1/Termin1; pembayaran bertahap menampilkan TahapN pada hasil/penutup dan TerminN di nilai. Tanggal/periode/identitas bersumber dari BARP pembayaran terkait; nilai bruto tahap ini/persentase terhadap SPK kanonis sesuai slot template. Tidak memaksakan100% Final atau mengganti tagihan dengan kasbon net. Detail dan Lampiran BA PP kumulatif, nomor internal, pengesahan, approval dan pelepasan tetap.

HTML/cetak/PDF memakai model pure khusus, A4portrait25.4mm, Calibri11pt/14ptjudul; nama panjang memakai jarak ringkas tanpa truncation dan tiga TTD bersama. Print mempertahankan YA/TIDAK dan seluruh keputusan assignee aktual.0SQL/API/schema/migration/dependency baru; O(item kesepakatan), tanpa per-row master/account lookup, fixture1000item lulus. Tidak mengubah its aktif/env/arsip/Dompis atau melakukan deploy.

Verifikasi:310PHP/7137assertions SQLite memory,325frontend,TypeScript/Vite/assets36references/28preloads×3profil lulus. Referensi1halaman diperiksa, hash sumber utuh. Tiga PDF browser sintetis Final1/Final2/longFinal3 masing-masing1halaman, seluruh3PNG diperiksa, nonblankportrait/label/nilai/3nama benar, tanpa nomor internal;375/768/1440 tanpa overflow. Browser akun/server/cetak fisik belum diverifikasi. Tidak menambah unduhan Word pada tugas preview ini. [Perilaku](docs/BAPP_TEMPLATE.md), [bukti](docs/qa/bapp-template-20261116.json).

## 16 November 2026 — Tabel Lampiran BARP mengikuti Lampiran SPK

Permintaan pengguna menyesuaikan **tabel saja**: No, Designator, Uraian, Satuan, Harga Satuan Material/Jasa, kolom volume LOP, Total Vol, Material, Jasa dan Jumlah. Pembayaran pertama langsung Final menampilkan nama LOP tanpa Termin; pembayaran bertahap menampilkan nama LOP + Termin #1, #2, sampai tahap dokumen yang dibuka (maksimal tiga). Jumlah kolom menjadi 11/12/13. Lima penandatangan, urutan, identitas historis dan ruang TTD tetap.

HTML/print/native Word memakai satu model. Volume berasal dari aktual, bukan seluruh komitmen SPK. Baris Material/Jasa digabung hanya bila volume komitmen dan seluruh vektor aktualnya sama; selain itu dipisah agar tidak menukar volume komponen. Nilai tabel bersifat kumulatif dari termin terdahulu yang Disetujui dan aktual saat ini; rekap tagihan saat ini tetap dipisahkan. Setiap termin mempertahankan kebijakan nominalnya. Tidak menghitung ulang pembayaran/PP/SPK/kasbon/ledger tersimpan atau mengubah approval.

Tidak ada SQL/API/schema/migration/dependency baru atau mutasi its aktif. Map referensi/kesepakatan/komponen dibangun sekali, maksimal tiga tahap dan dua komponen per item; fixture 1000 baris tetap tanpa pencarian katalog per baris. Bukan benchmark beban. Verifikasi:310 PHP/7137 assertions pada SQLite memory,320 frontend,TypeScript/Vite dan assets36 references/28 preloads×3profil lulus. Empat Word sintetis dan empat PDF browser nested masing-masing1/1/1/3halaman, seluruh12PNG hasil dokumen diperiksa; ZIP/CRC/XML,span/lebar,headerWord berulang,5TTD,landscape dan nonblankPDF valid. Teks LOP sangat panjang dapat menghasilkan beberapa halaman; Chrome tidak selalu mengulang header tinggi, sedangkan Word mengulang dua baris header. Browser akun/server, Word desktop dan cetak fisik belum diuji. [Perilaku](docs/BARP_APPENDIX.md), [bukti](docs/qa/barp-spk-grid-20261116.json).

## 16 November 2026 — Tandai Lunas dengan APM opsional

Finance dapat menekan Tandai Lunas setelah bukti transfer aktif selesai diunggah dan approval/review Finance lengkap, tanpa nomor APM. Upload sendiri tidak membayar otomatis. APM dapat diisi saat pelunasan atau ditambah/diedit sesudah lunas; format/keunikan dan konfirmasi YA/TIDAK tetap. Koreksi nomor menyimpan audit before/after/actor/waktu dan tidak mengubah nilai/tanggal/penerima/bukti/keputusan maupun menambah pembayaran kedua. Berlaku pada alur kasbon-v1/kasbon-settlement-v2; pra-kasbon historis tetap.

PHP/React menambah settleFinancePayment/updatePaymentApm dan settlementRecorded; tanda pelunasan terikat kasbon/proof disimpan server, APM kosong tetap Belum Terbit. Next-term/picker, inbox/timeline, matrix CLOSE dan List Payment mengikuti lunas aktual. SQL apm_settlements tetap1baris/pengajuan; hanya apm_no diperbarui saat koreksi, paymentHistory asli tetap. Migration2026_11_16_000001 membuat apm_no nullable dengan unique/collation/FK/authority tetap dan CHECK NULL khusus kasbon+proof, trigger SQLite dipulihkan, rollback ditolak bila masih ada NULL. Tidak ada backfill/pembayaran/adopsi massal; migration belum diterapkan agen pada its aktif/server. Backupdemo233472byte/0600 sebelum edit; env/APP_KEY/PII/Dompis tidak disentuh.

Query:resolver0SQL, readiness1query per command baru, pending upload probe tetap; fixture pelunasan/koreksi39query masing-masing≤80, List Payment4query/20+1 tanpa per-row query/katalog/dekripsi. Uji UI HTML memeriksa tombol sebelum/sesudah proof, tanpa APM, editor setelah lunas dan role; bukan pengujian browser akun/aaPanel/MySQL DDL/transfer bank nyata. 303PHP/6749assertions,310frontend,TypeScript/Vite,scopedPint dan assets36refs/28preloads×3profil lulus. [Perilaku dan penerapan](docs/PAYMENT_SETTLEMENT_OPTIONAL_APM.md), [bukti suite/query/batas](docs/qa/optional-payment-apm-20261116.json).

## 16 November 2026 — Bukti transfer ditolak worker lokal lama

Dua task bukti transfer lokal ditolak dengan pesan kasbon belum disetujui Finance. Diagnosis baca-saja pada its menunjukkan flow kasbon-settlement-v2/version3, pengajuan Disetujui, Finance Diverifikasi dengan keputusan Setujui dan kasbonId cocok, belum APM/lunas; context task juga cocok. Worker PID23479 masih berjalan sejak15November16:41, sebelum handler/domain diperbarui16November04:48–04:51. Kode worker yang sudah dimuat berbeda dari kode web; tidak perlu mengubah approval atau status SQL.

Cache file/prefix/lokasi proyek diverifikasi sebelum queue:restart, lalu proses lama dikonfirmasi berhenti. Autostart lokal tetaptrue, worker pengganti mengikuti identitas OS web pada unggahan berikutnya. Pengguna mengonfirmasi unggahan baru berhasil. Task gagal tetap sebagai riwayat; tidak mengedit business rows, membayar, mengulang persetujuan, menghapus jobs, mengubah .env/izin privat atau menyentuh worker aplikasi lain. Setelah pembaruan handler/domain upload, restart worker proyek merupakan bagian penerapan kode; lihat UPLOAD_QUEUE_PERFORMANCE.

Regresi existing BundleSettlementTest kini benar-benar melepas guard/sesi browser sebelum memproses bukti transfer Parsial dan Final setelah tujuh keputusan, memeriksa pemulihan guard kosong, uploaded_by Finance, file privat/preview dan APM. Worker43query per fixture (batas80), tanpa query tambahan pada aplikasi atau lookup per approver. Tidak ada perubahan kode produksi/schema/migration/dependency; hanya pengujian dan dokumentasi ditambah. Pengujian memakai SQLite memory/fake storage, bukan transfer bank nyata atau load benchmark/server aaPanel. 300PHP/6593assertions,305frontend,TypeScript/Vite,Pint scoped dan assets36manifest/28preload×3profil lulus. [Bukti dan hasil lengkap](docs/qa/transfer-worker-restart-20261116.json).

## 16 November 2026 — Label termin pada kasbon

Pembayaran pertama langsung Final (termNumber1/termTypeFinal) tidak menampilkan label Termin1. Pembayaran bertahap menampilkan hanya TerminN, termasuk termin terakhir, tanpa (Final)/(Parsial). Berlaku pada preview, HTML yang dicetak dan native Word melalui kasbonTermSuffix yang sama, untuk seluruh versi template. Nomor SPK dan metadata tersimpan tetap; nama file tetap memakai nomor termin untuk identifikasi. Tidak mengubah BARP, editor/daftar pembayaran, tujuh approval, cap, nominal net, ledger atau APM.

Presentasi murni dari props authorized yang telah dimuat: helper O(1), tanpa query/lookup SQL/API/dependency/schema/migration baru dan tanpa perubahan snapshot/riwayat. Tidak ada live its/env/Dompis/worker/deploy; tidak memerlukan backup baru karena tanpa write database.

Verifikasi:300PHP/6581assertions,305frontend,TypeScript/Vite dan assets36manifest/28preload×3profil lulus. Regresi mencakup5kombinasi nomor/jenis pada3versi template untuk HTML/Word serta snapshot unchanged. Tiga DOCX sintetis (langsung Final, Parsial1, Final2) lulus ZIP/CRC/XML dan render1halaman per dokumen; ketiga PNG diperiksa, PDF render berisi dan label sesuai. Browser print tidak dijalankan ulang untuk perubahan teks ini; print memakai komponen HTML yang sama. Browser akun/server, Word desktop dan cetak fisik belum diverifikasi. [Bukti](docs/qa/kasbon-term-label-20261116.json).

## 16 November 2026 — Hapus penandatangan Admin Area dan Finance pada kasbon

Permintaan pengguna menghapus slot **Memeriksa · Admin Area** dan **Menyetujui · Finance** dari kasbon template3. Preview, cetak/PDF dan native Word menggunakan model signer yang sama: PM/pengaju; Project Support/Deployment/Head; Mgr Finance & Settlement/EVP. Tujuh slot fisik ditata2/3/2 dengan enam kolom dasar, colspan3/2/3; tidak menyisakan slot kedua peran yang dihapus. Alur aplikasi tetap tujuh approval termasuk Admin Area dan Finance, dengan keputusan, binding kasbonId, inbox/timeline dan Finance/APM yang sama. Maker Finance pada dokumen lama bukan slot Menyetujui Finance dan tetap dipertahankan; susunan template lama tetap.

Perubahan hanya pada kasbon-content, kasbon-document dan kasbon-docx. Nominal net/PPh, tanggal, penerima/rekening, snapshot SQL/riwayat dan kontrol akses tidak dimutasi. Tidak ada query, endpoint, lookup katalog, dependency atau migration baru; render memakai props authorized yang sudah dimuat dan tujuh sel tetap. Tanpa live its/env/Dompis/worker/commit/push/deploy; tidak perlu backup database baru untuk perubahan presentasi tanpa writes.

Verifikasi:300PHP/6581assertions,304frontend,TypeScript/Vite dan assets36manifest/28preload×3profil lulus. Existing semantic tests memastikan peran/nama yang dihapus tidak ada di HTML/Word, history aplikasi tetap7, cap nyata yang masih ditampilkan5 (atau4 bila PM tidak cocok), arsip beda kasbon tidak bercap, dan snapshot sumber tidak berubah. Native Word normal/20baris dirender1+2halaman; seluruh3PNG diperiksa serta ZIP/CRC/XML valid. Browser QA sintetis normal/nested dan panjang/nested menghasilkan PDF1/1/3/3halaman, semua berisi;5cap preview/0print, signer tersisa dan colspan benar. Preview layar juga diperiksa. Browser akun server/aaPanel/Word desktop/cetak fisik belum diverifikasi. [Bukti](docs/qa/kasbon-signatures-20261116.json).

## 16 November 2026 — Kasbon net Jasa Mandor dan List Payment Finance

- Potongan2,5% hanya Jasa Mandor aktual; Material utuh. Pengguna mengonfirmasi transfer/APM memakai net dan bruto tetap. Quote server-owned/versioned disimpan bersama kasbon; rincian/total/terbilang/Word/cetak hanya net tanpa baris PPh. Kasbon terbit/paid lama tidak dihitung ulang.
- APM/payed history menyimpan net; status Lunas/next-term/CLOSE/timeline/outstanding/CSV memakai payable versioned, bruto/BOQ/BARP/ledger volume tetap. Full approval/Finance/bukti/scan/Branch/UUID/revision tetap wajib.
- Pembayaran → List Payment khusus Mgr Finance & Settlement/Finance: ringkasan pengajuan/ID/sudah-belum/nilai, search LOP/dokumen/APM, filter Branch/status, tahap approval/berkas, detail,20+1baris dan refresh revisi aman. Tidak menambah menu utama atau membuka bank/PII.
- Query quote0SQL; daftar4servicequery untuk fixture100pending, tanpa full aggregate/dekripsi/per-row query; issue/APM44query fixture.300PHP/6581assertions,304frontend/build/Pint/assets36refs/28preloads×3profil lulus.2Word/2PNG diperiksa; browser QA filter/retry/375/768/1440/dark lulus. Bukan load benchmark atau browser akun live.
- Backupdemo233472byte/0600; tanpa migration baru/live its/env/Dompis/commit/push/deploy. [Perilaku/arsitektur/batas](docs/KASBON_PPH_PAYMENT_LIST.md), [bukti](docs/qa/kasbon-pph-payment-list-20261116.json).

## 16 November 2026 — BARP langsung Final dan Surabaya

- [x] Pembayaran pertama Final: hilangkan nomor tahap/termin pada main/appendix/Word/toolbar/filename, tanpa mengubah ledger. Parsial pertama dan Final berikutnya tetap bernomor.
- [x] Surabaya otomatis readonly pada form; Laravel/React mengabaikan kota masuk pada editable save. Output historis memakai kota tetap dan tanggal asli tanpa bulk rewrite.
- [x] Lampiran Final satu kali memakai satu kolom aktual Final; perhitungan/identitas/approval/Finance/APM dan arsip tetap.
- [x]296PHP/6459assertions,299frontend,build/Pint/assets lulus;4Word/6halaman diperiksa dan ZIP/XML/PDF nonblank. Helper0SQL baru; fixture simpan metadata59query≤60. Bukti: docs/qa/barp-final-surabaya-20261116.json.
- [ ] Uji manual browser akun/server/Word desktop/cetak fisik tetap terpisah. Tidak perlu migrasi; tidak melakukan deployment/data rewrite/env/Dompis. Backupdemo233472byte/0600.

## 15 November 2026 — Paket PP dan kasbon sebelum tujuh approval

- [x] PP baru/lanjutan bertanda server: PP → draf BA KH → draf SPK/profil Mandor → Ajukan Paket → lima keputusan → Sah/commit atomik.
- [x] Kunci isi paket/profil selama pengajuan, hash versi, harga/jadwal/volume/pending-upload guards; penolakan mempertahankan sejarah dan mengulang PM.
- [x] Kasbon Admin sebelum pengajuan pembayaran; pilih Settlement aktif/Branch, tujuh tahap/inbox/timeline, keputusan terikat kasbonId, penolakan pada tujuh posisi dan versi baru.
- [x] Finance review/approval7 → bukti transfer privat → APM penuh; scan boleh menyusul, next-term dan tiga termin tetap. Alur lama tidak diadopsi massal.
- [x] Kasbon tanpa TTD Mandor; nama Settlement aktual; cap keputusan nyata hanya preview; native Word lazy dan print/PDF biasa/nested tidak blank.
- [x] Migration role MGR_FINANCE_SETTLEMENT/order3 CHECK, rollback guard tanpa akun contoh; actor lookups batch, picker tetap bounded. Perilaku/arsitektur: docs/BUNDLE_SETTLEMENT_APPROVAL.md.
- [ ] Operator menerapkan migration/build pada server dan menyiapkan akun Settlement/Branch; belum dijalankan agen. Uji server, browser akun end-to-end, Word desktop dan cetak fisik masih terpisah.
- Bukti penuh:295PHP/6447assertions dan297frontend lulus; native Word1+2halaman diperiksa; PDF normal/nested1+3halaman,7cap layar/0cetak. Build/aset/Pint serta hasil tambahan terakhir dicatat pada LARAVEL_QA dan qa/bundle-settlement-20261115.json. Backup demo233472byte; tidak menulis its aktif/env/Dompis.

Target: /Applications/XAMPP/xamppfiles/htdocs/its/area3-prototype. Laravel12/PHP8.2, Blade shell + retained React/TypeScript interface. No dependency on Dompis Prov runtime, database, credentials or vendor directory.

- [x] Laravel skeleton and separate dependencies.
- [x] Retain exact screens/styles and browser drafts.
- [x] Laravel command service: PP/BOQ/BA KH/SPK/Termin/approval/APM/payment/BA PP/release/continuation/KHS/users.
- [x] SQLite workspace per visitor, CSRF, revision, idempotency and audit.
- [x] Native CSV/JSON exports and subdirectory URLs.
- [x] PHP feature tests, retained frontend invariant tests, build and PHP8.2 verification.
- [x] Copy verified project to requested target, verify native XAMPP/Artisan access, run browser QA and handoff.

Completed: 43 PHP tests / 314 assertions, 31 frontend tests, production build, all five skill validators, browser proof and native CSV download. See docs/LARAVEL_QA.md for observed flows and limits.

Follow-up: sidebar build refreshed; test database isolation strengthened and verified with 44 PHP tests / 320 assertions, 31 frontend tests and production build. The initial follow-up test run reset the demo database through a cached runtime configuration; recovery of the previous workspace data and audit remains pending. See the incident entry in `docs/LARAVEL_QA.md`.

## Workflow v2 — 3 November 2026

- [x] Back up the current demo SQLite before workflow edits; preserve v1 history.
- [x] BOQ-first PP wizard, official KHS calculations and automatic BOQ Owner.
- [x] Ordered Header fields, Branch/Unit dropdowns and server-generated configurable PP numbering.
- [x] Selected PM/Manager, PP submission and two-stage approval with inbox routing.
- [x] BA KH within SPK, nine sidebar menus, Termin labeled Pembayaran.
- [x] Two selected payment approvers, print gating and named empty wet-signature spaces.
- [x] Real private scan upload, correction history, Finance verification and APM full settlement.
- [x] Retain ceilings, volume conservation, three-term/Final gates, BA PP release and root-budget deduplication.
- [x] MySQL concept/ER diagram and initial 38-table DDL draft; refined to 42 tables + 1 ALTER below, no MySQL deployment.
- [x] 51 PHP tests / 465 assertions, 38 frontend tests, TypeScript/Vite build, Pint and observed browser flows. Evidence and limits in `docs/LARAVEL_QA.md`.
- [x] Map the supplied organizational/designator/package/price masters into the SQL concept and seed; runtime importer and KHS reference/effective-version mapping remain pending.
- [ ] Previous incident recovery: still awaiting a usable pre-reset backup/page; not completed by v2.

## BOQ template and numbering refinement — 3 November 2026

- [x] Separate private SQLite backup before edits: `area3-before-boq-numbering-20261103-084717.sqlite`.
- [x] Restore BOQ file selector in PP step 1 with explicit simulation boundary; remove primary manual designator entry.
- [x] Requested number pattern, Jakarta period, minimum four digits, no automatic reset; retain issued numbers/custom settings/counter.
- [x] Revise MySQL concept: versioned BOQ templates, source files, batches/row matching/review/provenance, Service Areas, numbering snapshots. 42 tables + 1 ALTER; no deployment.
- [x] Verification: 52 PHP tests / 474 assertions, 39 frontend tests, TypeScript/Vite build, Pint, static DDL references and browser BOQ/numbering/Owner/responsive checks.
- [x] Implement real parsing for the supplied OSP FO sheet/header mapping on 4 November; see BOQ_IMPORT.md. Other layouts remain separate.
- [x] Confirm Area → Region → Branch → Service Area from the supplied dumps; retain no automatic number reset until a different policy is requested.

## Supplied master-table alignment — 3 November 2026

- [x] Read/copy eight supplied SQL dumps as reference data only; never execute their database commands.
- [x] Preserve all 2,173 master rows and original IDs/values/dates, including seven zero prices.
- [x] Add 16 unique kode_branch values while keeping original code abbreviations; retain DENPASAR once.
- [x] Add areas, regions, designator_types and designator_package_prices to the concept. All 46 tables now have distinct id_* primary keys; composite business uniqueness is retained.
- [x] Retain numeric source audit references and NULL category values without invented users/categories.
- [x] Add source hashes, mapping documentation, safe offline literal parser and five passing fidelity/integrity tests; verify 46 named PKs / 86 FKs. Required regressions pass: 52 PHP tests / 474 assertions, 39 frontend tests and TypeScript/Vite build. No SQL deployment, no runtime/master replacement.
- [ ] Receive source user/category mapping if those references are needed; receive BOQ template and KHS reference/version/effective-scope metadata before implementing the official importer.

## Executable migrations for its — 3 November 2026

- [x] Add 48 ordered migrations: preserve/align Laravel users.id to id_user, create 45 other business tables, attach BOQ provenance, import all 2,173 source master rows atomically.
- [x] Rename SQL reference area3_users to users and align operational user FKs to unsigned BIGINT; update User model, keep standard password column and remove automatic Test User seeding.
- [x] Keep named business primary keys, 86 FKs, business UNIQUE keys and 52 MySQL/MariaDB CHECKs; use MariaDB-compatible collation.
- [x] Guard Area 3 migrations to mysql/mariadb database its (or testing :memory:); retain runtime .env/cache/SQLite.
- [x] Test fresh install, idempotency, exact master rows, existing user/hash preservation, conflict refusal and rollback/reapply in isolated SQLite; six migration tests.
- [x] Test actual migrations against a private, temporary MariaDB 10.4.28 instance with no networking: all schema/data/checks, invalid UNIQUE/FK/period, timezone restoration, rollback/reapply and existing user verified. Server stopped; user database untouched.
- [x] Publish docs/MYSQL_MIGRATION.md with configuration/commands and current aggregate-runtime limits. Final checks: 58 PHP tests / 611 assertions, 39 frontend tests, six offline source tests, build and Pint; evidence in docs/LARAVEL_QA.md.
- [x] User confirmed migration on its was completed. The account-audit follow-up migration was applied after a private backup on 4 November.
- [x] Read Branch, account assignments, designators, packages and prices from relational masters.
- [ ] Normalize workflow records and implement official BOQ template parsing in a later change.

## Account login and MySQL master integration — 4 November 2026

- [x] Back up the configured its database before changes; preserve SQLite and old session workspaces.
- [x] Responsive modern Blade login/setup and account-management pages, #9FA1FF, solid surfaces, accessible password controls and validation.
- [x] User-chosen local first Superadmin, serialized provisioning, hashed passwords, no seeded credentials.
- [x] One SQL role per active account, assigned Branches, account CRUD restricted to Superadmin, self-lockout guard and session revocation.
- [x] Shared workspace for login users with server-bound identity, Branch-filtered reads/exports/scans and scoped commands.
- [x] Remove live role switch/reset/scenario commands; real logout, account-specific drafts, explicit refresh and revision conflicts.
- [x] Read source Branch codes and designator/package prices without modifying source master rows or inventing KHS metadata.
- [x] Account identity in audits via one additive migration; 53 total migrations.
- [x] Verify UI login/logout, actual PM then Manager PP approval and Finance account using a marked disposable MariaDB instance; user database remains without test accounts.
- [ ] Official BOQ importer, normalized operational persistence, external verification and production hardening remain separate work.

Final verification for this change: **68 PHP tests / 697 assertions**, **39 frontend tests**, **six offline master-fidelity tests**, TypeScript/Vite build and Pint passed. Evidence: docs/qa/auth-integration-20261104.json and the account-integration section of docs/LARAVEL_QA.md. Live its retains 0 users/0 roles/0 workspaces until the user creates the first account; all 2,173 source master rows and the old SQLite hash are preserved.

Cleanup: Artisan :8013 stopped; disposable QA :8014/database remain running because the final stop permission was denied. No alternate stop was attempted. The main XAMPP services were untouched.

## Username and five-approver workflow — 4 November 2026

- [x] Back up its and apply two additive migrations; 55 total, 89 business FKs. Preserve the existing password and every old users/workspace/audit/master value.
- [x] Add username, nik, id_regions, id_branch, id_service_areas and role; validate unique identity, leading-zero NIK and placement hierarchy without expanding assigned Branch access.
- [x] Authenticate with username; old account username user-1, password unchanged, editable through Kelola akun. Do not install synthetic accounts or credentials.
- [x] Five selected PP approvers: PM → Admin Area → Mgr Deployment → Mgr Bussiness Support → Head of PMO; 5/5 opens BA KH/SPK.
- [x] Freeze five approvers on new terms, gate print/upload at 5/5 and require separate Finance approval bound to the latest private wet-signature scan before full APM settlement.
- [x] Retain v1/v2 documents/decisions; explicit future five-approver assignment for new payments on historical PPs, and v3 continuation with unchanged root ledger/budget rules.
- [x] Final checks: **73 PHP tests / 894 assertions**, **44 frontend tests**, **six source-fidelity tests**, TypeScript/Vite build and Pint passed. Login desktop/mobile browser inspection completed; new full-chain browser switching was not repeated.
- [x] Publish WORKFLOW_V3 and preservation evidence in docs/qa/username-approval-v3-preservation-20261104.json; live its retains 1 user, 1 workspace, 0 audits and all 2,173 source master rows.
- [ ] Official BOQ Excel parsing, normalized operational persistence and external banking/signature verification remain separate work.

## BOQ upload and optional draft approvers — 4 November 2026

- [x] Back up its before changes; preserve original workbook and all saved account/master/workspace values.
- [x] Parse the actual supplied OSP FO .xlsx layout server-side, store original/manifest privately and return canonical positive BOQ rows. 14 source jobs → 26 separate Material/Jasa components, total Rp140.056.789.
- [x] Match exact active package/code/unit, preserve missing versus zero, ignore cached totals, reject invalid input formulas/precision/duplicates/archives atomically.
- [x] Retain trusted source prices/provenance; KHS owns totals and discrepancy review must match current KHS version/price. Branch-scoped source download and upload ownership enforced.
- [x] Allow incomplete approver choices on draft saves; retain mandatory header/BOQ and validate any supplied choices. Require all five selected actors only at submission.
- [x] Populate BOQ/Owner from the real file, explain missing approver accounts, retain separate simulation sample, and rebuild assets.
- [x] Final verification: **81 PHP tests / 1,073 assertions**, **46 frontend tests**, **six fidelity tests**, TypeScript/Vite build and Pint passed. Authenticated browser clicks were not repeated without credentials; HTTP tests cover real upload/draft behavior.
- [x] Verify all 13 checked its tables unchanged against backup; 55 migrations, 1 user, 1 workspace, 0 audits and 2,173 source master rows. Evidence: docs/qa/boq-import-preservation-20261104.json.
- [ ] Other workbook layouts/input formulas, file retention/cleanup, normalized operational persistence and external verification remain separate work.

## Managed-account password length — 4 November 2026

- [x] Lower add/edit account minimum to six characters in server rules, both password inputs and help text; retain letters/numbers, confirmation and 72-byte cap. First local setup retains its existing policy.
- [x] Verify rejection below six, successful creation/reset/login at six and unchanged hash on optional blank edit through isolated HTTP tests.
- [x] 82 PHP tests / 1,103 assertions, 46 frontend tests, production build and Pint passed. No live account/password or workflow data was changed.

## Multiple PPs per Project ID and timeline — 4 November 2026

- [x] Confirm runtime and relational master/PP schema already allow one business Project ID to have multiple PP numbers; preserve separate BOQs, roots, decisions and payments.
- [x] Add accessible history icon to Project Plan actions, timeline deep link/breadcrumb and grouping of all visible PP siblings with optional PP filter.
- [x] Show PP and term approval positions, selected people, decision history, BA KH/SPK, current scan/Finance, APM/payment history and BA PP/release summary.
- [x] Filter related activity by exact document relationships and preserve Laravel Branch-scoped reads; no new command, schema or live data mutation.
- [x] Remove Jenis PP from the main PP table while retaining lineage data/detail and other actions.
- [x] Automated verification: 85 PHP tests / 1,136 assertions, 52 frontend tests, build and Pint pass. Browser visual preview blocked by the local-file URL policy; no bypass attempted. See PROJECT_TIMELINE.md and LARAVEL_QA.md.

## Fixed PP introduction/team and HK schedule — 4 November 2026

- [x] Read/render the supplied one-page DOCX and verify exact introduction/team paragraphs, five activity names and source chart image; retain the original file.
- [x] Back up its before workflow edits; no live account/workspace/migration mutation.
- [x] Share fixed template wording between Laravel and React. Current v3 draft saves canonicalize prose/team/activity names, whole HK, duration and dates from Header Saat Penggunaan. No invented team members/signatures.
- [x] Only HK is editable in the two requested tabs; preserve issued history and historical v2 draft behavior. New continuation starts with five 1-HK activities and retains continuation notes separately.
- [x] Header–Jadwal template print preview followed by retained BOQ/budget/risk/communication/quality appendices. Label the source 2020 S curve as a reference; actual curve and DOCX export remain unavailable.
- [x] 88 PHP tests / 1,168 assertions, 57 frontend tests, production build and Pint passed. Browser visual/print pagination QA was not repeated; no policy workaround or test credentials used. See PP_TEMPLATE.md and LARAVEL_QA.md.

## PP budget tables, margin and fixed closing — 4 November 2026

- [x] Read/render all three pages of 01._PROJECT_PLAN_DIGITAL.v5.docx; preserve source and verify exact communication, quality and closing wording.
- [x] Back up its before edits; remove Kurva S from form and preview per latest user instruction.
- [x] Owner from canonical BOQ/allocation; editable Mandor prices/volumes with independent component ceilings, totals and comparison ratio.
- [x] Add/delete Material Non Stock, BOP and usage rows; manual permit; rupiah BOP limit with Cost Structure reference (user confirmed rupiah), reconciled category usage before submission.
- [x] Derive IV.5 and service-only revenue/COGS/Gross Profit/margin without double counting reimbursement; shared decimal test vector, negative margin and half-up rounding.
- [x] Risk checklist, fixed communication table/quality/closing, editable/deletable print rows with server-bound names/NIK/actual dates and no generated signatures. Five approvals remain mandatory regardless of print rows.
- [x] Archive previous v3 draft planning notes/costs on adoption; preserve locked/legacy documents, source prices, root budget and ledger.
- [x] 94 PHP tests / 1,285 assertions, 61 frontend tests, TypeScript/Vite build and Pint passed. Real form URL redirects to login; authenticated responsive/print layout not manually verified. No live workflow/account data or migrations changed. See PP_BUDGET_TEMPLATE.md and LARAVEL_QA.md.

## Usage dropdown amount autofill — 4 November 2026

- [x] Selecting Material Non Stock, BOP or Perizinan on IV.5 fills the current category total, less existing allocations on other rows, using cents and a zero floor. The selected row's existing amount is excluded from that deduction; dates/terms/other rows remain intact.
- [x] Amount stays editable for installments; selecting Jasa Mandor starts manual input at zero. Existing saved rows are not rewritten on read. Server amount/reconciliation validation and workflow rules remain unchanged.
- [x] Two frontend regressions cover category totals, absent permit, existing installments/reselection, the actual select handler and draft persistence. 94 PHP tests / 1,285 assertions, 63 frontend tests and TypeScript/Vite build passed. UI-only change; no live data/configuration/migration mutation or browser visual verification.

## SPH threshold from approved BOQ Mandor — 5 November 2026

- [x] Back up its before workflow changes; preserve live data and original SQLite.
- [x] Limit draft/new SPH Material/Jasa per row to the selected PP Mandor unit prices; independent components, equality/zero allowed, missing itemized references rejected. Populate new SPH defaults from the same source.
- [x] Show immediate accessible per-field threshold alert and per-unit reference. Server rejects save/generate/attest above the threshold and ignores fabricated client policy fields/status.
- [x] Preserve issued snapshots through evidence attestation/SPK validation, retain historical PP without itemized budgets, and keep KHS agreement/ledger/approval/payment invariants.
- [x] 99 PHP tests / 1,352 assertions, 67 frontend tests, production build and Pint passed. No migration/configuration/live workflow mutation. Browser after login not manually retested. See SPH_THRESHOLD.md and LARAVEL_QA.md.


## Active MySQL storage — 5 November 2026
- [x] Confirmed the existing PP was in MySQL workspace JSON while business tables were empty; backed up its before deployment.
- [x] Add relational document storage, PP/BOQ/budget/contract/payment/approval/file/ledger projections, atomic receipts/revision/audit, and fail-closed activation with idempotent verified backfill.
- [x] Deploy migration and activation on its: 1 PP, 20 BOQ rows, 1 BA KH, 5 actual approval decisions retained; identities/source masters/archive/revision unchanged. No test data or Dompis changes.
- [x] Remove live demo/sample behavior and update configuration/docs. Login pages without boot fail closed. Preserve private upload directories.
- [x] 104 PHP tests / 1,406 assertions, 67 frontend tests, build and Pint pass; disposable MariaDB full workflow through APM passes. Snapshot/projection limits and browser QA limits: docs/MYSQL_RUNTIME.md.


## Automatic BA KH period — 5 November 2026
- [x] Back up active its before workflow edits; identify stale draft period against the approved PP schedule.
- [x] Derive new/draft BA KH dates from the selected PP in React and Laravel, ignore stale/client periods, display read-only controls and keep calendar/HK bounds.
- [x] Preserve issued/approved snapshots, prices, PP schedule, approval chain and exact volume ledger. Existing MySQL storage writes canonical dates on the next authorized save.
- [x] Cover shared date vectors, draft save/generate/attestation, continuation source, issued snapshots, invalid schedule and HTTP SQL persistence. See docs/BAKH_SCHEDULE.md and current QA evidence.

Final BA KH date verification: **109 PHP tests / 1,477 assertions**, **71 frontend tests**, production build and Pint passed.


## Work evidence upload and approver preview — 5 November 2026
- [x] Back up its before changes and before deployment; preserve all identities, supplied masters and existing workflow rows/revision.
- [x] Add actual private PDF/JPEG/PNG/WebP uploads for draft/returned terms, ten active files/10 MB each, server-owned metadata and atomic SQL path/document/term relation.
- [x] Add preview/download to detail and selected inbox item, authorization by active account/Branch, range delivery and immutable pending/approved evidence with retained removal history.
- [x] Preserve actual/type drafts, five approvals, wet-signature scan, Finance/APM and historical workflows. Ignore forged path/evidence/scan/payment metadata.
- [x] 116 PHP tests / 1,647 assertions and 75 frontend tests, build and Pint passed. Disposable MariaDB controller upload/path/PM preview and full flow through APM passed. No manual authenticated browser QA or live test transactions.
- [x] Deploy migration 2026_11_05_000002 to its (57 total); existing business/account/master rows and revision 16 unchanged. See TERM_WORK_EVIDENCE.md and QA evidence.

## Database relationship handbook — 5 November 2026
- [x] Inspect only live its schema metadata in a read-only transaction; inventory 61 tables, 497 columns, 61 primary keys and 106 foreign keys. No application/data/configuration changes.
- [x] Describe every table, all outgoing FKs, logical links and snapshot storage. Distinguish 42 runtime tables, 11 prepared tables and eight Laravel infrastructure tables; do not infer use from row counts.
- [x] Create an A5 PDF/Word handbook, nine readable SVG/PNG diagrams, complete Mermaid ERD and offline searchable HTML catalog with every column/incoming/outgoing FK.
- [x] Validate schema/catalog/ERD coverage, references, SVG/XML, HTML search behavior and PDF page/text coverage; render and inspect all 42 pages. Documentation-only checks; application regression/build not repeated. See docs/DATABASE_RELATIONS.md and QA evidence.

## Rejected v3 PP and payment resubmission — 5 November 2026
- [x] Back up its before workflow edits: its-before-rejection-resubmit-20261105-144618.sql (1,019,899 bytes, SHA-256 1541823e46757029416eb9fab285eab65bbd534316b716efaaf50a2e774aae7c).
- [x] Allow Admin correction and explicit resubmission of rejected v3 PP/terms on the same document. Preserve required rejection notes, identities/numbers, historical decisions and v1/v2 rejection behavior; all five new approvals restart from PM.
- [x] Reopen rejected v3 work-proof correction under existing Admin/Branch/file rules, retain removed history, and lock again on resubmission. Keep scan/Finance/APM, Final/BA PP and volume gates unchanged.
- [x] Match React form availability, budget previews, rejection guidance and timeline positions to server validation. SQL rejected cycles/decisions remain intact while new submission creates a new pending cycle; no schema migration or live data rewrite.
- [x] 127 PHP tests / 2,282 assertions, 86 frontend tests, production build main-CrxKAb5G.js and explicit PHP Pint checks pass. New coverage rejects at every stage for both PP and Final termin, checks fresh five-approver cycles and SQL history, and verifies real eviden correction. Tests remain isolated SQLite memory; no live synthetic data, authenticated browser or separate MySQL engine retest. See docs/APPROVAL_RESUBMISSION.md.

## Leader dashboard analytics — 5 November 2026
Historical iteration; superseded by the dashboard restoration below.
- [x] Replace the four dashboard operational cards with unique ID Project/PP totals, SPK Sah, approved invoices, recorded payments and outstanding; deduplicate continuation BOQ and preserve historical partial settlement.
- [x] Add Branch/ID filters, PP status counts, all current approval-stage queues, mutually exclusive payment stages and per-ID portfolio with PP/detail/timeline links. Keep the nine-menu shell and existing Inbox intact.
- [x] Use read-only projections of Laravel's authorized saved state; no schema, live data, workflow or Dompis Prov changes.
- [x] 127 PHP tests / 2,282 assertions, 96 frontend tests and TypeScript/Vite build pass. Browser preview permission denied; responsive/dark/light visual and click QA remains unverified. See docs/DASHBOARD_ANALYTICS.md.

## Restore original dashboard summary — 5 November 2026
- [x] Restore the original title/toolbar, three primary cards and six mini metrics. Remove the four specified operational cards and the additional analytics/portfolio panels; preserve the shared nine-menu shell.
- [x] Provide Semua Branch dropdown from the authorized Branch master, including Branches without PP, with consistent filtering of all summary values. Preserve role/assignee-based approval count, historical payment amounts and root-BOQ deduplication.
- [x] Replace unused analytics code/styles/tests with the smaller read-only summary. No migration, workflow, live database or Dompis Prov edits.
- [x] 127 PHP tests / 2,282 assertions, 94 frontend tests and TypeScript/Vite build pass. Browser visual/interaction QA not repeated after the previous permission denial. See docs/DASHBOARD_SUMMARY.md and docs/qa/dashboard-summary-20261105.json.

## Automatic PP budget recap and compact table grids — 5 November 2026
- [x] Back up active its before validation changes: its-before-automatic-budget-summary-20261105-211745.sql, 1,019,899 bytes, SHA-256 b1fe8eac3610f42fbefac80b27555b73bdd4ebaf13fad227ef1be839b1617c58.
- [x] Replace editable point IV.5 manual usage/term/date rows with canonical budget-version-2 positive-category recap; derive Mandor Jasa/Material, Non Stock, BOP and separate permit from source inputs, ignore forged/stale recap payloads and preserve all ceilings/rounding/margin rules.
- [x] Keep submitted/approved historical budget-version-1 schedules unchanged; only authorized v3 draft/correction saves adopt the new recap. SQL detail rows and per-document snapshots persist atomically through existing storage, without a migration or live data rewrite.
- [x] Separate Designator, Uraian Pekerjaan and Satuan in Owner/Mandor BOQ; use compact bordered PP form tables and bordered document previews/print styling. Keep positive/zero and missing-component meanings intact.
- [x] 131 PHP tests / 2,369 assertions, 96 frontend tests, TypeScript/Vite build and scoped Pint passed. HTTP SQL persistence, historical read/approval and continuation/source-change coverage added. All framework tests remain SQLite memory; browser/authenticated visual, print pagination and separate MySQL-engine retest not performed. See docs/PP_BUDGET_TEMPLATE.md and QA evidence.

## PP-derived BA KH and SPK numbering — 5 November 2026
- [x] Back up active its before workflow changes: its-before-contract-numbering-20261105-222558.sql, 1,047,622 bytes, SHA-256 03a077b6ba245dc96e44cf2ef8ff5be340b4751df5e3c2e8fb76bab7587921ea.
- [x] Derive current-account new/draft BA KH/SPK numbers from the selected saved PP with fixed -01/-02 codes, ignoring client/cached numbers and retaining PP padding/Branch/period/counter. Use the continuation PP number where applicable.
- [x] Match read-only form numbers, preview and save commands; preserve issued/Sah snapshots, SQL uniqueness, volume, prices, approvals and historical offline workflows. Existing draft numbers adopt the format on authorized save; no migration or live bulk data rewrite.
- [x] 137 PHP tests / 2,486 assertions, 103 frontend tests, TypeScript/Vite build and scoped Pint passed. Shared vectors and HTTP SQL snapshots/columns, duplicate rollback and unchanged PP/counters verified. Browser/authenticated visual and separate MySQL engine retest not performed. See docs/CONTRACT_NUMBERING.md and QA evidence.

## Header-only PP drafts, preview stamps and BOP cap removal — 5 November 2026
- [x] Back up active its before workflow changes: its-before-pp-drafts-preview-20261105-230244.sql, 1,047,622 bytes, SHA-256 b2d77823569c92f2c3b5bd1a123efb45c3efe96936c27e6570d6e83082ef87c6.
- [x] Allow complete-Header v3 Drafts without BOQ, completed HK, risk or approvers, through controller/domain/React; canonical zero-HK schedule and empty BOQ status persist to SQL. Keep submission gates, input validation, numbering, Branch authorization and historical v1/v2 behavior.
- [x] Make new PP forms fresh, retain per-ID edit recovery, await server save confirmation before clearing/navigating to the PP list, and keep editor/cache on server failure. Draft status and retry persistence are covered.
- [x] Remove BOP limit/Cost Structure fields, caption and backend validation; ignore stale payload fields, retain valid cost/COGS/source/precision rules and locked snapshots.
- [x] Render APPROVED preview indicators only from actual current-cycle selected-user decisions; hide stamps and signature-cell status on print with an empty wet-signature container. Never fabricate approval identities/signatures.
- [x] 140 PHP tests / 2,553 assertions, 110 frontend tests, TypeScript/Vite build and scoped Pint passed. All framework tests use SQLite memory; no migration, live synthetic transactions or bulk rewrite. Browser/print visual and separate MySQL engine QA not repeated. See docs/PP_DRAFTS_PREVIEW.md and QA evidence.

## Master Designator dan Mgr Project Support — 6 November 2026

- [x] Backup its sebelum perubahan; menu Master Designator dengan submenu Designator/KHS/Paket KHS dan tombol ikon ber-tooltip.
- [x] Superadmin CRUD SQL sumber asli, validasi relasi/harga, hapus arsip, lindungi identitas terpakai dan snapshot lama.
- [x] Kunci/revisi workspace, receipt dan audit sebelum/sesudah; hanya metadata referensi ditulis saat perubahan master.
- [x] Role MGR_PROJECT_SUPPORT dapat dibuat/login melalui username; approval tetap lima peran yang ditetapkan.
- [x] Migration tambahan diterapkan: total 58; role id_role 10, tabel master_data_changes kosong. Perbandingan backup sebelum/sesudah membuktikan seluruh data pada 37 tabel berisi data tetap sama, kecuali tambahan role/migration yang memang dimaksudkan; 14 akun, 724 designator dan 1.030 harga dipertahankan.
- [x] 145 PHP tests / 2.644 assertions, 113 frontend tests, TypeScript/Vite build dan Pint. Browser visual belum diulang setelah penolakan izin sebelumnya. Tidak ada akun/PP/harga uji pada its. Lihat docs/MASTER_DESIGNATOR.md dan docs/qa/master-designator-20261106.json.

## Upload queue dan optimasi — 6 November 2026

- [x] Backup its sebelum perubahan: its-before-upload-queue-20261106-055100.sql (1.049.284 byte; SHA-256 fb87d6ba0fbd71318474032ddc8b10aacf934d0f8be1dc04949689f9dcb8d9e9).
- [x] Antrean SQL BOQ/eviden, HTTP 202, status/recovery, parser dan file privat, retry/idempotency/cleanup, pemeriksaan ulang akun/Branch/status, serta blok approval saat pending.
- [x] Autostart/resume worker lokal memakai identitas proses web dan lock eksklusif, heartbeat; tidak mengubah izin privat atau menjalankan proses terpisah pada tests.
- [x] Penulisan dokumen inkremental, balance/event batch, lookup katalog berindeks di memori, SQL scope Branch PP sendiri dan preview file langsung; lazy route modules.
- [x] Migration 59 diterapkan pada its, 61 fingerprint tabel lama dipertahankan (kolom proyeksi Branch baru diverifikasi terpisah); task/jobs tetap kosong, autostart aktif dan PHP CLI tersedia. Tidak ada uji transaksi pada its.
- [x] 156 PHP tests / 2.729 assertions, 116 frontend tests, build dan Pint. Fixture 20 PP: 1.330 → 8 query untuk perubahan pengaturan; main JS sekitar 27% lebih kecil. Browser/Apache autostart dengan file pengguna dan load-test serentak belum dilakukan. Lihat docs/UPLOAD_QUEUE_PERFORMANCE.md.

## Koreksi worker default dan autostart macOS — 6 November 2026
- [x] Konfirmasi queue default pengguna berbeda dari uploads/area3-uploads; indikator menggunakan heartbeat worker khusus.
- [x] Konfirmasi log autostart nohup gagal tanpa console Apache; ganti dengan shell SIGHUP-ignore dan redirect seluruh stream, tetap sebagai OS user web dengan lock eksklusif.
- [x] 159 PHP tests / 2.733 assertions, 116 frontend tests, build dan Pint lulus; tiga test launcher memakai process palsu dan tidak menjalankan worker terpisah. Tidak ada migration atau perubahan live data; startup melalui Apache perlu dicoba kembali lewat Lanjutkan antrean.


## BOQ kosong/hasil rumus — 6 November 2026
- [x] Backup its terverifikasi: its-before-boq-formulas-20261106-091043.sql; 1.066.383 byte; SHA-256 e644b77997bb1b9997b264093b77790e3ff25f7c49df18981b0add01ffac94ea.
- [x] Konfirmasi F43 kosong, terima harga kosong dan cached numeric formula tanpa evaluasi; cache hilang/error tetap validasi jelas, provenance/KHS/review tetap wajib.
- [x] Regresi file Paket 5 empat baris dan antrean, penjagaan master yang belum tersedia, 163 PHP tests/2.804 assertions, 116 frontend tests, build/Pint lulus.
- [x] Baca-saja its mengonfirmasi dua KHS Jasa Paket 5 belum tersedia; tidak menyalin otomatis harga Excel ke master. Sinyal restart worker hanya melalui file cache proyek Area 3.
- [ ] Superadmin melengkapi harga resmi J-AC-OF-SM-24D dan J-ODP Solid-PB-16 AS untuk Paket 5; upload aktual lewat browser belum diuji ulang.


## Bulk Import Designator dan KHS — 6 November 2026
- [x] Backup its terverifikasi: its-before-master-bulk-20261106-092902.sql; 1.068.816 byte; SHA-256 30d228ed88bc8d36341f2590bd5820b02d495ab3993ef5fea269c0e03f2888fd.
- [x] Tombol Bulk Import / Download Template khusus Superadmin di dua submenu, XLSX Data kosong/panduan/referensi aktif, preview dan mode create/upsert.
- [x] Batch 500 baris/5 MB, validasi CRUD/duplikat/arsip/identitas terpakai/harga, seluruh batch atomik, satu revisi/receipt, audit per baris, UUID/hash replay dan rollback SQL.
- [x] 171 PHP tests/2.957 assertions, 120 frontend tests, build dan Pint lulus. Tidak ada migration, seed atau batch contoh pada database pengguna; worker BOQ/eviden tidak diubah.
- [ ] QA visual browser dan pembukaan template manual di Excel belum dilakukan; lihat docs/MASTER_BULK_IMPORT.md.


## Koreksi download template dan query — 6 November 2026
- [x] Log Apache: tempnam gagal pada lokasi sementara pengguna macOS; generator diganti ZIP/XLSX di memori tanpa file sementara/ubah izin global.
- [x] Proyeksi referensi khusus: 2 query Designator / 3 KHS, tanpa tabel harga/workflow dan lock workspace; aturan review query untuk setiap fitur baru dicatat pada AGENTS/QA skill.
- [x] Regresi unavailable TMPDIR + ZIP consistency/CRC/XML dan HTTP query budgets. 173 PHP tests/3.006 assertions, 120 frontend tests, build dan Pint lulus.
- [x] Tidak ada perubahan database/schema/workflow atau seed pengguna. Klik browser dan membuka template di Excel manual belum diuji ulang.


## Koreksi revisi multipart bulk import — 6 November 2026
- [x] Reproduksi string revisi FormData menghasilkan 409 palsu; controller menormalisasi hasil validasi menjadi integer dan helper test mengikuti transport browser.
- [x] Preview memakai revisi master terkini; commit wajib cocok hasil preview. Konflik nyata membatalkan preview, file tetap ada dan harus diperiksa ulang; ketidakpastian jaringan tetap memakai UUID semula.
- [x] File pengguna 435 baris lolos preview/upsert di memory tanpa mutasi master. 175 PHP tests/3.031 assertions, 121 frontend tests, build/Pint lulus.
- [x] Tidak ada fetch seluruh katalog/state tambahan untuk pemulihan revisi; query budget template dan uji workflow tetap lulus. Tidak ada migration/import live; QA klik browser belum dilakukan.


## Batas impor master 1.000 baris — 6 November 2026
- [x] Naikkan batas Designator/KHS ke 1.000 baris data, selaraskan konstanta server, form, template dan dokumentasi; batas file tetap 5 MB.
- [x] Commit 1.000 Designator, preview/commit/replay 1.000 KHS dan penolakan 1.001 baris kedua entitas; satu revisi/receipt per batch, graph workflow dibaca sekali pada commit KHS.
- [x] 175 PHP tests/3.049 assertions, 121 frontend tests, build/Pint lulus. Tidak ada migration atau uji impor live; klik browser/load test belum dilakukan.


## Approval Project Support dan penutup PP — 6 November 2026
- [x] Backup its: its-before-project-support-20261106-104911.sql, 1.996.112 byte, SHA-256 6ee4902c4a15175e6c0fa3433438d816948df58fb59020cf9f1f44a3b7d2217b.
- [x] PP/Pembayaran baru mengganti approval keempat menjadi Project Support; server-owned scheme menjaga role/assignment/keputusan Business Support historis. PP lama memerlukan future-payment assignment baru secara eksplisit.
- [x] Form/preview penutup baru empat jabatan baku dari template bersama; Admin Area tetap approval kedua; cap nyata/current-cycle dan area print kosong.
- [x] 177 PHP tests/3.075 assertions, 124 frontend tests, build/Pint lulus. Query tambahan per role/penandatangan tidak diperlukan; uji budget/performance lama tetap lulus.
- [x] Sinyal graceful restart worker melalui cache file proyek; tanpa migration, seed atau perubahan massal transaksi live. Browser/print fisik belum diuji manual.

## Subtotal Material dan Jasa BOQ Owner — 6 November 2026
- [x] Ringkasan BOQ Owner di pojok kanan bawah dengan tiga baris Material, Jasa, Total, angka rata kanan, garis horizontal penuh dan pemisah vertikal sejajar pada form/preview/print PP; menggunakan total dan snapshot yang sudah tersedia tanpa query tambahan.
- [x] 177 PHP tests/3.075 assertions, 124 frontend tests dan build lulus. Perubahan tampilan saja; QA browser/print fisik belum diulang manual.

## Perbaikan Server Error pengajuan PP — 6 November 2026
- [x] Log MariaDB 4025 menunjukkan constraint two_approval_steps belum menerima Project Support. Migration 000002 memperbarui CHECK atomik, menjaga Business Support lama/versi 2 dan menolak rollback setelah role baru digunakan.
- [x] Backup its-before-approval-check-20261106-132833.sql: 2.015.272 byte; SHA-256 99e7a7dc04bbdd8376edb3e79eabeb56b7312f5caa3cafe59486839e51c97024. Migration sudah diterapkan; fingerprint data akun/PP/approval/workspace/audit/receipt identik.
- [x] 180 PHP tests/3.108 assertions, 124 frontend tests, build/Pint lulus. Probe CHECK MariaDB melalui temporary table menerima 9/menolak 6 kombinasi tanpa data uji di tabel bisnis. Tidak ada query runtime tambahan atau pengajuan PP atas nama pengguna; QA klik browser belum dilakukan.

## Timeline PP sesuai baris yang diklik — 6 November 2026
- [x] Filter awal timeline memakai ID PP pada URL, sehingga kartu/aktivitas PP terpilih langsung muncul; opsi seluruh PP/sibling dan ringkasan ID Project dipertahankan. Tidak ada perubahan database atau tambahan query.
- [x] Regresi gagal sebelum perbaikan lalu lulus: PP sibling yang diklik, event terpisah, pembukaan PP lain, state tetap utuh dan ID tidak tersedia tidak jatuh ke PP pertama. Klik browser belum diuji manual.
- [x] 180 PHP tests/3.108 assertions, 126 frontend tests dan build lulus.

## Urutan Project Support sebelum Deployment — 6 November 2026
- [x] Urutan PP/Pembayaran menjadi PM, Admin Area, Project Support, Deployment, Head, termasuk pengajuan berjalan sesuai jawaban pengguna. Giliran mencari role yang belum menyetujui; keputusan Deployment terdahulu tetap dihitung.
- [x] Backup its-before-approval-order-20261106-141354.sql: 2.020.568 byte; SHA-256 c3feadbc839a0f4ed7c7ad266d6d7cb424afbc76bc04fdec1d0f32b7adf578f1. Migration 000003 diterapkan: versi urutan SQL baru 2, posisi/ID run lama tetap versi 1; CHECK MariaDB menerima 15/menolak 6 probe temporary.
- [x] Pengguna memilih akun Support pada PP terbaru untuk legacy berjalan: 1 PP dan 1 Pembayaran diperbarui melalui command Admin tervalidasi dengan audit/receipt, keputusan lama dan PP Sah utuh. Dua PP menunggu Support; pembayaran menunggu Admin Area. Tidak ada approval otomatis.
- [x] 185 PHP tests/3.161 assertions, 129 frontend tests, build/Pint lulus. Query routing tambahan nol, fixture incremental tetap 8 query; restart worker graceful. Browser/cetak fisik belum diulang manual. Bukti docs/qa/approval-order-20261106.json.

## Garis tabel menu SPK — 6 November 2026
- [x] Scope tampilan SPK/BA KH mencakup tabel daftar, penugasan, SPH, penawaran, kesepakatan, lampiran SPK dan preview; garis horizontal/vertikal lengkap, header jelas dan sel lebih ringkas.
- [x] Warna garis mengikuti tema terang/gelap dan dokumen putih/cetak, scroll tabel tetap tersedia. Tanpa perubahan data, alur atau query; browser/cetak fisik belum diperiksa manual.
- [x] 185 PHP tests/3.161 assertions, 129 frontend tests dan build lulus.
- [x] Penyesuaian pengguna: khusus list SPK · BA KH & Negosiasi kembali ke gaya tabel awal melalui pengecualian class; list SPK lain, detail, lampiran dan preview tetap bergaris.

## Batas Harga Kesepakatan dari BOQ TA–Mandor — 6 November 2026
- [x] Material/Jasa pada kesepakatan baru/draf masing-masing dibatasi harga satuan Mandor PP tersimpan, termasuk PP lanjutan; input batas/alert dan badge Invalid, nilai tepat batas/nol valid, KHS tetap dibatasi, snapshot terbit/historis tetap utuh.
- [x] Backup its-before-agreement-threshold-20261106-151435.sql: 2.032.468 byte; SHA-256 291301806fc246fc12de83914667c5b322f4b4f0cd91471f6c2732b23b859086. Tidak ada migration atau perubahan transaksi live.
- [x] 189 PHP tests/3.216 assertions, 133 frontend tests, build/Pint lulus. HTTP penolakan tanpa write SQL/revisi/audit; referensi dipetakan sekali tanpa query baru, fixture incremental tetap 8 query. Browser/cetak manual belum diulang.

## Penandatangan BA KH Head of PMO otomatis — 6 November 2026
- [x] Form BA KH baru/draf PP v3 menampilkan nama/NIK Head terpilih secara read-only; PHP mengabaikan identitas client, preview/cetak memuat nama/NIK dan ruang TTD kosong. Snapshot terbit/Sah dan dokumen historis tetap utuh; NIK hilang tidak dikarang.
- [x] Nama/jabatan memakai proyeksi contracts yang ada; NIK/ID disimpan di document_data tanpa migration/query baru. Backup its-before-bakh-signer-20261106-153102.sql: 2.032.468 byte, SHA-256 6e655019f5186b02969fbdcb4fca2ace345412e289c9d361f13967d04968c68a.
- [x] 194 PHP tests/3.260 assertions, 137 frontend tests, build/Pint lulus; helper 0 query, HTTP tiga aksi membuktikan identitas SQL canonical. Tidak ada mutasi live atau uji browser/cetak manual.

## Preview Lampiran BA KH sesuai Excel — 6 November 2026
- [x] Lampiran khusus dengan 10 kolom/header tiga tingkat Negosiasi Mandor/Telkom Akses dan Kesepakatan, masing-masing Material/Jasa; harga satuan aktual, nol/N/A terpisah, designator/uraian/satuan terpisah dan seluruh sel bergaris.
- [x] Nama/NIK Head dari identitas BA KH, jabatan Head of PMO Area Jawa Bali dan Mandor di dua blok TTD kosong. Snapshot historis utuh; tanpa menyalin data contoh atau mengarang tanda tangan.
- [x] Lookup referensi dipetakan sekali tanpa query/jaringan/mutasi baru; scroll lokal dan CSS cetak tersedia. Detail dan batas verifikasi: docs/BAKH_APPENDIX.md.
- [x] 194 PHP tests/3.260 assertions, 142 frontend tests dan build produksi lulus. Browser/cetak fisik belum diulang manual; tidak ada migration atau perubahan data live.

## Lampiran BA KH horizontal, sel kosong dan audit perubahan — 6 November 2026
- [x] Tabel lampiran diperkecil: minimum 760px, font layar 9px, padding ringkas; named page A4 horizontal/margin 10mm khusus lampiran. Head/Mandor dan ruang TTD basah tetap tersedia; orientasi dokumen lain tetap.
- [x] Semua penanda komponen N/A bawaan pada tabel React menjadi sel kosong; label KHS tanpa komponen dihilangkan. Angka 0 tetap terlihat, null/pemilihan komponen/perhitungan/validasi/snapshot/ekspor tidak berubah.
- [x] docs/CHANGELOG.md menjadi catatan audit terpusat; AGENTS.md mewajibkan pencatatan setiap perubahan termasuk tampilan, README menautkan panduan melanjutkan percakapan. docs/BAKH_APPENDIX.md dan docs/LARAVEL_QA.md diperbarui tanpa menghapus entri audit terdahulu.
- [x] 194 PHP tests/3.260 assertions, 144 frontend tests dan build lulus. Dua regresi tambahan memeriksa sepuluh keluaran serta state tidak berubah; tidak ada penanda N/A bawaan tersisa di resources. Tanpa query/jaringan baru, migration atau perubahan live its. Browser/responsif/cetak fisik belum diverifikasi manual.

## Header kecil Lampiran BA KH di kiri atas — 6 November 2026
- [x] Header menjadi dua baris sesuai teks pengguna: LAMPIRAN BERITA ACARA KESEPEKATAN HARGA dan PEKERJAAN: nama kegiatan PP terpilih. Rata kiri, 10px pada preview/8pt cetak; nomor PP dan BA KH dihilangkan khusus dari lampiran.
- [x] Catatan audit ditambahkan pada docs/CHANGELOG.md dan docs/LARAVEL_QA.md; perilaku aktif docs/BAKH_APPENDIX.md diperbarui. Tanpa query/migration/mutasi data atau perubahan penomoran transaksi.
- [x] 194 PHP tests/3.260 assertions, 144 frontend tests dan build lulus; regresi memeriksa judul/nama kegiatan dan kedua nomor tidak dirender. Browser/cetak fisik belum diverifikasi manual.

## Preview BA KH mengikuti template Word — 6 November 2026
- [x] Source BA KH.docx satu halaman A4 portrait dirender/diperiksa. Preview utama mengikuti judul/nomor, pekerjaan/lokasi/Witel, tanggal terbilang, tiga poin kesepakatan, FO/jointer/periode, tempat/tanggal dan TTD Head/Mandor; isian berasal dari Informasi Dokumen BA KH/PP terpilih.
- [x] Harga tetap di lampiran horizontal, identitas tersimpan dan ruang TTD basah utuh. Data contoh/jumlah orang tidak ditebak; field tim tetap teks bebas. Tidak ada query/jaringan/migration/mutasi live; jalur utama melewati lookup BOQ dan total yang tidak digunakan.
- [x] 194 PHP tests/3.260 assertions, 149 frontend tests dan build lulus. Lima regresi baru memeriksa pemetaan/tanggal/kosong/riwayat/escaping/routing serta render hanya dengan form tanpa katalog/root BOQ. Browser aplikasi/cetak fisik belum diuji manual. Audit dan pemetaan: docs/CHANGELOG.md, docs/LARAVEL_QA.md, docs/BAKH_TEMPLATE.md; README/AGENTS diperbarui.

## Unduh Word BA KH dan Lampiran — 6 November 2026
- [x] Tombol Unduh Word (.docx) pada kedua preview menghasilkan paragraf/tabel native editable dari isian preview/draf yang sama: BA KH portrait, lampiran landscape dengan header tiga tingkat berulang dan sel kosong/nol terpisah. Identitas Head/Mandor tersimpan dan ruang TTD basah tetap utuh.
- [x] Model isi/tanggal/identitas/baris dipusatkan di bakh-content.ts. Generator dimuat saat klik (8,76 kB/gzip 3,53 kB), ZIP UTF-8/CRC32 tanpa dependency baru; referensi diindeks sekali, tanpa SQL/network data request atau mutasi live/schema/.env/arsip/alur/approval. Tombol punya busy/error/klik-ganda guard; unduh tidak menyimpan/mengesahkan dokumen.
- [x] 194 PHP tests/3.260 assertions, 156 frontend tests dan build lulus. Tujuh regresi baru termasuk 1000 item tanpa lookup master per baris/pemotongan, XML/Unicode dan kesesuaian model. CRC/XML/python-docx independen valid; output generator yang sama dirender 1+3 halaman, seluruh 4 PNG akhir diperiksa setelah perbaikan indent panjang/TTD sejajar.
- [x] Audit lengkap: docs/BAKH_WORD_EXPORT.md, docs/CHANGELOG.md, docs/LARAVEL_QA.md, docs/qa/bakh-word-export-20261106.json, README/AGENTS dan panduan preview diperbarui. Klik unduh browser terautentikasi, Microsoft Word desktop dan cetak fisik belum diperiksa manual; berkas QA tetap di temporary lokal dan tidak masuk its.


## Pengesahan tanpa referensi teks dan catatan arsitektur — 7 November 2026
- [x] Hapus isian referensi bukti bertanda tangan pada BA KH, SPK dan BA PP, status referensi pada preview serta kolom Bukti dokumen pada timeline. Laravel/React menghapus kewajiban teks; attestBapp memakai ID/nomor dan mengabaikan argumen ketiga lama.
- [x] Input evidence usang diabaikan; dokumen baru kosong dan referensi tersimpan tetap menjadi arsip. Terbit/Sah tidak dapat diubah harga/nomor/periode/identitasnya. Actual work proof, scan TTD Pembayaran, lima approval, Finance/APM, Branch dan ledger tetap dijaga. Tanpa migration, backfill atau mutasi data live.
- [x] Rekonsiliasi BA PP mengindeks BOQ/KHS sekali per render. Domain matriks BA KH/SPK nol query; lima jalur HTTP fixture memakai 51–58 query termasuk revision read, batas 60 serta larangan write ulang tabel PP/BOQ/approval/file/scan. Bukan uji beban/throughput MySQL.
- [x] 197 tes PHP / 3.654 assertions, 160 tes frontend, build produksi dan scoped Pint lulus. Tiga tes PHP baru, empat tes frontend baru dan regresi HTTP diperbarui untuk SQL tanpa referensi, kunci riwayat, Final, role/nomor dan ledger. Browser/cetak fisik belum diuji manual.
- [x] docs/ARCHITECTURE.md dan docs/DOCUMENT_ATTESTATION.md baru; README/AGENTS/skills menautkan otoritas baru dan mewajibkan catatan dampak arsitektur/performa setiap update. Audit: docs/CHANGELOG.md, docs/LARAVEL_QA.md dan docs/qa/document-attestation-20261107.json. Backup its-before-remove-evidence-reference-20261107-085027.sql valid; tidak ada pengujian pada its.


## Cari ID Project lalu pilih PP untuk negosiasi — 7 November 2026
- [x] Negosiasi Baru tanpa auto-pilih PP pertama/dropdown panjang. Cari ID Project/nama pekerjaan (2–100 karakter, debounce 300 ms), pilih project lalu tabel PP, ringkasan dan Lanjut Negosiasi. Pagination 20, alasan nonaktif, Ganti ID Project/Ganti PP dan cache per PP; existing/deep link tetap kontekstual.
- [x] Dua API GET metadata akun Admin/Superadmin/Branch/workspace, tanpa baca aggregate/master/arsip atau mutasi. Nama/count sibling mengikuti Branch PP; PP lanjutan memakai alokasi snapshot sendiri. Race/cancellation/error ditangani; Continue menyegarkan state sebelum form, command/harga/ledger/approval lama tetap berwenang.
- [x] Enam tes PHP dan enam frontend baru: 120 PP/30 ID, 33 PP satu ID, pagination, sibling/search/Branch/no leak, role/input/wildcard/storage, kapasitas domain, UI/transport. Query layanan 2 (cari) dan 3–4 (daftar), batch tanpa N+1; bukan benchmark beban umum.
- [x] 203 tes PHP / 3.796 assertions, 166 tes frontend, build dan scoped Pint lulus. Pemeriksaan baca saja MySQL its: 1 ID Project/2 PP berhasil tanpa transaksi/sesi/akun uji. Browser/responsif/keyboard belum diuji manual. Backup its-before-negotiation-project-picker-20261107-090820.sql valid; tidak ada migration/.env/mutasi live.
- [x] Perilaku/arsitektur/audit: docs/NEGOTIATION_PROJECT_PICKER.md, docs/ARCHITECTURE.md, docs/CHANGELOG.md, docs/LARAVEL_QA.md, docs/qa/negotiation-project-picker-20261107.json serta README/AGENTS. Shared boot/refreshState masih aggregate Branch; jalur pencarian dibatasi tanpa klaim universal aplikasi lebih cepat.


## Tindak lanjut SPK pada daftar BA KH — 7 November 2026
- [x] Kolom Tindak Lanjut SPK memperjelas negosiasi/BA KH belum Sah, BA KH Sah menunggu SPK, dan SPK Draf/Terbit/Sah dengan nomor aktual. Buat SPK hanya Admin/Superadmin saat belum ada SPK; Buka SPK mengikuti dokumen existing. Tidak ada submit/pengesahan otomatis.
- [x] Relasi per ID BA KH, tiga Map data dimuat sekali untuk menghindari find per baris, tanpa query/HTTP/migration/mutasi data atau perubahan alur/otorisasi. Filter mencakup ID Project/nama kegiatan; daftar BA KH tetap tanpa garis, tab SPK tetap enam kolom.
- [x] 203 tes PHP / 3.796 assertions, 169 tes frontend dan build lulus. Tiga regresi status/role/link/tanpa duplikat/PP bersama/no-find/immutable state; browser/responsif belum diuji manual. Perilaku, arsitektur dan audit pada docs/NEGOTIATION_PROJECT_PICKER.md, ARCHITECTURE.md, CHANGELOG.md, LARAVEL_QA.md dan qa/contract-follow-up-20261107.json.


## Preview SPK Mandor dan Word editable — 7 November 2026
- [x] Baca/render template SPK Mandor.docx satu halaman; petakan surat dari isian SPK/BA KH/PP aktual. Preview khusus portrait berlogo, mandor/alamat, perihal/periode, nilai M/J/Total, Waspang/K3 dan penandatangan SPK; tidak menyalin data contoh atau membuat TTD.
- [x] Unduh Word lazy memakai paket salinan sumber/slot escaped satu lintasan, tabel/paragraf native dan logo asli, tanpa metadata penulis/customXml. Delapan bagian sumber identik; geometry/header/border/grid utuh. Tabel inline, wrapping, whitespace panjang dan blok TTD diperbaiki; aset logo root/subfolder didukung. ZIP menerima byte gambar dengan CRC/batas lama; ekspor BA KH tetap lulus.
- [x] 203 tes PHP / 3.796 assertions, 176 tes frontend dan build lulus. Tujuh regresi SPK termasuk nilai nol/komponen kosong/pembulatan domain, historical/missing/hostile text/literal slot/1000 baris/URL. Native CRC/XML/relasi/python-docx valid, source SHA utuh. Render akhir aplikasi normal1+panjang1 halaman, kedua PNG diperiksa; fixture privat tidak masuk its. Browser/Microsoft Word desktop/cetak fisik belum diuji manual.
- [x] Tanpa SQL/endpoint/migration/mutasi live/.env/worker/aturan baru. Main preview melewati lookup root/KHS, agregasi O(baris). Word91,32kB/gzip13,22kB lazy + logo publik122,55kB; bukan benchmark beban. Dokumentasi: docs/SPK_TEMPLATE_WORD.md, ARCHITECTURE.md, CHANGELOG.md, LARAVEL_QA.md, qa/spk-template-word-20261107.json serta README/AGENTS.

## Lampiran SPK — 7 November 2026

- [x] Baca Lampiran-SPK.xlsx Sheet1 A1:K25 tanpa mengubah workbook/menjalankan rumus; jangan salin contoh nama/nilai ke transaksi.
- [x] Grid11 kolom template dengan Nama Proyek/Vol, harga M/J, Total Vol, nilai/rekap, Witel dan TTD kosong memakai penandatangan SPK tersimpan; pakai ulang form/preview, landscape lampiran saja.
- [x] Pertahankan volume komponen berbeda lewat dua baris, nol/sel kosong, total domain/pembulatan, PP lanjutan serta surat portrait/Word utama.
- [x] Review query0 tambahan, indeks metadata sekali,regresi1000 baris tanpa per-row find; tanpa perubahan backend/domain/SQL/live its/migration.
- [x]203 PHP/3796 assertions,182 frontend dan build; catat SPK_APPENDIX_TEMPLATE,ARCHITECTURE,CHANGELOG,QA,README/AGENTS,qa/spk-appendix-template-20261107.json.
- [ ] Visual browser/responsif/dark/print: akses localhost ditolak karena izin tidak diberikan. Fixture privat tidak masuk its; server dihentikan; belum ada klaim visual.

## SPK Head of PMO otomatis dan dua Word preview — 7 November 2026

- [x] Hapus label NAMA PROYEK pada grid form/preview; nama kegiatan langsung di atas VOL.
- [x] Backup privat its-before-spk-head-word-20261107-095133.sql sebelum perubahan domain; tidak deploy/mutasi data live.
- [x] Resolver PP Head dibagi BA KH/SPK; form read-only3 isian, Laravel override client dan snapshot SQL nama/jabatan/NIK. Preserve Terbit/Sah, legacy/missing NIK dan semua gates/ledger/penomoran.
- [x] Main SPK Word menambah NIK; lampiran punya DOCX native landscape,grid/recap/volume/TTD kosong sama. Helper OOXML bersama,lazy import,tanpaendpoint baru; Satuan6%.
- [x]208 PHP/4031 assertions,187 frontend,build,Pint,diffcheck; pure resolver0 SQL/HTTP≤60 query peraksi,1000 rows no per-row find. Delapan source parts surat utuh,4 native outputs dirender1+1+1+4 halaman dan seluruh PNG diperiksa.
- [x] Audit SPK_SIGNER_WORD,ARCHITECTURE,CHANGELOG,LARAVEL_QA,README/AGENTS dan qa/spk-head-word-20261107.json; perbarui perilaku surat/lampiran.
- [ ] Browser/klik unduh responsif/dark,Word desktop,cetak fisik belum diperiksa; penolakan browser sebelumnya dihormati. Fixture privat bukan transaksi.

## Pengajuan Pembayaran — Cari ID Project / SPK Sah, 7 November 2026

- [x] Backup privat its-before-payment-project-picker-20261107-153204.sql sebelum perubahan;liveSQL hanya dibaca,Dompis Prov tidak disentuh.
- [x] Pencarian IDProject/kegiatan→groupSPKSah→tabel20/halaman→pilih/ringkasan→BuatDraf. HanyaSPKSah,explicitselection,noautofirst,BranchscopeperPP,contextualexactlink,loading/error/retry/cancellation dan scrolllokal.
- [x] Existingdraft/returned/rejectedv3/active/FinanceAPM/Final membuka dokumen yang sama;legacyreadonly/batas3/Final preserved. Store refresh/recheck/createcommand/waitconfirmation;retry ambiguousresponse tanpa duplicate.
- [x] ReadonlyAPI2querysearch/4list,batchterms+SQLamounts,noarchive/private/master/actuals;120SPK/30Projectquerybudget,pagination20+13. Liveitsreadonly1Project/1SPK2/4queries,revision/archivesame. Sharedboot/refreshaggregate limitation dicatat.
- [x]215PHP/4132assertions,196frontend,build,Pint,diffcheck;7PHP+9frontend termasuk13sharedvectors,Branch/nonactiveapprover/duplicate/409,uncertainrequest/saveconfirmation.
- [x]PAYMENT_PROJECT_PICKER,ARCHITECTURE,CHANGELOG,LARAVEL_QA,README/AGENTS,qa/payment-project-picker-20261107.json.
- [ ] Browserautentikasi/responsif/dark/keyboard/loadtestmanual belum;izinbrowsersebelumnya dihormati. Tidak ada fixture/transaksi live atau migration.

## BARP pembayaran sesuai template dan Word — 8 November 2026

- [x] Baca aturan dan panduan proyek serta panduan dokumen Word. Periksa dua halaman sumber; simpan referensi/checksum dan backup privat its sebelum perubahan domain.
- [x] Tambahkan form tanggal, tempat, periode, lokasi, STO dan Homepass. Identitas Waspang, Mandor dan Head terpilih ditentukan server. Simpan `barp` dan snapshot `barpHistory` setiap pengajuan pada JSON SQL; validasi periode dalam SPK dan pertahankan dokumen terkunci/riwayat lama.
- [x] Gunakan satu model surat untuk HTML/Word: judul TAHAP N, aktual termin × harga kesepakatan, Material/Jasa, pembulatan dan terbilang. Ikuti lima tabel sumber serta tiga penandatangan dengan area TTD kosong. BA PP tetap terpisah; tidak mengarang progres fisik 100% atau addendum.
- [x] Sediakan Word setelah Diajukan dengan penanda review hingga semua approval lengkap. Pertahankan syarat cetak, scan, Finance, APM dan ledger. Pertahankan enam bagian OOXML sumber; perbaiki floating table agar baris terlihat; ekspor lazy tanpa endpoint, dependency atau migration baru.
- [x] Periksa query: resolver 0 SQL; fixture HTTP satu PP 59 query termasuk GET revisi, batas 60, dua pembacaan katalog bawaan. Larang penulisan proyeksi tak terkait/arsip. Ganti pencarian berulang form dengan Map; preview tidak memerlukan root/KHS atau nilai termin terdahulu.
- [x] Lulus 218 pengujian PHP/4258 assertions, 202 frontend, build, scoped Pint dan diff check. Tambahkan 3 pengujian PHP dan 6 frontend; sesuaikan 2 pengujian tanda tangan dengan template sambil menjaga syarat approval. Render 3 Word, masing-masing 2 halaman; periksa seluruh 6 PNG, CRC/XML/relationships/python-docx dan geometri sumber.
- [x] Catat perilaku dan bukti pada BARP_TEMPLATE_WORD, kontrak artifact/referensi, ARCHITECTURE, CHANGELOG, LARAVEL_QA, README/AGENTS serta qa/barp-template-word-20261108.json.
- [ ] Pemeriksaan manual browser/klik akun/responsif/dark, Word desktop, cetak fisik dan load test belum dilakukan. Penolakan izin browser sebelumnya dihormati. Tidak ada transaksi/fixture live; addendum belum didukung dan Word editable bukan tanda tangan digital.

## Pekerjaan otomatis dan Lampiran Rekonsiliasi — 9 November 2026

- [x] Baca aturan, README/WORKPLAN/QA/changelog dan panduan workflow, ledger, desain, QA serta Word. Periksa screenshot dan simpan kontrak reference. Backup privat its selesai sebelum perubahan; tidak ada transaksi/fixture live.
- [x] Ganti lokasi manual dengan Pekerjaan dari nama PP, read-only/canonical server. Metadata versi 2 menangkap dua Manager sesuai assignee; client/cache tidak mengganti job/identitas. Preserve submitted versi 1 dan riwayat siklus; adopsi hanya melalui koreksi/simpan Admin yang sah.
- [x] Bagi model term-volume antara tabel form dan lampiran: harga/volume kesepakatan, aktual #1–3, accepted/sisa, Material/Jasa dan pembulatan. Map referensi sekali, tanpa volume campuran atau tagihan kumulatif berulang.
- [x] Lampiran horizontal mengikuti screenshot dengan kolom Volume Aktual Pekerjaan, rekap dan lima penandatangan berurutan. Semua ruang TTD kosong; histori identitas hanya dari snapshot/keputusan cocok. Main BARP tetap portrait dan metadata versi 2 menggunakan PEKERJAAN.
- [x] Word lampiran native lazy setelah diajukan, pending review-marked; print/scan/Finance/APM/ledger/approval tetap. Pisahkan filename/MIME agar lampiran tidak memuat template Word utama. Tidak perlu migration, endpoint atau dependency baru.
- [x] Resolver 0 SQL; HTTP satu PP tetap 59 query termasuk GET revisi, batas 60/two existing catalog reads; tidak ada penulisan proyeksi tak terkait atau arsip. Fixture 1000 baris memeriksa indexing/komponen/continuation/Word utuh. Catat batas aggregate boot/command dan tidak mengklaim throughput umum.
- [x] 219 PHP/4287 assertions, 208 frontend, build, scoped Pint dan diff check lulus. Tambahan satu PHP dan enam frontend; empat Word dirender 2+1+1+4 halaman dan seluruh delapan PNG diperiksa. CRC/XML/relationships/python-docx serta exact regenerated package hashes lulus.
- [x] Catat BARP_APPENDIX, pembaruan BARP_TEMPLATE_WORD, kontrak source, ARCHITECTURE, CHANGELOG, LARAVEL_QA, README/AGENTS dan qa/barp-appendix-20261109.json.
- [ ] Browser/klik unduh akun/responsif/dark/keyboard, Word desktop, cetak fisik dan load test belum manual; izin browser sebelumnya tetap dihormati. Dokumen panjang dapat memakai halaman akhir khusus signoff. Addendum/release otomatis dan tanda tangan digital tidak dibuat oleh fitur ini.

## Matriks dashboard Region → Branch — 9 November 2026

- [x] Tambah accordion Region berisi Branch di bawah dashboard awal; tabel bergaris dan filter Branch yang sama, JMLH/OPEN/CLOSE, jumlah PP, realisasi dan total BOQ Plan. Jaga tiga kartu/enam ringkasan/navigasi/tema.
- [x] Hitung ID unik per Branch/Region/global, semua PP dalam akses menentukan CLOSE sebelum filter; seluruh SPK harus final Disetujui/APM Valid/lunas. Draft/tanpa SPK/partial historical paid tetap OPEN. User memilih pembayaran terealisasi/APM sebagai realisasi. BOQ induk tersimpan saja, tidak menggandakan subalokasi lanjutan; missing ID/Region ditandai tanpa mengarang.
- [x] Join Region pada query Branch yang ada dengan kolom minimal; scope tetap Branch PP. Direct bind 5 query pada katalog awal/+200 Branch/Region, satu join tanpa per-row SQL. Map/Set/memo dan shared financial formula; 1000 PP/SPK/termin diperiksa tanpa repeated collection find. Tidak mengklaim throughput universal; boot aggregate tetap.
- [x] 222 PHP/4332 assertions, 218 frontend, build, scoped Pint dan diff check lulus. Tambah tiga PHP/sepuluh frontend untuk financial/status/filter/no-write/authorization/query/indexing/markup. Tidak menyentuh its live/.env/schema/arsip/Dompis Prov; seluruh fixture memory.
- [x] Catat DASHBOARD_MATRIX, DASHBOARD_SUMMARY, ARCHITECTURE, CHANGELOG, LARAVEL_QA, README/AGENTS dan qa/dashboard-matrix-20261109.json. Tidak ada perubahan workflow/data tersimpan sehingga tidak perlu backup/migration baru.
- [ ] Visual dan interaksi browser, responsif/dark/keyboard/load test belum manual; akses browser sebelumnya tetap dihormati. Realisasi masih pencatatan aplikasi, bukan koneksi bank.

## Worker upload server aaPanel — 9 November 2026

- [x] Catat folder server pengguna `/www/wwwroot/itsy`; pisahkan root SSH dari user OS PHP-FPM. Tambah generator konfigurasi Supervisor dari PHP_BINARY/base/storage aktual dengan user non-root eksplisit dan penolakan injeksi/path ambigu. Hanya tampilkan konfigurasi; tidak memasang daemon atau menjalankan worker.
- [x] Template memakai perintah lama area3:work-uploads/koneksiuploads/queuearea3-uploads dan lock/heartbeat yang sama: satu proses, autostart/autorestart, stop/kill group, stopwait120/jobtimeout90/retry180, umask0077 dan rotasi log. Runbook aaPanel/userwww contoh/CLI/izin/.envserverautostartfalse/boot/deploy/verifikasi; jangan menjalankan workerroot atau raw queuedefault.
- [x] Review generator0 SQL/0 process/0 write, satu stub substitution. Tidak mengubah job/queue/gates/SQL/otorisasi/ledger, migration/data/workflow/archive/.env lokal atau live its. Tidak perlu backup data baru. Query/runtime upload lama tetap; tidak mengklaim latency/load universal.
- [x] 225 PHP/4382 assertions,218 frontend/build,scoped Pint,diff check lulus. Tiga PHP baru/50 assertions noSQL/noProcess/config/timing/private user/injeksi. Independent INI parse memakai fixture path server; tidak diklaim sebagai service integration.
- [x] Dokumentasikan SERVER_UPLOAD_WORKER, UPLOAD_QUEUE_PERFORMANCE, ARCHITECTURE, CHANGELOG, LARAVEL_QA, README/AGENTS dan qa/server-upload-worker-20261109.json.
- [ ] Penerapan Supervisor aaPanel/include/service boot/restart, verifikasi PHP/extension/userFPM dan upload nyata/server/browser/load menunggu operator. Tidak ada akses remote yang dipakai agen. Heartbeat20s lama bisa stale saat job panjang dan tidak diubah fitur ini.

## Registrasi worker its CLI di Supervisor aktif — 9 November 2026

- [x] User memastikan Supervisor sudah menjalankan worker Dompis/wibsy. Buat script registrasi satu program its, bukan instalasi daemon/paket/service baru. Deteksi PID/config/include dari supervisorctl aktif, mendukung *.ini/*.conf dengan opsi eksplisit jika ambigu.
- [x] Root hanya menulis config. PHP preflight/generator/worker memakai user OS PHP situs non-root. Periksa project/target its/autostartfalse/extension/izin; allowlist satu command/user/folder/log, tolak foreign program/group/config utama. Atomic0600/idempotent+backup; reread lalu update/start/status area3-uploads saja. Tidak restart all/ubah worker lain, .env, permission, transaksi, archive atau queue gates.
- [x] Delapan regresi Python offline dengan subprocess palsu/temp dirs lulus; 225 PHP/4382 assertions,218 frontend/build,diff check dan lint embedded PHP lulus. Tidak ada Supervisor/worker/DB nyata disentuh pada tes. Tidak ada query jalur web baru; preflight config/guard tanpa query tabel bisnis, bukan benchmark latency atau integration server.
- [x] Catat SERVER_UPLOAD_WORKER, ARCHITECTURE, CHANGELOG, LARAVEL_QA, README/AGENTS dan qa/supervisor-cli-registration-20261109.json. Tidak perlu migration/build server untuk script ini, atau backup database karena tidak ada perubahan workflow/data tersimpan.
- [ ] Operator menjalankan script di `/www/wwwroot/itsy` setelah file ikut diunggah, dengan user/PHP situs yang benar; RUNNING/heartbeat/upload nyata/server/boot belum diverifikasi agen.
- [x] Klarifikasi: sediakan juga blok siap tempel langsung Terminal aaPanel tanpa push/pull script baru, memakai generator server yang sudah tersedia. AST syntax/global-update scope diperiksa; resep belum dijalankan pada layanan nyata. Simpan di SERVER_UPLOAD_WORKER dan catat QA/arsitektur agar audit tidak menyamakan syntax dengan service integration.

## Header → BOQ, harga KHS otomatis — 9 November 2026

- [x] Ubah urutan1Header/2BOQ dan Total Nilai v3 baca saja, Rp0 sebelum upload; Header lengkap tetap Draft. Hasil BOQ/volume/hapus/ganti menghitung ulang form, simpan terkonfirmasi memproyeksikan total ke SQL.
- [x] KHS SQL menjadi harga/versi otomatis saat explicit save/submit v3 root; harga/note/review/budget client tidak dipercaya. Simpan pembanding/provenance privat dan policy khs-auto-v1 sebagai normalisasi sistem, tanpa human approval rekaan. Pertahankan sejarah/v1/v2/lanjutan/ceilingMandor.
- [x] Migration CHECK budget0 hanya v3DRAFT + guardits/rollbackfailclosed disiapkan; tidak menulis/backfill transaksi. Map/Set/array_column untuk KHS/import/ID/Owner/Mandor. Domain500PHP0SQL/TS dan HTTP26≤220/2catalogreads lulus, tanpa klaim throughput universal.
- [x] 230PHP/4473assertions,225frontend,build,Pint,diffcheck lulus. Ekspresi CHECK fixtureSQLite/SQLDDLmock, bukan liveMySQLapply. Backup privat2.083.416byte/hash verified, failed0byte ditandai.failed. Docs/4skills/AGENTS/arsitektur/audit diperbarui.
- [ ] Operator deploy kode/build, jalankan migration dan queue restart; agen belum deploy/migrate pada its lokal/server. Browser/responsif/keyboard/engineDDL/cetak/loadtest terpisah belum diuji ulang. Rincian: docs/PP_HEADER_BOQ_KHS.md dan docs/qa/pp-header-boq-khs-20261109.json.

## Progres upload semua fitur — 9 November 2026

- [x] Transport/event byte dan nativeprogress bersama: BOQ, eviden per file, scan TTD, preview/commit Designator/KHS; nama file berlabel, unknown size tanpa persen rekaan.
- [x] Pisahkan transfer 100%/serverconfirmation/queued/processing/done/failure; task reload tetap 100% penerimaan, worker tidak diberi angka rekaan. Simpan hasil/scan/master mengikuti konfirmasi server dan preview master tetap read-only. CSRF/revisi/UUID/Branch/approval/privatefiles/polling lama tetap.
- [x]0 SQL/request baru untuk progres; deduplikasi angka bulat, cleanup handlers, noPOSTautoretry. FakeXHR integration7 existing GET/0 progress GET dan6 tes baru,230 PHP/4473 assertions/231 frontend/build/diffcheck lulus. Tidak ada perubahan PHP/schema/migration/job/data/.env/permission; tidak membuat backup workflow baru karena kontrak server/data unchanged.
- [x] Docs/arsitektur/audit dan dua skill/AGENTS diperbarui. Rincian docs/UPLOAD_PROGRESS.md dan docs/qa/upload-progress-20261109.json.
- [ ] Browser/realnetwork/Apache/aaPanel/responsif/dark/keyboard/load masih perlu verifikasi terpisah; tidak mengklaim dari mockXHR/SSR. Deployment membutuhkan kode + hasil build baru; migration tambahan untuk fitur progres tidak diperlukan.

## Payment Parsial ke Finance sebelum scan — 9 November 2026

- [x] Admin Ajukan Payment setelah lima selected approvals/Head, Finance verify dan APM full bill tanpa scan; actual/harga/volume tetap. Penanda/request/cycle/identitas/amount server-owned dan client forgery diabaikan. Request Finance return beralasan lalu explicit Admin resubmit menyimpan siklus tanpa mengulang work decisions.
- [x] Pisahkan verifikasi Payment dari scan, izinkan late private scan/correction/Finance verification setelah APM hanya jalur baru; preserve payment/settlement/approval/history. Gerbang next term butuh lunas+APM, bukan scan, dengan ledger/max3/Final lama tetap.
- [x] Status Berkas NOK/OK dan catatan incompleteness, paid amount+Termin N/jenis pada list, independent inbox/menu/timeline/report/picker. List20rowDOM pagination/index/memo,1000row fixture; runtime SQL/global read tetap.
- [x] Migration000002 partial_payment_requests + XOR/term-composite reference APM, its guard/rollbackhistory. Incremental term projection/query review54/55/57 including revision, budget75/2pricecatalog; no unnecessary actual/approval/scan/ledger writes. Backup2.084.634byte/hash verified, no live transaction rewrite.
- [x]239PHP/4944assertions,237frontend/build/Pint/diffcheck; MariaDB10.4 private schema63migrations/repeat/0users. Legacy scan settlement survives SQLite down/up; no-scan financial flow/late scan/SQL failure/replay/roles/Branch/cycles tested memory fail-closed.
- [x] Docs/arsitektur/query/relasi/audit/AGENTS/lima skill updated. Buku saku artifact lama dated5Nov tidak diregenerasi; relasi baru dijelaskan docs/PARTIAL_PAYMENT.md. Bukti docs/qa/partial-payment-20261109.json.
- [ ] **Format kasbon ditunda atas permintaan pengguna**, menunggu format/kolom resmi. Tidak ada input/nominal/potongan atau prasyarat kasbon yang diinvent.
- [ ] Operator deploy kode/build lalu migration baru dan queue restart; **agen belum migrate its aktif lokal/server**. Browser/account click/responsif/keyboard/cetak/full MySQL financial flow/load terpisah masih belum diverifikasi.


## Konfirmasi setiap tombol aksi — 9 November 2026

- [x] Ajukan/approval/save/Finance/APM/master/import/upload/hapus/antrean/unduh/cetak serta akun/logout meminta YA/TIDAK. Existing contextual dialogs distandardisasi tanpa double confirmation; navigasi/tab/preview/validasi baca-saja tetap langsung.
- [x] Shared native dialog on-demand, TIDAK default, safe text, Escape/backdrop/route/pagehide cancel, single pending/no duplicate callback, recheck kontrol aktif. Form Enter tetap validasi/CSRF dan logout baru clear draf sesudah YA.
- [x] UI/Blade only: 0 SQL/endpoint/poll/migration/package tambahan, domain/state/otorisasi/ledger dan live its/.env/Dompis tidak berubah. Tidak memerlukan backup workflow baru.
- [x] 239 PHP/4.944 assertions, 243 frontend, build/diff check; enam regresi fake DOM dan audit entry points, existing query budgets pass. Docs/arsitektur/audit/README/AGENTS/dua skill tercatat; docs/ACTION_CONFIRMATION.md dan docs/qa/action-confirmation-20261109.json.
- [ ] Browser nyata/fokus/responsif/dark/keyboard/download/cetak/server deployment masih perlu verifikasi terpisah. Migration Payment Parsial sebelumnya tetap terpisah dan tidak dijalankan fitur ini.


## Perbaikan error preload confirmation CSS — 9 November 2026

- [x] Telusuri generated preload helper /build/ origin-root yang salah pada Apache subfolder, meski file CSS lokal tersedia. Set Vite base ./, pertahankan @vite manifest entry dan rebuild lokal. Tidak hardcode path aplikasi/.env.
- [x] npm run check:assets: file/manifest lengkap, generated helper actual dieksekusi pada fake DOM untuk root/subfolder/CDN, 30 manifest references / 23 deps / 3 profiles lulus; 243 frontend dan build lulus. Review query: 0 jalur SQL/poll baru; tidak mengubah flow/data/its/Dompis.
- [x] Audit ARCHITECTURE/CHANGELOG/ACTION_CONFIRMATION/QA; panduan deploy build lengkap karena public/build diabaikan Git, lalu refresh tab lama.
- [ ] Browser/HTTP/server pengguna/CDN nyata belum diverifikasi; bukan klaim deployment server telah diperbaiki otomatis.
# 10 November 2026 — Role akun Operation

- [x] Tambahkan ADMIN_OPS, ADMIN_OPS_AREA, OFFICER_OPS1/2/3, MGR_BRANCH_OPS dan GM_OPS beserta label yang diminta ke validasi akun Laravel, domain PHP/TypeScript dan katalog SQL melalui guarded migration.
- [x] Pertahankan satu role aktif per akun, penugasan Branch dan hierarki penempatan yang ada; role baru tidak mendapat kewenangan workflow otomatis.
- [x] Catat mapping/perilaku pada docs/ACCOUNT_ROLES.md, README.md, docs/ARCHITECTURE.md, docs/CHANGELOG.md dan docs/LARAVEL_QA.md.
- [ ] Jalankan verifikasi suite hanya setelah diminta; migration belum dijalankan pada its aktif.


## Failed to fetch pada simpan draf/Ajukan PP — 10 November 2026

- [x] Pengguna menyatakan masalah akun selesai dengan create ulang. Tugas koneksi dijeda untuk pull lalu dilanjutkan pada3e397b8; role Operation dan seluruh aturan approval/Branch dipertahankan. Stash tidak di-pop seluruhnya/dihapus.
- [x] Same-origin root/subfolder boot/API/account URLs dan fixed endpoint command/state/recovery/CSV/backup; tidak memakai URL HTTP/upstream yang salah, tanpa hardcode host/CORS permissive/trust proxy/.env change.
- [x] Pesan network/timeout/nonJSONHTTP jelas, hasil write tetap uncertain dan PP form/cache tidak dibuang. Satu recovery GET lama, tanpa POST auto-retry atau klaim sukses/navigasi palsu.
- [x] 240 PHP/4.963 assertions,244 frontend/build/check:assets/Pint/diff check lulus. NewPHP12assertions+1FE integration; dua mock actual endpoint updates dan migration assertion64 sesuai pulled Operation. Query review0 SQL/endpoint/poll/request tambahan; normal1POST/failure1POST+1GET.
- [x] Docs SAME_ORIGIN_TRANSPORT, arsitektur/CHANGELOG/README/LARAVEL_QA dan qa/same-origin-transport-20261110.json tercatat. Tidak ada live its/migration/env/permission/Dompis changes atau workflow contract change.
- [ ] Fix baru perlu commit/push/pull+build/check:assets pada server dan refresh halaman. Agen belum commit/push/deploy, belum periksa authenticated browser/Console/Network/SSL/proxy/load server; tidak mengklaim root cause remote telah terbukti.

## Data Mandor SPK, kasbon Finance dan Payment — 11 November 2026

- [x] Pengguna menyetujui konsep dan implementasi; template kasbon dibaca sebagai data. EVP dan Mgr Business Support hanya TTD fisik, lima approval aplikasi tetap.
- [x] Backup its sebelum perubahan: `its-before-kasbon-20261111-004540.sql`, 2.096.563 byte, SHA256 `55ac43195104c27f527fb75daf042185cb873d83b8a304743e8cf71133056cdb`. Database/environment/master/Dompis tidak diubah.
- [x] Profil Mandor versi terpisah (NIK, rekening, pemilik, bank, foto KTP/buku), enkripsi APP_KEY, upload privat terantre/progress, akses Admin/Finance per Branch dan pengesahan SPK baru dengan kelengkapan server-owned.
- [x] Default server kasbon-v1 untuk pengajuan v3 baru; label Parsial/Final, Finance inbox sejak submit/read-only sebelum Head, issuance kasbon snapshot dari tagihan/penerima aktual, approval Finance, bukti transfer dan APM/lunas. Scan tidak wajib sebelum pembayaran kedua jenis, tetapi scan yang tersedia harus diverifikasi; scan susulan tidak mengubah pembayaran.
- [x] Koreksi kasbon beralasan, versi/rekaman rekening lama, proof terikat versi dan fresh-proof pada reissue; task versi lama ditolak. Legacy v1/v2/request Parsial/pembayaran/keputusan tetap; adopsi dokumen lama eksplisit. Ledger, tiga termin/Final, BA PP/release tetap.
- [x] Preview/cetak kasbon portrait berdasarkan struktur XLSX tanpa contoh identitas/angka/TTD; tujuh ruang TTD fisik kosong. Timeline, list/picker dan laporan Finance memakai status/riwayat baru tanpa membaca PII global.
- [x] Review query: private payee 5 termasuk workspace fixture (4 service), issuance47/APM44 HTTP satu termin; regression budget75 dan larangan write PP/BOQ/actual/approval/ledger saat issuance. Bukan load benchmark; aggregate/catalog boot/command lama tetap.
- [x] Verifikasi akhir: 254 PHP/5.390 assertions,252 frontend,build/check:assets,Pint/diff check lulus.
- [x] Dokumen perilaku/arsitektur: docs/FINANCE_KASBON.md; bukti suite dan batas akhir dicatat di LARAVEL_QA/CHANGELOG/qa/finance-kasbon-20261111.json.
- [ ] Operator deploy migration baru/build/restart khusus worker its dan uji browser/Finance/cetak/aaPanel. Tidak menjalankan migration live, mengganti APP_KEY/.env, commit/push atau deploy server oleh agen.

## Server Error upload eviden / kolom context_id — 11 November 2026

- [x] Log lokal menunjukkan SQL1054 Unknown column context_id pada INSERT upload_tasks kind evidence. Perubahan kasbon salah mengikutkan kolom untuk upload biasa sebelum migration diterapkan.
- [x] INSERT context hanya transfer-proof; BOQ/evidence tetap berjalan pada schema lama. Private uploads503 dengan pesan migration sebelum staging/job bila belum siap; binding kasbon dan otorisasi tetap.
- [x] Hasil akhir 256PHP/5.451assertions,252frontend,build/check:assets/Pint/diff check lulus.
- [x] Dua regresi HTTP/worker memory: BOQ26baris+PDF diproses/preview/replay/arsip tetap pada schema tanpa kasbon; KTP/bank/transfer503 tanpa writes. Query dua enqueue24, budget50 dan0schema/migration lookup jalur lama; private readiness1query.
- [x] Arsitektur/perilaku diperbarui, tanpa .env/APP_KEY/live its/Dompis/worker/DDL perubahan atau migration baru. Hasil akhir suite di QA/CHANGELOG dan qa/evidence-schema-compatibility-20261111.json.
- [ ] Deploy kode perbaikan/restart worker its bila dibutuhkan di server; agen belum commit/push atau mengakses server. Migration kasbon lengkap tetap langkah operator untuk fitur baru.

## Pemeriksaan koreksi PP0002 kembali ke Head — 11 November 2026

- [x] Snapshot attachment tervalidasi Sah/v3/Support-first, lima keputusan aktual sesuai assignee; empat tahap sebelum Head dapat diidentifikasi tanpa mengubah isian PP.
- [x] Jelaskan target Diajukan/SUBMITTED + empat approval, berbeda dari Dikembalikan/reset PM; keputusan Head terdahulu perlu tetap diaudit. Writer tidak mendukung pembatalan dengan edit JSON saja.
- [x] Siapkan SELECT-only pemeriksaan row PP/dokumen turunan/approval SQL dan audit sumber pada /private/tmp/area3-pp-head-review. Tidak mengubah attachment, database, source behavior atau tanda tangan.
- [ ] Tunggu konfirmasi BA KH/SPK/pembayaran dan lokasi database sebelum menyiapkan/menerapkan koreksi final yang tercatat. Belum ada rollback keputusan atau mutasi data pengguna.

### Tindak lanjut konfirmasi dokumen turunan — 11 November 2026

- [x] Pengguna mengonfirmasi BA KH/SPK ada dan pembayaran ditolak; melengkapi pertanyaan di atas, bukan verifikasi status SQL/APM server.
- [x] Review validateDeal dan writer approval: PP Sah menjadi prasyarat BA KH dan keputusan lama immutable. Tidak mengganti target menjadi pengembalian ke Admin atau menghapus dokumen turunan.
- [ ] Peroleh hasil SELECT-only PP/dokumen turunan/APM/run/step/decision pada database PP0002 sebelum merancang koreksi terkoordinasi. Belum ada command pencabutan Head atau koreksi data diterapkan.

### Hasil pemeriksaan turunan yang dikirim pengguna — 11 November 2026

- [x] Terima hasil BA KH/SPK Sah, termin1 Final REJECTED/Ditolak tanpa settlement/APM pada baris yang dikirim. Tidak mengubah/membatalkan dokumen tersebut.
- [ ] Pemeriksaan terakhir identitas/revisi PP/workspace dan run/step/decision SQL; koreksi belum diterapkan. Akses database remote langsung tidak tersedia pada review ini.

## Koreksi Head PP0002 setelah hasil SQL lengkap — 11 November 2026

- [x] Terima run/step/decision: revisi PP40, run1 APPROVED, Head akun34. Cocokkan target IDs dengan hash aplikasi; informasi pemeriksaan yang ditunggu pada item sebelumnya sudah diterima. Revisi workspace tetap dibaca saat CLI inspeksi.
- [x] Cadangkan tabel its lokal sebelum workflow code; hash/bytes/routines-exclusion dicatat di PP_HEAD_CORRECTION. Tidak mengubah transaksi/.env/server/Dompis.
- [x] CLI inspeksi/apply eksplisit, guard its/Superadmin/Branch/revisi/actual Head/keputusan/graf unpaid, private backup, lock/revision/receipt/audit dan hash-bound replay. Rencana spesifik tersedia deploy/corrections/pp0002-r546-head.json.
- [x] Pertahankan keputusan dan run lama; marker server-owned menambahkan siklus koreksi dengan empat approval sumber dan Head pending. Seluruh turunan ditahan saat PP belum Sah; rejection/resubmission tetap normal. Cap/notice/timeline konsisten tanpa TTD fiktif atau overwrite SQL histories.
- [x]263PHP/5597assertions,257frontend,build/check-assets/Pint/diffcheck lulus. Apply84query/budget140/no catalog/no descendant/BOQ/ledger write; JSON-order/strict-values, rollback/retry, role/Branch/conflict dan semua descendant routes diuji memory. Docs/arsitektur/AGENTS/dua skills diperbarui.
- [ ] Operator deploy kode/build lengkap, inspeksi lalu apply dengan revisi workspace terkini, refresh akun Head dan verifikasi hasil SQL/server. Agen belum commit/push/deploy atau mengoreksi data its lokal/server; tidak ada migration baru. Browser/MySQL transaction/aaPanel belum diverifikasi.


## Kasbon Admin / review Finance / inbox otomatis — 11 November 2026

- [x] Backup lokal tabel/data/triggers its sebelum workflow change, SHA/bytes dicatat; tanpa routines/restore test, bukan backup server.
- [x] Admin Konstruksi/Superadmin Ajukan Kasbon setelah lima actual approvals sampai Head; template, canonical bill, bank/SPK, actual actor dan returned versions. Finance issuance ditolak. Default Surabaya/date/EVP/Business Support persis otoritas pengguna; TTD Mandor kosong, histori lama utuh.
- [x] Modal review Finance PP→kasbon/lampiran/berkas privat, bank frozen beserta versi/foto; current profile eksplisit. payeeVersion dan reviewed kasbon ID menolak stale bank/keputusan. Approval/proof/APM/scan/full payment/ledger tetap.
- [x] Preview BOQ TA Mandor semua PP/payment approval memakai selected saved PP budget, indeks metadata/rounding/M-J/null-vs-zero; tidak mereprice dokumen lama.
- [x] Authenticated revision probe1query/15detik, state hanya saat berubah, backoff/hidden abort, dirty/editor/dialog/pending/stale protection dan20task/page tanpa private reads per row. Success setelah konfirmasi server, YA/TIDAK tetap.
- [x]269PHP/5771assertions/270frontend,TypeScript/Vite build/check-assets31refs/24preload×3profil,scoped Pint/diffcheck lulus;6PHP/13FE baru. Query pengajuan44/budget75/APM44, lazy private3masing-masing, counter1service/2HTTPfixture; bukan load benchmark.
- [x] [Perilaku Admin kasbon dan inbox](docs/ADMIN_KASBON_REVIEW_SYNC.md), arsitektur/QA/changelog/README/AGENTS dan empat skill diperbarui; bukti qa/admin-kasbon-review-sync-20261111.json. Tidak ada migration tambahan, env/key/live transaction/Dompis/worker/commit/deploy.
- [ ] Operator deploy kode dan build, migration kasbon terdahulu bila belum terpasang, bersihkan cache aplikasi/reload hanya worker its bila perlu; uji akun Admin→lima approval→AdminKasbon→FinanceReview→proof/APM serta inbox dua akun/browser/print dan MySQL server. Jangan auto-apply rencana koreksi PP0002 saat deploy.


## Rupiah bulat / tipe uang / rincian dashboard — 11 November 2026

- [x] Klarifikasi pengguna mencakup tipe database dan preview berkas. Backup lokal tabel/data/triggers its sebelum workflow, bytes/hash pada dokumen; bukan backup server, routines/restore belum diuji.
- [x] Formatter tunggal rupiah/Word/terbilang tanpa pecahan; policy idr-whole-v1 server-owned pada editable/new v3, BCMath/BigInt half-up per komponen. Raw ceiling dicek sebelum rounding, harga/form/budget/kasbon/ledger gates tetap. Legacy locked cents/paid/decision/archive/provenance tidak ditulis ulang.
- [x] Migration its-only14kolom/10tabel BIGINT, preflight semua kolom+pending snapshot sebelum DDL, CLI check read-only, trigger/null/FK/XOR preserved.0live schema/data mutation.
- [x] Enam angka matriks Branch/Region/TOTAL klik modal sesuai scope/unique ID/classification/root/payment contributors; search/page20, ID→PP dan exact detail links. Indexed model/memo,0SQL/API/private reads.
- [x]275PHP/5837assertions/279FE/build/check-assets31refs/24preload×3/Pint/diffcheck lulus.6PHP/9FE baru, legacy tests tetap; preflight11SELECT baseline+pages200, catalog4query, model1000PP/20DOM, bukan benchmark.
- [x] [Perilaku, SQL, arsitektur dan deploy](docs/WHOLE_RUPIAH_DASHBOARD_DETAILS.md), CHANGELOG/QA/README/AGENTS/skills dan qa/whole-rupiah-dashboard-20261111.json dicatat.
- [ ] Operator backup server/maintenance/deploy/build/check/migrate dan verifikasi schema/data/akun/klikmodal/Word/print/MySQL. Jika data cents, rekonsiliasi terpisah dahulu; jangan paksa ALTER/reset/import ulang. Agen belum migrate/preflight its aktif, env/key/worker/Dompis/sourcefile/commit/deploy tidak diubah.


## Koreksi: potong pecahan nilai tersimpan — 12 November 2026

- [x] Otoritas pengguna menggantikan half-up dengan pemotongan menuju nol, termasuk data tersimpan. Backup lokal read-only its sebelum perubahan, hash/bytes/routines-exclusion dicatat; bukan backup server.
- [x] idr-trunc-v1 PHP/TS/format/Word/terbilang, source totals/katalog/budget/inputs konsisten; policy half-up lama tetap dikenali. Ceiling asli, quantity/percent/null/nol dan workflow gates tetap.
- [x] Migration data000001a sebelum BIGINT000002, SQL+snapshot aktif+private kasbon, before/after audit terenkripsi, transaksi/lock/revisi/idempotence/rollback-retry. Total otomatis dihitung dari sumber; paid/APM bukan transfer baru. Konflik zero-positive/kasbon/pembayaran menghentikan sebelum update. SourceExcel/arsip/decisions/files tetap.
- [x] CLI plan read-only/its-only; pending migration mendukung server yang sudah/belum menjalankan BIGINT. Tidak apply pada its aktif/server. Dokumentasi [TRUNCATE_STORED_MONEY](docs/TRUNCATE_STORED_MONEY.md), arsitektur, QA, changelog, README, AGENTS, skills dan relasi tambahan dicatat.
- [ ] Operator backup server, maintenance tanpa writer/worker its, inspeksi/migrate/build/cache dan cek ulang berkas/pembayaran/SQL. Browser/Word/print/MySQL DDL/remote belum diverifikasi. Jangan mengubah APP_KEY atau reset/import ulang untuk melewati konflik.

- [x] Verifikasi akhir koreksi truncation:280PHP/5877assertions,280frontend,build/check-assets31refs/24preload×3/Pint/diff check lulus;5PHP/1FE baru. Audit before/after terenkripsi, rollback SQL terlambat/retry, fresh migration sebelum tipe dan konversi populated fixture sesudah tipe diuji. Hasil bukan verifikasi browser/Word/print/DDL MySQL server.


## Baris EVP pada penutup PP — 13 November 2026

- [x] Pengguna meminta row selalu Disetujui Oleh I GEDE WAHYU DANYNDRA/730371/EVP Area Jawa Bali dan automatic approve; mengonfirmasi tanggal satu hari sebelum BA KH tersimpan paling awal pada exact PP.
- [x] Tambah row presentasi tetap setelah baris akun; readonly/nonremovable, form status otomatis/preview APPROVED/Persetujuan otomatis, print status/cap kosong. Lima actual account decisions dan tanggal/history/role/routing tetap, tidak menjadi tahap keenam.
- [x] Helper minimum valid date exact PP termasuk Draft tersimpan, minus satu UTC calendar day; tanpa fallback root/sibling/projectcode/hari ini, kosong bila belum ada BA KH. Satu scanO(n)/constant memory/0SQL/API, tanpa schema/snapshot/write/live its mutation.
- [x]280PHP/5877assertions,284frontend,build/check-assets31refs/24preload×3profil/diff check lulus.4FE baru: row/form/preview/print structure, chronology/calendar/missingdate/identityscope dan unchanged state/routing/allstatuses. Backend tests tetap SQLite memory.
- [x] Catat docs/PP_EVP_SIGNATORY.md, arsitektur/CHANGELOG/QA/README/AGENTS/two skills dan qa/pp-evp-signatory-20261113.json. Tidak perlu migration/backup data baru untuk fitur presentasi; tidak menjalankan konversi rupiah/migration terdahulu.
- [ ] Operator deploy kode/build dan periksa akun/375–1440px/keyboard/preview/cetak/BA KH dates pada server. Tidak ada browser/physical-print/server check atau commit/push/deploy oleh agen.


## Unduh Word PP / perbaikan PDF kosong — 13 November 2026

- [x] Tambah tombol Word PP current+legacy dengan YA/TIDAK/running/busy/error; clone ref preview sebelum lazy import, native OOXML paragraphs/tables dari isi terotorisasi, tanpa perhitungan/akun/query ulang. SeluruhI–VIII/EVP/date ikut; cap/status TTD tidak ikut dan area basah kosong.
- [x] Reproduksi0px print lama; perbaiki path ancestor/nested review/siblings/body/modal clipping. PP-only named A4 portrait; tabel BOQ ringkas/numeric wrapping dan TTD dijaga bersama. BA KH portrait/appendix landscape tetap pada fixture.
- [x]280PHP/5877assertions,289FE/build/assets32refs/25preload×3profil/diff check lulus.5FE baru. Headless PDF4/6halaman ordinary+nested +375/768/1440, CRC/XML native +Word LibreOffice4/5halaman/all9PNG dan standard PDFall4PNG diperiksa.0SQL/API/schema/live rewrite, module6,20kB/gzip2,71kB bukan benchmark.
- [x] Catat PP_WORD_PRINT/arsitektur/CHANGELOG/QA/README/AGENTS/skills dan qa/pp-word-print-20261113.json. Tidak mengambil backup DB/migrate/convert monetary/start worker/change env/Dompis/commit/push/deploy.
- [ ] Operator deploy kode+build aset dan uji tombol/download/print-dialog pada akun server, desktop Microsoft Word/Safari/Firefox serta cetak fisik. Tidak memerlukan migration baru untuk fitur presentasi ini.


## Tiga teks penutup PP dihapus — 15 November 2026

- [x] Hapus heading penandatangan dari preview (editor tetap), catatan otomatis EVP dan footer prototipe yang disebut pengguna. DOM-based DOCX serta PDF mengikuti penghapusan yang sama.
- [x] Pertahankan tabel akun/EVP/date/preview stamps/blank wet TTD dan lima tahap approval;0SQL/API/storage/schema/performance-path change. Tidak ada live data/env/worker/Dompis/migration/deploy.
- [x]280PHP/5877assertions/289FE/build/assets32refs/25preload×3/diff check lulus. Existing assertion diperluas; fixture HTML/DOCX/PDF tanpa teks, CRC/XML valid, Word4/5halaman/all9PNG diperiksa dan PDF4/6halaman/width375/768/1440 nonblank. Tidak menanam data uji di its.
- [x] Catat CHANGELOG/QA/PP_WORD_PRINT/PP_EVP/ARCHITECTURE/README/AGENTS/design skill serta qa/pp-clean-copy-20261115.json; audit lama tidak diganti.
- [ ] Operator deploy kode+build; verifikasi akun server/native print dialog/Microsoft Word/cetak fisik. Perubahan ini tidak memerlukan migration; agen belum commit/push/deploy.


## Total Anggaran PP poin5 — 15 November 2026

- [x] Tambah baris terakhir Total Anggaran pada form/preview/Word/PDF; jumlah seluruh rincian termasuk permit. Total lama versi2 menjadi subtotal biaya proyek, COGS/margin tetap. Versi1 menjumlahkan amount tiap termin sekali dan tidak menulis ulang jadwal/snapshot.
- [x] Query review0SQL/API, satu passO(usage rows)/memori konstan. Tanpa backend/schema/migration/live rewrite/backup baru/env/worker/Dompis/konversi rupiah lama.
- [x] 280PHP/5877assertions,289FE/build/assets32refs/25preload×3profil lulus. Existing semantic regressions diperluas;2native DOCX CRC/XML/render4+5pages/all9PNG inspected, PDF4/6pages current/nested/3viewport nonblank dan grand total tepat.
- [x] Catat PP_BUDGET_TEMPLATE/ARCHITECTURE/CHANGELOG/LARAVEL_QA dan qa/pp-budget-total-20261115.json tanpa menimpa audit sebelumnya.
- [ ] Operator deploy kode+build dan verifikasi akun server/desktop Word/native print dialog/cetak fisik. Tidak ada migration baru atau commit/push/deploy oleh agen untuk perubahan ini.


## Header PP dari Total Anggaran poin5 — 15 November 2026

- [x] Shared helper tabel/Header; form/summary/preview/Word/PDF memakai jumlah seluruh usage termasuk permit. Zero awal, edit source langsung ikut, notice BOQ/Header diperbaiki, legacy tanpa planBudget fallback historis.
- [x] Rincian tetap canonical Laravel/save/reload. Tidak mengubah SQL budget BOQ/alokasi, COGS/margin/ledger/dashboard/tagihan/approval/riwayat;0SQL/API tambahan/satu pass/memori konstan/tanpa schema/migration/live write/backup baru.
- [x] 280PHP/5880assertions/289FE/build/assets32refs/25preload×3profil lulus. Semantic regressions lama diperluas;2native DOCX4/5pages CRC/XML/Header=recap,2changed pages inspected+7hash-identik dengan render inspected sebelumnya. PDF current/nested4/6pages/3viewport nonblank.
- [x] Catat PP_HEADER_BOQ_KHS/PP_BUDGET_TEMPLATE/ARCHITECTURE/CHANGELOG/QA/README/AGENTS/skills dan qa/pp-header-recap-20261115.json, audit sebelumnya dipertahankan.
- [ ] Operator deploy kode+build dan uji akun server/desktop Word/native print dialog/cetak fisik. Tidak ada migration baru atau commit/push/deploy oleh agen.


## Perapian BOQ PP saja — 15 November 2026

- [x] Pengguna mengklarifikasi tabel penutup tidak perlu dikembalikan. Pertahankan isi/format penutup dan Header dari IV.5.
- [x] Ringkaskan dua BOQ preview/print/Word: font/padding lebih kecil, fixed grid/kolom terpisah, designator wrap dan nominal utuh. Input editor tetap. Capture memberi compactBoq; margin OOXML opsional/default exporter lain tetap.
- [x] Query review0SQL/API/backend/schema/live write/backup baru, capture tetap O(rendered DOM). Tidak mengubah data/nominal/approval/env/worker/Dompis/migration.
- [x] 280PHP/5880assertions/289FE/build/assets32refs/25preload×3profil lulus. Preview850px tabel668px tanpa overflow, screenshot diperiksa; native ZIP/XML/text sama/closing byte-identik, Word3/4pages (5changed inspected+2Header hash-identik), PDF current/nested4/5pages/3viewport nonblank.
- [x] Catat PP_WORD_PRINT/PP_BUDGET_TEMPLATE/ARCHITECTURE/CHANGELOG/QA dan qa/pp-compact-boq-20261115.json tanpa menimpa audit.
- [ ] Operator deploy kode+build dan verifikasi akun server/desktop Word/native print dialog/cetak fisik. Tidak ada migration baru atau commit/push/deploy oleh agen.


## Surabaya hanya pada BA Negosiasi Kesepakatan Harga — 15 November 2026

- [x] Backup demo SQLite privat233472byte sebelum edit, tidak menyentuh its aktif.
- [x] Form tempat readonly Surabaya; preview/Word/print memakai model tetap Surabaya + tanggal BA untuk Draft/Terbit/Sah/historis. Lokasi/Witel/pekerjaan serta dokumen lain tetap.
- [x] Backend/TS canonical signingPlace pada new/draft; legacy place dipisahkan/dipertahankan untuk SPK, issued snapshots tidak direwrite. contracts.document_data existing/tanpa schema/migration.0SQL/API/lookup tambahan,6domain paths0queries.
- [x] 280PHP/5906assertions/289FE/build/Pint/assets32refs/25preload×3profil lulus;2native DOCX CRC/XML/render1/1pages/all2PNG, exact city/date/location/Witel/source assertions.
- [x] Catat BAKH_TEMPLATE/BAKH_WORD_EXPORT/ARCHITECTURE/CHANGELOG/QA/AGENTS/workflow skill dan qa/bakh-surabaya-20261115.json tanpa mengganti audit lama.
- [ ] Operator deploy kode+build dan uji browser akun/live server/MySQL/desktop Word/native print/cetak fisik. Tidak ada migration baru atau commit/push/deploy oleh agen.

## Surabaya dan tanggal penerbitan SPK — 15 November 2026

- [x] Backup demo SQLite privat233472byte read-only sebelum edit, tanpa mengubah its aktif.
- [x] Tempat SPK readonly Surabaya; tanggal kosong/invalid/melewati awal pekerjaan memakai tanggal mulai SPK, tanggal lebih awal tetap. Form/cache/domain PHP/TS serta preview/Word/print mengikuti aturan yang sama.
- [x] New/draft disimpan melalui field/proyeksi existing; Terbit/Sah stored snapshot tetap, output historis mengikuti presentasi baru. Periode/nomor/signer/NIK/nominal/approval/ledger/payee tetap. Tanpa schema/migration/archive rewrite.
- [x] Query review scalar O(1),0SQL/API/lookup tambahan;10vektor kalender helper0queries.281PHP/5959assertions,290FE/build/Pint/assets32refs/25preload×3profil lulus;2native DOCX CRC/XML/render1+1halaman/semua2PNG diperiksa, model HTML/Word/source tetap.
- [x] Catat SPK_TEMPLATE_WORD/SPK_SIGNER_WORD/ARCHITECTURE/CHANGELOG/LARAVEL_QA/AGENTS/workflow skill dan qa/spk-issuance-20261115.json tanpa menimpa audit.
- [ ] Operator deploy kode+build dan uji browser akun/live MySQL/server/desktop Word/native print/cetak fisik. Tidak ada migration baru atau commit/push/deploy oleh agen.

## Unggah identitas Mandor lokal — 15 November 2026

- [x] BackupdemoSQLite233472byte sebelum edit, diagnosisits baca-saja tanpa NIK/rekening/foto di output. Dua taskfailed, akun/SPK/Branch valid.
- [x] Temukan workerdaemon sejak7November; sinyalLaravel restart pada cachefile khususproyek menghentikan workerlama. Autostarttrue/PHPXAMPP tetap, tanpa ubahworker lain/izin/env/taskdata.
- [x] Pengguna mengonfirmasi fotoKTP berhasil setelah reload/uploadbaru. NIKsimpan sudah aman setelah16digit; pesan validasiKTP/rekening diperbaiki, leadingzero/trim/validasi ketat tetap.
- [x] Retryprivat melepasUUID hanya pada confirmedterminalfailed; jaringan/polluncertain tetapUUIDlama/nolautoPOST. Role/Branch errorjelas, keamanan/versi/enkripsi/audit/FinanceAPM tetap.
- [x] 283PHP/5991assertions,291FE/build/Pint/assets lulus; sessionlessworker KTP/bank39query/task≤75, tanpa query/API/poll tambahan. Audit FINANCE_KASBON/UPLOAD_QUEUE_PERFORMANCE/ARCHITECTURE/CHANGELOG/LARAVEL_QA dan qa/mandor-upload-fix-20261115.json.
- [ ] Jika deployserver nanti, restart hanya workerArea3 setelah updatekode; tidak ada migrationbaru. ServeraaPanel/Financeend-to-end/browserresponsive tidak diuji ulang untuk perbaikan ini.


## 16 November 2026 — Penandatangan Mgr Finance pada kasbon lama

Pengguna mengonfirmasi bahwa preview yang masih menampilkan Mgr Business Support berasal dari pembayaran lama. Pull/migration sebelumnya tidak mengganti snapshot historis tersebut. Kini Admin Konstruksi, Superadmin atau Finance dapat membuka **Preview Kasbon** versi aktif, memilih akun aktif **Mgr Finance & Settlement** pada Branch PP yang sama, lalu **Simpan Penandatangan** dengan konfirmasi YA/TIDAK. Preview, cetak dan Word menggunakan nama akun yang dipilih serta jabatan Mgr Finance & Settlement Area Jawa Bali. Pilihan ini hanya penandatangan fisik; tidak membuat cap/keputusan approval baru atau memindahkan keputusan Business Support ke Finance.

Berlaku untuk kasbon-v1 berstatus Disetujui, termasuk yang telah lunas. Versi arsip, pengajuan berjalan yang belum Disetujui dan alur tujuh approval tidak dapat dialihkan melalui kontrol ini. Snapshot asli finance_kasbons tetap terenkripsi dan tidak ditulis ulang; annotation server-owned kasbonPrintSigners per UUID kasbon dan kasbonPrintSignerHistory before/after/pelaku/waktu disimpan dalam payment_terms.document_data melalui lock/revision/receipt/audit yang sudah ada. Nominal bruto/net, paid, APM, penerima/rekening, bukti transfer, approval dan prototype_workspaces.state tetap. Versi kasbon baru tidak mewarisi pilihan versi sebelumnya. Nama yang telah dipilih tidak berubah otomatis ketika akun diganti namanya. Pembuatan kasbon baru tetap mengikuti tujuh approval yang berlaku; teks bantuan form kini membedakan template baru dan historis.

Implementasi menggunakan users/Branch/relasi yang sudah dimuat, satu lookup per koleksi saat aksi, tanpa query per baris rincian atau endpoint/schema/migration baru. Lazy private read tetap3query; metadata-only HTTP fixture35query (batas50), tanpa write finance_kasbons/APM/approval/actual/volume/PP. Full aggregate/reference loading pada command tetap ada; angka ini bukan benchmark beban MySQL. Simpan menunggu konfirmasi server dan memuat ulang dokumen privat sebelum preview/unduh ditampilkan kembali.

Verifikasi:306PHP/6887assertions,313frontend,TypeScript/Vite,assets36manifest/28preload×3profil dan Pint scoped lulus. Regresi paid/unpaid, role/Branch/akun nonaktif, stale revision/UUID replay, versi arsip/reissue, Head lock, larangan alur tujuh approval, original ciphertext/settlement/archive unchanged serta HTML/Word memakai identitas yang sama tanpa approval palsu. Dua DOCX sintetis untuk template lama penerbit Finance dan Admin lulus ZIP/CRC/XML independen, render1halaman masing-masing; seluruh PNG diperiksa dan PDF render berisi. Interaksi akun browser/server aaPanel, fresh browser print, Microsoft Word desktop dan cetak fisik belum diverifikasi. Backup SQLite demo dibuat privat sebelum perubahan; its aktif/.env/Dompis/server tidak diubah. [Bukti QA](docs/qa/legacy-kasbon-signer-20261116.json).

Penerapan perubahan ini sendiri tidak memerlukan migration baru. Setelah kode diterapkan dan frontend dibangun ulang pada server, buka pembayaran lama melalui akun berwenang dan pilih penandatangan pada preview kasbon aktif. Tidak ada penggantian otomatis massal pada pembayaran yang telah tersimpan.


## 16 November 2026 — Alihkan payment lama dari Finance ke Mgr Finance

Pengguna meminta pengajuan lama yang belum disetujui Finance dipindahkan dahulu ke inbox Mgr Finance. Finance/Superadmin kini dapat memilih maksimal50 kasbon-v1 yang memenuhi syarat melalui tombol **Alihkan Payment Lama ke Mgr Finance** pada Inbox Approval, mencari ID Project/LOP/PP/Branch (20baris/halaman), memilih akun aktif yang mencakup semua Branch, lalu konfirmasi YA/TIDAK. Pemindahan atomik dan eksplisit; tidak otomatis saat deploy. Lima approval pekerjaan/SQL lama tetap, sementara review tambahan kasbon aktif harus disetujui akun Manager yang dipilih sebelum Finance dapat melanjutkan approve/upload transfer/APM/lunas. Kasbon dikembalikan dengan catatan wajib ke Admin dan versi baru memerlukan review Manager kembali, tanpa mengulang lima approval pekerjaan historis.

Metadata penugasan/keputusan aktual dan riwayat disimpan server-side pada payment_terms.document_data melalui lock/revision/receipt/audit. Inbox/count, List Payment, timeline dan kasbon aktif mengikuti marker; cap Manager hanya dari keputusan nyata dengan UUID kasbon cocok, Word/cetak TTD tetap kosong. Original ciphertext/rekening/nominal/paid/APM/proof/approval_runs/steps/decisions/volume/archive tidak diubah. Tolak status/version/Branch/role/nonaktif/duplikasi/Head lock, pengajuan tujuh approval, Finance sudah Setujui, proof/APM/pembayaran dan queued proof. Tidak ada migration baru/live its/.env/Dompis/server write. [Aturan, data, deployment dan query](docs/LEGACY_FINANCE_ROUTING.md).


Verifikasi akhir alih inbox:310PHP/7137assertions,318frontend,TypeScript/Vite,Pint dan assets36manifest/28preload×3profil lulus; satu DOCX/1halaman dirender/diperiksa, cap hanya preview. Query single36/batch8=51, batas50/120; list tetap4query. Tidak ada private_data query per target atau perubahan proyeksi lima approval/ledger/nominal lama. Browser akun/server/MySQL load/Word desktop/cetak fisik belum diverifikasi. [Bukti](docs/qa/legacy-finance-routing-20261116.json), [catatan QA](docs/LARAVEL_QA.md).


## 17 November 2026 — Ikon Unduh Semua Dokumen

Selesai: gunakan ikon `Download`16 pada tombol Unduh Semua Dokumen bersama; pertahankan tooltip/status/modal. Sumber sudah sesuai saat diperiksa dan dipertahankan. TypeScript/Vite build serta check:assets lulus; tanpa backend/database/migration. Browser/server belum diuji ulang.


## 17 November 2026 — Matriks Pengajuan dan Terbayar serta dashboard per peran

Selesai: rapikan matriks Region/Branch, Nilai Pengajuan bruto (konfirmasi pengguna) + Nilai Terbayar, modal kontribusi, dashboard prioritas/tombol per role dan akun/Branch. Simpan seluruh aturan CLOSE/BOQroot/settlementnet/riwayat. Summary memakai indeks dan satu total perterm, tanpa tambahan query/API/schema/migration/live mutation.311PHP/7241assertions,341frontend,TypeScript/Vite/assets/Pint,fixture1000row,9roleBranchscope serta browser sintetis6viewport/theme dan interaksi lulus. Server/akunlive/loadtest belum diverifikasi. [Perilaku](docs/DASHBOARD_MATRIX.md), [QA](docs/qa/dashboard-matrix-payments-20261117.json).


## 17 November 2026 — Keterangan PPh dan label pembayaran Final satu kali

Selesai: keterangan PPh2,5% khusus Jasa Mandor pada UI pengajuan/review kasbon dengan bruto/potongan/net; berkas tetap net-only dan histori tidak repricing. Final1 menghilangkan Termin dari label/editor/list/inbox/review/BA PP/dashboard/report; Parsial1/Final2/3 tetap bernomor. Finance legacy tetap tersedia, next-term card Final1 disembunyikan, nama Word kasbon Final1 memakai _Final. Tidak ada perubahan workflow/SQL/migration/data aktif. 311PHP/7241assertions,345frontend/build/assets dan browser sintetis6viewport/theme/interaksi lulus; belum server/liveakun/Wordrender/cetakfisik. [Perilaku](docs/KASBON_PPH_PAYMENT_LIST.md), [QA](docs/qa/payment-presentation-20261117.json).


## 17 November 2026 — Material BOQ Mandor masuk Revenue pada margin PP

Selesai: Jml_Mat BOQ TA–Mandor masuk Material1.1 dan Revenue margin PP; Jasa1.2 tetap Owner, COGS tidak berubah, GP/persentase mengikuti Revenue baru. Laravel/React memakai marker JSON server-owned untuk explicit editable save/submit/continuation; locked history/maintenance tetap policy tersimpan, tanpa migration/backfill/live write.313PHP/7278assertions,346frontend/build/assets/Pint serta browser sintetis6viewport/theme dan source edits lulus. Layanan0query/HTTPfixture83 (budget90), bukan load benchmark. [Perilaku dan batas](docs/PP_BUDGET_TEMPLATE.md), [QA](docs/qa/pp-mandor-material-revenue-20261117.json).


## 17 November 2026 — Revisi Material Owner terpilih / Non Stock otomatis

Selesai implementasi Laravel/React policy selected-owner-material-nonstock-v2 untuk editable v3: Revenue Jasa Owner+Material Owner hanya item yang diisi Mandor; user menegaskan volume sama. IV.2 otomatis readonly dari Mandor,2.2=Jml_Mat Mandor; COGS/IV.5/Header menghitung biaya sekali. Simpan canonical memakai sourceRowId/referensi batched, current inputs/totals palsu diabaikan; histori locked/oldpolicy tetap. Tidak ada migration/live write. [Aturan](docs/PP_BUDGET_TEMPLATE.md), [QA](docs/qa/pp-selected-owner-material-20261117.json).


## 17 November 2026 — Designator dan tambah/hapus Material Non Stock

Selesai: IV.2 menampilkan kode designator sumber, tombol tambah dan hapus dengan YA/TIDAK, baris Total Nilai paling bawah pada editor/preview/Word source. Tambahan manual tervalidasi max200; penghapusan sumber samePP disimpan agar tidak muncul kembali. Latest policy v3 editable saja;2.2/COGS/rekap/Header mengikuti rincian aktif sekali, Revenue/BOQ tetap, histori locked v2/v1 dipertahankan. Existing JSON/rincian/biaya dan indexed source references tanpa migration/API/lookup perbaris. [Aturan](docs/PP_BUDGET_TEMPLATE.md), [bukti](docs/qa/pp-editable-nonstock-20261117.json).


## 17 November 2026 — Preview paket langsung sebelum approval

Selesai: lima tombol PP/BA KH/lampiran/SPK/lampiran di ProjectApprovalPanel sebelum keputusan; Inbox20rows mendapat Preview Paket read-only. Target UUIDexact, banyak kontrak dikelompokkan, tidak memilih dokumen pertama/sibling; satu modal picker/preview dengan Escape kembali. Detail tidak menduplikasi kartu; operator links terjaga, dokumen kurang diberi status. Existing Word/print/five approval/Branch/manifest tanpa perubahan.0newSQL/API/schema/runtime dependency/migration/livewrite. [Perilaku](docs/BUNDLE_SETTLEMENT_APPROVAL.md), [QA](docs/qa/project-bundle-preview-20261117.json).


## 17 November 2026 — Ganti kata sandi sendiri dan tema topbar

Selesai: Profil & Preferensi → Ganti Kata Sandi untuk seluruh akun aktif, konfirmasiYA/TIDAK/current password/new confirmed6letters+numbers/max72bytes. Self-only locked server update/remember/session rotation, sessionlain gagal nextrequest, tanpa role/Branch/workflow change. Form volatile/no auto retry; status/error/eye/autocomplete. Tema dipindah sebelahBell tanpa konfirmasi, keylocalexisting dan fallbackstorageblocked. No schema/migration/env/seed/livepasswordchange/worker. [Perilaku](docs/SELF_SERVICE_PASSWORD.md), [QA](docs/qa/profile-password-theme-20261117.json).


## 18 November 2026 — Jabatan akun pada penandatangan dokumen

Permintaan pengguna sebelum eksekusi Operation: tambahkan users.jabatan dan gunakan pada seluruh penandatangan berbasis akun, terutama VIII.PENUTUP PP. Migration guarded nullableVARCHAR250 tanpa backfill; form tambah/ubah dan kolom daftar akun hanya Superadmin, satu baris/max250/plaintext. Peran/Branch/login tetap. PP/actual decisions, BA KH/SPK, BARP/BA PP dan kasbon menangkap jabatan server-owned pada snapshot existing; HTML/nativeWord/print konsisten, fallback template untuk akun kosong/riwayat lama, EVP dan manual Waspang/Mandor tetap. Dokumen locked/issued/paid/ciphertext tidak diubah saat akun diedit. Konsep Operation belum dieksekusi.

Query akun dan batch kasbon menambah kolom saja; resolver0SQL/per-row lookup baru0. Daftar akun kini satu batch assignments, fixture30extraaccounts2assignmentreads termasuk auth.327PHP/8061assertions,359FE,build/assets42refs/33preloads×3profil,Pint/diffcheck lulus.10DOCX/13halaman render semuanya diperiksa; Chrome PP modal1440,0JSerror/0API,nativeWord dan PDF4halaman nonblank/blankTTD. Fixture-only; bukan MySQL/aaPanel/akunlive/Worddesktop/cetakfisik/loadtest. BackupSQLite demo233472byte sebelum edit; tidak liveits/env/Dompis/deploy/migrate/restartworker. Migration disiapkan, belum diterapkan agen. [Perilaku/penerapan](docs/USER_JABATAN.md), [bukti](docs/qa/user-jabatan-20261118.json).


## 18 November 2026 — Perbaikan jabatan pada preview PP lama yang sudah Sah

Pengguna sudah mengisi Jabatan akun tetapi preview PP Sah masih memakai label template. Penyebab kode: signaturePerson hanya membaca users.jabatan pada draf; PP lama yang belum menyimpan signatureUsers.job/actorJob selalu jatuh ke row.job. Koreksi terbaru mengizinkan fallback presentasi dari akun aktif dengan userId dan role yang tepat serta Branch PP, hanya bila snapshot/keputusan belum memiliki jabatan nonblank. Jabatan historis nonblank tetap menang. Identitas nama/NIK/tanggal/actual approvals, status Sah, EVP dan dokumen kontrak/kasbon tidak diubah; tanpa backfill/SQL write/read tambahan/migration/worker restart. Snapshot userId yang tidak cocok diabaikan; akun nonaktif, beda role/Branch atau hilang memakai template. Draf tetap menampilkan jabatan akun terkini. Karena Word/cetak PP mengambil DOM preview yang sama, teks jabatan mengikuti hasil resolver yang sama dan TTD cetak tetap kosong. Setelah pembaruan aset, buka ulang halaman sekali untuk mengambil metadata jabatan akun terkini; edit akun tidak mengubah revision transaksi otomatis.

Pengujian:360frontend dan327PHP/8061assertions isolated SQLite memory, TypeScript/Vite,assets42manifest/33preload×3profil,diffcheck lulus. Regresi baru PP Diajukan/Sah × absent/empty/whitespace job, actual decision/name unchanged, read-only render tanpa mutasi state, akun aktif/role/Branch/snapshotID mismatch, saved-job/decision-job priority dan EVP tetap. Pure presentation dari users authorized yang sudah dimuat, tanpa API/query baru atau per-row lookup baru. NativeWord/print suites existing lulus; tidak menjalankan browser/Word/PDF render baru untuk koreksi satu resolver ini. Tidak mengubah its aktif/env/Dompis/workflow/arsip/akun/server atau menjalankan migration/deploy. [Aturan jabatan](docs/USER_JABATAN.md), [bukti](docs/qa/pp-legacy-jabatan-20261118.json).


## 18 November 2026 — Kolom tanggal VIII. PENUTUP PP dikosongkan

Pengguna meminta kolom TANGGAL pada tabel penandatangan PP kosong. Sel tanggal seluruh baris akun dan EVP kini benar-benar kosong (tanpa tanggal, dash, input, atau Menunggu tanggal BA KH) pada form dan preview. Header TANGGAL tetap. Word/cetak mengambil DOM PP yang sama sehingga sel tanggal juga kosong; nama/NIK/jabatan, tabel/ukuran dan TTD basah tetap. Tanggal keputusan dan referensi EVP satu hari sebelum BA KH tetap tersedia dalam model/riwayat; tanggal di cap pada kolom TANDA TANGAN terpisah tidak diubah oleh permintaan yang khusus menyebut kolom TANGGAL. Notice editor tidak lagi menjanjikan tanggal referensi pada kolom. Header PP, jadwal, timeline dan tanggal dokumen lain tidak berubah.

Perubahan presentasi saja pada RiskQualitySection,0query/API/schema/migration/runtime dependency/backfill/live mutation; memakai resolver dan loaded account/deal data yang sudah ada. Regresi existing diperbarui memeriksa lima sel tanggal kosong pada form/preview, EVP/approval mark tetap, serta referensi kalender dan state/history unchanged. Verifikasi suite/build dan batas dicatat pada docs/qa/pp-closing-empty-dates-20261118.json. Tidak mengubah its aktif/.env/Dompis/worker atau deploy.

Hasil final: **327 tes PHP/8061 assertions**, **360 tes frontend**, TypeScript/Vite dan assets42refs/33preloads×3profil serta diffcheck lulus. Backend tidak berubah; tes menggunakan SQLite memory. Tidak ada pengujian browser/Word/PDF render baru pada perubahan ini.


## 18 November 2026 — Cap tanpa tanggal dan cap otomatis pengaju/EVP kasbon

Tanggal di seluruh cap APPROVED PP dan kasbon dihapus dari presentasi. Kolom TANGGAL PP tetap kosong; tanggal normal berkas/kota, keputusan aktual dan referensi kalender EVP tetap tersimpan. Ini menggantikan ketentuan sebelumnya yang mempertahankan tanggal pada cap terpisah, tanpa mengubah audit lama.

Pengaju kasbon mendapat cap Persetujuan otomatis hanya setelah UUID kasbon tersebut memiliki catatan penerbitan issuedBy/issuedAt, bukan pada form belum diajukan. EVP kasbon mendapat cap otomatis hanya setelah keputusan Setujui nyata Mgr Finance & Settlement untuk UUID aktif, akun snapshot dan assignee terpilih cocok, pada pengajuan aktif. Reissue, keputusan versi lain, akun/role berbeda, draft/ditolak/dikembalikan atau Finance return tidak membawa cap EVP sebelumnya. Kasbon-v1 yang dialihkan secara eksplisit memakai keputusan legacySettlementReview nyata dengan UUID dan printSigner Manager yang sama; penunjukan penandatangan fisik saja tidak cukup. Arsip boleh memperlihatkan fakta pengaju yang pernah menerbitkan, tetapi tidak diberi cap EVP otomatis dari versi aktif.

Cap administratif memakai field presentasi automatic terpisah dari approved: tidak membuat akun/keputusan EVP, tidak menambah tahap approval, tidak mengubah snapshot terenkripsi, uang, status, riwayat, akses Branch atau tahapan Finance. Maksimum kasbon template3 menjadi lima cap keputusan nyata ditambah dua cap otomatis; susunan fisik tujuh slot2/3/2 dan tujuh approval aplikasi tetap. PP EVP tetap mengikuti aturan otomatis PP sebelumnya, bukan gate Finance kasbon. Word native dan cetak/PDF, termasuk paket gabungan, tetap mengosongkan area tanda tangan; label Persetujuan otomatis membedakan cap administratif dari keputusan akun.

Perubahan hanya resolver/render React yang memakai metadata authorized yang sudah dimuat,0SQL/API/schema/migration/dependency baru, tanpa GET backfill/live write. UUID aktif O(1), pencarian keputusan maksimal tujuh tahap; pencarian arsip hanya pada history existing bila arsip dipilih. Tidak menyentuh its aktif, .env, Dompis, worker, server atau flow Operation.

Verifikasi:327 tes PHP/8061 assertions menggunakan SQLite memory,363 tes frontend, TypeScript/Vite, assets42manifest/33preload×3profil dan diffcheck lulus. Regresi mencakup penerbitan aktual, Manager sebelum/sesudah approval, UUID/assignee/role/return/rejection/reissue/arsip, legacy supplemental review, state tanpa mutasi dan Word XML tanpa cap. Browser Chrome fixture sintetis18kasus (PP/sebelum Manager/sesudah Manager ×375/768/1440 ×light/dark) menghasilkan0JSerror/0APIrequest/0pageoverflow; screenshot ketiga skenario diperiksa. Dua PDF/dua halaman nonblank dirender dan seluruh PNG diperiksa, cap tidak tercetak, area TTD kosong. NativeWord diuji XML/suite, tidak render ulang DOCX pada perubahan ini. Bukan pengujian akun live/aaPanel/MySQL/concurrency/load/MicrosoftWord/cetak fisik. [Bukti terstruktur](docs/qa/automatic-preview-stamps-20261118.json).


## 18 November 2026 — CRUD katalog Peran Pengguna setelah Penyimpanan

Superadmin mendapat tab Peran Pengguna setelah Penyimpanan: tambah kode/nama, edit nama, hapus peran tambahan tidak terpakai, pencarian, pagination20, ikon/tooltip dan konfirmasi YA/TIDAK. Pengguna mengonfirmasi tahap ini hanya katalog; peran tambahan belum dipilih pada akun dan belum mengubah hak akses/approval/flow Operation. Kode role tetap, peran bawaan dilindungi, users/assignment historis mencegah hapus. Nama katalog terpisah dari label otoritas AccountAccess dan Jabatan dokumen.

RoleCatalog/API memakai auth/active/Superadmin/CSRF/throttle, lock provisioning bersama akun, actor recheck, fingerprint versi, UUID+payload+actor-bound replay dan audit before/after existing master_data_changes entity role dalam transaksi yang sama. ID role yang pernah dihapus tidak dipakai ulang. Loading/abort/no-store/timeout, konflik mempertahankan input, hasil tak pasti tanpa retry otomatis. Tidak menyentuh SQL workflow, keputusan, uang, arsip, private data, .env, its aktif, Dompis atau worker; tanpa schema/migration/dependency baru. [Aturan, arsitektur dan penerapan](docs/ROLE_CATALOG.md).

Performa:3query service daftar/4HTTP termasuk auth,20+1 metadata dan2group counts, tanpa query perbaris. Fixture30extraaccounts tambah9/edit8/hapus9/replay4query. Pencarian contains/pagination bukan jaminan indeks atau benchmark beban. Regresi CRUD/audit/replay/rollback/version/refs/role/CSRF dan query lulus. Browser sintetis6kasus375/768/1440×light/dark memeriksa tab lazy,20rows/pagination/search, tambah/edit/hapus, YA/TIDAK, code lock, delete protection, stale-input dan uncertain-no-retry:0JSerror/0pageoverflow. Screenshot desktop/mobile/dark diperiksa. Bukti suite/build dan batas aktual ada pada [QA](docs/qa/role-catalog-20261118.json); belum menguji akun nyata/server/MySQL/concurrency/load.

Verifikasi akhir: **335 tes PHP/8187 assertions**, **366 tes frontend**, TypeScript/Vite, assets42manifest/33preload×3profil, scoped Pint dan diffcheck lulus. Regresi katalog8tes/126assertions termasuk query mutasi konstan; enam skenario browser sintetis lulus. Tanpa penerapan migration/live SQL/server.

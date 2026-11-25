# Optimasi query dan respons aplikasi

## 25 November 2026 — Budgeting Mandor tanpa query tambahan

Canonical totals already produced by PP domain reused for grossMandorM/J/total/BOP. New-release planner builds loaded exact-parent maps only when BAPPs exist, returns before accepted-term scan when no own release, then O(terms+actuals+released+Mandorrows), no perrowSQL/catalog/private lookup. Legacy branch untouched. UI amount changes reuse scoped reference result; actual interactivebrowser1GET for all Mandor volume/price edits,0write. Same formal67/reference6/own7/mapping10012HTTP fixtures; not latency or productionload proof. No API/schema/migration/dependency/poller or crossrequest authorization cache. [Rules/tests/limits](BUDGETING_PID.md).

## 25 November 2026 — Finance signed scan readiness

Readyhelper uses loaded term and≤7actualroledecisions, onecomputedUIvalue shared forupload/verify;0query/API/schema/dependency/poller. Controller rejects wrongrole before expensiveworkspacegraph, domainfreshrole remains underlock. Existingfile/projection/receipt transaction reused; measured55HTTPupload atFinance stage forConstructionPartial/Final andOperation, no before/afterlatencyclaim. NewFlowverifydoesnotrewriteactualSQLdecisions; no perrowlookup/privatefetch. Existing500BOQ59query/500BARP50query/full20PP353vs8/archive3SELECT tests retained. [Evidence and limits](FINANCE_SCAN_UPLOAD.md).

## 25 November 2026 — Pagination master dan batched Branch inference

SQL count scoped/filter tambah1query/list, offset+limit size+1 dengan allowlist10/20/50/100; items/usage tetap satuquery masing-masing untuk halaman. KatalogBranch aktif dibaca sekali secara locking dalam transaksi impor; lookupkode in-memoryMap, reserved conflict≤1query/500PID (bukan perbaris), existingwritebuffers100PID/50items tetap.5192PIDpreview49/commit780/list7 vs37/768/6 terdahulu; page10–100≤8HTTPqueries. UImax100DOM/localpreview, sourcekey+abortsize/resetselection dan loadeddata reuse;0newpoller/dependency/API/schema. Import masih serialized/sinkron120s, belum bukti file40k dapat selesai sebelum timeout atau server bebasbottleneck. [Kontrak dan batas](BUDGETING_PID.md).

## 25 November 2026 — Changed BA RP actuals dan PP confirmation

Actuals membership max500ID exactcontract, detail upsert100 quantity-only; unchanged actuals skipped. Real authenticated500row/1000component fixture **3038→50HTTPqueries / 3001→13detailqueries**, existing998IDs retained after removal and latebatchfailure fullyrolledback. No schema/index/API/poller/dependency or money/ledger/approval changes. Raw canonical command hash reused across transaction retries; PP receipt marker uses existing JSON/loaded graph, confirmation remains18vs17HTTP (one indexed receipt SELECT). No perPPquery. Full528PHP/491frontend plus isolated smallMariaDB and mockedAPI1000graph browser; these do not measure aaPanel latency or productionload. Workspace graph/serialization/lock and remaining contract loops still need profiling under real load. [Evidence/bounds](RUNTIME_SAFETY_AUDIT.md).

## 25 November 2026 — Baseline SPK pada BA RP

Contract M/J/Total dan currentactuals dihitung pada loop deal.rows yang sama; actual lookup sekali/baris dan hasMaterial/hasService sekali di loop mengganti repeated.some. Tidak memanggil SPK renderer penuh, katalog/Owner/root/sibling atau private fetch; newSQL/API/poller/schema/dependency0. Baseline moneyPolicy deal sama officialSPK, actualspolicy tetapterm. HTML/nativeWord/combinedpackage reuse samepuremodel. Full525PHP dan490frontend/assets lulus; nativeWord/browserPDF2+2halaman diperiksa. Ini bukti fixture, bukan benchmark loadserver. [Audit/batas](qa/barp-spk-baseline-20261125.json).

## 25 November 2026 — Rincian dua komponen kasbon

Pembagian Jasa bersih dan Material memakai quote existing satu kali; TSBigInt/PHPBCMath O(1), metadata matching maksimal20row lama/formbaru2. Tidak ada percomponent SQL/privatefetch/endpoint/dependency/poller/bootdata baru. Issue typed tetap satu transaksi/revision/payeever/receipt dan batch signer existing; kasbon-v1 fixture44HTTPqueries dengan budget48, bukan perbandingan throughput/server. Full existing paid/private/Branch/unit/PID/volume guards diuji bersama. Preview HTML/nativeWord/paket reuse saved rows tanpa SQL. [Audit/batas](qa/kasbon-components-20261125.json).

## 24 November 2026 — Budgeting PID stage4 measurement boundaries

Master UI reuses same20+1SQL payload and20DOM; merges PID/name rather than expanding list, Set-selected once, warnings only expanded in detail. No new SQL/endpoint/poller/privatefetch/boot graph/dependency. Existing source-key/abort prevents old unit/page data reuse; import completion carries chosenunit. Stage3 reference6/own7/masterlist6/mapping10012HTTP remain historical fixture measurements, not new MariaDB throughput numbers.

Actual offline MariaDB10.4.28: two independent concurrent submissions at same workspace revision produced200/409, fresh explicit retry422 budgetcap. Separate2connection RRprobe ordinary SUMsnapshot7 vs lockingcurrent11 after330ms; globalmasterlock serialized correctly. Construction paid reservation kept, authorizedreleasecreditsonlyunusedJ, Operation5/Region flows and repeated populatedmigrationhash24 passed. This does not establish production capacity/deadlockfreedom/crossworkspace/highload/aaPanel latency. Fullsuite521PHP/15278assertions114.356s/140.50MB onXAMPP8.2.4, frontend484, actualApp mockAPI3widths2themes noJSerror. [Audit/bounds](qa/budgeting-pid-stage4-20261124.json).

## 24 November 2026 — Revisi ROUND khusus PPh

Tax-round-v4 mengganti tahap arithmetic saja: satu passsavedrows/0SQL, exactBCMath/BigInt, no extra lookup atau rounding nilai lain. Legacyv1/v2/v3 resolusi in-memory per policy; no GET reprice/PIIfetch/poller/schema/dependency. Actualissue45HTTP/settle41HTTP tetap; full520tes menjaga existing500BOQ59query danarchiveexclusion3SELECT. Bukan pengukuran latency/loadserver. [Kontrak](KASBON_PPH_PAYMENT_LIST.md), [audit](qa/pph-tax-round-v4-20261124.json).

## 24 November 2026 — ROUND hanya Jasa net kasbon

Quote tetap satu lintasan loaded saved agreement×actuals/0SQL; BCMath/BigInt hanya mengubah tahap pembulatan ke totalJasa setelahPPh, Material langsungditambah. Policyv1/v2 dipilih per savedquote, tidak lookup tambahan/reprice/mutasi GET. UI notice memakai summaryauthorized, tanpa fetch/rowquery/poller baru. Existingissue45HTTP/newnetsettle41HTTP padafixture; full517tests mempertahankan500BOQ59query danarchiveexclusion3SELECT. Ini perubahan arithmetic, bukan bukti throughputserver. [Kontrak](KASBON_PPH_PAYMENT_LIST.md), [audit](qa/pph-service-net-round-20261124.json).

## 24 November 2026 — Simpan draf dan respons terlambat

NewrootDraft tidak memiliki saldo lama untuk dipindah; dua SELECT movement seluruhworkspace sebelum/sesudah save dihilangkan khusus absentroot. Pembacaan scoped existing balances untuk membangun state/proyeksi tetap wajib. Empat real queued26BOQ Construction/Operation×duaalias66→64HTTPquery; existing edits/approval/continuation audit scans tetap. Recovery hanya satu GET state bila respons tidak pasti, +1uniqueUUIDreceipt SELECT (18vs17HTTPfixture),0extraquery normalGET; rare metadata-race menambah1lightweightSELECT sebelum satu payload. Tidak menggandakan write/loopretry/poller, tidak menambah schema/index/dependency. TimeoutPP60s/recovery30s tidak membuktikan server lebih cepat; graph/bootstrap/hash/lock dan infrastruktur masih perlu profiling fisik. [Kontrak/deploy](PP_DRAFTS_PREVIEW.md), [audit](qa/pp-save-confirmation-20261124.json).

## 24 November 2026 — Draf baru dan konflik global revision

Pengecualian absent-root-Draft memakai full projectgraph yang sudah dimuat pada transaksi command; satu scan ID tanpa SQL tambahan. Tidak preflight GET/retryPOST/refreshpoller dan tidak melemahkan conflict check dokumen existing. Empat real queued26BOQ HTTP fixture Construction/Operation × saveProject/saveProjectBundle mencatat66query per save, budget80; bukan perbarisquery atau pengukuran server. Fullsuite506tes mempertahankan500BOQ/1000components59query serta archive524304bytes excluded/3runtimeSELECT. Konkurensi fisik MySQL/OPcache/server belum diuji. [Aturan](PP_DRAFTS_PREVIEW.md), [audit](qa/pp-draft-concurrency-20261124.json).

## 24 November 2026 — Biaya terukur ledger Budgeting PID tahap 3

Budget read tidak membangun graph PP/BOQ historis/arsip/private. List20+1 menambah satu grouped query usage dengan join berkolasi DB; reference memakai indexed SUM, exactownPP satu metadata lookup. Writecapacity memakai current FOR UPDATE maksimal500moneyrows/page setelah sharedcontrol lock untuk menghindari REPEATABLE READ snapshot lama; tidak SUM nonlocking setelah menunggu. Impor menambah satu batched identitycheck/500PID, mapping satu batched reservedBranch check. Planner earlyreturn unrelated approval/payment tanpa query tambahan, sementara budgetcommand retry menambah satu auditread. Singleton menserialkan import/mapping/allocation: konsistensi diprioritaskan dan throughput fisik belum diukur.

Fixture authenticatedHTTP: reference6/own7, mapping10012, list6, import5192preview37/commit768, formal2BOQpackage67. Existing500BOQ/1000componentswrite59; archive524304bytes tidak terambil di3runtimeSELECT. 501allocation current-read diuji2pagequery; physical MySQLrace/deadlock/load belum diuji. UI lazy/scoped350msabort dan20DOM tetap, tanpa dependency/poller/masterboot baru. [Kontrak](BUDGETING_PID.md), [audit aktual](qa/budgeting-pid-stage3-20261124.json).

## 19 November 2026

Permintaan: mengurangi beban query/script agar aplikasi lebih lancar tanpa mengubah data, tampilan, nominal, hak akses atau alur bisnis. Optimasi dikerjakan pada kode lokal; tidak menjalankan migration, mengubah `.env`, menyentuh its aktif, worker hidup, server aaPanel atau proyek Dompis.

### Pembacaan workspace

`PrototypeWorkspace::runtimeQuery()` memilih id/owner/revisi/status aktivasi/timestamps saja. Jalur workspace HTTP, lock perintah, enqueue/proses upload dan lock dokumen Finance memakai proyeksi ini. `prototype_workspaces.state` tetap arsip utuh dan tidak dimuat oleh jalur tersebut. Inisialisasi/aktivasi atau pemeriksaan arsip eksplisit tetap dapat memakai model penuh; tidak menambahkan global scope yang mengganggu maintenance.

Fixture arsip 524.304 byte memastikan tiga SELECT workspace pada GET state + command tidak memilih `state` atau `*`. Arsip database setelah command identik. Ini pengurangan data yang dipilih, bukan klaim pengurangan jumlah SELECT atau hasil ukur memori server produksi.

### Penyimpanan PP dan BOQ

Lookup Branch/Unit/header existing dibatch hanya untuk PP berubah. Header membaca created_by/created_at saja dan dibagi maksimum500ID. Nama Branch tetap pencocokan uppercase; lookup business Project mempertahankan pembandingan database existing dan dimemoisasi per kode persis dalam satu write. Cache tidak dipakai lintas request/akun/worker; perubahan penugasan/harga tetap dibaca ulang seperti semula.

BOQ lines dibatch50baris, komponen100baris; jadwal5tahap dan empat cost memakai upsert dengan unique key existing. Tim dan rincian anggaran tetap mengikuti kebijakan replace existing, tetapi insert dibagi100baris. Parent BOQ line ditulis sebelum komponen. Nilai nol/nullable, ID deterministik, source Excel, snapshot harga, tanggal, urutan kategori, created_by/created_at dan penghapusan BOQ yang benar-benar dilepas mengikuti aturan lama. Tidak mengubah tipe/schema/indeks.

MySQL mempertimbangkan semua unique key saat upsert: satu preflight terbatasi memeriksa existing identity komponen terhadap exact BOQ line/component. Konflik ditolak, tidak dialihkan ke identitas lain. Transaksi mengembalikan perubahan parent/jadwal/anggaran yang sudah dilakukan ketika terjadi kegagalan. Guard domain, Branch/Region/unit, lock workspace, revisi/receipt, approval, ledger, nominal net/bruto/PPh, kasbon UUID, private file, status paid dan riwayat tetap.

Hash before/after audit dan receipt dihitung sekali masing-masing pada command yang sama. Format serialisasi/hasil hash tetap identik; menghindari dua serialisasi penuh yang sebelumnya berulang, tanpa melemahkan audit atau mengganti snapshot.

### Pencarian dan hitungan inbox

App memakai memo berdasarkan referensi state untuk hitungan inbox, dan state+query untuk pencarian. Tema, drawer dan panel tidak memicu perhitungan ulang saat state tetap. Aturan count tetap satu PP/tugas dan OR per pengajuan, mengikuti actual actor/current kasbon/legacy review/Finance/Operation existing.

Pencarian menjaga urutan PP dahulu lalu SPK dan maksimum6hasil. Hasil dihentikan setelah enam cocok; tidak membuat semua objek hasil lalu membuang sisanya. Pencocokan SPK memakai Map exact dealId, bukan scan semua deal per SPK. Blank query, case, spasi, label/URL dan cakupan graph authorized tetap; referensi terlepas tidak membuka dokumen pengganti. Tidak menambahkan cache akses lintas akun, API, private fetch atau poller.

### Pengukuran dan batas

- Fixture20PP, full write: **1.330 → 353 query**; incremental metadata tetap8query. Ini bukan satu aksi UI normal dan bukan simulasi pengguna bersamaan.
- FixturePP baru500BOQ/1.000komponen: **59query** (batas90), termasuk proyeksi/ledger/metadata. Simpan ulang/perubahan qty/removal mempertahankan jadwal existing dan nilai tepat.
- HTTP fixture PP lengkap existing: **98 → 75query**; perubahan anggaran **84 → 55query**. Budget dan fixture lama tetap dipertahankan.
- Fixture repeat-write500BOQ sebelum/sesudah batching membandingkan enam tabel proyeksi secara byte-equal; angka aktual dicatat pada QA JSON. Source baseline sementara memakai lookup metadata yang sudah dibatch, jadi perbandingan ini mengisolasi penulisan children.
- Pure search regression10.000PP memastikan hanya6PP dibaca saat enam hasil awal cocok dan koleksi SPK/deal tidak disentuh. SPK5.000record exact join dan count seluruh peran dibandingkan dengan perilaku lama. Bukan klaim latensi jaringan/browser produksi.
- Browser App asli dengan1.000PP/deal/SPK/pengajuan sintetis: daftar20DOM, pencarian6PP/1SPK exact, tema/notifications,375/768/1440light-dark,0POST/0JSerror/pageoverflow. Seluruh jaringan eksternal diblokir, transport mock; bukan login/live dataset/load test.

Rincian hasil otomatis dan artefak: `docs/qa/performance-optimization-20261119.json`. Word/PDF tidak dirender ulang karena model/generator/tampilan dokumen tidak berubah.

Bootstrap masih memuat graph/referensi authorized existing; command masih memvalidasi graph dan memegang lock workspace bersama untuk atomicity. Histori event/approval dan audit tetap lengkap. Pagination server per modul atau perubahan strategi lock adalah pekerjaan arsitektur lanjutan yang membutuhkan profil beban server nyata, bukan diubah diam-diam dalam optimasi ini. Tidak ada jaminan aplikasi bebas bottleneck pada semua kapasitas/hardware.

Penerapan fitur ini tidak memerlukan migration baru. Operator menerapkan source+build aset dan memuat ulang worker Area3 dengan prosedur deploy proyek agar web/daemon memakai kode yang sama. Tidak memerintahkan reset/seed/refresh database atau menghapus antrean.

Verifikasi akhir optimasi: **372PHP/9122assertions**, **412frontend**, TypeScript/Vite/Pint/diffcheck/assets46refs/37preloads×3profil lulus. Tambahan benchmark sementara terisolasi1test/1assertion: repeat-write500BOQ **3044→48query**, enam tabel proyeksi byte-identik pada fixture hangat/jamdibekukan; baseline sebelum childbatching/sesudah lookupbatching. Full-write20PP1330→353, incremental8tetap, creation500BOQ1000komponen59query. Archivefixture524304byte tidak dipilih tigaSELECT runtime, tetaputuh setelahcommand. BrowseractualApp1000record state/transportmock:20DOM,6PP-first/exactSPK,theme/notif,375-768-1440lightdark,0POST/JSerror/overflow; screenshotdesktopdark diperiksa. BukanMySQLEXPLAIN/server/akunnyata/concurrency/load/WordPDFulang; bootstrapgraph/fullvalidation/workspacelock/histori tetap. Tanpa migration/liveits/env/server/worker/Dompis/commit/push/deploy. Bukti: `docs/qa/performance-optimization-20261119.json`.

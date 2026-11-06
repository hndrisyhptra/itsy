# Laravel conversion checklist

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

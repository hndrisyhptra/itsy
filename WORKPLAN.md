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

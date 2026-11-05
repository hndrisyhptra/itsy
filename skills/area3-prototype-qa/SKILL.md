---
name: area3-prototype-qa
description: Test the its Area 3 interactive prototype through the linked business scenarios, permissions, persistence, financial/volume invariants, documents and responsive layouts.
---

# area3-prototype-qa

## Rejection resubmission verification — 5 November 2026
For v3 PP and payments, test rejection by each of the five selected approvers, mandatory notes, Admin-only correction/resubmission, restart from PM, retained numbers and rejected SQL cycles/decisions, no approval before resubmission or skipped approver, and work-evidence replacement with retained history. Rejection must not unlock scan/Finance/APM, the next term or premature BA PP. Keep historical v1/v2 terminal rejection tests. See docs/APPROVAL_RESUBMISSION.md.

## Current storage authority — 5 November 2026
The user explicitly authorized active MySQL persistence. docs/MYSQL_RUNTIME.md supersedes older aggregate/demo storage descriptions below. Runtime transactions read/write business-table rows with per-document snapshots; prototype_workspaces.state is a preserved archive, never a fallback. Preserve atomic SQL records/revision/receipts/audit, actual five-approver decisions and exact ledger. Live sample BOQ, role switch and reset are unavailable. Tests remain fail-closed SQLite memory; disposable MySQL QA is separate from artisan test. Do not import the archive again after activation or invent historical identities/KHS contracts.

## Current BOQ/draft authority — 4 November 2026
The supplied OSP FO workbook now defines a real private .xlsx import (docs/BOQ_IMPORT.md). Laravel parses sheet BoQ/header rows 6–7, positive quantities, exact package/code/unit matches and separate M-/J- components. Preserve source prices/row provenance; KHS prices own totals. Require explicit mismatch review at the current KHS version. Never evaluate input formulas or accept client manifests. Only the separate sample button remains simulated. Drafts may omit approvers; submitted PP must have all five selected active Branch-assigned accounts. Older pending-import descriptions below are historical.

## Current v3 authority — username and five approvals (4 November 2026)
The latest user request and docs/WORKFLOW_V3.md override two-stage descriptions below for new documents. Create PP as Admin Konstruksi, then selected PM → Admin Area → Mgr Deployment → Mgr Bussiness Support → Head of PMO. New payments freeze those five IDs, unlock print only at 5/5, then require a real private wet-signature scan and separate Finance approval bound to that scan before APM/full settlement. Preserve both v1 and v2 historical decisions; future terms on old PPs require explicit five-approver configuration. Login is username-based; users metadata includes NIK/Region/Branch/Service Area/role with validated placement and matching SQL assignments. Never fabricate NIK, location or signatures. Primary placement does not expand Branch permissions. Prior descriptions below are historical regression guidance where incompatible.

## Purpose
Validate observable business behavior, not screenshots alone. Source: `resources/js/area3/` and `app/Services/`; automated invariants: `tests/Frontend/` and `tests/Feature/Prototype*`; manual steps: `docs/LARAVEL_QA.md`.

## Current workflow
Use `docs/WORKFLOW_V2.md` for new transactions. Test BOQ-first PP, generated numbering, assigned PM/Manager approvals for PP and payment, real private scan upload, Finance verification and APM full settlement. Keep the original v1 suite as historical regression coverage. The older manual scenarios below apply only where compatible with v2.

## Checklist
- Run PHP XAMPP `artisan test --compact`, `npm test` and `npm run build` here. PHP tests use SQLite in-memory. Never migrate Dompis Prov.
- Exercise every sidebar route and required create/detail/edit route, breadcrumb, search, status/Branch/date filters, pagination and contextual links.
- Create BOQ-first PP with a template file selector and explicit simulation label until mapping is available; keep sample data separate from file contents. Complete all seven sections, verify generated number (`0001/LG.000/TA-03R424/11-2026` for branch suffix R424 in November 2026), save/reload and approve through the assigned PM/Manager. Test Jakarta month boundaries, no year reset, retained issued/custom numbers, missing required data and duplicate number rejection.
- A: Simulasi Import Demo → price mismatch → review with note → Final. Verify finalization is blocked beforehand and no arbitrary file parsing is claimed.
- B/C: assign Material/Jasa separately, compare SPH/offer/agreement, exceed each KHS component ceiling, issue BA KH with evidence, create and attest matching SPK.
- D: actual volume → preview BA RP → submit → assigned PM → assigned Manager → print/wet signature → private scan upload → Finance verification → APM/full settlement. Test wrong role, required return/reject reason, locked actuals, history and inbox counts.
- For v2, APM must require a Finance-verified scan and settle the full bill once; only then can the next Partial term open. Keep separate/partial payments only as v1 regression coverage.
- Test Term 3 Final and early Final blocks later terms. Historical quantities remain read-only.
- E: Final approved → BA PP evidence/attestation → selected component release → automatically numbered continuation → verify Parent/Root/source, reserved volume and unchanged consolidated budget.
- Check BOQ, BA KH, SPK, current-term and cumulative BA PP totals against shared data. Preview all nine document types and print HTML. Do not invent signatures.
- Change KHS: affected draft BOQ needs review; issued historical snapshots remain unchanged. Superadmin user CRUD is separate from formal approvals.
- CSV exports reflect filters and contain actual generated data. JSON backup and reset confirmation work.
- Save form drafts, workflow events, APM, release and continuation; reload and use browser back/forward without losing state.
- Inspect 375, 768 and 1440px, light/dark, keyboard focus, modal trap/Escape, mobile drawer, table scroll, error/loading/empty/disabled reasons. Reset temporary viewport overrides.

## Acceptance and reporting
All primary actions must execute a real mock transaction or navigation, not a toast pretending to perform work. Report actual automated count, browser scenarios verified, untested paths and backend limitations separately. The offline role picker is not authentication. Login uses SQL users/roles/Branch assignments and a shared, Branch-scoped workspace on the configured its database. Test account switching through actual login/logout. Preserve old session-owned workspaces separately. localStorage holds drafts/theme only. Aggregate storage is not the final production schema. Never claim credentials or external integrations were tested.

## Account mode (4 November 2026)
Authenticated actions follow SQL account role and Branch assignments. Use real login/logout for PM, Manager and Finance; selected PM/Manager IDs must match the logged-in actor. Live reset/role-switch/demo-user/master commands are forbidden. Superadmin manages accounts and global numbering. Source masters are read-only; workflow transactions remain JSON aggregates. Use isolated tests or a marked disposable database for browser QA, never seed test credentials or synthetic transactions into the user database. BOQ Excel import follows docs/BOQ_IMPORT.md; other layouts remain unsupported and the separate sample button remains simulated.

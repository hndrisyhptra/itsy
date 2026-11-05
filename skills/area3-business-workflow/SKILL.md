---
name: area3-business-workflow
description: Implement its Area 3 Project Plan, BOQ, mandor contracting, assigned two-stage approvals, scan verification and full APM settlement, APM gating, BA PP and continuation state transitions.
---

# area3-business-workflow

## Queue/performance authority — 6 November 2026
Follow docs/UPLOAD_QUEUE_PERFORMANCE.md. Active UI queues BOQ and work evidence in the database; show recoverable pending/completed/failed status without presenting accepted bytes as parsed BOQ or attached proof. Recheck active account/Branch/term status in the worker, retain real audit/private paths and block submission while related uploads are pending. Tests may process jobs in isolated memory but must not start detached workers. Incremental writes must preserve unchanged snapshots, affected-root balances and cross-record projections; SQL Branch scoping uses each PP's branch_id, including PP sharing one business Project ID. Verify idempotency/retries/cleanup, cross-Branch access and previous workflow invariants. Bundle/query measurements are scoped evidence, not a load-test guarantee.

## Master management authority — 6 November 2026
The user authorized real SQL master CRUD. Follow docs/MASTER_DESIGNATOR.md, superseding older read-only-master guidance. Keep Master Designator as one of nine parents, with Designator/KHS/Paket KHS submenus and accessible icon tooltips. Only Superadmin mutates SQL masters; other roles read. Preserve soft-deleted references, used identities, issued snapshots and archive; use shared locks/revisions, reference-only metadata writes, receipts and before/after master audit. Mgr Project Support is a real account role, not an extra approver. Verify CRUD/permissions/CSRF, zero prices, concurrency/replay, historical preservation and current-version BOQ review using isolated memory tests.

## Header-only draft and PP preview authority — 5 November 2026
The latest request permits a v3 Draft after complete Header, without BOQ, positive/complete HK, risk or assigned approvers. Submission retains all requirements. Preserve valid entered data and source validation, start new PP forms clean, and clear/navigate to the list only after server confirmation. BOP cap/Cost Structure fields and requirements were removed. Preview stamps represent actual selected-user approvals in the current cycle; print must leave the signature column blank for wet signatures. Never invent decisions or signatures. See docs/PP_DRAFTS_PREVIEW.md.

## Automatic BA KH/SPK numbers — 5 November 2026
Active account new/draft documents derive their number from the selected saved PP: insert -01 for BA KH or -02 for SPK before the first slash. SPK must derive from PP, never append to the BA KH number. Laravel ignores client/cached numbers; React shows matching read-only values and previews. Preserve already Terbit/Sah numbers, uniqueness, PP numbering counters, snapshots and all other workflow gates. See docs/CONTRACT_NUMBERING.md.

## Rejection resubmission authority — 5 November 2026
The user changed v3 rejection behavior. Rejected PP/payment submissions require a note and return to Admin correction/resubmission on the same document. Preserve the rejected status until a draft save/resubmission, immutable decision history and document numbers. All five active approvals restart from the selected PM only after the Admin resubmits; never reuse previous-cycle approvals. Permit v3 rejected work-evidence correction under the existing Admin/Branch/file rules. Keep v1/v2 historical rejection behavior unchanged. Follow docs/APPROVAL_RESUBMISSION.md; older terminal-rejection guidance below applies only to historical versions.

## Current storage authority — 5 November 2026
The user explicitly authorized active MySQL persistence. docs/MYSQL_RUNTIME.md supersedes older aggregate/demo storage descriptions below. Runtime transactions read/write business-table rows with per-document snapshots; prototype_workspaces.state is a preserved archive, never a fallback. Preserve atomic SQL records/revision/receipts/audit, actual five-approver decisions and exact ledger. Live sample BOQ, role switch and reset are unavailable. Tests remain fail-closed SQLite memory; disposable MySQL QA is separate from artisan test. Do not import the archive again after activation or invent historical identities/KHS contracts.

## Current BOQ/draft authority — 4 November 2026
The supplied OSP FO workbook now defines a real private .xlsx import (docs/BOQ_IMPORT.md). Laravel parses sheet BoQ/header rows 6–7, positive quantities, exact package/code/unit matches and separate M-/J- components. Preserve source prices/row provenance; KHS prices own totals. Require explicit mismatch review at the current KHS version. Never evaluate input formulas or accept client manifests. Only the separate sample button remains simulated. Drafts may omit approvers; submitted PP must have all five selected active Branch-assigned accounts. Older pending-import descriptions below are historical.

## Current v3 authority — username and five approvals (4 November 2026)
The latest user request and docs/WORKFLOW_V3.md override two-stage descriptions below for new documents. Create PP as Admin Konstruksi, then selected PM → Admin Area → Mgr Deployment → Mgr Bussiness Support → Head of PMO. New payments freeze those five IDs, unlock print only at 5/5, then require a real private wet-signature scan and separate Finance approval bound to that scan before APM/full settlement. Preserve both v1 and v2 historical decisions; future terms on old PPs require explicit five-approver configuration. Login is username-based; users metadata includes NIK/Region/Branch/Service Area/role with validated placement and matching SQL assignments. Never fabricate NIK, location or signatures. Primary placement does not expand Branch permissions. Prior descriptions below are historical regression guidance where incompatible.

## Current authority
The user-approved workflow of 3 November 2026 in `docs/WORKFLOW_V2.md` overrides older flow descriptions. BOQ is the first PP step; PP numbering is server-generated and configurable; selected PM then Manager approve PP and new payments. Finance verifies uploaded wet-signature scans and records APM, atomically settling the full term bill. Preserve version-1 history; do not fabricate decisions or scans. Sidebar contains nine menus with BA KH under SPK and Termin labeled Pembayaran.

## Purpose
Preserve the v2 process: BOQ inside PP → PP submission → assigned PM → assigned Manager → SPH/negotiation/BA KH inside SPK → SPK → payment actuals → assigned PM → assigned Manager → print/wet signature/upload → Finance scan verification → APM/full settlement. Final approval enables BA PP; attested BA PP precedes Admin release and continuation.

## Boundaries and constraints
- Saved commands are validated and persisted by Laravel. Shared workflow mutations live in `resources/js/area3/domain.ts` and `app/Services/PrototypeDomain.php`; UI calls the centralized store transaction. Keep both implementations consistent. The server uses the stored demo role; production requires real authentication and authorization.
- Admin Konstruksi manages PP submission, BOQ, contract documents and volume release. Superadmin is explicitly privileged for those operational actions, but never substitutes for any payment approver.
- New documents: level 1 is the assigned PM, level 2 the assigned Manager. Finance verifies scans and records APM, not a third approval. Version-1 historical documents retain their original approval chain.
- BA KH/SPK/BA PP attestation is a separate document workflow, never a three-level payment inbox task.
- Each SPK has at most three terms. Term 3 must be Final. Any earlier Final blocks all subsequent terms.
- The preceding Partial must have the required approvals plus a valid APM before the next term exists. Full payment is not a prerequisite.
- APM, payment, physical completion, document status and approval status are distinct fields.
- Return/reject requires a reason. Return permits correction and restarts the active approval chain; all prior decisions remain in append-only history. Rejection is terminal for historical v1/v2 only; v3 follows the resubmission authority above.
- Submitted/approved quantities and issued price snapshots are immutable through draft forms.

## Implementation checklist
1. Validate permissions and existing status inside each service before mutation, then calculate on a cloned transaction; persist only on success.
2. PP requires template-based BOQ upload first; retain the clearly labeled file-selection simulation until the official template mapping is supplied. Never replace a user's uploaded contents with demo rows or claim parsing happened. Header starts with ID Project, active Branch, Unit Kerja Konstruksi/Operation and server-generated No PP. Default `{SEQ}/LG.000/TA-03{BRANCH}/{MM}-{YYYY}` uses minimum four-digit padding, Jakarta server time and no automatic reset. Preserve issued numbers and custom formats. Select active PM and Manager; PP must receive both decisions before contracting. Attestation also requires narrative, team, full schedule, risk, communication and quality.
3. BOQ Final and PP Sah precede mandor contracting. BA KH Sah precedes SPK. SPK Sah commits reserved volume and enables term 1.
4. Actual term volume is mandatory. Submission creates PM inbox work, locks fields, logs actor/time and updates counts from the shared state.
5. Finance records and validates a unique APM; corrections cannot invalidate a predecessor after its next term has been created without a future formal correction process.
6. Final approved creates Draft BA PP. Admin attests with evidence reference, releases selected valid remainder, then creates an automatically numbered continuation without a new root BOQ upload.

## Acceptance
Attempt out-of-sequence approvals, wrong roles, missing reasons, fourth terms, next-term creation without APM, release before BA PP Sah, and duplicated PP numbers. Each must fail without partial mutation. Walk scenarios A–E from `resources/js/area3/seed.ts` and `database/demo/` and verify history and inbox changes.

## Account mode (4 November 2026)
Authenticated actions follow SQL account role and Branch assignments. Use real login/logout for PM, Manager and Finance; selected PM/Manager IDs must match the logged-in actor. Live reset/role-switch/demo-user/master commands are forbidden. Superadmin manages accounts and global numbering. Source masters are read-only; workflow transactions remain JSON aggregates. Use isolated tests or a marked disposable database for browser QA, never seed test credentials or synthetic transactions into the user database. BOQ Excel import follows docs/BOQ_IMPORT.md; other layouts remain unsupported and the separate sample button remains simulated.

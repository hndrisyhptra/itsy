---
name: area3-financial-calculation
description: Implement and verify official KHS component prices, agreed BA KH/SPK snapshots, actual term amounts, outstanding payment and consolidated budgets for its Area 3.
---

# area3-financial-calculation


## Mandor agreement price cap — 6 November 2026
Follow docs/SPH_THRESHOLD.md. New/draft agreed Material/Jasa unit prices, as well as SPH, must independently stay within the selected saved PP planBudget.mandor.priceM/priceS for that source row. Equal/zero limits are valid; missing itemized references fail closed and continuations use their own budgets. Preserve KHS caps, legacy PP without itemized budgets and issued BA KH/SPK snapshots. Show max/per-unit hints and accessible immediate alerts on agreement inputs and invalid row badges. Reject over-limit save/generate/attest on Laravel, ignoring client limits/status. Reuse loaded indexed references without new SQL/network lookups; verify HTTP rejection writes no SQL/revision/audit plus PHP/React boundary and historical tests.

## Current Project Support authority — 6 November 2026
Follow docs/PROJECT_SUPPORT_APPROVAL.md. Latest PP/payment order is PM, Admin Area, Project Support, Deployment, Head, including running submissions as explicitly requested. nextApprovalRole finds the first unapproved role rather than indexing by decision count; retain earlier Deployment decisions without approving twice. New documents use server-owned project-support-first and SQL approval_order_version=2; existing SQL run positions/IDs remain original via storedApprovalRoles/order version 1. Migration 2026_11_06_000003 is deployed on its; preserve exact CHECK/rollback restrictions. User-authorized pending legacy assignments were retargeted through audited adoptApprovalOrder using the selected Support account from the latest PP. Do not transfer an existing Business Support decision or prior rejected cycle to another account; guarded adoption refuses this. Closed history, actual identities/stamps and blank print cells remain intact. No new routing query or live test identities.

## Removed BOP cap/reference — 5 November 2026
The user removed the BOP limit and Cost Structure reference inputs and backend requirements. Ignore stale fields on editable saves and preserve locked snapshots. Keep valid nonnegative BOP details, precision, submission completeness, COGS/recap calculations and technical amount limits. No official BOP ceiling is implied. Header-only v3 Drafts may have no BOQ/budget yet; submission still requires complete valid planning. See docs/PP_DRAFTS_PREVIEW.md.

## Automatic PP budget recap — 5 November 2026
Point IV.5 now derives positive category rows from source Mandor Material/Jasa, Non Stock, BOP and separate permit reimbursement. New/editable v3 saves use budget version 2; Laravel ignores client usage/term/date/amount summaries and recomputes them. Do not require manual installments, dates or usage reconciliation. Preserve version-1 schedules on locked submitted/approved documents. COGS remains IV.1+IV.2+IV.3 with permit separate; component ceilings, rounding and continuation allocations remain unchanged. See docs/PP_BUDGET_TEMPLATE.md.

## Current storage authority — 5 November 2026
The user explicitly authorized active MySQL persistence. docs/MYSQL_RUNTIME.md supersedes older aggregate/demo storage descriptions below. Runtime transactions read/write business-table rows with per-document snapshots; prototype_workspaces.state is a preserved archive, never a fallback. Preserve atomic SQL records/revision/receipts/audit, actual five-approver decisions and exact ledger. Live sample BOQ, role switch and reset are unavailable. Tests remain fail-closed SQLite memory; disposable MySQL QA is separate from artisan test. Do not import the archive again after activation or invent historical identities/KHS contracts.

## BOQ blank/formula results authority — 6 November 2026
Follow docs/BOQ_IMPORT.md: blank E/F prices compare as zero; never fabricate a missing KHS component. Read only saved numeric formula results in E/F/G with existing range/precision checks; never evaluate formulas or external links. An explicit saved empty-string result may be blank; missing caches/errors/text fail closed with a recalculation/save instruction. KHS prices, explicit mismatch review and private source provenance remain authoritative. The supplied Paket 5 workbook also needs two currently absent Jasa KHS entries; do not create live prices from Excel automatically. Test both rejected missing masters and successful four-row import using memory-only fixtures, plus queued import.

## Current BOQ/draft authority — 4 November 2026
The supplied OSP FO workbook now defines a real private .xlsx import (docs/BOQ_IMPORT.md). Laravel parses sheet BoQ/header rows 6–7, positive quantities, exact package/code/unit matches and separate M-/J- components. Preserve source prices/row provenance; KHS prices own totals. Require explicit mismatch review at the current KHS version. Never evaluate input formulas or accept client manifests. Only the separate sample button remains simulated. Drafts may omit approvers; submitted PP must have all five selected active Branch-assigned accounts. Older pending-import descriptions below are historical.

## Current v3 authority — username and five approvals (4 November 2026)
The latest user request and docs/WORKFLOW_V3.md override two-stage descriptions below for new documents. Create PP as Admin Konstruksi, then selected PM → Admin Area → Mgr Deployment → Mgr Bussiness Support → Head of PMO. New payments freeze those five IDs, unlock print only at 5/5, then require a real private wet-signature scan and separate Finance approval bound to that scan before APM/full settlement. Preserve both v1 and v2 historical decisions; future terms on old PPs require explicit five-approver configuration. Login is username-based; users metadata includes NIK/Region/Branch/Service Area/role with validated placement and matching SQL assignments. Never fabricate NIK, location or signatures. Primary placement does not expand Branch permissions. Prior descriptions below are historical regression guidance where incompatible.

## Purpose
Keep BOQ Owner, planning budget, mandor contracts, actual invoices and payments mathematically consistent without conflating their meaning.

## Constraints
- Master KHS is authoritative. Imported Excel prices are comparison only. Each mismatch requires explicit resolution before finalization.
- Material and Jasa are independent price components. An agreed Material price cannot exceed Material KHS even if Jasa is discounted; the reverse also holds.
- A missing component is N/A, not a zero-priced included component. An official price of zero is valid and distinct from null.
- Persist BOQ final price/version snapshots and BA KH/SPK agreed prices. Updating Master KHS invalidates affected draft BOQ review but never silently reprices final historical documents.
- Never fabricate the known official sample totals from unrelated synthetic rows. Prototype `DEMO-*` rows are explicitly synthetic (6 rows, BOQ Rp134.780.000 at seed version 1), not the supplied 14-item Rp140.056.789 reference.

## Calculations
- BOQ Owner = sum(volume × official Material price + volume × official Jasa price).
- BA KH/SPK = sum(assigned Material volume × agreed Material price + assigned Jasa volume × agreed Jasa price), included components only.
- Current term = sum(current Material actual × agreed Material price + current Jasa actual × agreed Jasa price).
- Previously approved actuals are never included in the current term again.
- Cumulative approved = sum(approved terms only). Projected remaining contract = SPK − previous approved amounts − current draft amount.
- Outstanding payment = approved term amounts − recorded payments. Draft/pending/rejected amounts are not approved outstanding.
- BA PP completed amount uses cumulative accepted quantities across all approved terms, not just the last Final.
- Consolidated project budget sums root PPs once. Continuation budgets are source BOQ-price suballocations and never add to consolidated budget.

## Implementation checklist
1. Use shared functions `boqTotal`, `dealTotal`, `termTotal`, `accepted`; derive screens, previews, CSV and KPI values from them.
2. Preserve precision and consistent rounding at item boundaries. Prototype rounds currency amounts to 2 decimals; display units/quantities independently. Laravel uses BCMath and half-up rounding per component. Never replace server authority with binary floats.
3. Block NaN, infinity, negative quantities/prices, overbilling and payments above approved outstanding.
4. For v2, Finance validates the real uploaded scan before recording APM; APM settles the full outstanding atomically and never changes approved quantities or decisions. Keep v1 payment history and its separate payment rule intact.
5. Export real CSV, escaping quotes and neutralizing formula-leading text; never advertise XLSX/DOCX generation when only HTML/CSV exists.

## Acceptance
Check excluded components, independent price ceilings, partial payment, multiple actual terms, cumulative BA PP, immutable historical prices after KHS update and root-budget deduplication. All summaries/previews must equal the underlying transaction rows.

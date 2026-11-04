---
name: area3-volume-ledger
description: Implement and reconcile its Area 3 BOQ component volume reservations, commitments, accepted actuals, authorized release and continuation allocation without double use.
---

# area3-volume-ledger

## Current BOQ/draft authority — 4 November 2026
The supplied OSP FO workbook now defines a real private .xlsx import (docs/BOQ_IMPORT.md). Laravel parses sheet BoQ/header rows 6–7, positive quantities, exact package/code/unit matches and separate M-/J- components. Preserve source prices/row provenance; KHS prices own totals. Require explicit mismatch review at the current KHS version. Never evaluate input formulas or accept client manifests. Only the separate sample button remains simulated. Drafts may omit approvers; submitted PP must have all five selected active Branch-assigned accounts. Older pending-import descriptions below are historical.

## Current v3 authority — username and five approvals (4 November 2026)
The latest user request and docs/WORKFLOW_V3.md override two-stage descriptions below for new documents. Create PP as Admin Konstruksi, then selected PM → Admin Area → Mgr Deployment → Mgr Bussiness Support → Head of PMO. New payments freeze those five IDs, unlock print only at 5/5, then require a real private wet-signature scan and separate Finance approval bound to that scan before APM/full settlement. Preserve both v1 and v2 historical decisions; future terms on old PPs require explicit five-approver configuration. Login is username-based; users metadata includes NIK/Region/Branch/Service Area/role with validated placement and matching SQL assignments. Never fabricate NIK, location or signatures. Primary placement does not expand Branch permissions. Prior descriptions below are historical regression guidance where incompatible.

## Purpose
Represent legitimate reuse of unfinished BOQ volume through a single root ledger, separated by designator, component and physical unit.

## Invariant
For each root PP × BOQ item × Material/Jasa component:
`root quantity = available + reserved + committed + accepted`.
Every term is nonnegative. Never aggregate meters, units, links, points and batang into one physical total.

## State meanings
- Available: unallocated root component quantity, including authorized released remainder not subsequently reserved.
- Reserved: draft/sah BA KH allocation not yet backed by Sah SPK; also unassigned component allocation held by continuation PP.
- Committed: Sah SPK quantity less approved accepted actuals and authorized release.
- Accepted: actual quantity from fully approved terms (two selected approvers for v2; retain v1 history), immutable after approval.
- Release: Admin-authorized movement of selected unfinished committed quantity back to available, retaining original SPK, Final term and BA PP history.

## Constraints
- Root BOQ defines one stock of volume; continuation BOQs do not create a second stock.
- Material and Jasa may have different assigned and actual quantities; excluded components have zero capacity and display N/A.
- Draft negotiation reserves quantity. SPK Sah moves that reservation to committed exactly once.
- Pending/draft term actuals remain committed until all approvals complete. Only one active next-term chain is allowed per SPK.
- Release requires BA PP Sah, Final term approved by the required assigned approvers and Admin/Superadmin authorization. Release cannot exceed unaccepted and unreleased quantity.
- Continuation allocation must fit BOTH the source BA PP released-but-unallocated component amount and the global root available amount.
- A root or different mandor cannot consume quantity already reserved for a continuation. Do not count its unassigned allocation plus its draft BA KH twice.
- A new continuation needs a unique server-generated No PP, Parent PP, Root PP and source BA PP. Its budget is a suballocation priced from the source BOQ snapshot.

## Implementation checklist
1. Reuse `ledger`, `capacity`, `remaining`, `accepted`, `released` and `continuationAvailable` from the domain service.
2. Validate every allocation at mutation time; do not rely on stale rendered capacity.
3. Keep release history and continuation lineage append-only. Never rewrite original quantities to represent remaining work.
4. Apply changes transactionally; Laravel adds row locks, optimistic revisions, request idempotency and append-only audit. Production still needs reviewed operational models and identity.
5. Report per component and unit; the dashboard may select one item or show percentages/counts for mixed-unit summaries.

## Acceptance
Test overlapping BA KH drafts, SPK issuance, multiple approvals, early Final, repeated release, accepted-volume reuse, two continuations competing for one source and a full continuation contract cycle. Reconcile every root ledger after each transition, including zero and fractional quantities.

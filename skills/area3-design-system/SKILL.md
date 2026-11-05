---
name: area3-design-system
description: Build or revise the its Area 3 interface with its specific enterprise design tokens, responsive shell, tables, and accessible interaction states. Do not apply its brand to Dompis Prov.
---

# area3-design-system

## Queue/performance authority — 6 November 2026
Follow docs/UPLOAD_QUEUE_PERFORMANCE.md. Active UI queues BOQ and work evidence in the database; show recoverable pending/completed/failed status without presenting accepted bytes as parsed BOQ or attached proof. Recheck active account/Branch/term status in the worker, retain real audit/private paths and block submission while related uploads are pending. Tests may process jobs in isolated memory but must not start detached workers. Incremental writes must preserve unchanged snapshots, affected-root balances and cross-record projections; SQL Branch scoping uses each PP's branch_id, including PP sharing one business Project ID. Verify idempotency/retries/cleanup, cross-Branch access and previous workflow invariants. Bundle/query measurements are scoped evidence, not a load-test guarantee.

## Master management authority — 6 November 2026
The user authorized real SQL master CRUD. Follow docs/MASTER_DESIGNATOR.md, superseding older read-only-master guidance. Keep Master Designator as one of nine parents, with Designator/KHS/Paket KHS submenus and accessible icon tooltips. Only Superadmin mutates SQL masters; other roles read. Preserve soft-deleted references, used identities, issued snapshots and archive; use shared locks/revisions, reference-only metadata writes, receipts and before/after master audit. Mgr Project Support is a real account role, not an extra approver. Verify CRUD/permissions/CSRF, zero prices, concurrency/replay, historical preservation and current-version BOQ review using isolated memory tests.

## Current BOQ/draft authority — 4 November 2026
The supplied OSP FO workbook now defines a real private .xlsx import (docs/BOQ_IMPORT.md). Laravel parses sheet BoQ/header rows 6–7, positive quantities, exact package/code/unit matches and separate M-/J- components. Preserve source prices/row provenance; KHS prices own totals. Require explicit mismatch review at the current KHS version. Never evaluate input formulas or accept client manifests. Only the separate sample button remains simulated. Drafts may omit approvers; submitted PP must have all five selected active Branch-assigned accounts. Older pending-import descriptions below are historical.

## Current v3 authority — username and five approvals (4 November 2026)
The latest user request and docs/WORKFLOW_V3.md override two-stage descriptions below for new documents. Create PP as Admin Konstruksi, then selected PM → Admin Area → Mgr Deployment → Mgr Bussiness Support → Head of PMO. New payments freeze those five IDs, unlock print only at 5/5, then require a real private wet-signature scan and separate Finance approval bound to that scan before APM/full settlement. Preserve both v1 and v2 historical decisions; future terms on old PPs require explicit five-approver configuration. Login is username-based; users metadata includes NIK/Region/Branch/Service Area/role with validated placement and matching SQL assignments. Never fabricate NIK, location or signatures. Primary placement does not expand Branch permissions. Prior descriptions below are historical regression guidance where incompatible.

## Purpose
Create a consistent interface for the construction administration prototype `area3-prototype`. This brand belongs to it's Area 3; the existing Laravel Dompis Prov branding is separate.

## Constraints
- Primary `#9FA1FF`, hover `#898CF0`, surface `#F2F2FF`; white default, canvas `#F8F9FB`, border `#E5E7EB`, main text `#111827`, muted `#667085`.
- Solid colors only. No gradients, glass, glows, decorative imagery, emoji, large shadows or oversized pills. Baseline radius 6px.
- Primary labels and errors use Bahasa Indonesia. Use Manager Konstruksi and BA Rekonsiliasi Pekerjaan for partial documents.
- Dense readable tables retain every component and unit, align numeric columns right and use tabular numerals. Preserve data with horizontal table scrolling, never page overflow.
- Signatures are evidence from an actual document. Never invent a signature in a preview.

## Implementation checklist
1. Reuse `resources/js/area3/styles.css` and primitives in `resources/js/area3/ui.tsx`: Button, Field, Badge, Table, Modal, Steps, DocumentPreview.
2. Keep navigation, breadcrumb, authenticated account summary and data persistence status (role selector only offline) in the shared shell. Distinguish operational document actions from approval inbox actions.
3. At widths below 768px, use a sidebar drawer; keep table overflow inside panels and stack forms/cards. At 768–1023px retain a compact sidebar. Restore desktop viewport after QA.
4. Every visible action must navigate, save, validate, calculate, export, preview or open a meaningful dialog. Explain disabled actions with a nearby message or title.
5. Label form controls, show visible keyboard focus, trap modal focus, restore focus and support Escape. Do not rely on color alone for state.
6. Keep light and dark backgrounds, borders, semantic badges and disabled states legible. Primary button text must contrast with the light brand fill.

## Acceptance
- Check 375/768/1440px and dark/light; no document-level horizontal overflow.
- Sidebar routes and contextual links work. Keyboard opens and closes dialogs. Numeric values agree with domain services.
- No gradient styles, generated signatures, nonfunctional controls or unlabelled icon actions.

## Account mode (4 November 2026)
Authenticated actions follow SQL account role and Branch assignments. Use real login/logout for PM, Manager and Finance; selected PM/Manager IDs must match the logged-in actor. Live reset/role-switch/demo-user/master commands are forbidden. Superadmin manages accounts and global numbering. Source masters are read-only; workflow transactions remain JSON aggregates. Use isolated tests or a marked disposable database for browser QA, never seed test credentials or synthetic transactions into the user database. BOQ Excel import follows docs/BOQ_IMPORT.md; other layouts remain unsupported and the separate sample button remains simulated.

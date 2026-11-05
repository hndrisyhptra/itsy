import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type State, type Term } from '../../resources/js/area3/domain';
import { dashboardBranches, dashboardSummary } from '../../resources/js/area3/dashboard-summary';
import { Dashboard } from '../../resources/js/area3/dashboard';

function state(): State {
  const s = seed('D'), p = s.projects[0];
  p.branch = 'Surabaya'; p.workflowVersion = 3;
  p.boq = [{ ...p.boq[0], qty: 10, m: 100, s: null }];
  s.deals[0].rows = [{ ...s.deals[0].rows[0], rowId: p.boq[0].id, m: true, s: false, qm: 10, qs: 0, pm: 100, ps: 0 }];
  s.branches = [{ id: 'surabaya', name: 'Surabaya', code: 'R501', active: true }, { id: 'semarang', name: 'Semarang', code: 'R428', active: true }];
  return s;
}
function invoice(s: State, id: string, status: Term['status'], qty = 2): Term {
  return { id, spkId: s.spks[0].id, number: 1, type: 'Parsial', status, workflowVersion: 3,
    actuals: { [s.deals[0].rows[0].rowId]: { m: qty, s: 0 } }, approvals: [], history: [], paid: 0,
    paymentHistory: [], apm: '', apmStatus: 'Belum Terbit' };
}

test('dashboard restores original three cards and six summaries without removed operational or analytics panels', () => {
  const html = renderToStaticMarkup(createElement(Dashboard, { s: state() }));
  for (const label of ['Dashboard', 'Ringkasan operasional', 'Proyek Aktif', 'Total Nilai SPK', 'Outstanding Pembayaran', 'PP Lanjutan', 'Mandor Aktif', 'Total Nilai BOQ', 'Tagihan Disetujui', 'Pembayaran Terealisasi', 'Approval Menunggu']) assert.ok(html.includes(label));
  assert.doesNotMatch(html, /Progress Pekerjaan per PP|Aktivitas Terbaru|Monitoring Volume|Inbox Approval|Dashboard Analitik|Posisi Approval|Tahapan Pembayaran|Portofolio per ID Project/);
  assert.match(html, /aria-label="Filter Branch dashboard"/);
  assert.match(html, /<option value="" selected="">Semua Branch<\/option>/);
  assert.match(html, /href="\/project-plan\/new"/);
});

test('Branch dropdown includes authorized masters without PP and deduplicates historical transaction Branches', () => {
  const s = state(); s.projects.push({ ...structuredClone(s.projects[0]), id: 'other', branch: 'Malang' });
  const before = JSON.stringify(s);
  assert.deepEqual(dashboardBranches(s), ['Malang', 'Semarang', 'Surabaya']);
  const html = renderToStaticMarkup(createElement(Dashboard, { s }));
  assert.match(html, /<option value="Semarang">Semarang<\/option>/);
  assert.doesNotMatch(html, /value="Denpasar"/); assert.equal(JSON.stringify(s), before);
  delete s.branches; assert.deepEqual(dashboardBranches(s), ['Malang', 'Surabaya']);
});

test('Branch filter updates all related financial and project metrics, including a Branch without transactions', () => {
  const s = state(); s.terms = [invoice(s, 'approved', 'Disetujui')];
  s.projects.push({ ...structuredClone(s.projects[0]), id: 'other', rootId: 'other', branch: 'Malang', status: 'Draft' });
  const all = dashboardSummary(s), surabaya = dashboardSummary(s, 'Surabaya'), malang = dashboardSummary(s, 'Malang'), empty = dashboardSummary(s, 'Semarang');
  assert.equal(all.projects.length, 2); assert.equal(all.contractValue, surabaya.contractValue); assert.equal(surabaya.billed, 200);
  assert.equal(malang.projects.length, 1); assert.equal(malang.activeProjects, 0); assert.equal(malang.contractValue, 0); assert.equal(malang.billed, 0);
  assert.equal(empty.projects.length, 0);
  for (const key of ['activeProjects', 'continuations', 'activeMandors', 'contractValue', 'totalBoq', 'billed', 'paid', 'outstanding', 'pendingApprovals'] as const) assert.equal(empty[key], 0);
});

test('approved current-term totals preserve historical partial payments and exclude pending, draft and rejected invoices', () => {
  const s = state(), approved = invoice(s, 'approved', 'Disetujui'); approved.workflowVersion = 1; approved.apmStatus = 'Valid'; approved.paid = 75.25;
  const paid = invoice(s, 'paid', 'Disetujui', 1); paid.paid = 100;
  s.terms = [approved, paid, invoice(s, 'pending', 'Diajukan', 3), invoice(s, 'draft', 'Draft', 4), invoice(s, 'reject', 'Ditolak', 5)];
  const before = JSON.stringify(s), data = dashboardSummary(s);
  assert.equal(data.contractValue, 1000); assert.equal(data.billed, 300); assert.equal(data.paid, 175.25); assert.equal(data.outstanding, 124.75);
  assert.equal(JSON.stringify(s), before);
});

test('active projects exclude only the PP with a Sah BA PP, while orphan links do not crash the summary', () => {
  const s = state(), p = s.projects[0];
  s.projects.push({ ...structuredClone(p), id: 'sibling', rootId: 'sibling' });
  const term = invoice(s, 'final', 'Disetujui'); term.type = 'Final'; s.terms = [term];
  s.bapps = [{ id: 'bapp', termId: term.id, no: 'BA-PP-QA', status: 'Draft', evidence: '', released: {}, releaseHistory: [] }];
  assert.equal(dashboardSummary(s).activeProjects, 2);
  s.bapps[0].status = 'Sah'; assert.equal(dashboardSummary(s).activeProjects, 1);
  s.bapps.push({ ...s.bapps[0], id: 'orphan', termId: 'missing' });
  assert.equal(dashboardSummary(s).activeProjects, 1);
});

test('BOQ excludes continuation suballocations and draft SPKs do not count as contracts or active mandors', () => {
  const s = state(), p = s.projects[0];
  s.projects.push({ ...structuredClone(p), id: 'child', parentId: p.id, budget: 300 });
  assert.equal(dashboardSummary(s).totalBoq, 1000); assert.equal(dashboardSummary(s).continuations, 1); assert.equal(dashboardSummary(s).activeMandors, 1);
  s.spks[0].status = 'Draft'; s.terms = [invoice(s, 'draft-spk', 'Disetujui')];
  s.spks.push({ ...s.spks[0], id: 'orphan', dealId: 'missing', status: 'Sah' });
  const data = dashboardSummary(s); assert.equal(data.activeMandors, 0); assert.equal(data.contractValue, 0); assert.equal(data.billed, 0);
});

test('approval summary follows the assigned actor and Branch, including Finance scan tasks', () => {
  const s = state(), pm = s.users.find(u => u.role === 'PM Konstruksi')!, p = s.projects[0], t = invoice(s, 'pending', 'Diajukan');
  p.status = 'Diajukan'; p.approverUserIds = { 'PM Konstruksi': pm.id }; t.approverUserIds = { 'PM Konstruksi': pm.id };
  s.terms = [t]; s.role = 'PM Konstruksi'; s.actorUserId = pm.id;
  assert.equal(dashboardSummary(s).pendingApprovals, 2); assert.equal(dashboardSummary(s, 'Semarang').pendingApprovals, 0);
  s.actorUserId = 'wrong'; assert.equal(dashboardSummary(s).pendingApprovals, 0);
  t.status = 'Disetujui'; t.scan = { id: 'scan', name: 'scan.pdf', mime: 'application/pdf', extension: 'pdf', size: 1, sha256: 'a'.repeat(64), uploadedAt: s.lastSaved, status: 'Menunggu Verifikasi' };
  s.role = 'Finance'; assert.equal(dashboardSummary(s).pendingApprovals, 1);
  t.apmStatus = 'Valid'; assert.equal(dashboardSummary(s).pendingApprovals, 0);
});

test('empty authorized state renders zero summaries and keeps Branch choices without synthetic data', () => {
  const s = state(); s.projects = []; s.deals = []; s.spks = []; s.terms = []; s.bapps = [];
  const html = renderToStaticMarkup(createElement(Dashboard, { s }));
  assert.match(html, /0 Project Plan terdaftar/); assert.match(html, /value="Semarang"/); assert.doesNotMatch(html, /NaN|Infinity/);
  assert.equal(dashboardSummary(s).outstanding, 0);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { seed } from '../../resources/js/area3/seed';
import { type State, type Term, type Role, normalizeWorkflow, saveProject, decideProject, setNumberFormat, selectActor, canApproveProject, canApproveTerm, blankDeal, saveDeal, createTerm, saveTerm, decide, recordApm, verifyTermScan, termTotal, boqTotal, ledger, getProject, assignProjectApprovers, attestBapp, releaseVolume, createContinuation, getSpk, getDeal } from '../../resources/js/area3/domain';

function state(scenario = 'B') { return normalizeWorkflow(seed(scenario)); }
function actor(s: State, role: Role, id?: string) { s.role = role; selectActor(s, id ?? s.users.find(u => u.role === role && u.active)!.id); }
function project(s: State) { const p = structuredClone(s.projects[0]); p.id = p.rootId = 'pp-v2-new'; p.no = 'FORGED'; p.unit = 'Konstruksi'; p.pmUserId = 'user-1'; p.managerUserId = 'user-2'; p.boq = [{ ...p.boq[0], id: 'new-row', qty: 10.125, m: 1 }]; return p; }
function payment(s: State, final = false) { assignProjectApprovers(s, s.projects[0].id, 'user-1', 'user-2'); const id = createTerm(s, s.spks[0].id), t = s.terms.find(t => t.id === id)!; t.type = final ? 'Final' : 'Parsial'; const row = s.deals[0].rows[0].rowId; t.actuals = { [row]: { m: 100, s: 80 } }; saveTerm(s, t, true); return s.terms.find(t => t.id === id)!; }
function approve(s: State, t: Term) { actor(s, 'PM Konstruksi'); decide(s, t.id, 'Setujui', 'PM demo'); actor(s, 'Manager Konstruksi'); decide(s, t.id, 'Setujui', 'Manager demo'); }

test('requested default number uses Jakarta month, keeps issued numbers and never resets the counter', t => {
  t.mock.timers.enable({ apis: ['Date'], now: new Date('2026-10-31T18:15:00Z') });
  let s = state(); const history = structuredClone(s.projects);
  s.branches![0].code = 'R424'; s.settings = { ppNumberFormat: 'PP/{BRANCH}/{YYYY}/{SEQ}', nextPpSequence: 1 };
  s = normalizeWorkflow(s); assert.deepEqual(s.projects, history);
  const p = project(s); saveProject(s, p); const issued = getProject(s, p.id).no;
  assert.equal(issued, '0001/LG.000/TA-03R424/11-2026'); assert.equal(s.settings?.nextPpSequence, 2);
  t.mock.timers.setTime(new Date('2027-01-01T00:00:00+07:00').getTime());
  saveProject(s, { ...p, no: 'CLIENT-EDIT' }); assert.equal(getProject(s, p.id).no, issued);
  saveProject(s, { ...p, id: 'pp-next-year', rootId: 'pp-next-year' });
  assert.equal(s.projects[0].no, '0002/LG.000/TA-03R424/01-2027');
  setNumberFormat(s, 'PP/{BRANCH}/{YYYY}/{SEQ}'); assert.equal(normalizeWorkflow(s).settings?.ppNumberFormat, 'PP/{BRANCH}/{YYYY}/{SEQ}');
  s.settings = { ppNumberFormat: 'CUSTOM/{SEQ}', nextPpSequence: 12000 }; s = normalizeWorkflow(s);
  assert.equal(s.settings?.ppNumberFormat, 'CUSTOM/{SEQ}');
  saveProject(s, { ...p, id: 'pp-custom', rootId: 'pp-custom' }); assert.equal(s.projects[0].no, 'CUSTOM/12000');
});

test('v2 canonical BOQ, numbering and PP decisions require the selected people', () => {
  const s = state(), input = project(s); setNumberFormat(s, 'PLAN/{YYYY}/{BRANCH}/{SEQ}'); saveProject(s, input, true); const p = getProject(s, input.id);
  assert.match(p.no, /^PLAN\/\d{4}\/BR01\/0001$/); assert.equal(p.boq[0].m, 18500); assert.equal(boqTotal(p), 313875); assert.equal(p.status, 'Diajukan'); assert.deepEqual(p.approvals, []);
  assert.throws(() => saveDeal(s, blankDeal(s, p.id)), /PP harus sah/);
  actor(s, 'Manager Konstruksi'); assert.equal(canApproveProject(s, p), false); assert.throws(() => decideProject(s, p.id, 'Setujui', ''), /dipilih/);
  s.users.push({ id: 'other-pm', role: 'PM Konstruksi', name: 'PM lain demo', email: 'other@example.com', active: true }); actor(s, 'PM Konstruksi', 'other-pm'); assert.equal(canApproveProject(s, p), false); assert.throws(() => decideProject(s, p.id, 'Setujui', ''), /dipilih/);
  selectActor(s, 'user-1'); assert.equal(canApproveProject(s, p), true); decideProject(s, p.id, 'Setujui', ''); actor(s, 'Manager Konstruksi'); decideProject(s, p.id, 'Setujui', ''); assert.equal(p.status, 'Sah'); assert.equal(p.approvalHistory?.length, 2);
});

test('PP return restarts both approvals, preserves number and append-only decisions', () => {
  const s = state(), input = project(s); saveProject(s, input, true); let p = getProject(s, input.id); const no = p.no;
  actor(s, 'PM Konstruksi'); assert.throws(() => decideProject(s, p.id, 'Kembalikan', ''), /Alasan/); decideProject(s, p.id, 'Kembalikan', 'Lengkapi'); actor(s, 'Admin Konstruksi'); p = { ...p, no: 'FORGED-AGAIN' }; saveProject(s, p, true); p = getProject(s, p.id);
  assert.equal(p.no, no); assert.equal(p.approvalHistory?.length, 1); assert.deepEqual(p.approvals, []); assert.equal(s.settings?.nextPpSequence, 2);
});

test('BOQ rejects missing, duplicated, excluded and over-precision inputs before PP save', () => {
  const s = state(), p = project(s); assert.throws(() => saveProject(s, { ...p, boq: [] }), /BOQ/); assert.throws(() => saveProject(s, { ...p, boq: [p.boq[0], p.boq[0]] }), /unik/);
  for (const qty of [0, -1, .0001]) assert.throws(() => saveProject(s, { ...p, boq: [{ ...p.boq[0], qty }] }), /BOQ/);
  assert.throws(() => saveProject(s, { ...p, pmUserId: 'user-3' }), /PM Konstruksi/); assert.throws(() => setNumberFormat(s, 'PP/{BAD}'), /Format/);
});

test('two approvals lock actual quantities and Finance cannot act as a third approver', () => {
  const s = state('D'), t = payment(s); actor(s, 'Finance'); assert.equal(canApproveTerm(s, t), false); assert.throws(() => decide(s, t.id, 'Setujui', ''));
  approve(s, t); assert.equal(t.status, 'Disetujui'); assert.equal(t.approvals.length, 2); actor(s, 'Finance'); assert.throws(() => decide(s, t.id, 'Setujui', '')); assert.throws(() => recordApm(s, t.id, 'APM/001'), /scan/);
  actor(s, 'Admin Konstruksi'); assert.throws(() => saveTerm(s, t), /terkunci/); const row = s.deals[0].rows[0].rowId; assert.equal(ledger(s, s.projects[0].id, row, 'm').accepted, 100);
});

test('verified scan and APM settle the exact outstanding once and unlock the next term', () => {
  const s = state('D'), t = payment(s); approve(s, t);
  // File metadata is supplied by the server-only upload endpoint in the running app.
  t.scan = { id: 'test-file', name: 'scan.pdf', extension: 'pdf', mime: 'application/pdf', size: 10, sha256: 'a'.repeat(64), status: 'Menunggu Verifikasi', uploadedAt: new Date().toISOString() };
  actor(s, 'Finance'); assert.throws(() => recordApm(s, t.id, 'APM/001'), /scan/); assert.throws(() => verifyTermScan(s, t.id, 'Perlu Koreksi', ''), /Catatan/); verifyTermScan(s, t.id, 'Valid', 'Diperiksa'); recordApm(s, t.id, 'APM/001');
  assert.equal(t.paid, 2422500); assert.equal(t.paid, termTotal(s, t)); assert.equal(t.paymentHistory.length, 1); assert.throws(() => recordApm(s, t.id, 'APM/002'), /belum dibayar/);
  actor(s, 'Admin Konstruksi'); assert.ok(createTerm(s, t.spkId));
});

test('new Final still requires valid BA PP and releases only unaccepted volume', () => {
  const s = state('D'), t = payment(s, true); approve(s, t); actor(s, 'Admin Konstruksi'); const b = s.bapps.find(b => b.termId === t.id)!, row = getDeal(s, getSpk(s, t.spkId).dealId).rows[0].rowId;
  assert.throws(() => releaseVolume(s, b.id, { [row]: { m: 100, s: 20 } }), /BA PP sah/); attestBapp(s, b.id, 'BAPP/V2', 'Referensi dokumen asli demo'); releaseVolume(s, b.id, { [row]: { m: 100, s: 20 } });
  const rootBudget = s.projects[0].budget, id = createContinuation(s, b.id, '', { [row]: { m: 60, s: 10 } }, 'Sisa pekerjaan');
  assert.equal(getProject(s, id).budget, 1235000); assert.match(getProject(s, id).no, /^0001\/LG\.000\/TA-03BR01\/\d{2}-\d{4}$/); assert.equal(getProject(s, id).workflowVersion, 2); assert.equal(s.projects[0].budget, rootBudget); assert.throws(() => createTerm(s, t.spkId), /Final/);
});

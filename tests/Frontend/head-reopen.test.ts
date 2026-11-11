import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { APPROVERS_V3, normalizeWorkflow, nextApprovalRole, decideProject, selectActor, recordApm, saveProject, blankProject, type Approval } from '../../resources/js/area3/domain';
import { assertHeadReopenCommand } from '../../resources/js/area3/head-reopen';
import { signaturePerson } from '../../resources/js/area3/plan-budget-ui';
import { defaultSignatureRows } from '../../resources/js/area3/plan-budget';
import { ProjectTimeline } from '../../resources/js/area3/project-timeline-page';
import { setCommandRecorder } from '../../resources/js/area3/commands';

function fixture() {
  const s = normalizeWorkflow(seed('E')); s.schema = 3;
  const p = s.projects[0]; p.workflowVersion = 3; p.approvalScheme = 'project-support-first';
  const approvals: Approval[] = APPROVERS_V3.map((role, i) => {
    const id = 'head-reopen-qa-' + i;
    s.users.push({ id, role, name: role + ' QA', email: `head${i}@example.test`, active: true });
    (p.approverUserIds ??= {})[role] = id;
    return { role, userId: id, actorName: role + ' QA', decision: 'Setujui', note: 'Original', at: '2026-11-10T01:00:00Z' };
  });
  p.approvalHistory = structuredClone(approvals); p.approvals = approvals.slice(0, 4); p.status = 'Diajukan';
  p.headApprovalReopen = { version: 1, historyOffset: 5, carriedApprovals: structuredClone(p.approvals), carriedDecisionIds: Object.fromEntries(APPROVERS_V3.slice(0,4).map((r,i)=>[r,"original-"+i])), previousHeadDecision: approvals[4], previousRunId: 'original-run', previousDecisionId: 'original-head', actorUserId: 'admin-qa', reason: 'Accidental Head approval', at: '2026-11-11T02:00:00Z', requestId: 'qa-request', planHash: 'a'.repeat(64) };
  return { s, p, term: s.terms[0], spk: s.spks[0], deal: s.deals[0] };
}

test('reopened PP routes only to selected Head; history and four original decisions survive reapproval', () => {
  const { s, p } = fixture(); const original = structuredClone(p.approvalHistory), retained = structuredClone(p.approvals);
  assert.equal(nextApprovalRole(p, true), 'Head of PMO');
  s.role = 'PM Konstruksi'; selectActor(s, p.approverUserIds!['PM Konstruksi']!);
  assert.throws(() => decideProject(s, p.id, 'Setujui', 'Wrong stage'));
  s.role = 'Head of PMO'; selectActor(s, p.approverUserIds!['Head of PMO']!);
  decideProject(s, p.id, 'Setujui', 'Reviewed again');
  assert.equal(p.status, 'Sah'); assert.deepEqual(p.approvalHistory!.slice(0, 5), original);
  assert.deepEqual(p.approvals!.slice(0, 4), retained); assert.equal(p.approvalHistory!.length, 6);
  assert.equal(p.approvals![4].note, 'Reviewed again');
});

test('all related mutation routes are held until PP Sah; read state and unrelated PP remain untouched', () => {
  const { s, p, term, spk, deal } = fixture();
  const cases: [string, unknown[]][] = [
    ['saveDeal', [{ projectId: p.id }]], ['saveSpk', [{ dealId: deal.id }]], ['saveSpkFinance', [{ dealId: deal.id }]],
    ...['_recordPayeeFile', '_setPayeeVersion', 'createTerm'].map(c => [c, [spk.id]] as [string, unknown[]]),
    ...['saveTerm', 'submitFinanceTerm'].map(c => [c, [{ id: term.id }]] as [string, unknown[]]),
    ...['decide', 'adoptFinancePayment', '_issueKasbon', '_attachTransferProof', 'decideFinancePayment', 'requestPartialPayment', 'verifyPartialPayment', 'payPartialPayment', 'recordApm', 'recordPayment', 'verifyTermScan', '_attachTermScan', '_attachTermWorkEvidence', 'removeTermWorkEvidence'].map(c => [c, [term.id]] as [string, unknown[]]),
    ...['attestBapp', 'releaseVolume', 'createContinuation'].map(c => [c, [s.bapps[0].id]] as [string, unknown[]]),
  ];
  // The demo BA PP belongs to the other term; either term on the SPK resolves to the same PP.
  const before = JSON.stringify(s);
  for (const [command, args] of cases) assert.throws(() => assertHeadReopenCommand(s, command, args), /ditahan/);
  assert.equal(JSON.stringify(s), before);
  assert.doesNotThrow(() => assertHeadReopenCommand(s, 'decideProject', [p.id]));
  assert.doesNotThrow(() => assertHeadReopenCommand(s, 'saveDeal', [{ projectId: 'other-pp' }]));
  p.status = 'Sah';
  for (const [command, args] of cases) assert.doesNotThrow(() => assertHeadReopenCommand(s, command, args));
});

test('form command recorder blocks a pending-parent payment before optimistic mutation', () => {
  const { s, term } = fixture(); const before = JSON.stringify(s);
  setCommandRecorder(c => assertHeadReopenCommand(s, c.command, c.args));
  try {
    assert.throws(() => recordApm(s,term.id,'QA'), /ditahan/);
    assert.equal(JSON.stringify(s), before);
  } finally { setCommandRecorder(); }
});

test('timeline labels correction separately from preserved history and preview has no active Head stamp', () => {
  const { s, p } = fixture(); const before = JSON.stringify(s);
  const html = renderToStaticMarkup(createElement(ProjectTimeline, { s, id: p.id }));
  assert.match(html, /Koreksi approval Head/); assert.match(html, /Accidental Head approval/);
  assert.match(html, /Menunggu approval Head of PMO/); assert.match(html, /ditahan sampai PP Sah kembali/);
  assert.equal(signaturePerson(s, p, defaultSignatureRows().find(r=>r.role==='Head of PMO')!).approved, false);
  assert.equal(JSON.stringify(s), before);
});

test('a new PP cannot inherit a forged correction marker from form input', () => {
  const { s, p } = fixture();
  s.role = 'Admin Konstruksi'; selectActor(s,s.users.find(u=>u.role===s.role)!.id);
  const input={...blankProject(),unit:'Konstruksi',name:'Fresh header',branch:p.branch,projectCode:'HEADER-REOPEN-QA',date:'2026-11-11',headApprovalReopen:structuredClone(p.headApprovalReopen)};
  const id=saveProject(s,input,false);
  assert.equal(s.projects.find(v=>v.id===id)!.headApprovalReopen,undefined);
});

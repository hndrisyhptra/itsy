import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { DocumentPreview } from '../../resources/js/area3/ui';
import { ProjectApproverFields, SubmissionRejectionNotice } from '../../resources/js/area3/workflow-ui';
import { TermWorkEvidencePanel } from '../../resources/js/area3/term-work-evidence';
import { projectPosition, paymentPosition } from '../../resources/js/area3/project-timeline';
import { type State, type Role, type Term, APPROVERS_V3, normalizeWorkflow, selectActor, saveProject, decideProject, getProject, canApproveProject, assignPaymentApprovers, createTerm, getTerm, saveTerm, decide, approvalCount, canApproveTerm, verifyTermScan, recordApm, termTotal, getSpk, getDeal, attestBapp, releaseVolume, createContinuation, ledger } from '../../resources/js/area3/domain';

function state(scenario = 'D') {
  const s = normalizeWorkflow(seed(scenario)); s.schema = 3;
  s.projects.forEach(p=>{p.riskLevel='SEDANG';p.costs.permit=0;});
  for (const [i,role] of APPROVERS_V3.entries()) if (!s.users.some(u => u.role === role)) s.users.push({id:'qa-approver-'+i,name:'QA '+role,email:`qa-${i}@example.test`,role,active:true});
  return s;
}
function actor(s:State, role:Role) { s.role=role; selectActor(s,s.users.find(u => u.active && u.role===role)!.id); }
function chain(s:State) { return Object.fromEntries(APPROVERS_V3.map(role => [role,s.users.find(u => u.role===role)!.id])); }
function payment(s:State, final=false) {
  assignPaymentApprovers(s,s.projects[0].id,chain(s));
  const id=createTerm(s,s.spks[0].id), t=getTerm(s,id); t.type=final?'Final':'Parsial';
  t.actuals={[s.deals[0].rows[0].rowId]:{m:100,s:80}}; saveTerm(s,t,true); return getTerm(s,id);
}
function approve(s:State,t:Term) { for (const role of APPROVERS_V3) { actor(s,role); assert.equal(canApproveTerm(s,t),true); decide(s,t.id,'Setujui','QA'); } }
function scan(t:Term,id='qa-private-scan') { t.scan={id,name:'synthetic-unit-test.pdf',extension:'pdf',mime:'application/pdf',size:100,sha256:'a'.repeat(64),status:'Menunggu Verifikasi',uploadedAt:new Date().toISOString()}; delete t.financeApproval; }

for (const [stage,rejector] of APPROVERS_V3.entries()) {
  test(`v3 PP rejection by ${rejector} requires a fresh admin submission and five new approvals`,()=>{
    const s=state('B'),input=structuredClone(s.projects[0]);input.id=input.rootId='pp-rejected-'+stage;input.unit='Konstruksi';input.approverUserIds=chain(s);
    saveProject(s,input,true);let p=getProject(s,input.id);const no=p.no;
    for(const role of APPROVERS_V3.slice(0,stage)){actor(s,role);decideProject(s,p.id,'Setujui','Siklus awal');}
    actor(s,rejector);assert.throws(()=>decideProject(s,p.id,'Tolak','  '),/Alasan/);decideProject(s,p.id,'Tolak','Perbaiki PP');
    assert.equal(p.status,'Ditolak');assert.deepEqual(p.approvals,[]);assert.equal(canApproveProject(s,p),false);assert.match(projectPosition(p),/pengajuan ulang Admin/);
    const history=structuredClone(p.approvalHistory);assert.throws(()=>saveProject(s,p,true),/Admin/);
    actor(s,'Admin Konstruksi');saveProject(s,{...p,approvalHistory:[],approvals:[],no:'FORGED'},false);p=getProject(s,p.id);
    assert.equal(p.status,'Draft');assert.equal(p.no,no);assert.deepEqual(p.approvalHistory,history);
    actor(s,'PM Konstruksi');assert.throws(()=>decideProject(s,p.id,'Setujui',''),/belum diajukan/);
    actor(s,'Admin Konstruksi');saveProject(s,p,true);p=getProject(s,p.id);actor(s,'Admin Area');assert.equal(canApproveProject(s,p),false);assert.throws(()=>decideProject(s,p.id,'Setujui',''),/dipilih/);
    for(const [i,role] of APPROVERS_V3.entries()){actor(s,role);decideProject(s,p.id,'Setujui','Siklus baru');assert.equal(p.status,i===4?'Sah':'Diajukan');}
    assert.deepEqual(p.approvalHistory!.slice(0,stage+1),history);assert.equal(p.approvalHistory!.length,stage+6);assert.equal(p.no,no);
  });
  test(`v3 Final payment rejection by ${rejector} preserves history and restarts before scan Finance and APM`,()=>{
    const s=state();let t=payment(s,true);const id=t.id;
    for(const role of APPROVERS_V3.slice(0,stage)){actor(s,role);decide(s,id,'Setujui','Siklus awal');}
    actor(s,rejector);assert.throws(()=>decide(s,id,'Tolak',''),/Alasan/);decide(s,id,'Tolak','Perbaiki volume');
    assert.equal(t.status,'Ditolak');assert.deepEqual(t.approvals,[]);assert.match(paymentPosition(t),/pengajuan ulang Admin/);
    const history=structuredClone(t.history);assert.throws(()=>saveTerm(s,t,true),/Admin/);
    actor(s,'Admin Konstruksi');assert.throws(()=>createTerm(s,t.spkId),/Final|sebelumnya/);
    saveTerm(s,{...t,history:[],approvals:[],paid:999,apmStatus:'Valid'},false);t=getTerm(s,id);
    assert.equal(t.status,'Draft');assert.deepEqual(t.history,history);assert.equal(t.paid,0);assert.equal(t.apmStatus,'Belum Terbit');assert.equal(t.scan,undefined);
    actor(s,'PM Konstruksi');assert.throws(()=>decide(s,id,'Setujui',''),/berurutan/);
    actor(s,'Admin Konstruksi');saveTerm(s,t,true);t=getTerm(s,id);actor(s,'Head of PMO');assert.equal(canApproveTerm(s,t),false);assert.throws(()=>decide(s,id,'Setujui',''),/berurutan/);assert.equal(s.bapps.length,0);
    for(const [i,role] of APPROVERS_V3.entries()){actor(s,role);decide(s,id,'Setujui','Siklus baru');assert.equal(t.status,i===4?'Disetujui':'Diajukan');}
    assert.equal(t.history.length,stage+6);assert.deepEqual(t.history.slice(0,stage+1),history);assert.equal(s.bapps.length,1);
    actor(s,'Finance');assert.throws(()=>recordApm(s,id,'APM/REJECTED'),/scan/);assert.equal(t.paid,0);
  });
}

test('v3 rejected forms display the reason and allow evidence correction while historical rejected documents remain locked',()=>{
  const s=state();let t=payment(s);actor(s,'PM Konstruksi');decide(s,t.id,'Tolak','Lengkapi <eviden>');actor(s,'Admin Konstruksi');
  const notice=renderToStaticMarkup(createElement(SubmissionRejectionNotice,{document:t,history:t.history}));
  assert.match(notice,/Lengkapi &lt;eviden&gt;/);assert.match(notice,/Seluruh approval dimulai dari PM Konstruksi/);
  const panel=()=>renderToStaticMarkup(createElement(TermWorkEvidencePanel,{s,term:t}));assert.match(panel(),/Upload eviden pekerjaan/);
  t.workflowVersion=2;assert.doesNotMatch(panel(),/type="file"/);assert.throws(()=>saveTerm(s,t,true),/terkunci/);
  const p=s.projects[0];p.workflowVersion=2;p.status='Ditolak';assert.throws(()=>saveProject(s,p,true),/terkunci/);
  t.workflowVersion=3;saveTerm(s,t,true);t=getTerm(s,t.id);assert.doesNotMatch(renderToStaticMarkup(createElement(SubmissionRejectionNotice,{document:t,history:t.history})),/ditolak/);
});

test('new PP form presents all five approvers in order and retains the two-field historical editor', () => {
  const s=state('B'),p=structuredClone(s.projects[0]); p.approverUserIds=chain(s);
  const html=renderToStaticMarkup(createElement(ProjectApproverFields,{s,p,onChange:()=>{},disabled:false}));
  assert.equal((html.match(/<select/g)??[]).length,5);
  for (const [i,role] of APPROVERS_V3.entries()) assert.ok(html.includes(`Approval ${i+1} · ${role}`));
  p.workflowVersion=2; const legacy=renderToStaticMarkup(createElement(ProjectApproverFields,{s,p,onChange:()=>{},disabled:false}));
  assert.equal((legacy.match(/<select/g)??[]).length,2); assert.ok(legacy.includes('Manager Konstruksi'));
});

test('draft permits missing approvers but submission requires all five and supplied draft roles stay validated', () => {
  const s=state('B'),p=structuredClone(s.projects[0]); p.id=p.rootId='qa-draft-optional';p.unit='Konstruksi';delete p.pmUserId;delete p.managerUserId;delete p.approverUserIds;
  saveProject(s,p,false); const saved=getProject(s,p.id),no=saved.no;
  assert.equal(saved.status,'Draft');assert.deepEqual(saved.approverUserIds,{});assert.throws(()=>saveProject(s,saved,true),/PM Konstruksi/);
  saved.approverUserIds={'PM Konstruksi':chain(s)['PM Konstruksi']};saveProject(s,saved,false);
  saved.approverUserIds['Head of PMO']=chain(s)['PM Konstruksi'];assert.throws(()=>saveProject(s,saved,false),/Head of PMO/);
  saved.approverUserIds=chain(s);saveProject(s,saved,true);assert.equal(getProject(s,p.id).status,'Diajukan');assert.equal(getProject(s,p.id).no,no);
});

test('v3 imported comparison prices survive while saved prices and total automatically follow current KHS without manual review', () => {
  const s=state('B'),p=structuredClone(s.projects[0]);p.id=p.rootId='qa-import-review';p.unit='Konstruksi';p.approverUserIds=chain(s);
  p.boqUpload={id:'qa-source',name:'qa.xlsx',sha256:'a'.repeat(64),size:100,sheet:'BoQ',package:'Paket 10',sourceRows:1,sourceTotal:1,uploadedBy:'qa',uploadedAt:new Date().toISOString()};
  const r=p.boq[0],k=s.khs.find(k=>k.id===r.khsId)!;r.excelM=(k.m??0)+1;r.reviewed=false;r.note='';
  saveProject(s,p,false);let saved=getProject(s,p.id);assert.equal(saved.boq[0].excelM,r.excelM);assert.equal(saved.boq[0].reviewed,true);assert.equal(saved.boqPricingPolicy,'khs-auto-v1');
  saved.boq[0].note='';saved.boq[0].reviewed=false;k.m=(k.m??0)+2;k.version++;
  saveProject(s,saved,true);saved=getProject(s,p.id);assert.equal(saved.boq[0].m,k.m);assert.equal(saved.boq[0].version,k.version);assert.equal(saved.boq[0].excelM,r.excelM);assert.equal(saved.boqStatus,'Final');assert.match(saved.boq[0].note,/otomatis mengikuti Master KHS/);
});

test('v3 PP requires all five assigned roles and a return restarts all approvals with retained history', () => {
  const s=state('B'), p=structuredClone(s.projects[0]); p.id=p.rootId='qa-pp-v3'; p.unit='Konstruksi'; p.workflowVersion=2; p.approverUserIds=chain(s);
  const missing=structuredClone(p); delete missing.approverUserIds!['Head of PMO']; assert.throws(()=>saveProject(s,missing,true),/Head of PMO/);
  saveProject(s,p,true); let saved=getProject(s,p.id); const no=saved.no; assert.equal(saved.workflowVersion,3);
  actor(s,'Head of PMO'); assert.equal(canApproveProject(s,saved),false); assert.throws(()=>decideProject(s,p.id,'Setujui',''),/dipilih/);
  actor(s,'PM Konstruksi'); decideProject(s,p.id,'Setujui','QA'); actor(s,'Admin Area'); assert.throws(()=>decideProject(s,p.id,'Kembalikan',''),/Alasan/); decideProject(s,p.id,'Kembalikan','QA koreksi');
  assert.equal(saved.status,'Dikembalikan'); assert.equal(saved.approvals?.length,0);
  actor(s,'Admin Konstruksi'); saveProject(s,saved,true); saved=getProject(s,p.id); assert.equal(saved.no,no);
  for (const [i,role] of APPROVERS_V3.entries()) { actor(s,role); decideProject(s,p.id,'Setujui','QA'); assert.equal(saved.status,i===4?'Sah':'Diajukan'); }
  assert.equal(saved.approvalHistory?.length,7); assert.deepEqual(saved.approvals?.map(a=>a.role),APPROVERS_V3);
});

test('v3 payment locks five pre-signature decisions and requires a matching Finance scan approval for APM', () => {
  const s=state(), t=payment(s); assert.equal(approvalCount(t),5); assert.deepEqual(t.approverUserIds,chain(s));
  actor(s,'Finance'); assert.throws(()=>decide(s,t.id,'Setujui',''),/berurutan/); assert.throws(()=>verifyTermScan(s,t.id,'Valid',''),/Scan/);
  approve(s,t); assert.equal(t.status,'Disetujui'); assert.equal(t.approvals.length,5);
  actor(s,'Admin Konstruksi'); assert.throws(()=>saveTerm(s,t,false),/terkunci/);
  actor(s,'Finance'); assert.throws(()=>recordApm(s,t.id,'APM/QA/V3-01'),/scan/);
  scan(t); verifyTermScan(s,t.id,'Perlu Koreksi','QA kurang'); assert.equal(t.financeHistory?.length,1); assert.equal(t.financeApproval,undefined);
  scan(t,'qa-replacement'); verifyTermScan(s,t.id,'Valid','QA diperiksa');
  const approved=structuredClone(getTerm(s,t.id).financeApproval)!; assert.equal(approved.scanId,t.scan?.id); assert.equal(t.financeHistory?.length,2);
  t.financeApproval={...approved,scanId:'stale-scan'}; assert.throws(()=>recordApm(s,t.id,'APM/QA/V3-01'),/scan terbaru/); t.financeApproval=approved;
  recordApm(s,t.id,'APM/QA/V3-01'); assert.equal(t.paid,termTotal(s,t)); assert.equal(t.paymentHistory.length,1); assert.equal(t.approvals.length,5); assert.throws(()=>recordApm(s,t.id,'APM/QA/V3-02'),/scan/);
});

test('v3 print is blocked until five approvals and uses the BARP template signature block', () => {
  const s=state(), t=payment(s), spk=getSpk(s,t.spkId);
  const render=()=>renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'BA Rekonsiliasi Pekerjaan – Termin',term:t,spk,onClose:()=>{}}));
  for (const role of APPROVERS_V3.slice(0,4)) { actor(s,role); decide(s,t.id,'Setujui','QA'); }
  assert.match(render(),/<button[^>]*disabled[^>]*>[\s\S]*?Cetak \/ Simpan PDF browser/);
  actor(s,'Head of PMO'); decide(s,t.id,'Setujui','QA'); const html=render();
  assert.doesNotMatch(html,/<button[^>]*disabled[^>]*>[\s\S]*?Cetak \/ Simpan PDF browser/);
  const signatures=html.match(/class="barp-signatures"[\s\S]*?<\/section>/)?.[0]??'';
  assert.equal((signatures.match(/Area TTD basah dokumen asli/g)??[]).length,3);
  assert.ok(signatures.includes(t.barp!.headName));
  assert.ok(signatures.includes(t.barp!.waspang));
  assert.ok(signatures.includes(t.barp!.mandor));
  assert.doesNotMatch(signatures,/<img|<svg/);
});

test('v3 Final retains the component ledger and continuation inherits the five approvers', () => {
  const s=state(), root=structuredClone(s.projects[0]), t=payment(s,true); approve(s,t); actor(s,'Admin Konstruksi');
  const b=s.bapps[0], row=getDeal(s,getSpk(s,t.spkId).dealId).rows[0].rowId;
  assert.throws(()=>releaseVolume(s,b.id,{[row]:{m:100,s:20}}),/BA PP/);
  attestBapp(s,b.id,'BA/QA/V3','Referensi sintetis QA'); releaseVolume(s,b.id,{[row]:{m:100,s:20}});
  const id=createContinuation(s,b.id,'',{[row]:{m:60,s:10}},'QA'), p=getProject(s,id);
  assert.equal(p.workflowVersion,3); assert.deepEqual(p.approverUserIds,t.approverUserIds); assert.equal(p.budget,1235000); assert.equal(getProject(s,root.id).budget,root.budget);
  assert.deepEqual(ledger(s,root.id,row,'m'),{total:1200,available:40,reserved:60,committed:1000,accepted:100});
});

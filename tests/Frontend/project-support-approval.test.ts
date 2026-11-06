import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { normalizeWorkflow, APPROVERS_V3_PREVIOUS, adoptApprovalOrder, nextApprovalRole, canApproveTerm, decide, APPROVERS_V3, APPROVERS_V3_LEGACY, approvalRoles, canApproveProject, decideProject, selectActor, assignPaymentApprovers, createTerm, nextTermReason, type Role } from '../../resources/js/area3/domain';
import { RiskQualitySection } from '../../resources/js/area3/plan-budget-ui';
import { defaultSignatureRows } from '../../resources/js/area3/plan-budget';
import { ProjectApproverFields } from '../../resources/js/area3/workflow-ui';

function fixture(){
  const s=normalizeWorkflow(seed('D'));s.schema=3;
  for(const [i,role]of [...new Set([...APPROVERS_V3,...APPROVERS_V3_LEGACY])].entries())if(!s.users.some(u=>u.role===role))s.users.push({id:'qa-support-'+i,name:'User '+role,nik:'QA'+i,email:`qa${i}@example.test`,role,active:true});
  const chain=(roles:Role[])=>Object.fromEntries(roles.map(role=>[role,s.users.find(u=>u.role===role)!.id]));
  return {s,p:s.projects[0],chain};
}
function actor(s:ReturnType<typeof fixture>['s'],role:Role){s.role=role;selectActor(s,s.users.find(u=>u.role===role)!.id);}

test('new PP field has Project Support at stage three while a pending legacy PP still expects Business Support',()=>{
  const {s,p,chain}=fixture();p.workflowVersion=3;p.status='Draft';p.approvals=[];p.approvalHistory=[];p.approverUserIds=chain(APPROVERS_V3_LEGACY);
  const fields=renderToStaticMarkup(createElement(ProjectApproverFields,{s,p,onChange:()=>{},disabled:false}));
  assert.match(fields,/Approval 3 · Mgr Project Support \(wajib saat pengajuan\)/);assert.doesNotMatch(fields,/Approval 4 · Mgr Bussiness Support/);
  p.status='Diajukan';p.approvals=APPROVERS_V3_LEGACY.slice(0,3).map(role=>({role,userId:p.approverUserIds![role],decision:'Setujui',note:'Historical QA',at:'2026-11-06T01:00:00Z'}));p.approvalHistory=structuredClone(p.approvals);
  assert.deepEqual(approvalRoles(p,true),APPROVERS_V3_LEGACY);
  actor(s,'Mgr Project Support');assert.equal(canApproveProject(s,p),false);assert.throws(()=>decideProject(s,p.id,'Setujui','Denied'));
  actor(s,'Mgr Bussiness Support');assert.equal(canApproveProject(s,p),true);decideProject(s,p.id,'Setujui','Historical QA');assert.equal(p.approvals[3].role,'Mgr Bussiness Support');
});

test('old approved PP requires explicit future assignments and new payment selects Project Support without rewriting PP history',()=>{
  const {s,p,chain}=fixture();s.terms=[];p.workflowVersion=3;p.status='Sah';p.approverUserIds=chain(APPROVERS_V3_LEGACY);delete p.approvalScheme;delete p.paymentApproverUserIds;
  p.approvals=APPROVERS_V3_LEGACY.map(role=>({role,userId:p.approverUserIds![role],decision:'Setujui',note:'Historical QA',at:'2026-11-06T01:00:00Z'}));p.approvalHistory=structuredClone(p.approvals);
  const before=JSON.stringify(p.approvalHistory);actor(s,'Admin Konstruksi');
  assert.match(nextTermReason(s,s.spks[0].id),/Tetapkan lima approver/);assignPaymentApprovers(s,p.id,chain(APPROVERS_V3));
  const id=createTerm(s,s.spks[0].id),term=s.terms.find(t=>t.id===id)!;
  assert.equal(term.approvalScheme,'project-support-first');assert.deepEqual(approvalRoles(term),APPROVERS_V3);assert.equal(JSON.stringify(p.approvalHistory),before);
});

test('new closing preview has exactly four requested positions in order, genuine stamps and empty print cells',()=>{
  const {s,p,chain}=fixture();p.workflowVersion=3;p.approvalScheme='project-support';p.status='Sah';p.approverUserIds=chain(APPROVERS_V3);
  p.approvals=APPROVERS_V3.map(role=>({role,userId:p.approverUserIds![role],actorName:s.users.find(u=>u.id===p.approverUserIds![role])!.name,decision:'Setujui',note:'QA',at:'2026-11-06T01:00:00Z'}));
  p.signatureRows=[{id:'signature-custom',label:'Dibuat Oleh',role:'Admin Konstruksi',job:'CLIENT OVERRIDE'}];
  const html=renderToStaticMarkup(createElement(RiskQualitySection,{s,p,printing:true})),closing=html.split('VIII. PENUTUP')[1];
  const jobs=['Officer 1 Project Deployment Area Jawa Bali','Mgr Project Support Area Jawa Bali','Mgr Deployment Area Jawa Bali','Head of PMO Area Jawa Bali'];
  const indices=jobs.map(job=>closing.indexOf(job));assert.ok(indices.every(i=>i>=0));assert.deepEqual(indices,[...indices].sort((a,b)=>a-b));
  assert.deepEqual(defaultSignatureRows().map(r=>r.role),['PM Konstruksi','Mgr Project Support','Mgr Deployment','Head of PMO']);
  assert.equal((closing.match(/>APPROVED</g)??[]).length,4);assert.equal((closing.match(/class="pp-signature-print" aria-hidden="true"><\/div>/g)??[]).length,4);
  assert.doesNotMatch(closing,/Admin Area|Mgr Bussiness Support|CLIENT OVERRIDE/);
  p.status='Ditolak';const rejected=renderToStaticMarkup(createElement(RiskQualitySection,{s,p,printing:true}));assert.doesNotMatch(rejected,/>APPROVED</);
});


test('running PP keeps Deployment decision and waits for the first unapproved role in the new order',()=>{
  const {s,p,chain}=fixture();p.workflowVersion=3;p.approvalScheme='project-support';p.status='Diajukan';p.approverUserIds=chain(APPROVERS_V3);
  p.approvals=APPROVERS_V3_PREVIOUS.slice(0,3).map(role=>({role,userId:p.approverUserIds![role],decision:'Setujui',note:'Existing decision',at:'2026-11-06T01:00:00Z'}));p.approvalHistory=structuredClone(p.approvals);
  const before=structuredClone(p.approvalHistory);assert.equal(nextApprovalRole(p,true),'Mgr Project Support');
  actor(s,'Mgr Deployment');assert.equal(canApproveProject(s,p),false);assert.throws(()=>decideProject(s,p.id,'Setujui','No duplicate'));
  actor(s,'Mgr Project Support');decideProject(s,p.id,'Setujui','QA');assert.equal(nextApprovalRole(p,true),'Head of PMO');
  actor(s,'Head of PMO');decideProject(s,p.id,'Setujui','QA');assert.equal(p.status,'Sah');assert.deepEqual(p.approvalHistory!.slice(0,3),before);
});

test('adopting a running legacy approval validates the selected Support account and preserves PM history',()=>{
  const {s,p,chain}=fixture();p.workflowVersion=3;p.status='Diajukan';p.approverUserIds=chain(APPROVERS_V3_LEGACY);delete p.approvalScheme;
  p.approvals=APPROVERS_V3_LEGACY.slice(0,1).map(role=>({role,userId:p.approverUserIds![role],decision:'Setujui',note:'Existing decision',at:'2026-11-06T01:00:00Z'}));p.approvalHistory=structuredClone(p.approvals);
  const before=JSON.stringify(p.approvalHistory),support=s.users.find(u=>u.role==='Mgr Project Support')!;actor(s,'Admin Konstruksi');
  assert.throws(()=>adoptApprovalOrder(s,'projects',p.id,s.users.find(u=>u.role==='PM Konstruksi')!.id));
  adoptApprovalOrder(s,'projects',p.id,support.id);assert.equal(JSON.stringify(p.approvalHistory),before);assert.deepEqual(approvalRoles(p,true),APPROVERS_V3);assert.equal(p.approverUserIds['Mgr Project Support'],support.id);
  actor(s,'Admin Area');decideProject(s,p.id,'Setujui','QA');assert.equal(nextApprovalRole(p,true),'Mgr Project Support');
});


test('a running payment with Deployment already approved advances through Support to Head without duplicate approval',()=>{
  const {s,p,chain}=fixture();p.workflowVersion=3;p.status='Sah';p.approvalScheme='project-support';p.approverUserIds=chain(APPROVERS_V3);s.terms=[];actor(s,'Admin Konstruksi');
  const id=createTerm(s,s.spks[0].id),t=s.terms.find(t=>t.id===id)!;t.approvalScheme='project-support';t.status='Diajukan';
  t.approvals=APPROVERS_V3_PREVIOUS.slice(0,3).map(role=>({role,userId:t.approverUserIds![role],decision:'Setujui',note:'Existing decision',at:'2026-11-06T01:00:00Z'}));t.history=structuredClone(t.approvals);
  const before=structuredClone(t.history);actor(s,'Mgr Deployment');assert.equal(canApproveTerm(s,t),false);assert.throws(()=>decide(s,id,'Setujui','No duplicate'));
  actor(s,'Mgr Project Support');assert.equal(canApproveTerm(s,t),true);decide(s,id,'Setujui','QA');assert.equal(nextApprovalRole(t),'Head of PMO');
  actor(s,'Head of PMO');decide(s,id,'Setujui','QA');assert.equal(t.status,'Disetujui');assert.deepEqual(t.history.slice(0,3),before);assert.equal(t.history.length,5);
});

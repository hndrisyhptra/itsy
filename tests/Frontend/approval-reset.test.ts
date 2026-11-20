import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {type State,type Role,type Term,type Approval,APPROVERS_V3,normalizeWorkflow,createSettlementTerm,nextApprovalRole,approvalRoles} from '../../resources/js/area3/domain';
import {OPERATION_APPROVERS} from '../../resources/js/area3/work-unit';
import {approvalResetPlan,approvalCycles,resetPaymentApproval,resetProjectApproval} from '../../resources/js/area3/approval-reset';
import {ApprovalDecisionCard,ApprovalResetHistory} from '../../resources/js/area3/approval-actions';
import {ProjectApprovalPanel} from '../../resources/js/area3/workflow-ui';
import {TermEditor,ApprovalInbox} from '../../resources/js/area3/payments';
import {kasbonSignatures,type KasbonData} from '../../resources/js/area3/kasbon-content';
import {dashboardApprovalData} from '../../resources/js/area3/dashboard-approval';

function fixture(project:boolean,operation=false){
 const s=normalizeWorkflow(seed('D'));s.schema=3;const p=s.projects[0];p.workflowVersion=3;p.unit=operation?'Operation':'Konstruksi';p.approvalScheme=operation?'operation-v1':'project-support-first';p.approverUserIds={};
 const work:Role[]=operation?[...OPERATION_APPROVERS]:[...APPROVERS_V3],all:Role[]=[...work,'Mgr Finance & Settlement','Finance'];
 for(const [i,role]of all.entries()){const id='reset-user-'+i;s.users.push({id,name:role+' QA',role,email:i+'@example.test',active:true,branches:[p.branch],workUnits:[p.unit],accessScope:i>=3?'REGION':'BRANCH'});if(i<5)p.approverUserIds[role]=id;}
 const decisions:Approval[]=work.map((role,i)=>({role,userId:'reset-user-'+i,actorName:role+' QA',decision:'Setujui',at:`2026-11-20T01:0${i}:00Z`,note:'Original'}));
 p.approvals=structuredClone(decisions);p.approvalHistory=structuredClone(decisions);p.status='Sah';
 const manifest={id:'submission-reset',at:'2026-11-20T01:00:00Z',documents:{deals:{[s.deals[0].id]:'server-hash'},spks:{[s.spks[0].id]:'server-hash'}}};p.contractBundle={version:1,submissions:[manifest]};
 for(const child of [...s.deals,...s.spks])child.approvedWithProject={ppId:p.id,submissionId:manifest.id};
 s.terms=[];s.bapps=[];s.role='Admin Konstruksi';s.actorUserId=s.users.find(u=>u.role===s.role)!.id;
 let t:Term|undefined;
 if(!project){const id=createSettlementTerm(s,s.spks[0].id);t=s.terms.find(t=>t.id===id)!;t.type='Parsial';t.actuals={[s.deals[0].rows[0].rowId]:{m:1,s:1}};t.kasbon={id:'current-kasbon',version:1,amount:26350,issuedBy:s.actorUserId,issuedAt:'2026-11-20T00:00:00Z'};
 t.approverUserIds=Object.fromEntries(all.map((r,i)=>[r,'reset-user-'+i]));t.approvals=all.map((role,i)=>({role,userId:'reset-user-'+i,actorName:role+' QA',decision:'Setujui',at:`2026-11-20T02:0${i}:00Z`,note:'Original',kasbonId:t!.kasbon!.id}));t.history=structuredClone(t.approvals);t.status='Disetujui';t.financePayment={status:'Diverifikasi',decision:t.approvals.at(-1)! as Approval&{kasbonId:string}};t.financePaymentHistory=[structuredClone(t.financePayment.decision!)];}
 const roles=project?work:all;
 const actor=(stage:number)=>{s.role=roles[stage];s.actorUserId='reset-user-'+stage;};
 return {s,p,t,roles,actor};
}

for(const operation of [false,true])for(const project of [true,false])for(let stage=0;stage<(project?5:7);stage++)test(`self reset ${operation?'Operation':'Construction'} ${project?'PP':'payment'} stage ${stage+1} retains the prefix, actual history and current version`,()=>{
 const {s,p,t,roles,actor}=fixture(project,operation);actor(stage);const doc=project?p:t!,history=JSON.stringify(project?p.approvalHistory:t!.history),privateVersion=JSON.stringify(t?.kasbon),rows=JSON.stringify(s.deals.map(d=>d.rows));
 const plan=approvalResetPlan(s,doc,project);assert.equal(plan.own,true);assert.equal(plan.reason,'');
 if(project)resetProjectApproval(s,doc.id,plan.expected,'Koreksi saya');else resetPaymentApproval(s,doc.id,plan.expected,'Koreksi saya');
 assert.equal(doc.status,'Diajukan');assert.equal(nextApprovalRole(doc,project),roles[stage]);assert.deepEqual(doc.approvals!.map(a=>a.role),roles.slice(0,stage));assert.equal(JSON.stringify(project?p.approvalHistory:t!.history),history);assert.equal(JSON.stringify(t?.kasbon),privateVersion);assert.equal(JSON.stringify(s.deals.map(d=>d.rows)),rows);
 assert.equal(doc.approvalResets!.length,1);assert.equal(doc.approvalResets![0].affectedApprovals.length,roles.length-stage);assert.equal(approvalCycles(doc,project).length,2);
 const read=JSON.stringify(s);approvalCycles(doc,project);approvalResetPlan(s,doc,project);assert.equal(JSON.stringify(s),read);
 const html=renderToStaticMarkup(createElement(ApprovalDecisionCard,{s,document:doc,project}));assert.match(html,new RegExp(project?'Approve Paket PP, BA KH, SPK':'Approve Pembayaran'));assert.match(html,/Kembalikan untuk Revisi/);assert.match(html,project?/Setujui PP/:/Setujui Pembayaran/);assert.doesNotMatch(html,/>Tolak(?: PP)?</);
});

test('wrong account, stale expected version and blank reset note never mutate a document',()=>{
 const {s,p,actor}=fixture(true);actor(2);let before=JSON.stringify(s),plan=approvalResetPlan(s,p,true);
 assert.throws(()=>resetProjectApproval(s,p.id,{...plan.expected,historyLength:999},'Koreksi'),/berubah/);assert.equal(JSON.stringify(s),before);
 assert.throws(()=>resetProjectApproval(s,p.id,plan.expected,' '),/Alasan/);assert.equal(JSON.stringify(s),before);
 s.actorUserId='other-user';before=JSON.stringify(s);assert.throws(()=>resetProjectApproval(s,p.id,plan.expected,'Koreksi'),/pemilik/);assert.equal(JSON.stringify(s),before);
 actor(2);resetProjectApproval(s,p.id,plan.expected,'Koreksi');const offset=p.approvalResets![0].historyOffset;actor(0);plan=approvalResetPlan(s,p,true);resetProjectApproval(s,p.id,plan.expected,'Koreksi awal');assert.equal(p.approvalResets![1].historyOffset,offset);assert.equal(approvalCycles(p,true).length,3);assert.equal(p.approvalHistory!.length,5);
});

test('transfer, settlement, later payment, released volume and PP descendants disable self reset',()=>{
 for(const change of [
  (s:State,t:Term)=>{t.paid=1;},(s:State,t:Term)=>{t.apm='APM/QA/01';},(s:State,t:Term)=>{t.transferProof={id:'proof',kasbonId:t.kasbon!.id,uploadedAt:'2026-11-20',uploadedBy:'qa'};},
  (s:State,t:Term)=>{s.terms.push({...structuredClone(t),id:'next-term',number:2});},
  (s:State,t:Term)=>{s.bapps.push({id:'bapp-released',termId:t.id,no:'BAPP/QA',status:'Sah',evidence:'',released:{row:{m:1,s:0}},releaseHistory:[]});},
 ]){const {s,t,actor}=fixture(false);actor(6);change(s,t!);const plan=approvalResetPlan(s,t!);assert.ok(plan.reason);const before=JSON.stringify(s);assert.throws(()=>resetPaymentApproval(s,t!.id,plan.expected,'Koreksi'));assert.equal(JSON.stringify(s),before);const html=renderToStaticMarkup(createElement(ApprovalDecisionCard,{s,document:t!}));assert.match(html,/disabled=""/);assert.match(html,/Batalkan Persetujuan Saya/);}
 const {s,p,actor}=fixture(false);actor(0);assert.match(approvalResetPlan(s,p,true).reason,/pengajuan pekerjaan/);
});

test('current kasbon preview cannot reuse revoked approval history or a stale legacy Manager print annotation',()=>{
 const {s,t,actor}=fixture(false),data=JSON.parse(readFileSync(new URL('../Fixtures/kasbon-vector.json',import.meta.url),'utf8')) as KasbonData;
 for(const [i,role]of approvalRoles(t!).entries())data.signers[role]={name:role,userId:'reset-user-'+i};
 assert.equal(kasbonSignatures(data,t,'current-kasbon').flat().filter(r=>r.approved).length,5);
 actor(2);const plan=approvalResetPlan(s,t!);resetPaymentApproval(s,t!.id,plan.expected,'Koreksi');const marks=kasbonSignatures(data,t,'current-kasbon').flat();assert.equal(marks.filter(r=>r.approved).length,1);assert.equal(marks.some(r=>r.automatic==='evp'),false);assert.equal(t!.history.length,7);
 const legacy=fixture(false);const term=legacy.t!;term.paymentFlow='kasbon-v1';term.approvals=term.approvals.slice(0,5);term.history=term.history.slice(0,5);const decision={role:'Mgr Finance & Settlement' as Role,userId:'reset-user-5',actorName:'Manager QA',decision:'Setujui' as const,note:'',at:'2026-11-20T02:05:00Z',kasbonId:'current-kasbon'};
 term.legacySettlementReview={version:1,kasbonId:'current-kasbon',userId:'reset-user-5',name:'Manager QA',assignedAt:decision.at,assignedBy:'qa',decision};data.templateVersion=2;data.printSigner={userId:'reset-user-5',name:'Manager QA',job:'Manager QA',at:decision.at,by:'qa'};data.printSignerApproval=decision;
 assert.ok(kasbonSignatures(data,term,'current-kasbon').flat().some(r=>r.name==='Manager QA'&&r.approved));legacy.actor(5);term.legacySettlementReview.decision!.kasbonId='archived-kasbon';assert.equal(approvalResetPlan(legacy.s,term).own,false);term.legacySettlementReview.decision!.kasbonId='current-kasbon';const reset=approvalResetPlan(legacy.s,term);assert.equal(reset.mode,'legacy-manager');resetPaymentApproval(legacy.s,term.id,reset.expected,'Koreksi manager');assert.equal(term.approvals.length,5);assert.equal(term.financePayment!.status,'Kasbon Terbit');assert.equal(term.legacySettlementReview!.decision,undefined);assert.equal(kasbonSignatures(data,term,'current-kasbon').flat().some(r=>r.name==='Manager QA'&&r.approved),false);
});

test('review and approval are separate cards in PP/payment and reset history is paged without altering prior rejection text',()=>{
 const {s,p,t,actor}=fixture(false);actor(0);const plan=approvalResetPlan(s,t!);resetPaymentApproval(s,t!.id,plan.expected,'Salah pilih sebelumnya');
 const editor=renderToStaticMarkup(createElement(TermEditor,{s,id:t!.id}));assert.match(editor,/Approve Pembayaran/);assert.doesNotMatch(editor,/>Tolak</);
 const inbox=renderToStaticMarkup(createElement(ApprovalInbox,{s}));assert.match(inbox,/Approve Pembayaran/);assert.doesNotMatch(inbox,/>Tolak</);
 const pp=renderToStaticMarkup(createElement(ProjectApprovalPanel,{s,p}));assert.match(pp,/Approve Paket PP, BA KH, SPK/);assert.doesNotMatch(pp,/>Tolak PP</);
 for(let i=1;i<45;i++)t!.approvalResets!.push({...structuredClone(t!.approvalResets![0]),id:'reset-'+i,reason:'Reason '+i});
 const history=renderToStaticMarkup(createElement(ApprovalResetHistory,{document:t!}));assert.equal((history.match(/<tr>/g)??[]).length,21);assert.match(history,/45 pembatalan/);
});

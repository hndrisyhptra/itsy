import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {readFileSync} from 'node:fs';
import {seed} from '../../resources/js/area3/seed';
import {normalizeWorkflow,APPROVERS_V3,type Role,type Term} from '../../resources/js/area3/domain';
import {canRouteLegacyFinance,routeLegacyFinance,canReviewLegacySettlement,decideLegacySettlementReview,legacyReviewApproved,legacyReviewDecisions} from '../../resources/js/area3/legacy-settlement-review';
import {financeTaskPending} from '../../resources/js/area3/partial-payment';
import {decideFinancePayment,financePaymentLabel} from '../../resources/js/area3/finance-payment';
import {KasbonDocument} from '../../resources/js/area3/kasbon-document';
import {createKasbonDocx} from '../../resources/js/area3/kasbon-docx';
import {docxParts} from './helpers/docx-parts';
import {type KasbonData} from '../../resources/js/area3/kasbon-content';
import {assertHeadReopenCommand} from '../../resources/js/area3/head-reopen';
function fixture(){
 const s=normalizeWorkflow(seed('E')),t=s.terms[0];s.role='Finance';s.actorUserId=s.users.find(u=>u.role==='Finance')!.id;
 Object.assign(t,{workflowVersion:3,paymentFlow:'kasbon-v1',status:'Disetujui',paid:0,apm:'',apmStatus:'Belum Terbit',paymentHistory:[],financePaymentHistory:[],financePayment:{status:'Kasbon Terbit'},kasbon:{id:'current',version:1,amount:1000,issuedBy:'admin',issuedAt:'2026-11-16T01:00:00Z'},approverUserIds:{}});delete t.transferProof;
 t.approvals=APPROVERS_V3.map((role,i)=>{const id='qa-'+i;t.approverUserIds![role]=id;return {role,userId:id,decision:'Setujui',at:'2026-11-15T01:00:00Z',note:''};});t.history=structuredClone(t.approvals);
 s.users.push({id:'manager',role:'Mgr Finance & Settlement',active:true,name:'Chosen Manager QA',email:'qa@example.test'});
 return {s,t,target:{id:t.id,kasbonId:'current'}};
}
test('legacy route moves the inbox to the exact manager, preserves five actual decisions and blocks Finance until manager approval',()=>{
 const {s,t,target}=fixture();assert.equal(financeTaskPending(t),true);assert.equal(canRouteLegacyFinance(t),true);
 const before=structuredClone(t);routeLegacyFinance(s,[target],'manager');assert.equal(financeTaskPending(t),false);assert.match(financePaymentLabel(t),/Menunggu Mgr Finance/);
 const after=structuredClone(t);delete after.legacySettlementReview;delete after.legacySettlementReviewHistory;assert.deepEqual(after,before);
 assert.throws(()=>decideFinancePayment(s,t.id,'Setujui','','current'),/Menunggu persetujuan Mgr Finance/);
 s.role='Mgr Finance & Settlement';s.actorUserId='other-manager';assert.equal(canReviewLegacySettlement(s,t),false);assert.throws(()=>decideLegacySettlementReview(s,t.id,'current','Setujui',''),/akun/);
 s.actorUserId='manager';assert.equal(canReviewLegacySettlement(s,t),true);assert.throws(()=>decideLegacySettlementReview(s,t.id,'stale','Setujui',''),/Versi/);
 decideLegacySettlementReview(s,t.id,'current','Setujui','Sesuai');assert.equal(legacyReviewApproved(t),true);assert.equal(financeTaskPending(t),true);assert.deepEqual(t.approvals,before.approvals);assert.deepEqual(t.history,before.history);assert.equal(legacyReviewDecisions(t).length,1);
});
test('routing refuses paid, already Finance-approved, seven-stage, duplicates, mixed stale targets and Head corrections without partial mutation',()=>{
 for(const change of [(t:Term)=>t.paid=1,(t:Term)=>t.apm='APM/EXISTING',(t:Term)=>t.financePayment={status:'Diverifikasi'},(t:Term)=>t.paymentFlow='kasbon-settlement-v2']){
  const {s,t,target}=fixture();change(t);assert.equal(canRouteLegacyFinance(t),false);assert.throws(()=>routeLegacyFinance(s,[target],'manager'));assert.equal(t.legacySettlementReview,undefined);
 }
 const {s,t,target}=fixture();assert.throws(()=>routeLegacyFinance(s,[target,target],'manager'),/berbeda/);
 const sibling={...structuredClone(t),id:'sibling'};s.terms.push(sibling);assert.throws(()=>routeLegacyFinance(s,[target,{id:'sibling',kasbonId:'stale'}],'manager'));assert.equal(t.legacySettlementReview,undefined);
 const spk=s.spks.find(v=>v.id===t.spkId)!,deal=s.deals.find(d=>d.id===spk.dealId)!,p=s.projects.find(p=>p.id===deal.projectId)!;p.status='Diajukan';p.headApprovalReopen={version:1} as never;assert.throws(()=>assertHeadReopenCommand(s,'routeLegacyFinance',[[target],'manager']),/Head of PMO/);
});
test('manager return requires note, returns to Admin and approval cannot carry to another kasbon',()=>{
 const {s,t,target}=fixture();routeLegacyFinance(s,[target],'manager');s.role='Mgr Finance & Settlement';s.actorUserId='manager';
 assert.throws(()=>decideLegacySettlementReview(s,t.id,'current','Kembalikan',''),/wajib/);decideLegacySettlementReview(s,t.id,'current','Kembalikan','Perbaiki');assert.equal(t.financePayment!.status,'Dikembalikan');assert.equal(financeTaskPending(t),false);assert.equal(canReviewLegacySettlement(s,t),false);
 t.kasbon!.id='new';t.financePayment={status:'Kasbon Terbit'};assert.equal(legacyReviewApproved(t),false);
});
test('additional real manager mark appears only for the bound preview; native Word keeps empty physical signature space',()=>{
 const {s,t,target}=fixture();routeLegacyFinance(s,[target],'manager');s.role='Mgr Finance & Settlement';s.actorUserId='manager';decideLegacySettlementReview(s,t.id,'current','Setujui','Sesuai');
 const d=JSON.parse(readFileSync(new URL('../Fixtures/kasbon-vector.json',import.meta.url),'utf8')) as KasbonData;d.templateVersion=2;d.printSigner={userId:'manager',name:'Chosen Manager QA',job:'Mgr Finance & Settlement Area Jawa Bali',at:'2026-11-16T01:00:00Z',by:'finance'};d.printSignerApproval=t.legacySettlementReview!.decision;
 const render=(id:string)=>renderToStaticMarkup(createElement(KasbonDocument,{data:d,term:t,kasbonId:id}));assert.match(render('current'),/APPROVED/);assert.doesNotMatch(render('archive'),/APPROVED/);
 const logo=new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url))),xml=docxParts(createKasbonDocx(d,logo,t,'current'))['word/document.xml'];assert.match(xml,/Chosen Manager QA/);assert.doesNotMatch(xml,/APPROVED/);
});
test('routing selector is limited to Finance/Superadmin and indexes hundreds of documents without per-row relation finds',async()=>{
 const {s,t}=fixture();Object.assign(globalThis,{localStorage:{getItem:()=>null,setItem:()=>{},removeItem:()=>{}},window:{addEventListener:()=>{},__AREA3__:{state:s,account:{id:s.actorUserId,role:s.role},revision:0,csrf:'qa',basePath:'',scenarios:{},urls:{}}}});
 const {LegacyFinanceRouting,legacyRoutingRows}=await import('../../resources/js/area3/legacy-finance-routing-ui');
 for(let i=0;i<500;i++)s.terms.push({...structuredClone(t),id:'many-'+i});
 let finds=0;for(const rows of [s.spks,s.deals,s.projects])Object.assign(rows,{find(){finds++;throw new Error('per-row lookup');}});
 assert.equal(legacyRoutingRows(s).length,501);assert.equal(finds,0);assert.match(renderToStaticMarkup(createElement(LegacyFinanceRouting,{s})),/Alihkan Payment Lama/);
 for(const role of ['Admin Konstruksi','Mgr Finance & Settlement','PM Konstruksi'] as Role[]){s.role=role;assert.equal(renderToStaticMarkup(createElement(LegacyFinanceRouting,{s})), '');}
});

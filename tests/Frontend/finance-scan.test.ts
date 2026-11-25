import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {type Role,type Term} from '../../resources/js/area3/domain';
import {approvalRoles,scanReviewReady,verifyTermScan,canApproveTerm} from '../../resources/js/area3/workflow';
import {PaymentEvidencePanel} from '../../resources/js/area3/payment-evidence';
import {documentStatus} from '../../resources/js/area3/partial-payment';

function fixture(operation=false){
 const s=seed('E'),t=s.terms[0];s.schema=3;s.role='Finance';s.actorUserId='qa-finance';
 Object.assign(t,{workflowVersion:3,paymentFlow:'kasbon-settlement-v2',approvalScheme:operation?'operation-v1':'project-support-first',status:'Diajukan',paid:0,apm:'',apmStatus:'Belum Terbit',kasbon:{id:'qa-kasbon',version:1,amount:100,issuedBy:'admin',issuedAt:'2026-11-25T01:00:00Z'},financePayment:{status:'Menunggu Approval'},approvals:[],history:[]});
 t.approverUserIds={};for(const role of approvalRoles(t)){const id=role==='Finance'?'qa-finance':'qa-'+role;s.users.push({id,name:role,email:id+'@example.test',active:true,role,branches:s.projects.map(p=>p.branch),workUnits:['Konstruksi','Operation']});if(role!=='Finance')t.approverUserIds[role]=id;}
 t.approvals=approvalRoles(t).slice(0,-1).map(role=>({role,userId:t.approverUserIds![role],decision:'Setujui',note:'QA',at:'2026-11-25T01:00:00Z',kasbonId:t.kasbon!.id}));t.history=structuredClone(t.approvals);delete t.scan;delete t.financeApproval;delete t.financeHistory;
 return {s,t};
}
const scan=()=>({id:'qa-scan',name:'signed.pdf',extension:'pdf' as const,mime:'application/pdf',size:100,sha256:'a'.repeat(64),uploadedBy:'qa-finance',uploadedAt:'2026-11-25T01:00:00Z',status:'Menunggu Verifikasi' as const});

test('scan review accepts the actual current six-decision prefix for both units and rejects stale UUID, actor or order',()=>{
 for(const operation of [false,true]){
  const {t}=fixture(operation),before=JSON.stringify(t);assert(scanReviewReady(t));assert.equal(JSON.stringify(t),before);
  const invalid:Term[]=[];
  for(const status of ['Draft','Dikembalikan','Ditolak'] as const)invalid.push({...t,status});
  invalid.push({...t,approvals:t.approvals.slice(0,-1)},{...t,paid:1},{...t,approvals:[...t.approvals].reverse()});
  for(const wrong of [{kasbonId:'previous'},{userId:'wrong'},{decision:'Kembalikan' as const}])invalid.push({...t,approvals:[{...t.approvals[0],...wrong},...t.approvals.slice(1)]});
  for(const bad of invalid)assert.equal(scanReviewReady(bad),false);
 }
});

test('Finance scan verification marks only the file, leaving the actual seventh approval, payment and kasbon unchanged',()=>{
 for(const operation of [false,true]){
  const {s,t}=fixture(operation);t.scan=scan();const before=structuredClone(t);
  assert(canApproveTerm(s,t));assert.equal(documentStatus(t),'OK');
  verifyTermScan(s,t.id,'Valid','Dokumen lengkap');
  assert.equal(t.scan.status,'Valid');assert.equal(t.scan.verifiedBy,'qa-finance');assert(canApproveTerm(s,t));
  assert.deepEqual(t.approvals,before.approvals);assert.deepEqual(t.history,before.history);assert.deepEqual(t.financePayment,before.financePayment);assert.deepEqual(t.kasbon,before.kasbon);assert.equal(t.financeApproval,undefined);assert.equal(t.financeHistory,undefined);assert.equal(t.paid,0);assert.equal(t.status,'Diajukan');
 }
});

test('only Finance sees the scanner; current Finance can upload before seventh decision while Admin remains read only',()=>{
 const {s,t}=fixture(),before=JSON.stringify(t);
 let html=renderToStaticMarkup(createElement(PaymentEvidencePanel,{s,t}));assert.match(html,/type="file"(?! disabled)/);assert.match(html,/Unggah Scan &amp; Tandai OK/);assert.match(html,/NOK/);
 t.approvals.push({role:'Finance',userId:'qa-finance',kasbonId:t.kasbon!.id,decision:'Setujui',at:'2026-11-25T02:00:00Z',note:'QA'});t.status='Disetujui';
 for(const role of ['Admin Konstruksi','Admin Operation','Superadmin','Mgr Finance & Settlement'] as Role[]){s.role=role;html=renderToStaticMarkup(createElement(PaymentEvidencePanel,{s,t}));assert.doesNotMatch(html,/type="file"/);assert.doesNotMatch(html,/Unggah Scan &amp; Tandai OK/);assert.match(html,/dilakukan oleh Finance/);}
 s.role='Finance';t.scan=scan();html=renderToStaticMarkup(createElement(PaymentEvidencePanel,{s,t}));assert.match(html,/OK/);assert.match(html,/Verifikasi Scan/);
 assert.notEqual(JSON.stringify(t),before); // Fixture changes above are explicit; rendering adds nothing.
 const frozen=JSON.stringify(t);renderToStaticMarkup(createElement(PaymentEvidencePanel,{s,t}));assert.equal(JSON.stringify(t),frozen);
});

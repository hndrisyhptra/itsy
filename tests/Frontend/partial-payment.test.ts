import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type State, type Role, type Term, APPROVERS_V3, normalizeWorkflow, selectActor, assignPaymentApprovers, createTerm, getTerm, saveTerm, decide, nextTermReason, requestPartialPayment, verifyPartialPayment, payPartialPayment, recordApm, termTotal, verifyTermScan } from '../../resources/js/area3/domain';
import { usesPartialPayment, documentStatus, financeTaskPending, partialFinanceHistory } from '../../resources/js/area3/partial-payment';
import { setCommandRecorder } from '../../resources/js/area3/commands';
import { PartialPaymentPanel } from '../../resources/js/area3/partial-payment-panel';
import { PaymentEvidencePanel } from '../../resources/js/area3/payment-evidence';
import { Terms } from '../../resources/js/area3/payments';
import { paymentPosition } from '../../resources/js/area3/project-timeline';
import { paymentStage, paymentLatest } from '../../resources/js/area3/payment-selection';

function actor(s:State,role:Role){s.role=role;selectActor(s,s.users.find(u=>u.active&&u.role===role)!.id);}
function fixture(final=false,all=true){
  const s=normalizeWorkflow(seed('D'));s.schema=3;
  for(const [i,role]of APPROVERS_V3.entries())if(!s.users.some(u=>u.role===role))s.users.push({id:'partial-role-'+i,name:'QA '+role,email:`partial-${i}@example.test`,role,active:true});
  actor(s,'Admin Konstruksi');assignPaymentApprovers(s,s.projects[0].id,Object.fromEntries(APPROVERS_V3.map(role=>[role,s.users.find(u=>u.role===role)!.id])));
  let t=getTerm(s,createTerm(s,s.spks[0].id));t.type=final?'Final':'Parsial';t.actuals={[s.deals[0].rows[0].rowId]:{m:1,s:1}};saveTerm(s,t,true);t=getTerm(s,t.id);
  for(const role of APPROVERS_V3.slice(0,all?5:4)){actor(s,role);decide(s,t.id,'Setujui','QA');}
  actor(s,'Admin Konstruksi');return {s,t};
}
function verified(){const {s,t}=fixture();requestPartialPayment(s,t.id);actor(s,'Finance');verifyPartialPayment(s,t.id,'Setujui','Dokumen menyusul');return {s,t};}

test('Head approval unlocks explicit Admin Payment request, then Finance verification and full APM before next term without scan',()=>{
  const {s,t}=fixture(false,false);assert.throws(()=>requestPartialPayment(s,t.id),/lima approval/);
  actor(s,'Head of PMO');decide(s,t.id,'Setujui','');actor(s,'Admin Konstruksi');const approvals=structuredClone(t.approvals);
  assert.equal(financeTaskPending(t),false);requestPartialPayment(s,t.id);assert.equal(usesPartialPayment(t),true);assert.equal(documentStatus(t),'NOK');assert.equal(t.paymentRequest!.amount,termTotal(s,t));assert.equal(financeTaskPending(t),true);
  assert.match(nextTermReason(s,t.spkId),/APM|lunas/);actor(s,'Finance');assert.throws(()=>payPartialPayment(s,t.id,'APM/PARTIAL'),/terverifikasi/);
  verifyPartialPayment(s,t.id,'Setujui','Berkas disusulkan');assert.match(paymentStage(paymentLatest(t)),/Menunggu APM/);assert.match(paymentPosition(t),/APM.*NOK/);assert.equal(t.paid,0);
  const commands:unknown[]=[];setCommandRecorder(value=>commands.push(value));try{payPartialPayment(s,t.id,'apm/partial/001');}finally{setCommandRecorder();}
  assert.equal(commands.length,1);assert.deepEqual(commands[0],{command:'payPartialPayment',args:[t.id,'apm/partial/001']});
  assert.equal(t.apm,'APM/PARTIAL/001');assert.equal(t.paid,termTotal(s,t));assert.equal(t.paymentHistory.length,1);assert.equal(t.paymentRequest!.documentsAtPayment,'NOK');assert.equal(financeTaskPending(t),false);assert.deepEqual(t.approvals,approvals);assert.equal(t.scan,undefined);
  actor(s,'Admin Konstruksi');assert.equal(nextTermReason(s,t.spkId),'');const next=getTerm(s,createTerm(s,t.spkId));assert.equal(next.number,2);assert.equal(next.type,'Parsial');
});

test('Finance return needs a note and explicit Admin re-request preserves payment cycles and all five document decisions',()=>{
  const {s,t}=fixture();requestPartialPayment(s,t.id);const approvals=structuredClone(t.approvals);actor(s,'Finance');
  assert.throws(()=>verifyPartialPayment(s,t.id,'Kembalikan',''),/Catatan/);verifyPartialPayment(s,t.id,'Kembalikan','Periksa data');assert.equal(financeTaskPending(t),false);
  const old=structuredClone(t.paymentRequest);actor(s,'Admin Konstruksi');requestPartialPayment(s,t.id);assert.equal(t.paymentRequest!.cycle,2);assert.deepEqual(t.paymentRequestHistory,[old]);assert.deepEqual(t.approvals,approvals);assert.equal(t.status,'Disetujui');assert.equal(partialFinanceHistory(t).length,1);assert.equal(financeTaskPending(t),true);
});

test('Final, historical v2, wrong roles, duplicate request and unverified APM cannot use the no-scan exception',()=>{
  const final=fixture(true);assert.throws(()=>requestPartialPayment(final.s,final.t.id),/Parsial/);actor(final.s,'Finance');assert.throws(()=>payPartialPayment(final.s,final.t.id,'APM/FINAL'),/mengajukan/);assert.throws(()=>recordApm(final.s,final.t.id,'APM/FINAL'),/scan/);
  const {s,t}=fixture();t.workflowVersion=2;assert.throws(()=>requestPartialPayment(s,t.id),/Parsial/);t.workflowVersion=3;actor(s,'Finance');assert.throws(()=>requestPartialPayment(s,t.id),/Admin/);assert.throws(()=>verifyPartialPayment(s,t.id,'Setujui',''),/mengajukan/);
  actor(s,'Admin Konstruksi');requestPartialPayment(s,t.id);assert.throws(()=>requestPartialPayment(s,t.id),/sudah diajukan/);assert.throws(()=>verifyPartialPayment(s,t.id,'Setujui',''),/Finance/);actor(s,'Finance');assert.throws(()=>recordApm(s,t.id,'APM/DIRECT'),/terverifikasi/);assert.equal(t.paid,0);
});

test('late scan verification/correction changes document status only and never resets or duplicates settled Payment',()=>{
  const {s,t}=verified();payPartialPayment(s,t.id,'APM/LATE/001');const request=structuredClone(t.paymentRequest),history=structuredClone(t.paymentHistory),amount=t.paid;
  t.scan={id:'late-private-scan',name:'signed.pdf',extension:'pdf',mime:'application/pdf',size:100,sha256:'a'.repeat(64),status:'Menunggu Verifikasi',uploadedAt:new Date().toISOString()};
  assert.equal(documentStatus(t),'OK');assert.equal(financeTaskPending(t),true);verifyTermScan(s,t.id,'Perlu Koreksi','Scan buram');assert.equal(documentStatus(t),'OK');assert.equal(t.scan.status,'Perlu Koreksi');
  t.scan={...t.scan,id:'late-scan-replacement',status:'Menunggu Verifikasi'};verifyTermScan(s,t.id,'Valid','Lengkap');assert.equal(financeTaskPending(t),false);assert.deepEqual(t.paymentRequest,request);assert.deepEqual(t.paymentHistory,history);assert.equal(t.paid,amount);assert.equal(t.apmStatus,'Valid');assert.throws(()=>payPartialPayment(s,t.id,'APM/LATE/002'),/terverifikasi/);
});

test('list shows paid value with termin description and independent NOK/OK; Finance form excludes deferred kasbon fields',()=>{
  const {s,t}=fixture();const admin=renderToStaticMarkup(createElement(PartialPaymentPanel,{s,t}));assert.match(admin,/Ajukan Payment/);assert.match(admin,/Berkas belum lengkap/);assert.doesNotMatch(admin,/Keperluan.*kasbon|Nominal kasbon/);
  requestPartialPayment(s,t.id);actor(s,'Finance');let html=renderToStaticMarkup(createElement(PartialPaymentPanel,{s,t}));assert.match(html,/Verifikasi Payment/);assert.doesNotMatch(html,/Nomor APM/);
  verifyPartialPayment(s,t.id,'Setujui','Dokumen menyusul');html=renderToStaticMarkup(createElement(PartialPaymentPanel,{s,t}));assert.match(html,/Nomor APM/);assert.match(html,/Nilai pembayaran \(otomatis\)/);
  payPartialPayment(s,t.id,'APM/VIEW/001');actor(s,'Admin Konstruksi');html=renderToStaticMarkup(createElement(Terms,{s}));assert.match(html,/Status Berkas/);assert.match(html,/Termin 1 · Parsial/);assert.match(html,/NOK/);assert.match(html,/Lunas/);
  const scanPanel=renderToStaticMarkup(createElement(PaymentEvidencePanel,{s,t}));assert.match(scanPanel,/type="file"(?! disabled)/);assert.doesNotMatch(scanPanel,/Nomor APM/);
});

test('1000-row payment list indexes loaded relationships once and bounds DOM to twenty rows without catalog finds',()=>{
  const {s,t}=verified();payPartialPayment(s,t.id,'APM/LIST/001');s.terms=Array.from({length:1000},(_,i)=>({...t,id:'large-payment-'+i}));
  for(const list of [s.spks,s.deals,s.projects])Object.defineProperty(list,'find',{value:()=>{throw new Error('Repeated relationship lookup');},configurable:true});
  const html=renderToStaticMarkup(createElement(Terms,{s}));assert.equal((html.match(/<tr>/g)??[]).length,21);assert.match(html,/1000 pengajuan/);assert.match(html,/Halaman 1\/50/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type State,type Role,APPROVERS_V3,normalizeWorkflow,selectActor,assignPaymentApprovers,createTerm,getTerm,decide,nextTermReason,recordApm,termTotal,verifyTermScan } from '../../resources/js/area3/domain';
import { submitFinanceTerm,decideFinancePayment,usesFinancePayment,adoptFinancePayment,submissionLabel,financePaymentLabel,saveSpkFinance } from '../../resources/js/area3/finance-payment';
import { financeTaskPending } from '../../resources/js/area3/partial-payment';
import { paymentPosition } from '../../resources/js/area3/project-timeline';
import { paymentStage,paymentLatest } from '../../resources/js/area3/payment-selection';
import { KasbonDocument,kasbonTerbilang,type KasbonData } from '../../resources/js/area3/kasbon-document';
import { setCommandRecorder } from '../../resources/js/area3/commands';

function actor(s:State,role:Role){s.role=role;selectActor(s,s.users.find(u=>u.active&&u.role===role)!.id);}
function fixture(final=false,all=true){
  const s=normalizeWorkflow(seed('D'));s.schema=3;
  for(const [i,role]of APPROVERS_V3.entries())if(!s.users.some(u=>u.role===role))s.users.push({id:'finance-role-'+i,name:'QA '+role,email:`finance-${i}@example.test`,role,active:true});
  actor(s,'Admin Konstruksi');assignPaymentApprovers(s,s.projects[0].id,Object.fromEntries(APPROVERS_V3.map(role=>[role,s.users.find(u=>u.role===role)!.id])));
  let t=getTerm(s,createTerm(s,s.spks[0].id));assert.equal(t.paymentFlow,'kasbon-v1');t.type=final?'Final':'Parsial';t.actuals={[s.deals[0].rows[0].rowId]:{m:1,s:1}};
  const forged={...structuredClone(t),kasbon:{id:'forged',version:1,amount:1,issuedBy:'forged',issuedAt:'forged'},transferProof:{kasbonId:'forged',id:'forged',uploadedBy:'forged',uploadedAt:'forged'}};
  const commands:unknown[]=[];setCommandRecorder(c=>commands.push(c));try{submitFinanceTerm(s,forged);}finally{setCommandRecorder();}
  assert.equal(commands.length,1);assert.equal((commands[0]as {command:string}).command,'submitFinanceTerm');
  t=getTerm(s,t.id);assert.equal(t.kasbon,undefined);assert.equal(t.transferProof,undefined);
  for(const role of APPROVERS_V3.slice(0,all?5:4)){actor(s,role);decide(s,t.id,'Setujui','');}
  actor(s,'Finance');return {s,t};
}
function issued(final=false){const {s,t}=fixture(final);t.kasbon={id:'server-kasbon',version:1,amount:termTotal(s,t),issuedBy:s.actorUserId!,issuedAt:new Date().toISOString()};t.financePayment={status:'Kasbon Terbit'};return {s,t};}

test('Partial and Final submit the same five approvals; Admin waits for Head before kasbon and Finance cannot pay early',()=>{
  const {s,t}=fixture(true,false);assert.equal(submissionLabel(t),'Ajukan Payment');assert.equal(submissionLabel({type:'Parsial'}),'Ajukan Termin');assert.equal(usesFinancePayment(t),true);assert.equal(financeTaskPending(t),false);assert.throws(()=>recordApm(s,t.id,'APM/HEAD'),/Approval/);
  assert.throws(()=>decideFinancePayment(s,t.id,'Setujui',''),/approval|Head/);actor(s,'Head of PMO');decide(s,t.id,'Setujui','');assert.match(financePaymentLabel(t),/Menunggu Pengajuan Kasbon Admin/);
});

for(const final of [false,true])test(`${final?'Final':'Partial'} requires issued kasbon, Finance decision and actual proof before full APM; late scan never pays again`,()=>{
  const {s,t}=issued(final),approvals=structuredClone(t.approvals);
  assert.throws(()=>recordApm(s,t.id,'APM/KASBON'),/Kasbon/);decideFinancePayment(s,t.id,'Setujui','Berkas menyusul');assert.throws(()=>recordApm(s,t.id,'APM/KASBON'),/bukti transfer/);
  t.transferProof={kasbonId:t.kasbon!.id,id:'server-proof',uploadedBy:s.actorUserId!,uploadedAt:new Date().toISOString()};recordApm(s,t.id,'apm/kasbon/001');assert.equal(t.apm,'APM/KASBON/001');assert.equal(t.paid,termTotal(s,t));assert.equal(t.financePayment?.documentsAtPayment,'NOK');assert.equal(t.financePayment?.kasbonId,t.kasbon!.id);assert.deepEqual(t.approvals,approvals);
  assert.match(paymentPosition(t),/lunas.*NOK/);assert.match(paymentStage(paymentLatest(t)),/APM valid/);assert.equal(financeTaskPending(t),false);assert.throws(()=>recordApm(s,t.id,'APM/DUP'),/belum dibayar/);
  actor(s,'Admin Konstruksi');if(final)assert.match(nextTermReason(s,t.spkId),/Final/);else assert.equal(nextTermReason(s,t.spkId),'');
  t.scan={id:'real-late-scan',name:'late.pdf',mime:'application/pdf',extension:'pdf',size:100,sha256:'0'.repeat(64),status:'Menunggu Verifikasi',uploadedAt:new Date().toISOString()};actor(s,'Finance');verifyTermScan(s,t.id,'Valid','');assert.equal(t.paymentHistory.length,1);assert.equal(t.financePayment?.documentsAtPayment,'NOK');
});

test('Finance return preserves five decisions and requires a fresh matching kasbon before payment',()=>{
  const {s,t}=issued(),approvals=structuredClone(t.approvals);assert.throws(()=>decideFinancePayment(s,t.id,'Kembalikan',''),/catatan/);decideFinancePayment(s,t.id,'Kembalikan','Koreksi rekening');assert.equal(t.financePayment?.status,'Dikembalikan');assert.deepEqual(t.approvals,approvals);assert.equal(t.status,'Disetujui');assert.throws(()=>recordApm(s,t.id,'APM/RETURN'),/Kasbon/);
  t.kasbon={...t.kasbon!,id:'new-server-kasbon',version:2};t.financePayment={status:'Kasbon Terbit'};decideFinancePayment(s,t.id,'Setujui','');assert.equal(t.financePayment.decision?.kasbonId,'new-server-kasbon');assert.equal(t.financePaymentHistory?.length,2);
});

test('Available scan must be verified; adopting a legacy unpaid document keeps its work decisions',()=>{
  const {s,t}=issued();t.scan={id:'scan',name:'scan.pdf',extension:'pdf',mime:'application/pdf',size:20,sha256:'0'.repeat(64),status:'Menunggu Verifikasi',uploadedAt:new Date().toISOString()};assert.throws(()=>decideFinancePayment(s,t.id,'Setujui',''),/verifikasi berkas/);verifyTermScan(s,t.id,'Valid','');decideFinancePayment(s,t.id,'Setujui','');
  const old=fixture().t;delete old.paymentFlow;delete old.financePayment;s.terms=[old];actor(s,'Admin Konstruksi');const decisions=structuredClone(old.approvals);adoptFinancePayment(s,old.id);assert.deepEqual(old.approvals,decisions);assert.equal(old.paymentFlow,'kasbon-v1');
});

test('Kasbon prints supplied saved bank/selected people with seven empty physical signatures and exact terbilang',()=>{
  assert.equal(kasbonTerbilang(1000.25),'seribu rupiah dua puluh lima sen');assert.equal(kasbonTerbilang(0),'nol rupiah');
  const data:KasbonData={costCenter:'QA-CC',wbs:'QA-WBS',activity:'Kegiatan',place:'Lokasi QA',date:'2026-11-11',evp:'EVP QA',businessSupport:'BS QA',rows:[{accountCode:'001',accountName:'Akun QA',text:'Pekerjaan <QA>',amount:'1000.25'}],payee:{nik:'0011223344556677',accountNumber:'000123456',accountName:'Penerima QA',bank:'Bank QA'},mandor:'Mandor QA',unit:'Konstruksi · QA',project:'Nama QA',projectCode:'ID-QA',ppNo:'QA/PP',spkNo:'QA/SPK',termNumber:2,termType:'Parsial',amount:1000.25,signers:Object.fromEntries(APPROVERS_V3.map(role=>[role,{name:'Aktual '+role,userId:'saved-'+role}])),issuer:'Finance Aktual'};
  const html=renderToStaticMarkup(createElement(KasbonDocument,{data}));assert.match(html,/000123456/);assert.match(html,/Pekerjaan &lt;QA&gt;/);assert.equal((html.match(/class="kasbon-wet-signature"/g)??[]).length,7);assert.match(html,/Aktual Mgr Project Support/);assert.match(html,/EVP QA/);assert.match(html,/BS QA/);assert.doesNotMatch(html,/0011223344556677|data:image|APPROVED|Disetujui/);
});

test('SPK financial draft emits one explicit command and keeps only financial marker in public state',()=>{
  const {s}=fixture();actor(s,'Admin Konstruksi');s.spks[0].status='Draft';const commands:{command:string;args:unknown[]}[]=[];
  setCommandRecorder(c=>commands.push(c));try{saveSpkFinance(s,structuredClone(s.spks[0]),'save');}finally{setCommandRecorder();}
  assert.equal(commands.length,1);assert.equal(commands[0].command,'saveSpkFinance');assert.equal(s.spks[0].financialFlow,'kasbon-v1');assert.equal('accountNumber' in s.spks[0],false);
});

test('Current account runtime cannot start another term from historical APM with an unpaid bill',()=>{
  const {s,t}=fixture();t.apmStatus='Valid';t.apm='APM/HISTORICAL';delete t.paymentFlow;delete t.financePayment;t.paid=0;actor(s,'Admin Konstruksi');
  assert.match(nextTermReason(s,t.spkId),/lunas/);assert.throws(()=>createTerm(s,t.spkId),/lunas/);
});

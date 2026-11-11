import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type Project, type BoqRow, APPROVERS_V3, normalizeWorkflow, today } from '../../resources/js/area3/domain';
import { adminKasbonPending, decideFinancePayment } from '../../resources/js/area3/finance-payment';
import { financeTaskPending } from '../../resources/js/area3/partial-payment';
import { kasbonInput } from '../../resources/js/area3/kasbon-form';
import { KasbonDocument, type KasbonData } from '../../resources/js/area3/kasbon-document';
import { boqMandorPreviewRows, BoqMandorDocument } from '../../resources/js/area3/boq-mandor-preview';
import { financeReviewContext, FINANCE_REVIEW_DOCUMENTS, FinanceReviewButton } from '../../resources/js/area3/finance-review';
import { ProjectInbox } from '../../resources/js/area3/workflow-ui';
import { ApprovalInbox } from '../../resources/js/area3/payments';
import { KasbonPayeeReview } from '../../resources/js/area3/finance-private-ui';

function fixture(){
  const s=normalizeWorkflow(seed('E'));s.schema=3;const p=s.projects[0];p.workflowVersion=3;p.approvalScheme='project-support-first';
  const t=s.terms[0];t.workflowVersion=3;t.paymentFlow='kasbon-v1';t.approvalScheme='project-support-first';t.status='Disetujui';t.paid=0;t.apm='';t.apmStatus='Belum Terbit';t.financePayment={status:'Menunggu Approval'};
  t.approverUserIds={};p.approverUserIds={};t.approvals=APPROVERS_V3.map((role,i)=>{const id='admin-kasbon-'+i;s.users.push({id,name:'QA '+role,role,email:`kasbon${i}@example.test`,active:true});t.approverUserIds![role]=id;p.approverUserIds![role]=id;return {role,userId:id,actorName:'QA '+role,decision:'Setujui' as const,note:'Saved decision',at:'2026-11-11T01:00:00Z'};});
  delete t.scan;delete t.kasbon;delete t.transferProof;
  return {s,p,t};
}
function kasbon():KasbonData {
  return {...kasbonInput(1000),templateVersion:2,issuerRole:'Admin Konstruksi',date:'2026-11-11',payee:{nik:'0011223344556677',accountNumber:'0001234567',accountName:'Mandor QA',bank:'Bank QA'},mandor:'Ketua Mandor QA',unit:'Konstruksi',project:'Proyek QA',projectCode:'QA-01',ppNo:'PP/QA',spkNo:'SPK/QA',termNumber:1,termType:'Parsial',amount:1000,signers:{},issuer:'Admin Aktual'};
}

test('Head completion creates an Admin kasbon task; Finance waits for kasbon and returns corrections to Admin',()=>{
  const {t}=fixture();assert.equal(adminKasbonPending(t),true);assert.equal(financeTaskPending(t),false);
  const head=t.approvals.pop()!;t.status='Diajukan';assert.equal(adminKasbonPending(t),false);t.status='Disetujui';assert.equal(adminKasbonPending(t),false);
  t.approvals.push(head);t.kasbon={id:'saved',version:1,amount:1000,issuedBy:'admin',issuedRole:'Admin Konstruksi',issuedAt:head.at};t.financePayment={status:'Kasbon Terbit'};
  assert.equal(adminKasbonPending(t),false);assert.equal(financeTaskPending(t),true);
  t.financePayment.status='Dikembalikan';assert.equal(adminKasbonPending(t),true);assert.equal(financeTaskPending(t),false);
  t.apmStatus='Valid';t.paid=1000;assert.equal(adminKasbonPending(t),false);assert.equal(financeTaskPending(t),false);
  t.scan={id:'scan',name:'scan.pdf',extension:'pdf',mime:'application/pdf',size:1,sha256:'a'.repeat(64),uploadedAt:head.at,status:'Menunggu Verifikasi'};assert.equal(financeTaskPending(t),true);
});

test('new kasbon defaults match user names and Surabaya; a correction keeps saved fields without rewriting old data',()=>{
  const form=kasbonInput(1234.5);assert.equal(form.place,'Surabaya');assert.equal(form.evp,'I GEDE WAHYU DANYNDRA');assert.equal(form.businessSupport,"ARIZA ARBA'ATUS S");assert.equal(form.date,today());assert.equal(form.rows[0].amount,'1234.5');
  const previous=kasbon();previous.place='Lokasi tersimpan';previous.evp='Nama historis';previous.date='2026-10-01';const before=JSON.stringify(previous);
  const revised=kasbonInput(1000,previous);assert.equal(revised.place,'Lokasi tersimpan');assert.equal(revised.evp,'Nama historis');assert.equal(revised.date,today());assert.equal(JSON.stringify(previous),before);
});

test('Finance decision is bound to the kasbon version actually shown by the review panel',()=>{
  const {s,t}=fixture();s.role='Finance';s.actorUserId=s.users.find(u=>u.active&&u.role==='Finance')!.id;
  t.kasbon={id:'current-kasbon',version:2,amount:1000,issuedBy:'admin',issuedRole:'Admin Konstruksi',issuedAt:'2026-11-11T01:00:00Z'};t.financePayment={status:'Kasbon Terbit'};
  const before=JSON.stringify(t);assert.throws(()=>decideFinancePayment(s,t.id,'Setujui','Reviewed old','old-kasbon'),/Versi kasbon berubah/);assert.equal(JSON.stringify(t),before);
  decideFinancePayment(s,t.id,'Setujui','Reviewed current','current-kasbon');assert.equal(t.financePayment.status,'Diverifikasi');assert.equal(t.financePayment.decision?.kasbonId,'current-kasbon');
});

test('v2 print uses actual Admin maker, provided default officials and Surabaya date above a blank Mandor signature',()=>{
  const data=kasbon(),html=renderToStaticMarkup(createElement(KasbonDocument,{data}));
  assert.match(html,/Admin Aktual/);assert.match(html,/Admin Konstruksi · Pengaju Kasbon/);assert.match(html,/I GEDE WAHYU DANYNDRA/);assert.match(html,/ARIZA ARBA&#x27;ATUS S/);
  const block=html.slice(html.indexOf('class="kasbon-mandor-signature"'),html.indexOf('<table class="kasbon-signatures"'));
  assert.match(block,/Surabaya, 11 November 2026/);assert.ok(block.indexOf('Surabaya')<block.indexOf('Ketua Mandor'));
  assert.match(block,/Ketua Mandor QA/);assert.equal((html.match(/class="kasbon-wet-signature"/g)??[]).length,8);
  assert.doesNotMatch(html,/Finance · Penerbit Kasbon|data:image|0011223344556677|APPROVED/);
});

test('Mandor preview takes saved selected-PP prices and independent quantities, including continuation and missing components',()=>{
  const {s,p}=fixture();const row:BoqRow={...p.boq[0],m:1000,s:2000};p.boq=[row];p.planBudget={version:2,mandor:[{rowId:row.id,qtyM:5,qtyS:5,priceM:9999,priceS:8888}],materials:[],bop:[],permit:0,usage:[]};
  const child:Project={...structuredClone(p),id:'child',no:'CHILD/PP',parentId:p.id,rootId:p.id,allocations:{[row.id]:{m:2,s:3}}};child.planBudget!.mandor=[{rowId:row.id,qtyM:.125,qtyS:.375,priceM:11,priceS:22}];s.projects.push(child);
  const before=JSON.stringify(s),[preview]=boqMandorPreviewRows(s,child);assert.equal(preview.priceM,11);assert.equal(preview.priceS,22);assert.equal(preview.amountM,1.38);assert.equal(preview.amountS,8.25);
  assert.equal(preview.qtyM,.125);assert.equal(preview.qtyS,.375);assert.equal(JSON.stringify(s),before);
  const html=renderToStaticMarkup(createElement(BoqMandorDocument,{s,p:child}));assert.match(html,/CHILD\/PP/);assert.doesNotMatch(html,/9\.999|8\.888|N\/A/);
  row.m=null;child.planBudget!.mandor[0].priceS=0;const [excluded]=boqMandorPreviewRows(s,child);assert.equal(excluded.material,false);assert.equal(excluded.service,true);assert.equal(excluded.amountS,0);
  const legacy={...p,planBudget:undefined};assert.match(renderToStaticMarkup(createElement(BoqMandorDocument,{s,p:legacy})),/belum memiliki rincian BOQ Mandor/);
});

test('Finance review resolves the selected payment rather than the first PP and never offers private review to an approver',()=>{
  const {s,p,t}=fixture(),spk=s.spks.find(v=>v.id===t.spkId)!,deal=s.deals.find(d=>d.id===spk.dealId)!;
  const sibling={...structuredClone(p),id:'selected-pp',no:'SELECTED/PP'};s.projects.push(sibling);s.deals.push({...deal,id:'selected-deal',projectId:sibling.id});s.spks.push({...spk,id:'selected-spk',dealId:'selected-deal'});
  const selected={...t,id:'selected-term',spkId:'selected-spk'},before=JSON.stringify(s);
  assert.equal(financeReviewContext(s,selected).p.id,sibling.id);assert.equal(financeReviewContext(s,selected).spk.id,'selected-spk');assert.equal(JSON.stringify(s),before);
  s.role='Finance';assert.match(renderToStaticMarkup(createElement(FinanceReviewButton,{s,t:selected,identity:null})),/Review Berkas Swakelola/);
  s.role='PM Konstruksi';assert.equal(renderToStaticMarkup(createElement(FinanceReviewButton,{s,t:selected,identity:null})), '');
  for(const label of ['Project Plan','BOQ TA - Mandor','BA Kesepakatan Harga','Lampiran BA KH','Surat Perintah Kerja','Lampiran SPK','BA Rekonsiliasi Pekerjaan – Termin','Lampiran Rekonsiliasi Pekerjaan','Eviden Pekerjaan','Scan TTD','Identitas & Rekening Mandor','Form Kasbon'])assert.ok((FINANCE_REVIEW_DOCUMENTS as readonly string[]).includes(label));
  assert.throws(()=>financeReviewContext(s,{...selected,spkId:'missing'}));
});

test('Finance recipient review shows the frozen kasbon version and account; latest SPK profile is only loaded on explicit request',()=>{
  const {s,t}=fixture(),data=kasbon();s.role='Finance';data.payee.version=4;data.payee.accountNumber='0009876543';data.payee.ktpFileId='saved-private-photo';data.payee.bankFileId='saved-private-bank';
  const before=JSON.stringify(data),html=renderToStaticMarkup(createElement(KasbonPayeeReview,{s,t,kasbon:data}));
  assert.match(html,/Versi data Mandor: 4/);assert.match(html,/0009876543/);assert.match(html,/Foto KTP pada Kasbon/);assert.match(html,/Buku Tabungan pada Kasbon/);assert.match(html,/Lihat Data SPK Terkini/);
  assert.doesNotMatch(html,/Simpan Data Mandor|Foto KTP terpilih/);assert.equal(JSON.stringify(data),before);
  s.role='PM Konstruksi';assert.equal(renderToStaticMarkup(createElement(KasbonPayeeReview,{s,t,kasbon:data})), '');
});

test('500-row Mandor preview has no per-row catalog finds; 1000-task PP/payment inboxes render at most twenty task rows',()=>{
  const {s,p,t}=fixture();const k=s.khs[0];p.boq=Array.from({length:500},(_,i)=>({...p.boq[0],id:'row-'+i,khsId:k.id,m:10,s:null}));
  p.planBudget={version:2,mandor:p.boq.map(r=>({rowId:r.id,qtyM:1,qtyS:0,priceM:1,priceS:0})),materials:[],bop:[],permit:0,usage:[]};
  s.khs.find=()=>{throw new Error('No per-row catalog search');};assert.equal(boqMandorPreviewRows(s,p).length,500);
  const html=renderToStaticMarkup(createElement(BoqMandorDocument,{s,p}));assert.ok(html.includes('<td>500</td>'));
  s.role='PM Konstruksi';s.actorUserId=p.approverUserIds!['PM Konstruksi'];p.status='Diajukan';p.approvals=[];s.projects=Array.from({length:1000},(_,i)=>({...p,id:'inbox-pp-'+i,no:'INBOX/PP/'+i}));
  const ppInbox=renderToStaticMarkup(createElement(ProjectInbox,{s}));assert.equal((ppInbox.match(/Preview BOQ TA Mandor/g)??[]).length,20);assert.match(ppInbox,/1000 PP/);
  s.projects=[p];t.status='Diajukan';t.approvals=[];s.terms=Array.from({length:1000},(_,i)=>({...t,id:'inbox-term-'+i}));
  const original=Object.getOwnPropertyDescriptor(globalThis,'location');Object.defineProperty(globalThis,'location',{configurable:true,value:{search:''}});
  try{const inbox=renderToStaticMarkup(createElement(ApprovalInbox,{s}));assert.equal((inbox.match(/class="task-row /g)??[]).length,20);assert.match(inbox,/1000 pengajuan/);assert.match(inbox,/Preview BOQ TA Mandor/);}finally{if(original)Object.defineProperty(globalThis,'location',original);else delete(globalThis as Record<string,unknown>).location;}
});

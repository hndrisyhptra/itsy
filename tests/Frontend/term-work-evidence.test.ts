import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type Term, type WorkEvidence, removeTermWorkEvidence, saveTerm, getTerm } from '../../resources/js/area3/domain';
import { TermWorkEvidencePanel, WorkEvidencePreview, workEvidenceUrl } from '../../resources/js/area3/term-work-evidence';

function fixture() {
  const s=seed('D'),spk=s.spks[0],deal=s.deals.find(d=>d.id===spk.dealId)!;
  const file:WorkEvidence={id:'2811f464-c30c-4b70-8471-a4d8f14666de',name:'Eviden pekerjaan.pdf',mime:'application/pdf',extension:'pdf',size:1234,sha256:'a'.repeat(64),uploadedBy:'user-1',uploadedAt:'2026-11-05T03:00:00.000Z'};
  const t:Term={id:'term-evidence-qa',spkId:spk.id,number:1,type:'Parsial',status:'Draft',actuals:{},approvals:[],history:[],apm:'',apmStatus:'Belum Terbit',paid:0,paymentHistory:[],workEvidence:[file]};
  const row=deal.rows[0];t.actuals={[row.rowId]:{m:row.m ? .125 : 0,s:row.s ? .125 : 0}};
  s.terms.push(t);return {s,t,file};
}

test('work evidence PDF and photo previews use authenticated application routes and safe names',()=>{
  const {t,file}=fixture(),url=workEvidenceUrl(t.id,file.id);
  const pdf=renderToStaticMarkup(createElement(WorkEvidencePreview,{termId:t.id,file}));
  assert.match(pdf,/<iframe/);assert.ok(pdf.includes('src="'+url+'"'));assert.match(pdf,/title="Preview PDF Eviden pekerjaan.pdf"/);
  const photo=renderToStaticMarkup(createElement(WorkEvidencePreview,{termId:t.id,file:{...file,name:'<script>.png',mime:'image/png',extension:'png'}}));
  assert.match(photo,/<img/);assert.doesNotMatch(photo,/<script>/);assert.match(photo,/&lt;script&gt;/);
  assert.doesNotMatch(pdf+photo,/\/storage\/|data:|blob:/);
});

test('approvers see preview and download controls in the inbox without administrative upload or remove controls',()=>{
  const {s,t,file}=fixture();s.role='PM Konstruksi';t.status='Diajukan';
  const inbox=renderToStaticMarkup(createElement(TermWorkEvidencePanel,{s,term:t,embedded:true}));
  assert.match(inbox,/Eviden pekerjaan \(1\)/);assert.match(inbox,/Preview/);assert.match(inbox,/\?download=1/);
  assert.doesNotMatch(inbox,/type="file"|Hapus eviden/);
  s.role='Admin Konstruksi';t.status='Draft';
  const admin=renderToStaticMarkup(createElement(TermWorkEvidencePanel,{s,term:t}));
  assert.match(admin,/Upload eviden pekerjaan/);assert.match(admin,/multiple=""/);assert.match(admin,/\.pdf,.jpg,.jpeg,.png,.webp/);
  assert.match(admin,/Hapus eviden/);assert.match(admin,/10 MB/);
  t.workEvidence=[{...file,removedAt:'2026-11-05T04:00:00Z',removedBy:'user-1'}];
  assert.match(renderToStaticMarkup(createElement(TermWorkEvidencePanel,{s,term:t,embedded:true})),/Riwayat lampiran yang dikeluarkan \(1\)/);
});

test('removing evidence preserves metadata and history and is locked outside draft or returned status',()=>{
  const {s,t,file}=fixture();removeTermWorkEvidence(s,t.id,file.id);
  assert.equal(t.workEvidence!.length,1);assert.ok(t.workEvidence![0].removedAt);assert.equal(t.workEvidence![0].sha256,'a'.repeat(64));
  assert.throws(()=>removeTermWorkEvidence(s,t.id,file.id),/tidak ditemukan/);
  for(const status of ['Diajukan','Ditolak','Disetujui']as const){const f=fixture();f.t.status=status;const before=JSON.stringify(f.s);assert.throws(()=>removeTermWorkEvidence(f.s,f.t.id,f.file.id),/Lampiran terkunci/);assert.equal(JSON.stringify(f.s),before);}
  const wrong=fixture();wrong.s.role='PM Konstruksi';assert.throws(()=>removeTermWorkEvidence(wrong.s,wrong.t.id,wrong.file.id),/Admin/);
});

test('saving actuals cannot replace server evidence, scan, Finance approval or payment metadata',()=>{
  const {s,t,file}=fixture(),input=structuredClone(t);input.workEvidence=[];input.scan={id:'forged',name:'fake.pdf',extension:'pdf',mime:'application/pdf',size:1,sha256:'x',status:'Valid',uploadedAt:'2026-11-05'};input.apmStatus='Valid';input.paid=99999;
  saveTerm(s,input,true);const saved=getTerm(s,t.id);
  assert.equal(saved.status,'Diajukan');assert.equal(saved.workEvidence![0].id,file.id);assert.equal(saved.scan,undefined);assert.equal(saved.apmStatus,'Belum Terbit');assert.equal(saved.paid,0);
});

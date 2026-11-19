import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement as h} from 'react';
import {renderToStaticMarkup as render} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {saveTerm,attestBapp,releaseVolume,createContinuation,getTerm,ledger,prepareBapp} from '../../resources/js/area3/domain';
import {blankBarp} from '../../resources/js/area3/barp-form';
import {bappCurrent,assertBappBeforePayment,ensureBapp} from '../../resources/js/area3/bapp-workflow';
import {BappDetail} from '../../resources/js/area3/payments';
import {DocumentPreview} from '../../resources/js/area3/ui';
import {createBappDocx} from '../../resources/js/area3/bapp-docx';
import {createBappAppendixDocx} from '../../resources/js/area3/bapp-appendix-docx';
import {docxParts} from './helpers/docx-parts';
import {paymentFlowSteps} from '../../resources/js/area3/workflow-overview';

function fixture(){
 const s=seed('E'),t=s.terms.find(t=>t.type==='Final')!,v=s.spks.find(v=>v.id===t.spkId)!,d=s.deals.find(d=>d.id===v.dealId)!,p=s.projects.find(p=>p.id===d.projectId)!;
 s.role='Admin Konstruksi';s.schema=3;s.bapps=[];t.workflowVersion=3;t.paymentFlow='kasbon-settlement-v2';t.status='Draft';t.approvals=[];t.history=[];t.paid=0;t.apm='';t.apmStatus='Belum Terbit';t.barp=blankBarp(s,t);t.barp.date=v.start;t.actuals={[d.rows[0].rowId]:{m:1,s:1}};
 saveTerm(s,t,false);return {s,t:getTerm(s,t.id),v,d,p,b:s.bapps[0]};
}
test('saved Final BARP creates one BAPP and Admin can attest before approval without releasing or paying',()=>{
 const {s,t,b,p,d}=fixture(),before=ledger(s,p.rootId,d.rows[0].rowId,'s');
 assert.equal(b.status,'Draft');assert.equal(t.status,'Draft');assert.equal(t.approvals.length,0);assert.throws(()=>assertBappBeforePayment(s,t),/Sahkan BA PP/);
 attestBapp(s,b.id,b.no);assert(bappCurrent(b,t));assert.deepEqual(before,ledger(s,p.rootId,d.rows[0].rowId,'s'));assert.doesNotThrow(()=>assertBappBeforePayment(s,t));
 assert.throws(()=>releaseVolume(s,b.id,{[d.rows[0].rowId]:{m:1,s:1}}),/approval Final/);assert.throws(()=>createContinuation(s,b.id,'',{},''),/Final approved/);assert.equal(t.paid,0);assert.equal(t.approvals.length,0);
 saveTerm(s,t,false);assert.equal(s.bapps.length,1);assert.equal(b.status,'Sah');assert.equal(b.attestationHistory,undefined);
 const steps=paymentFlowSteps(t,b);assert.deepEqual(steps.slice(0,3).map(s=>s.label),['BA RP','Sahkan BA PP','Kasbon Admin']);assert(steps[1].done);
});
test('BARP changes invalidate only its BAPP with retained source and require reattestation; missing dates and other roles are rejected',()=>{
 const {s,t,b}=fixture();attestBapp(s,b.id,b.no);const source=structuredClone(b.source);const input=structuredClone(t);input.actuals[Object.keys(input.actuals)[0]].s=2;saveTerm(s,input,false);
 assert.equal(b.status,'Draft');assert.equal(b.attestationHistory?.length,1);assert.deepEqual(b.attestationHistory![0].source,source);assert.throws(()=>assertBappBeforePayment(s,getTerm(s,t.id)),/Sahkan BA PP/);
 s.role='Finance';assert.throws(()=>attestBapp(s,b.id,b.no),/Admin/);s.role='Admin Konstruksi';const invalid=structuredClone(getTerm(s,t.id));invalid.barp!.date='';saveTerm(s,invalid,false);assert.throws(()=>attestBapp(s,b.id,b.no),/Lengkapi tanggal/);assert.equal(b.status,'Draft');
});
test('Sah BAPP before payment approval prints and exports both Word documents with blank TTD; preview and timeline remain separate from approval',()=>{
 const {s,t,b,p,d,v}=fixture();attestBapp(s,b.id,b.no);const before=JSON.stringify(s);
 const detail=render(h(BappDetail,{s,id:b.id}));assert(detail.includes('BA PP dapat disahkan sejak BA RP'));assert(detail.includes('Lanjut Kasbon &amp; Pembayaran'));assert.equal(t.approvals.length,0);
 for(const kind of ['BA Penyelesaian Pekerjaan','Lampiran BA Penyelesaian Pekerjaan']){
  const html=render(h(DocumentPreview,{state:s,project:p,deal:d,spk:v,term:t,bapp:b,kind,onClose:()=>{}}));assert(!html.includes('DRAF — BELUM DIAJUKAN'));assert(!html.includes('SALINAN REVIEW'));assert(!html.includes('disabled="" aria-busy="false" aria-label="Unduh'));
 }
 for(const bytes of [createBappDocx(p,d,v,t,b),createBappAppendixDocx(s,p,d,v,t,b)]){const xml=docxParts(bytes)['word/document.xml'];assert(!/APPROVED|DRAF —|SALINAN REVIEW/.test(xml));}
 assert.equal(JSON.stringify(s),before);
 p.status='Diajukan';assert.throws(()=>createBappDocx(p,d,v,t,b),/harus Sah/);assert.throws(()=>createBappAppendixDocx(s,p,d,v,t,b),/harus Sah/);
});
test('legacy pending Final prepares explicitly with unchanged approvals and no duplicate completion record',()=>{
 const {s,t}=fixture();s.bapps=[];delete t.completionFlow;t.status='Diajukan';const before=structuredClone(t.approvals),id=prepareBapp(s,t.id);assert.equal(prepareBapp(s,t.id),id);assert.equal(s.bapps.length,1);assert.deepEqual(t.approvals,before);assert.equal(t.status,'Diajukan');
});

test('Partial BAPP is source-bound before kasbon, never grants release, and each term has its own number',()=>{
  const {s,t}=fixture();t.type='Parsial';t.completionFlow='bapp-each-payment-v2';
  const first=ensureBapp(s,t,true);assert.equal(first.completionPolicy,'barp-each-payment-v2');
  assert.throws(()=>assertBappBeforePayment(s,t),/Sahkan BA PP/);
  attestBapp(s,first.id,first.no);assert.equal(bappCurrent(first,t),true);assert.equal(t.approvals.length,0);assert.equal(t.paid,0);
  assert.throws(()=>releaseVolume(s,first.id,{}),/Final/);
  const second={...structuredClone(t),id:'new-term-2',number:2};s.terms.push(second);const next=ensureBapp(s,second,true);assert.notEqual(next.no,first.no);assert.equal(first.termId,t.id);assert.equal(next.termId,second.id);
  const changed={...structuredClone(t),actuals:Object.fromEntries(Object.entries(t.actuals).map(([id,q])=>[id,{m:q.m/2,s:q.s/2}]))};ensureBapp(s,changed,true);assert.equal(first.status,'Draft');assert.equal(first.attestationHistory?.length,1);assert.equal(bappCurrent(first,changed),false);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement as h} from 'react';
import {renderToStaticMarkup as render} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {type Bapp,type Term,termTotal,attestBarp,prepareBapp,attestBapp,getTerm,saveTerm} from '../../resources/js/area3/domain';
import {barpCurrent,bappCurrent,bappBasis,ensureBapp} from '../../resources/js/area3/bapp-workflow';
import {bappDocumentData} from '../../resources/js/area3/bapp-content';
import {canPrintBarp,canExportBarp} from '../../resources/js/area3/barp-content';
import {DocumentPreview} from '../../resources/js/area3/ui';
import {blankBarp} from '../../resources/js/area3/barp-form';
import {savedBappPreparationAllowed,workDocumentRows,workPaymentReady} from '../../resources/js/area3/work-document-flow';
import {WorkDocuments} from '../../resources/js/area3/work-documents';
import {TermEditor,BappDetail} from '../../resources/js/area3/payments';
import {dashboardSummary} from '../../resources/js/area3/dashboard-summary';
import {settlementRecorded} from '../../resources/js/area3/payment-settlement';
import {createBappDocx} from '../../resources/js/area3/bapp-docx';
import {createBappAppendixDocx} from '../../resources/js/area3/bapp-appendix-docx';
import {docxParts} from './helpers/docx-parts';

function fixture(){const s=seed('E'),t=s.terms[0],v=s.spks.find(v=>v.id===t.spkId)!,d=s.deals.find(d=>d.id===v.dealId)!,p=s.projects.find(p=>p.id===d.projectId)!;s.schema=3;s.role='Admin Konstruksi';s.bapps=[];s.terms=[t];t.type='Parsial';t.status='Draft';t.completionFlow='bapp-each-payment-v2';t.paymentFlow='kasbon-settlement-v2';t.workflowVersion=3;t.paid=0;t.apm='';t.apmStatus='Belum Terbit';t.approvals=[];t.history=[];t.barp=blankBarp(s,t);t.barp.date=v.start;t.actuals={[d.rows[0].rowId]:{m:1,s:1}};saveTerm(s,t,false);return {s,t:getTerm(s,t.id),v,d,p};}

test('work page has an explicit attest action in its document card and no premature kasbon or payment submission',()=>{
 const {s,t}=fixture(),before=JSON.stringify(s),html=render(h(TermEditor,{s,id:t.id,stage:'work'}));
 assert.ok(html.includes('Sahkan BA RP'));assert.ok(html.includes('Preview BA RP'));assert.ok(html.includes('Lampiran BA RP'));assert.ok(html.includes('Volume Aktual Pekerjaan'));
 assert.doesNotMatch(html,/Kasbon Admin &amp; Review Pembayaran Finance|Ajukan Termin<\/button>|Ajukan Payment<\/button>|Isi Kasbon &amp; Ajukan Pembayaran<\/button>/);assert.equal(JSON.stringify(s),before);
});

test('Partial completion exposes preview and attestation then payment, without a release panel or full-package button',()=>{
 const {s,t,p,d,v}=fixture(),b=s.bapps[0];let html=render(h(BappDetail,{s,id:b.id}));
 assert.ok(html.includes('Sahkan BA PP &amp; Lanjut Kasbon'));assert.ok(html.indexOf('Pengesahan Dokumen BA PP')<html.indexOf('Rekonsiliasi Pekerjaan'));
 assert.doesNotMatch(html,/Pelepasan Volume &amp; PP Lanjutan|Unduh semua dokumen PP sampai/);assert.equal(workPaymentReady(t,b),false);
 attestBapp(s,b.id,b.no);assert.equal(workPaymentReady(t,b),true);html=render(h(BappDetail,{s,id:b.id}));assert.ok(html.includes('Lanjut Kasbon &amp; Pembayaran'));assert.equal(t.approvals.length,0);
 for(const bytes of [createBappDocx(p,d,v,t,b),createBappAppendixDocx(s,p,d,v,t,b)])assert.doesNotMatch(docxParts(bytes)['word/document.xml'],/APPROVED|SALINAN REVIEW|DRAF —/);
});

test('exact term joins prevent sibling BAPP reuse and cumulative totals exclude later payments',()=>{
 const {s,t}=fixture(),b=s.bapps[0];const later={...structuredClone(t),id:'later-term',number:2,status:'Disetujui' as const};s.terms.push(later);s.bapps=[{...b,id:'later-bapp',termId:later.id}];
 let rows=workDocumentRows(s);assert.equal(rows.find(r=>r.t.id===t.id)?.b,undefined);assert.equal(rows.find(r=>r.t.id===t.id)?.cumulative,0);
 s.bapps.push(b);rows=workDocumentRows(s);assert.equal(rows.find(r=>r.t.id===t.id)?.cumulative,termTotal(s,t));assert.equal(rows.find(r=>r.t.id===t.id)?.b?.id,b.id);
});

test('a settled Partial BAPP never closes its PP in the dashboard',()=>{
 const {s,t,p}=fixture(),b=s.bapps[0];attestBapp(s,b.id,b.no);t.status='Disetujui';t.apmStatus='Valid';t.paid=termTotal(s,t);assert.equal(settlementRecorded(t),true);assert.equal(dashboardSummary(s).activeProjects,s.projects.filter(p=>p.status==='Sah').length);assert.equal(p.status,'Sah');
});

test('work catalog renders only twenty of a thousand scoped records',()=>{
 const {s,t,v,d,p}=fixture();s.terms=[];s.spks=[];s.deals=[];s.projects=[];s.bapps=[];
 for(let i=0;i<1000;i++){const id='qa-'+i;s.projects.push({...p,id:'pp-'+id,projectCode:'PROJECT-'+i});s.deals.push({...d,id:'deal-'+id,projectId:'pp-'+id});s.spks.push({...v,id:'spk-'+id,dealId:'deal-'+id});s.terms.push({...t,id:'term-'+id,spkId:'spk-'+id});}
 const html=render(h(WorkDocuments,{s}));assert.equal((html.match(/<tr>/g)??[]).length,21);assert.ok(html.includes('1000 BA RP'));assert.equal(workDocumentRows(s).length,1000);
});


test('new BA RP requires a genuine source-bound attestation before an explicit BA PP creation',()=>{
 const {s,t}=fixture();s.bapps=[];s.actorUserId='qa-admin';t.barpFlow='attest-before-bapp-v1';
 saveTerm(s,t,false);let saved=getTerm(s,t.id);assert.equal(s.bapps.length,0);assert.equal(barpCurrent(saved),false);
 assert.throws(()=>prepareBapp(s,t.id),/Sahkan BA RP/);assert.equal(canPrintBarp(saved),false);
 let html=render(h(TermEditor,{s,id:t.id,stage:'work'}));assert(html.includes('Sahkan BA RP'));assert(!html.includes('Buat BA PP</button>'));
 attestBarp(s,saved);saved=getTerm(s,t.id);assert(barpCurrent(saved));assert.equal(saved.barpAttestation?.by,'qa-admin');assert.equal(s.bapps.length,0);assert.equal(saved.approvals.length,0);assert.equal(saved.paid,0);
 assert(canPrintBarp(saved));assert(canExportBarp(saved));html=render(h(TermEditor,{s,id:t.id,stage:'work'}));assert(html.includes('Buat BA PP</button>'));
 const id=prepareBapp(s,t.id),b=s.bapps.find(b=>b.id===id)!;assert.equal(prepareBapp(s,t.id),id);assert.equal(s.bapps.length,1);assert.equal(b.date,saved.barp?.date);assert.equal(b.status,'Draft');
});

test('BA PP date is independent, calendar validated and shared by its preview and native Word',()=>{
 const {s,t,p,d,v}=fixture();s.bapps=[];s.actorUserId='qa-admin';t.barpFlow='attest-before-bapp-v1';attestBarp(s,t);const saved=getTerm(s,t.id),id=prepareBapp(s,t.id),b=s.bapps.find(b=>b.id===id)!;
 assert.throws(()=>createBappDocx(p,d,v,saved,b),/Word BA PP/);
 let html=render(h(DocumentPreview,{state:s,kind:'BA Penyelesaian Pekerjaan',project:p,deal:d,spk:v,term:saved,bapp:b,onClose:()=>{}}));assert(html.includes('DRAF — BA PP BELUM DISAHKAN'));assert.match(html,/<button[^>]*disabled[^>]*[^>]*>[^]*?Cetak/);
 assert.throws(()=>attestBapp(s,id,b.no,undefined,'2026-02-30'),/tanggal/);assert.equal(b.status,'Draft');
 attestBapp(s,id,b.no,undefined,'2026-11-23');assert.equal(b.date,'2026-11-23');assert.notEqual(b.date,saved.barp?.date);assert(bappCurrent(b,saved));
 const data=bappDocumentData(p,d,v,saved,b);assert(data.placeDate.endsWith('23 November 2026'));
 const xml=docxParts(createBappDocx(p,d,v,saved,b))['word/document.xml'];assert(xml.includes('23 November 2026'));assert(!xml.includes('APPROVED'));
 // A BA RP preview with its sibling BA PP context must still use the real BARP template.
 html=render(h(DocumentPreview,{state:s,kind:'BA Rekonsiliasi Pekerjaan – Termin',project:p,deal:d,spk:v,term:saved,bapp:b,onClose:()=>{}}));assert(html.includes('barp-document'));assert(!html.includes('bapp-document'));
});

test('editing BA RP archives both attestations and blocks downstream actions, while identical saves retain Sah',()=>{
 const {s,t}=fixture();s.bapps=[];s.actorUserId='qa-admin';t.barpFlow='attest-before-bapp-v1';attestBarp(s,t);let saved=getTerm(s,t.id);const id=prepareBapp(s,t.id),b=s.bapps.find(b=>b.id===id)!;attestBapp(s,id,b.no);
 saveTerm(s,saved,false);saved=getTerm(s,t.id);assert(barpCurrent(saved));assert(bappCurrent(b,saved));assert.equal(saved.barpAttestationHistory,undefined);
 const changed=structuredClone(saved);changed.barp!.date='2026-11-22';changed.barpAttestation!.source=bappBasis(changed);delete changed.barpFlow;saveTerm(s,changed,false);saved=getTerm(s,t.id);
 assert.equal(saved.barpFlow,'attest-before-bapp-v1');assert.equal(barpCurrent(saved),false);assert.equal(saved.barpAttestationHistory?.length,1);assert.equal(b.status,'Draft');assert.equal(b.attestationHistory?.length,1);assert.equal(b.date,t.barp?.date);
 assert.throws(()=>attestBapp(s,id,b.no),/Sahkan BA RP/);assert.throws(()=>prepareBapp(s,t.id),/Sahkan BA RP/);
 s.role='Finance';assert.throws(()=>attestBarp(s,saved),/Admin/);
});


test('paid Termin 1 never hides legacy pending Termin 2 BAPP preparation or unlocks its approved BARP',()=>{
 const {s,t}=fixture();s.bapps=[];delete t.barpFlow;delete t.completionFlow;
 const first=structuredClone(t);first.id='paid-first';first.number=1;first.type='Parsial';first.status='Disetujui';first.paid=termTotal(s,t);first.apmStatus='Valid';first.apm='APM/OLD/ONE';
 const paidBapp=ensureBapp({...s,bapps:[]} as typeof s,first);paidBapp.id='bapp-paid-first';paidBapp.status='Sah';
 t.number=2;t.status='Diajukan';s.terms=[first,t];s.bapps=[paidBapp];
 const before=JSON.stringify(s),oldFirst=JSON.stringify(first);
 for(const status of ['Diajukan','Disetujui'] as const){t.status=status;assert(savedBappPreparationAllowed(t,termTotal(s,t)));
  const html=render(h(TermEditor,{s,id:t.id,stage:'work'}));assert(html.includes('Buat BA PP dari BA RP Tersimpan'));assert(html.includes('pengajuan perlu dikembalikan atau ditolak'));
  assert.match(html,/<select disabled=""/);assert.doesNotMatch(html,/>Sahkan BA RP<\/button>|>Simpan Draft<\/button>/);
 }
 t.status='Diajukan';assert.equal(JSON.stringify(s),before);const id=prepareBapp(s,t.id),b=s.bapps.find(b=>b.id===id)!;assert.equal(b.termId,t.id);assert.notEqual(b.id,paidBapp.id);assert.equal(t.status,'Diajukan');assert.equal(t.barpFlow,undefined);assert.equal(JSON.stringify(first),oldFirst);
});

test('saved BAPP preparation keeps paid, role and new BARP attestation guards',()=>{
 const {s,t}=fixture();s.bapps=[];t.status='Diajukan';delete t.completionFlow;
 assert(savedBappPreparationAllowed(t,termTotal(s,t)));let html=render(h(WorkDocuments,{s}));assert(html.includes('Buat BA PP dari BA RP Tersimpan'));
 t.barpFlow='attest-before-bapp-v1';assert.equal(savedBappPreparationAllowed(t,termTotal(s,t)),false);html=render(h(WorkDocuments,{s}));assert(!html.includes('Buat BA PP dari BA RP Tersimpan'));assert(html.includes('Lengkapi &amp; Sahkan BA RP'));
 delete t.barpFlow;t.paid=1;assert.equal(savedBappPreparationAllowed(t,termTotal(s,t)),false);html=render(h(WorkDocuments,{s}));assert(!html.includes('Buat BA PP dari BA RP Tersimpan'));assert(html.includes('Detail Pembayaran'));
 t.paid=0;s.role='Finance';html=render(h(WorkDocuments,{s}));assert(!html.includes('Buat BA PP dari BA RP Tersimpan'));
 t.status='Ditolak';assert.equal(savedBappPreparationAllowed(t,termTotal(s,t)),false);t.status='Diajukan';t.actuals={};assert.equal(savedBappPreparationAllowed(t,termTotal(s,t)),false);
});

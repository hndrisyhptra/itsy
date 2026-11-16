import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {dealTotal,getTerm,getSpk,getDeal,termTotal} from '../../resources/js/area3/domain';
import {bakhDocumentDate} from '../../resources/js/area3/bakh-content';
import {blankBarp} from '../../resources/js/area3/barp-form';
import {bappDocumentData} from '../../resources/js/area3/bapp-content';
import {DocumentPreview} from '../../resources/js/area3/ui';
function fixture(){
 const s=seed('E'),b=s.bapps[0],t=getTerm(s,b.termId),v=getSpk(s,t.spkId),d=getDeal(s,v.dealId),p=s.projects.find(p=>p.id===d.projectId)!;
 t.barp={...blankBarp(s,t),date:'2026-11-16',start:'2026-11-01',end:'2026-11-15',place:'Surabaya',mandor:'MANDOR TERSIMPAN',waspang:'WASPANG TERSIMPAN',headName:'HEAD TERSIMPAN'};
 b.no='BAPP-PRIVATE-INTERNAL-NUMBER';
 const render=()=>renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'BA Penyelesaian Pekerjaan',project:p,deal:d,spk:v,term:t,bapp:b,onClose:()=>{}}));
 return {s,b,t,v,d,p,render};
}
test('single Final BA PP follows the letter template, omits all installment/BAPP number and generic tables, and preserves three saved signers',()=>{
 const {s,t,render}=fixture();t.number=1;t.type='Final';const before=JSON.stringify(s),html=render();
 for(const value of ['BERITA ACARA PENYELESAIAN PEKERJAAN','Senin','Enam Belas','Dua Ribu Dua Puluh Enam','MANDOR TERSIMPAN','WASPANG TERSIMPAN','HEAD TERSIMPAN','1 November 2026','15 November 2026','Surabaya, 16 November 2026','sebagai dasar Rekonsiliasi, serta untuk dipergunakan seperlunya.','Belum dipotong pajak penghasilan.'])assert.ok(html.includes(value),value);
 assert.doesNotMatch(html,/Tahap\s*\d|Termin\s*\d|BAPP-PRIVATE-INTERNAL-NUMBER|doc-number|doc-brand|<table|Ringkasan Rekonsiliasi|Status Approval|Dokumen demonstrasi/);
 assert.equal((html.match(/Area TTD basah dokumen asli/g)??[]).length,3);assert.equal(JSON.stringify(s),before);
});
test('staged BA PP retains current stage in work and closing paragraphs, with the selected gross bill rather than cumulative/net transfer',()=>{
 const {s,p,d,v,t,render}=fixture();
 for(const number of [1,2,3]){
  t.number=number;t.type=number===1?'Parsial':'Final';
  const data=bappDocumentData(p,d,v,t),html=render();
  assert.equal(data.stage,'Tahap '+number);assert.equal(data.payment,'Termin '+number);
  assert.equal((html.match(new RegExp('Tahap '+number,'g'))??[]).length,2);assert.match(html,new RegExp('Termin '+number));
  assert.equal(data.total,termTotal(s,t));assert.equal(data.progress,new Intl.NumberFormat('id-ID',{maximumFractionDigits:2}).format(data.total/dealTotal(d)*100)+'%');
  assert.ok(html.includes(data.totalText));assert.doesNotMatch(html,/Mgr Finance|PPh 2,5|BAPP-PRIVATE/);
 }
});
test('BA PP uses historical inspection identity/place and leaves missing dates/zero-value percentage unresolved, without current account or today fallback',()=>{
 const {p,d,v,t}=fixture();const data=bappDocumentData(p,d,v,t);t.barp!.date='2026-02-30';t.barp!.place='Kota tersimpan';d.mandor='Nama baru kontrak';v.signer='Nama baru SPK';
 const changed=bappDocumentData(p,{...d,rows:[]},v,t);assert.equal(changed.date,null);assert.equal(changed.placeDate,'Kota tersimpan, …');assert.equal(changed.progress,'…');assert.equal(changed.mandor,data.mandor);assert.equal(changed.head,data.head);
 delete t.barp;const legacy=bappDocumentData(p,d,v,t);assert.equal(legacy.date,null);assert.ok(legacy.placeDate.endsWith(', …'));assert.equal(legacy.start,bakhDocumentDate(v.start)!.full);
});
test('BA PP print still requires genuine assigned approvals and is confirmation protected',()=>{
 const {t,render}=fixture();Object.assign(t,{workflowVersion:3,approvalScheme:'project-support-first',approverUserIds:{'PM Konstruksi':'qa-pm','Admin Area':'qa-area','Mgr Project Support':'qa-support','Mgr Deployment':'qa-deploy','Head of PMO':'qa-head'},approvals:[]});
 const roles=['PM Konstruksi','Admin Area','Mgr Project Support','Mgr Deployment','Head of PMO'] as const;
 assert.match(render(),/<button[^>]*disabled[^>]*>[^]*?Cetak \/ Simpan PDF browser/);
 t.approvals=roles.map(role=>({role,userId:t.approverUserIds![role],decision:'Setujui',note:'',at:'2026-11-16'}));
 assert.doesNotMatch(render(),/<button[^>]*disabled[^>]*>[^]*?Cetak \/ Simpan PDF browser/);
 assert.match(render(),/data-confirm="Buka pencetakan dokumen ini\?"/);
 t.approvals[0].userId='wrong';assert.match(render(),/<button[^>]*disabled[^>]*>[^]*?Cetak \/ Simpan PDF browser/);
});
test('large BA PP uses loaded agreement data without per-item catalog/account lookups or source mutation',()=>{
 const {s,p,d,t,render}=fixture(),r=d.rows[0];d.rows=Array.from({length:1000},(_,i)=>({...r,rowId:'qa-'+i}));t.actuals=Object.fromEntries(d.rows.map(r=>[r.rowId,{m:1,s:2}]));
 const before=JSON.stringify(s);s.khs.find=()=>{throw Error('No catalog lookup');};p.boq.find=()=>{throw Error('No source lookup');};s.users.find=()=>{throw Error('No current signer lookup');};
 assert.match(render(),/bapp-document/);assert.equal(JSON.stringify(s),before);
});

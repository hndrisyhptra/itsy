import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {createTerm,getTerm,saveTerm,termTotal, type Term, type Role} from '../../resources/js/area3/domain';
import {blankBarp,saveBarp} from '../../resources/js/area3/barp-form';
import {barpDocumentData,canExportBarp,canPrintBarp,rupiahWords,BARP_TEXT} from '../../resources/js/area3/barp-content';
import {createBarpDocx,barpWordFilename} from '../../resources/js/area3/barp-docx';
import {BARP_WORD_PARTS} from '../../resources/js/area3/barp-word-template';
import {DocumentPreview} from '../../resources/js/area3/ui';
import {TermEditor} from '../../resources/js/area3/payments';
import {docxParts} from './helpers/docx-parts';
const roles:Role[]=['PM Konstruksi','Admin Area','Mgr Project Support','Mgr Deployment','Head of PMO'];
function fixture(){
 const s=seed('D');s.role='Admin Konstruksi';
 const p=s.projects[0],d=s.deals[0],v=s.spks[0],t=getTerm(s,createTerm(s,v.id));
 t.workflowVersion=3;t.approvalScheme='project-support-first';t.approverUserIds=Object.fromEntries(roles.map((r,i)=>[r,`qa-${i}`]));
 t.barp={...blankBarp(s,t),date:'2026-11-08',place:'Tempat BARP',sto:'STO BARP',homepass:100,headName:'Head BARP QA',headNik:'QA-001',headUserId:'qa-4'};
 t.actuals={[d.rows[0].rowId]:{m:1,s:2}};
 return {s,p,d,v,t};
}
function approve(t:Term){t.status='Disetujui';t.approvals=roles.map((role,i)=>({role,decision:'Setujui',userId:`qa-${i}`,at:'2026-11-08',note:''}));}

test('BARP validates the same calendar, period, required fields and optional Homepass as Laravel; identities are server-owned',()=>{
 const {s,t}=fixture();
 for(const [key,value] of [['date','2026-02-30'],['start','1900-01-01'],['end','2999-01-01'],['homepass',-1],['homepass',1.2],['homepass','10'],['homepass',1000000001],['sto','x'.repeat(151)]] as const){
  const input=structuredClone(t);Object.assign(input.barp!,{[key]:value});assert.throws(()=>saveBarp(s,t,input,false));
 }
 const input=structuredClone(t);input.barp!.place='';assert.doesNotThrow(()=>saveBarp(s,t,input,false));assert.throws(()=>saveBarp(s,t,input,true),/Lengkapi/);
 Object.assign(input.barp!,{place:'BARP',headName:'FORGED',headNik:'FORGED',headUserId:'FORGED',waspang:'FORGED',mandor:'FORGED',homepass:0});
 const saved=saveBarp(s,t,input,true).barp!;
 for(const field of ['headName','headNik','headUserId','waspang','mandor'] as const)assert.equal(saved[field],t.barp![field]);
 assert.equal(saved.homepass,0);input.barp!.homepass=null;assert.equal(saveBarp(s,t,input,true).barp!.homepass,null);
 assert.throws(()=>saveBarp(s,t,{...t,barp:null} as unknown as Term,false),/formulir/);
 for(const malformed of [{},[]])assert.throws(()=>saveBarp(s,t,{...t,barp:malformed} as unknown as Term,false),/formulir/);
});

test('BARP submission stores immutable per-cycle snapshots and never trusts client history',()=>{
 const {s,t}=fixture(),input=structuredClone(t);input.barpHistory=[{at:'FORGED',document:input.barp!}];
 saveTerm(s,input,true);const saved=getTerm(s,t.id);
 assert.equal(saved.barpHistory?.length,1);assert.notEqual(saved.barpHistory?.[0].at,'FORGED');
 const first=structuredClone(saved.barpHistory![0]);saved.status='Ditolak';const next=structuredClone(saved);next.barp!.sto='Koreksi';
 saveTerm(s,next,true);assert.deepEqual(getTerm(s,t.id).barpHistory![0],first);assert.equal(getTerm(s,t.id).barpHistory?.length,2);
 assert.throws(()=>saveTerm(s,next,false),/terkunci/);
});

test('current-stage BARP excludes preceding approved terms and reconciles Material/Jasa including independent quantities and cent rounding',()=>{
 const {s,p,d,v,t}=fixture();t.number=2;
 s.terms.unshift({...structuredClone(t),id:'prior',number:1,status:'Disetujui',actuals:{[d.rows[0].rowId]:{m:99,s:98}}});
 for(const row of [{m:true,s:true,pm:.005,ps:.005},{m:false,s:true,pm:0,ps:0},{m:true,s:false,pm:123.45,ps:0}]){
  d.rows=[{...d.rows[0],...row}];t.actuals={[d.rows[0].rowId]:{m:row.m?1:0,s:row.s?1:0}};
  const data=barpDocumentData(p,d,v,t);assert.equal(data.total,termTotal(s,t));
  assert.equal(Math.round((data.material+data.service)*100),Math.round(data.total*100));
  if(!row.m)assert.equal(data.materialText,'');if(row.s&&row.ps===0)assert.equal(data.serviceText,'0');
  assert.match(data.title,/TAHAP 2$/);assert.doesNotMatch(data.price,/dikerjakan 100%|diterbitkan BAPP/);
 }
 assert.equal(rupiahWords(1000000000.05),'Satu Miliar Koma Nol Lima Rupiah');assert.equal(rupiahWords(1000),'Seribu Rupiah');
});

test('BARP Word and official print are separately gated, and approval must match the selected account for every role',()=>{
 const {p,d,v,t}=fixture();
 for(const status of ['Draft','Ditolak','Dikembalikan'] as const){t.status=status;assert.equal(canExportBarp(t),false);assert.throws(()=>createBarpDocx(p,d,v,t),/setelah pengajuan/);}
 t.status='Diajukan';assert.equal(canExportBarp(t),true);assert.equal(canPrintBarp(t),false);
 assert.match(docxParts(createBarpDocx(p,d,v,t))['word/document.xml'],/SALINAN REVIEW/);
 approve(t);assert.equal(canPrintBarp(t),true);assert.doesNotMatch(docxParts(createBarpDocx(p,d,v,t))['word/document.xml'],/SALINAN REVIEW/);
 t.approvals[2].userId='wrong-account';assert.equal(canPrintBarp(t),false);assert.match(barpDocumentData(p,d,v,t).status,/REVIEW/);
});

test('native BARP Word preserves source geometry, tables, prose and theme, escapes one-pass slots and removes sample identities',()=>{
 const {s,p,d,v,t}=fixture();approve(t);p.name='<script>é & 中文 😀</script>\n{{MANDOR}}\u0000';
 const before=JSON.stringify(s),parts=docxParts(createBarpDocx(p,d,v,t)),xml=parts['word/document.xml'];
 assert.equal(Object.keys(parts).length,11);assert.equal(parts['word/theme/theme1.xml'],BARP_WORD_PARTS['word/theme/theme1.xml']);
 assert.match(xml,/w:w="11906" w:h="16838"/);assert.match(xml,/w:top="720" w:right="720" w:bottom="720" w:left="720"/);
 assert.match(xml,/w:gridSpan w:val="3"/);assert.match(xml,/w:vMerge/);assert.equal((xml.match(/<w:tbl>/g)??[]).length,5);
 assert.match(xml,/&lt;script&gt;é &amp; 中文 😀&lt;\/script&gt;/);assert.match(xml,/\{\{MANDOR\}\}/);assert.match(xml,/<w:br\/>/);
 for(const text of [BARP_TEXT.sow,BARP_TEXT.note,BARP_TEXT.closing,t.barp!.headName,t.barp!.headNik])assert.ok(xml.replace(/<[^>]+>/g,'').replace(/\s+/g,' ').includes(text),text);
 assert.doesNotMatch(xml,/Ikhwan|IKHWAN|HILARIUS|HENDRA CAHYA|10\.000\.000|<script>|\u0000|tblpPr|APPROVED/);
 assert.doesNotMatch(Object.keys(parts).join('|'),/customXml|docProps|vba|media|comments/);
 assert.doesNotMatch(Object.values(parts).join(''),/TargetMode="External"|altChunk/);
 assert.doesNotMatch(barpWordFilename({...v,no:'../SPK:<QA>?'},t),/[\\/:*?"<>|\u0000-\u001f]/);assert.equal(JSON.stringify(s),before);
});

test('BARP preview bypasses root/KHS lookups, matches Word values, includes admin inputs, and never substitutes today into old issued documents',()=>{
 const {s,p,d,v,t}=fixture();t.status='Diajukan';p.rootId='not-loaded';s.khs=[];
 const before=JSON.stringify(s),data=barpDocumentData(p,d,v,t);
 const html=renderToStaticMarkup(createElement(DocumentPreview,{state:s,project:p,deal:d,spk:v,term:t,kind:'BA Rekonsiliasi Pekerjaan – Termin',onClose:()=>{}}));
 for(const text of [data.title,data.materialText,data.serviceText,data.totalText,t.barp!.sto,'Unduh Word (.docx)','WASPANG PROJECT','HEAD OF PMO AREA JAWA BALI'])assert.ok(html.includes(text),text);
 assert.match(html,/SALINAN REVIEW/);assert.equal((html.match(/Area TTD basah dokumen asli/g)??[]).length,3);assert.doesNotMatch(html,/N\/A|APPROVED|<img/);
 assert.equal(JSON.stringify(s),before);
 delete t.barp;const historical=JSON.stringify(s),old=barpDocumentData(p,d,v,t);assert.match(old.opening,/hari ini … tanggal …/);assert.equal(old.head,'…');assert.equal(JSON.stringify(s),historical);
 s.khs=seed('D').khs;p.rootId=p.id;t.status='Draft';t.barp=blankBarp(s,t);
 const form=renderToStaticMarkup(createElement(TermEditor,{s,id:t.id}));for(const label of ['Tanggal Rekonsiliasi','STO (opsional)','Homepass (opsional)','NIK Head of PMO'])assert.ok(form.includes(label));
});

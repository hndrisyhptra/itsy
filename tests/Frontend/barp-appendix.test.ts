import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {createTerm,getTerm,saveTerm,termTotal, type Term} from '../../resources/js/area3/domain';
import {blankBarp,saveBarp} from '../../resources/js/area3/barp-form';
import {barpDocumentData} from '../../resources/js/area3/barp-content';
import {barpAppendixData} from '../../resources/js/area3/barp-appendix-content';
import {termVolumeData} from '../../resources/js/area3/term-volume';
import {createBarpAppendixDocx,barpAppendixWordFilename} from '../../resources/js/area3/barp-appendix-docx';
import {DocumentPreview} from '../../resources/js/area3/ui';
import {TermEditor} from '../../resources/js/area3/payments';
import {docxParts} from './helpers/docx-parts';
const roles=['PM Konstruksi','Admin Area','Mgr Project Support','Mgr Deployment','Head of PMO'] as const;
function fixture(){
 const s=seed('D');s.role='Admin Konstruksi';const p=s.projects[0],d=s.deals[0],v=s.spks[0],t=getTerm(s,createTerm(s,v.id));
 for(const [i,role] of roles.entries())s.users.push({id:`qa-${i}`,name:`Penandatangan ${role}`,role,email:`qa-${i}@example.test`,active:true,nik:`000${i}`});
 Object.assign(t,{workflowVersion:3,approvalScheme:'project-support-first',approverUserIds:Object.fromEntries(roles.map((r,i)=>[r,`qa-${i}`]))});
 t.barp=blankBarp(s,t);t.actuals={[d.rows[0].rowId]:{m:1,s:2}};
 return {s,p,d,v,t};
}
function approve(t:Term){t.status='Disetujui';t.approvals=roles.map((role,i)=>({role,userId:`qa-${i}`,decision:'Setujui',note:'QA',at:'2026-11-09'}));}

test('direct Final appendix projects a single actual column and neutral recaps consistently in HTML and native Word without changing the ledger',()=>{
 const {s,p,d,v,t}=fixture();approve(t);t.type='Final';
 const before=JSON.stringify(s),ledger=termVolumeData(s,p,d,v,t),data=barpAppendixData(s,p,d,v,t);
 assert.deepEqual(data.projectHeaders,[p.name]);assert.equal(data.widths.length,11);assert.equal(data.widths.reduce((a,b)=>a+b,0),100);
 assert.equal(data.rows[0].cells.length,11);assert.equal(data.rows[0].cells[6],String(ledger.rows[0].current));
 assert.equal(data.current.total,ledger.current.total);
 const html=renderToStaticMarkup(createElement(DocumentPreview,{state:s,project:p,deal:d,spk:v,term:t,kind:'Lampiran Rekonsiliasi Pekerjaan',onClose:()=>{}}));
 const xml=docxParts(createBarpAppendixDocx(s,p,d,v,t))['word/document.xml'];
 for(const output of [html,xml]){assert.doesNotMatch(output,/(?:tahap|termin)\s*#?\s*[123]/i);assert.match(output,/JUMLAH HARGA/);assert.match(output,/HARGA SATUAN/);assert.doesNotMatch(output,/VOLUME SPK|SISA PROYEKSI|ACCEPTED SEBELUMNYA/);}
 assert.match(xml,/w:gridSpan w:val="4"/);assert.equal(JSON.stringify(s),before);
 assert.ok(barpAppendixWordFilename(v,t).startsWith('Lampiran-BARP_'));
});

test('Pekerjaan is canonical, cached/client location/job/manager overrides are ignored, and v1 correction captures managers without replacing old signatures',()=>{
 const {s,p,d,v,t}=fixture(),input=structuredClone(t);
 Object.assign(input.barp!,{job:'FORGED',location:'FORGED',deployment:{name:'FORGED',userId:'FORGED'},support:{name:'FORGED'}});
 let saved=saveBarp(s,t,input,true).barp!;
 assert.equal(saved.version,2);assert.equal(saved.job,p.name);assert.equal(saved.deployment!.name,'Penandatangan Mgr Deployment');assert.equal(saved.support!.nik,'0002');
 s.users.find(u=>u.id==='qa-3')!.name='Renamed current account';assert.deepEqual(saveBarp(s,{...t,barp:saved},input,true).barp!.deployment,saved.deployment);
 t.barp!.version=1;delete t.barp!.job;delete t.barp!.deployment;delete t.barp!.support;
 const old=structuredClone(t.barp!);saved=saveBarp(s,t,input,true).barp!;assert.equal(saved.version,2);assert.equal(saved.job,p.name);assert.equal(saved.headName,old.headName);
 assert.equal(barpDocumentData(p,d,v,{...t,barp:saved}).objectLabel,'PEKERJAAN');assert.equal(barpDocumentData(p,d,v,{...t,barp:saved}).location,p.name);
 const html=renderToStaticMarkup(createElement(TermEditor,{s,id:t.id}));assert.match(html,/<input readOnly="" value="Modernisasi Jaringan/);assert.doesNotMatch(html,/Lokasi Pekerjaan/);
});

test('editor and appendix share exact per-component actual/accepted/remainder/price data and current-stage rounding, excluding future/rejected/other-SPK records',()=>{
 const {s,p,d,v,t}=fixture();t.number=2;
 const row=d.rows[0];d.rows=[{...row,qm:10,qs:20,pm:.005,ps:.005}];t.actuals={[row.rowId]:{m:1,s:1}};
 const prior={...structuredClone(t),id:'prior',number:1,status:'Disetujui' as const,actuals:{[row.rowId]:{m:2,s:3}}};
 s.terms.push(prior,{...prior,id:'rejected',status:'Ditolak',actuals:{[row.rowId]:{m:50,s:50}}},{...prior,id:'future',number:3},{...prior,id:'other',spkId:'other'});
 const volume=termVolumeData(s,p,d,v,t),data=barpAppendixData(s,p,d,v,t);
 assert.equal(volume.rows.length,2);assert.deepEqual(volume.rows[0].actuals,[2,1,null]);assert.deepEqual(volume.rows[1].actuals,[3,1,null]);
 assert.equal(volume.rows[0].remaining,7);assert.equal(volume.rows[1].remaining,16);
 assert.equal(volume.current.total,termTotal(s,t));assert.equal(volume.rows.reduce((sum,r)=>sum+r.amount,0),volume.current.total);
 assert.equal(data.priorTotal,termTotal(s,prior));assert.equal(data.current.total,barpDocumentData(p,d,v,t).total);assert.equal(data.rows[0].cells.length,12);
 assert.equal(data.rows[0].cells[6],'2');assert.equal(data.rows[0].cells[7],'1');assert.equal(data.rows[0].cells[8],'3');assert.equal(data.rows[1].cells[8],'4');assert.equal(data.rows[0].component,'Material');assert.equal(data.rows[1].component,'Jasa');assert.equal(data.cumulative.total,.04);assert.equal(data.current.total,.01);
});

test('four construction signers follow requested order, captured names survive renames, historical fallbacks match actual selected decisions only',()=>{
 const {s,p,d,v,t}=fixture();const captured=t.barp!.deployment!;
 s.users.find(u=>u.id===captured.userId)!.name='Different current name';let data=barpAppendixData(s,p,d,v,t);
 assert.deepEqual(data.signatures.map(s=>s.job),['HEAD OF PMO AREA JAWA BALI','MGR PROJECT DEPLOYMENT AREA JAWA BALI','WASPANG PROJECT','KETUA MANDOR']);
 assert.equal(data.signatures[1].name,captured.name);assert.equal(data.signatures.length,4);assert.ok(!data.signatures.some(s=>s.nik==='0002'));assert.equal(t.barp!.support!.nik,'0002');
 t.barp!.version=1;delete t.barp!.deployment;delete t.barp!.support;p.approvals=[];p.signatureUsers={};data=barpAppendixData(s,p,d,v,t);assert.equal(data.signatures[1].name,'…');
 t.approvals=[{role:'Mgr Deployment',userId:'wrong',actorName:'WRONG',decision:'Setujui',at:'2026-11-09',note:''}];assert.equal(barpAppendixData(s,p,d,v,t).signatures[1].name,'…');
 t.approvals.push({role:'Mgr Deployment',userId:'qa-3',actorName:'Actual recorded name',actorNik:'0003',decision:'Setujui',at:'2026-11-09',note:''});assert.equal(barpAppendixData(s,p,d,v,t).signatures[1].name,'Actual recorded name');
 t.approvalScheme='operation-v1';t.barp!.support={userId:'qa-2',name:'Officer SS snapshot',nik:'0002',job:'OFFICER 1 SS'};const operation=barpAppendixData(s,p,d,v,t);assert.equal(operation.signatures.length,5);assert.equal(operation.signatures[2].name,'Officer SS snapshot');
});

test('dedicated landscape appendix preview has the shared ledger grid, four empty signature spaces and gated Word/print, without generic duplicated tables',()=>{
 const {s,p,d,v,t}=fixture();t.status='Diajukan';
 const before=JSON.stringify(s),data=barpAppendixData(s,p,d,v,t);
 const html=renderToStaticMarkup(createElement(DocumentPreview,{state:s,project:p,deal:d,spk:v,term:t,kind:'Lampiran Rekonsiliasi Pekerjaan',onClose:()=>{}}));
 for(const text of ['LAMPIRAN BERITA ACARA REKONSILIASI PEKERJAAN','PEKERJAAN:',p.name,'HARGA SATUAN','TOTAL VOL','Termin #1','JUMLAH MATERIAL','Unduh Word (.docx)','SALINAN REVIEW'])assert.ok(html.includes(text),text);
 assert.equal((html.match(/Area TTD basah dokumen asli/g)??[]).length,4);assert.doesNotMatch(html,/Ringkasan Rekonsiliasi Termin|N\/A|<img|doc-brand/);
 assert.doesNotMatch(html,/Penandatangan Mgr Project Support|MGR PROJECT SUPPORT AREA JAWA BALI/);for(const signer of data.signatures)assert.ok(html.includes(signer.name));assert.equal(JSON.stringify(s),before);
 assert.match(html,/<button[^>]*disabled[^>]*>[^]*?Cetak \/ Simpan PDF browser/);
});

test('native appendix Word repeats merged headers, matches actuals and recap, escapes names/slots and never includes example signatures or external relationships',()=>{
 const {s,p,d,v,t}=fixture();approve(t);p.name='Proyek <QA> & 中文 😀 {{HEAD}}';
 const parts=docxParts(createBarpAppendixDocx(s,p,d,v,t)),xml=parts['word/document.xml'];
 assert.equal(Object.keys(parts).length,5);assert.match(xml,/w:orient="landscape"/);assert.equal((xml.match(/<w:tblHeader\/>/g)??[]).length,2);assert.match(xml,/w:gridSpan w:val="2"/);assert.match(xml,/w:gridSpan w:val="10"/);
 assert.match(xml,/Proyek &lt;QA&gt; &amp; 中文 😀 \{\{HEAD\}\}/);assert.doesNotMatch(xml,/Penandatangan Mgr Project Support|MGR PROJECT SUPPORT AREA JAWA BALI/);assert.match(xml,/NIK: 0003/);
 assert.doesNotMatch(Object.values(parts).join(''),/HENDRA CAHYA|AMRAN|ADE AZHARI|TUNNIKMAH|TargetMode="External"|altChunk/);assert.doesNotMatch(Object.keys(parts).join('|'),/media|vba|customXml|docProps/);
 for(const status of ['Draft','Ditolak','Dikembalikan'] as const)assert.throws(()=>createBarpAppendixDocx(s,p,d,v,{...t,status}),/setelah pengajuan/);
 t.status='Diajukan';t.approvals=[];assert.match(docxParts(createBarpAppendixDocx(s,p,d,v,t))['word/document.xml'],/SALINAN REVIEW/);assert.doesNotMatch(barpAppendixWordFilename({...v,no:'../SPK:<QA>?'},t),/[\\/:*?"<>|]/);
});

test('1000-row attachment uses indexed metadata, preserves zero/excluded components and works for continuation without falling back to root prices',()=>{
 const {s,p,d,v,t}=fixture(),source=p.boq[0],agreement=d.rows[0],item=s.khs.find(k=>k.id===source.khsId)!;
 p.boq=Array.from({length:1000},(_,i)=>({...source,id:`row-${i}`}));d.rows=p.boq.map(r=>({...agreement,rowId:r.id,m:false,s:true,qs:1,ps:0}));t.actuals=Object.fromEntries(p.boq.map(r=>[r.id,{m:0,s:1}]));
 const child={...p,id:'child',rootId:p.id,name:'Continuation job'};
 s.projects.push(child);s.khs=[item];s.khs.find=()=>{throw new Error('Per-row catalog find forbidden');};p.boq.find=()=>{throw new Error('Per-row source find forbidden');};
 const data=barpAppendixData(s,child,d,v,t);assert.equal(data.rows.length,1000);assert.equal(data.rows.at(-1)!.cells[1],item.code);assert.equal(data.rows.at(-1)!.cells[9],'0');assert.equal(data.recap[0].value,'');assert.equal(data.recap[1].value,'0');assert.equal(data.current.total,0);
 t.status='Diajukan';assert.ok(createBarpAppendixDocx(s,child,d,v,t).length>1000000);
});


test('SPK-style grid combines equal component vectors, keeps actual rather than contracted volumes, and labels only existing stages through the selected term',()=>{
 const {s,p,d,v,t}=fixture(),row=d.rows[0];d.rows=[{...row,m:true,s:true,qm:20,qs:20,pm:100,ps:200}];
 t.actuals={[row.rowId]:{m:3,s:3}};t.number=2;t.type='Final';
 const prior={...structuredClone(t),id:'prior-approved',number:1,status:'Disetujui' as const,actuals:{[row.rowId]:{m:2,s:2}}};s.terms.push(prior);
 const before=JSON.stringify(s),data=barpAppendixData(s,p,d,v,t);
 assert.equal(data.rows.length,1);assert.equal(data.splitVolumes,false);assert.equal(data.rows[0].component,'');
 assert.deepEqual(data.rows[0].cells.slice(4),['100','200','2','3','5','500','1.000','1.500']);
 assert.deepEqual(data.projectHeaders,[p.name+' · Termin #1',p.name+' · Termin #2']);assert.equal(data.widths.length,12);
 assert.equal(data.current.total,900);assert.equal(data.cumulative.total,1500);assert.equal(data.recap.at(-1)!.value,'900');
 assert.deepEqual(data.summaryRows[2].values,['','','600','900','1.500','','','1.500']);
 t.number=1;t.type='Final';const one=barpAppendixData(s,p,d,v,t);assert.deepEqual(one.projectHeaders,[p.name]);assert.equal(one.rows[0].cells[6],'3');assert.equal(one.rows[0].cells[7],'3');assert.equal(one.current.total,900);assert.equal(one.rows[0].cells[10],'900');
 t.number=2;assert.equal(JSON.stringify(s),before);
});

test('same current quantity with different prior vectors stays split, and the third-stage grid has thirteen columns with future/rejected amounts excluded',()=>{
 const {s,p,d,v,t}=fixture(),row=d.rows[0];d.rows=[{...row,m:true,s:true,qm:20,qs:20,pm:10,ps:20}];t.number=3;t.type='Final';t.actuals={[row.rowId]:{m:1,s:1}};
 const prior={...structuredClone(t),id:'first',number:1,status:'Disetujui' as const,actuals:{[row.rowId]:{m:2,s:3}}};s.terms.push(prior,{...prior,id:'second',number:2,actuals:{[row.rowId]:{m:1,s:1}}},{...prior,id:'wrong',status:'Ditolak',actuals:{[row.rowId]:{m:999,s:999}}});
 const data=barpAppendixData(s,p,d,v,t);assert.equal(data.rows.length,2);assert.equal(data.widths.length,13);assert.equal(data.widths.reduce((n,w)=>n+w,0),100);assert.deepEqual(data.rows.map(r=>r.cells.slice(6,10)),[['2','1','1','4'],['3','1','1','5']]);assert.equal(data.cumulative.total,140);assert.equal(data.current.total,30);assert.deepEqual(data.projectHeaders,[1,2,3].map(n=>p.name+' · Termin #'+n));
});

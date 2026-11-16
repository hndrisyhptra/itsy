import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {getTerm,getSpk,getDeal} from '../../resources/js/area3/domain';
import {blankBarp} from '../../resources/js/area3/barp-form';
import {bappDocumentData,bappAppendixData} from '../../resources/js/area3/bapp-content';
import {barpAppendixData} from '../../resources/js/area3/barp-appendix-content';
import {createBappDocx} from '../../resources/js/area3/bapp-docx';
import {createBappAppendixDocx} from '../../resources/js/area3/bapp-appendix-docx';
import {createBarpAppendixDocx} from '../../resources/js/area3/barp-appendix-docx';
import {BAPP_WORD_PARTS} from '../../resources/js/area3/bapp-word-template';
import {bappWordFilename} from '../../resources/js/area3/bapp-file';
import {DocumentPreview} from '../../resources/js/area3/ui';
import {docxParts} from './helpers/docx-parts';
function fixture(){
 const s=seed('E'),b=s.bapps[0],t=getTerm(s,b.termId),v=getSpk(s,t.spkId),d=getDeal(s,v.dealId),p=s.projects.find(p=>p.id===d.projectId)!;
 t.barp={...blankBarp(s,t),date:'2026-11-16',place:'Surabaya',waspang:'WASPANG QA',mandor:'MANDOR QA',headName:'HEAD QA',headNik:'00001',deployment:{userId:'deployment',name:'DEPLOYMENT HIDDEN QA',nik:'00002'},support:{userId:'support',name:'SUPPORT HIDDEN QA',nik:'00003'}};
 b.no='INTERNAL-BAPP-NUMBER';return {s,b,t,v,d,p};
}
test('BA PP native Word keeps retained template parts and current HTML wording, omits internalnumber and preserves single/staged labels without mutating data',()=>{
 const {s,p,d,v,t}=fixture();
 for(const [number,type] of [[1,'Final'],[1,'Parsial'],[2,'Final'],[3,'Final']] as const){
  t.number=number;t.type=type;const before=JSON.stringify(s),data=bappDocumentData(p,d,v,t),parts=docxParts(createBappDocx(p,d,v,t)),xml=parts['word/document.xml'];
  assert.equal(Object.keys(parts).length,18);
  for(const [name,source] of Object.entries(BAPP_WORD_PARTS))if(name!=='word/document.xml')assert.equal(parts[name],source,name);
  for(const value of ['BERITA ACARA','Surabaya, 16 November 2026','WASPANG QA','MANDOR QA','HEAD QA',data.totalText])assert.ok(xml.includes(value),value);
  assert.doesNotMatch(xml,/HILARIUS|IKHWAN|HENDRA CAHYA|10761965-PT3-LWB-FC-RISWANDI|INTERNAL-BAPP|<!--BAPP_|\{\{BAPP_|altChunk|w:drawing/);
  if(number===1&&type==='Final')assert.doesNotMatch(xml,/(?:Tahap|Termin)\s*1/);else assert.match(xml,new RegExp('Tahap '+number));
  for(const rel of ['_rels/.rels','word/_rels/document.xml.rels'])assert.doesNotMatch(parts[rel],/TargetMode="External"/);
  assert.equal(JSON.stringify(s),before);
 }
});
test('BA PP Word escapes dynamic slots and literal placeholder input without cross-substitution, keeps full multiline names and safe filename identity',()=>{
 const {p,d,v,t}=fixture();p.name='Proyek <QA> & 中文 😀 {{BAPP_HEAD}}';t.barp!.mandor='Mandor <A>\nBaris Kedua & {{BAPP_HEAD}}';t.barp!.headName='Head Actual';
 const xml=docxParts(createBappDocx(p,d,v,t))['word/document.xml'];assert.match(xml,/Proyek &lt;QA&gt; &amp; 中文 😀 \{\{BAPP_HEAD\}\}/);assert.match(xml,/Mandor &lt;A&gt;\nBaris Kedua &amp; \{\{BAPP_HEAD\}\}/);assert.doesNotMatch(xml,/Baris Kedua &amp; Head Actual/);
 assert.doesNotMatch(bappWordFilename({...v,no:'../SPK:<QA>?'},t,true),/[\\/:*?"<>|]/);t.number=1;t.type='Final';assert.doesNotMatch(bappWordFilename(v,t),/Tahap/);assert.ok(bappWordFilename(v,t,true).startsWith('Lampiran-BA-PP_'));
});
test('BA PP appendix table matches BARP in model/HTML/Word for each stage, while only Head/Waspang/Mandor sign and BARP retains five',()=>{
 const {s,b,p,d,v,t}=fixture();
 const html=(kind:string,bapp=true)=>renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind,project:p,deal:d,spk:v,term:t,bapp:bapp?b:undefined,onClose:()=>{}}));
 for(const number of [1,2,3]){
  t.number=number;t.type='Final';const before=JSON.stringify(s),base=barpAppendixData(s,p,d,v,t),data=bappAppendixData(s,p,d,v,t);
  for(const key of ['rows','summaryRows','recap','widths','projectHeaders','current','prior','cumulative'] as const)assert.deepEqual(data[key],base[key],key);
  assert.deepEqual(data.signatures.map(x=>x.job),['HEAD OF PMO AREA JAWA BALI','WASPANG PROJECT','KETUA MANDOR']);assert.equal(base.signatures.length,5);
  const appendix=html('Lampiran BA Penyelesaian Pekerjaan');assert.equal((appendix.match(/Area TTD basah dokumen asli/g)??[]).length,3);assert.doesNotMatch(appendix,/DEPLOYMENT HIDDEN QA|SUPPORT HIDDEN QA|INTERNAL-BAPP|Ringkasan Rekonsiliasi/);
  assert.equal(appendix.match(/<table>[^]*?<\/table>/)?.[0],html('Lampiran Rekonsiliasi Pekerjaan',false).match(/<table>[^]*?<\/table>/)?.[0]);
  const xml=docxParts(createBappAppendixDocx(s,p,d,v,t))['word/document.xml'],barp=docxParts(createBarpAppendixDocx(s,p,d,v,t))['word/document.xml'];
  assert.equal(xml.match(/<w:tbl>[^]*?<\/w:tbl>/)?.[0],barp.match(/<w:tbl>[^]*?<\/w:tbl>/)?.[0]);assert.match(xml,/w:orient="landscape"/);assert.doesNotMatch(xml,/DEPLOYMENT HIDDEN QA|SUPPORT HIDDEN QA/);assert.equal(JSON.stringify(s),before);
 }
});
test('both BA PP Word downloads are confirmation protected, lazy, reject unavailable statuses and preserve review/print guards',()=>{
 const {s,b,p,d,v,t}=fixture();
 for(const kind of ['BA Penyelesaian Pekerjaan','Lampiran BA Penyelesaian Pekerjaan']){
  const html=renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind,project:p,deal:d,spk:v,term:t,bapp:b,onClose:()=>{}}));assert.match(html,/Unduh Word \(\.docx\)/);assert.match(html,/data-confirm="Unduh dokumen Word sesuai isi preview ini\?"/);
 }
 for(const status of ['Draft','Dikembalikan','Ditolak'] as const){const term={...t,status};assert.throws(()=>createBappDocx(p,d,v,term),/setelah pengajuan/);assert.throws(()=>createBappAppendixDocx(s,p,d,v,term),/setelah pengajuan/);}
 t.status='Diajukan';t.approvals=[];
 for(const bytes of [createBappDocx(p,d,v,t),createBappAppendixDocx(s,p,d,v,t)])assert.match(docxParts(bytes)['word/document.xml'],/SALINAN REVIEW/);
});

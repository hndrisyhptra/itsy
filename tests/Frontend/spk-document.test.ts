import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { dealTotal } from '../../resources/js/area3/domain';
import { spkDocumentData, SPK_TEXT } from '../../resources/js/area3/spk-content';
import { createSpkDocx, spkWordFilename, SPK_DOCX_MIME } from '../../resources/js/area3/spk-docx';
import { SPK_WORD_PARTS } from '../../resources/js/area3/spk-word-template';
import { DocumentPreview } from '../../resources/js/area3/ui';
import { docxParts } from './helpers/docx-parts';
import { spkLogoAssetUrl } from '../../resources/js/area3/spk-assets';

const logo=new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url)));
test('SPK logo and Word asset resolve on Artisan, Apache subfolders and external asset hosts',()=>{
  assert.equal(spkLogoAssetUrl('/build/assets/logo.png','http://127.0.0.1:8013/build/assets/main.js'),'http://127.0.0.1:8013/build/assets/logo.png');
  assert.equal(spkLogoAssetUrl('/build/assets/logo.png','http://localhost/its/area3-prototype/public/build/assets/main.js'),'http://localhost/its/area3-prototype/public/build/assets/logo.png');
  assert.equal(spkLogoAssetUrl('/its/area3-prototype/public/build/assets/logo.png','http://localhost/its/area3-prototype/public/build/assets/main.js'),'http://localhost/its/area3-prototype/public/build/assets/logo.png');
  assert.equal(spkLogoAssetUrl('https://assets.example.test/logo.png','http://localhost/sub/build/assets/main.js'),'https://assets.example.test/logo.png');
});
function fixture(){
  const s=seed('D'),p=s.projects[0],d=s.deals[0],spk=s.spks[0];
  p.name='Pekerjaan SPK Word QA';p.branch='MATARAM';
  Object.assign(spk,{no:'0099-02/LG.000/TA-03R546/11-2026',date:'2026-11-07',place:'Tempat SPK QA',address:'Alamat mandor QA',start:'2026-11-30',end:'2026-12-13',supervisor:'Waspang QA',phone:'08QA-1234',signer:'Penandatangan SPK QA',position:'Jabatan tersimpan SPK QA'});
  return {s,p,d,spk};
}

test('SPK model maps the saved form and Mandor agreement, preserves historical signer and never substitutes BA KH dates or identities',()=>{
  const {s,p,d,spk}=fixture(),before=JSON.stringify(s);
  d.signer='Head lain';d.start='2020-01-01';d.end='2020-01-02';
  const source=JSON.stringify(s),data=spkDocumentData(p,d,spk);
  assert.equal(data.number,spk.no);assert.equal(data.placeDate,'Tempat SPK QA, 7 November 2026');
  assert.equal(data.mandor,d.mandor);assert.equal(data.address,spk.address);assert.equal(data.signer,spk.signer);
  assert.equal(data.position,spk.position.toUpperCase());
  assert.ok(data.opening.includes('30 November 2026 s/d 13 Desember 2026'));
  assert.ok(data.subject.includes(p.name));assert.ok(data.subject.includes(p.branch));
  assert.equal(data.total,dealTotal(d));assert.equal(Math.round((data.material+data.service)*100),Math.round(data.total*100));
  assert.equal(JSON.stringify(s),source);assert.notEqual(source,before);
});

test('native editable SPK Word retains the source header, page geometry, merged table and all fields without source sample people or metadata',()=>{
  const {s,p,d,spk}=fixture(),before=JSON.stringify(s),parts=docxParts(createSpkDocx(p,d,spk,logo));
  assert.equal(Object.keys(parts).length,13);
  assert.equal(parts['word/header1.xml'],SPK_WORD_PARTS['word/header1.xml']);
  assert.equal(parts['word/theme/theme1.xml'],SPK_WORD_PARTS['word/theme/theme1.xml']);
  const xml=parts['word/document.xml'];
  for(const value of [SPK_TEXT.title,SPK_TEXT.safety,p.name,p.branch,d.mandor,spk.no,spk.address,spk.supervisor,spk.phone,spk.signer,spk.position.toUpperCase()])assert.ok(xml.includes(value),value);
  assert.match(xml,/w:w="11920" w:h="16840"/);assert.match(xml,/w:top="1160" w:right="780" w:bottom="280" w:left="1080"/);
  assert.equal((xml.match(/<w:tblHeader\/>/g)??[]).length,2);
  assert.equal((xml.match(/<w:tr(?:\s|>)/g)??[]).length,3);
  assert.match(xml,/w:gridSpan w:val="3"/);assert.match(xml,/w:vMerge w:val="restart"/);
  assert.match(parts['word/styles.xml'],/w:styleId="Area3SpkTitle"/);
  assert.doesNotMatch(Object.keys(parts).join('|'),/customXml|docProps|vba|comments/);
  assert.doesNotMatch(Object.values(parts).join(''),/HENDRA CAHYA|Ikhwan Prasetyo|6281259494242|10761965|Oktober 2024|altChunk|TargetMode="External"/);
  assert.doesNotMatch(xml,/\{\{SPK_|<w:tblpPr|FF0000|<w:drawing|<w:pict|APPROVED/);
  assert.equal(SPK_DOCX_MIME,'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  assert.equal(JSON.stringify(s),before);
});

test('HTML SPK preview and Word use the same aggregate row and actual identity, bypassing unloaded root/KHS and retaining print/download actions',()=>{
  const {s,p,d,spk}=fixture();
  s.projects=[];s.khs=[];p.rootId='unloaded-root';
  const before=JSON.stringify({s,p,d,spk}),data=spkDocumentData(p,d,spk);
  const html=renderToStaticMarkup(createElement(DocumentPreview,{state:s,project:p,deal:d,spk,kind:'Surat Perintah Kerja',onClose:()=>{}}));
  assert.match(html,/Preview · SPK Mandor|SPK Mandor sesuai template/);
  assert.match(html,/Unduh SPK Mandor sebagai Word DOCX|Unduh Word \(\.docx\)/);
  assert.match(html,/Cetak \/ Simpan PDF browser/);assert.match(html,/aria-busy="false"/);
  assert.match(html,/NAMA CLUSTER|rowSpan="2"/);assert.match(html,/Area tanda tangan basah dokumen asli/);
  assert.equal((html.match(/<img /g)??[]).length,1);assert.match(html,/alt="Telkom Akses"/);
  for(const value of [spk.no,spk.address,spk.supervisor,spk.phone,spk.signer,data.materialText,data.serviceText,data.totalText])assert.ok(html.includes(value),value);
  assert.doesNotMatch(html,/Referensi bukti|Status bukti|Jumlah Kesepakatan|KHS|Designator|N\/A/);
  assert.ok(createSpkDocx(p,d,spk,logo).length>0);
  assert.equal(JSON.stringify({s,p,d,spk}),before);
});

test('SPK values distinguish zero and excluded components and retain canonical per-line rounding without changing amounts',()=>{
  const {p,d,spk}=fixture();
  d.rows=[{...d.rows[0],m:false,s:true,qs:10,ps:0}];
  let data=spkDocumentData(p,d,spk);assert.equal(data.materialText,'');assert.equal(data.serviceText,'0');assert.equal(data.total,0);
  for(const rows of [
    [{m:true,s:true,qm:1,qs:1,pm:.005,ps:.005}],
    [{m:true,s:false,qm:1,qs:0,pm:.005,ps:0},{m:true,s:false,qm:1,qs:0,pm:.005,ps:0}],
    [{m:false,s:true,qm:0,qs:1.125,pm:0,ps:123.45}],
  ]){
    d.rows=rows.map(row=>({...d.rows[0],...row}));data=spkDocumentData(p,d,spk);
    assert.equal(data.total,dealTotal(d));assert.equal(Math.round((data.material+data.service)*100),Math.round(data.total*100));
    assert.ok(data.material>=0&&data.service>=0);
  }
});

test('missing or invalid fields stay unfilled; escaped XML and literal slot-like user text cannot replace other fields or inject content',()=>{
  const {p,d,spk}=fixture();
  Object.assign(spk,{date:'2026-02-30',start:'',end:'bad',signer:'',position:'',phone:'',address:'<script>é & 中文 😀</script>\n{{SPK_NO}}\u0000'});
  const data=spkDocumentData(p,d,spk);assert.equal(data.signer,'…');assert.equal(data.position,'…');assert.equal(data.phone,'…');
  assert.equal(data.placeDate,'Tempat SPK QA, …');assert.ok(data.opening.includes('Tanggal … s/d …'));
  const xml=docxParts(createSpkDocx(p,d,spk,logo))['word/document.xml'];
  assert.match(xml,/&lt;script&gt;é &amp; 中文 😀&lt;\/script&gt;/);assert.match(xml,/\{\{SPK_NO\}\}/);
  assert.match(xml,/<w:br\/>/);assert.doesNotMatch(xml,/<script>|\u0000|Head lain/);
  spk.no='../../SPK:<QA>?';const filename=spkWordFilename(spk);
  assert.match(filename,/^SPK-Mandor_.*\.docx$/);assert.doesNotMatch(filename,/[\\/:*?"<>|]/);
  assert.throws(()=>createSpkDocx(p,d,spk,new Uint8Array()),/Logo template/);
});

test('long inputs use source-derived compact spacing without truncation; 1000 agreement rows need no catalog lookup or SQL path',()=>{
  const {s,p,d,spk}=fixture();p.name='Pekerjaan panjang '.repeat(12);spk.address='Alamat panjang '.repeat(10);
  d.rows=Array.from({length:1000},(_,i)=>({...d.rows[0],rowId:'qa-'+i,qm:1,qs:1,pm:i,ps:i}));
  s.projects.find=s.khs.find=()=>{throw new Error('Unexpected catalog lookup');};
  const data=spkDocumentData(p,d,spk);assert.equal(data.compact,true);assert.equal(data.total,dealTotal(d));
  const xml=docxParts(createSpkDocx(p,d,spk,logo))['word/document.xml'];
  assert.ok(xml.includes(p.name.trim()));assert.ok(xml.includes(spk.address.trim()));assert.match(xml,/w:line="320"/);
  assert.equal((xml.match(/<w:tr(?:\s|>)/g)??[]).length,3);
  assert.ok(createSpkDocx(p,d,spk,logo).length<500000);
});

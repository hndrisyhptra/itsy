import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { APPROVERS_V3, blankSpk, saveSpk, type Spk } from '../../resources/js/area3/domain';
import { syncSpkSigner } from '../../resources/js/area3/spk-signer';
import { SpkSignerFields } from '../../resources/js/area3/contracts';
import { DocumentPreview } from '../../resources/js/area3/ui';
import { createSpkAppendixDocx, spkAppendixWordFilename } from '../../resources/js/area3/spk-appendix-docx';
import { createSpkDocx } from '../../resources/js/area3/spk-docx';
import { docxParts } from './helpers/docx-parts';

function fixture() {
  const s=seed('D'),p=s.projects[0],d=s.deals[0],spk=s.spks[0];
  s.spks=[];s.terms=[];s.schema=3;p.workflowVersion=3;p.approverUserIds={'Head of PMO':'selected'};
  p.approvals=APPROVERS_V3.map(role=>({role,userId:role==='Head of PMO'?'selected':'qa-'+role,actorName:role==='Head of PMO'?'PP Head QA':'Approver QA',actorNik:role==='Head of PMO'?'000017':'',decision:'Setujui',note:'QA',at:'2026-11-07T01:00:00Z'}));
  p.signatureUsers={'Head of PMO':{userId:'other',name:'Wrong snapshot',nik:'999'}};
  s.users.push({id:'selected',role:'Head of PMO',name:'Renamed Head',nik:'000018',email:'head@example.test',active:true},{id:'other',role:'Head of PMO',name:'Other Head',nik:'999',email:'other@example.test',active:true});
  Object.assign(spk,{status:'Draft',signer:'FORGED',position:'FORGED',signerNik:'FORGED',signerUserId:'other'});
  return {s,p,d,spk};
}

test('SPK new/cached drafts and domain saves resolve the same selected Head snapshot and ignore forged identity',()=>{
  const {s,p,d,spk}=fixture(),before=JSON.stringify(s);
  const v=syncSpkSigner(s,spk),fresh=blankSpk(s,d.id);
  for(const x of [v,fresh]) {assert.equal(x.signer,'PP Head QA');assert.equal(x.signerNik,'000017');assert.equal(x.signerUserId,'selected');assert.equal(x.position,'HEAD OF PMO AREA JAWA BALI');}
  assert.equal(JSON.stringify(s),before);
  for(const action of ['save','generate','attest'] as const){const copy=structuredClone(s);saveSpk(copy,spk,action);assert.equal(copy.spks[0].signerNik,'000017');assert.equal(copy.spks[0].signer,'PP Head QA');}
  const child={...structuredClone(p),id:'child'};child.approverUserIds!['Head of PMO']='other';child.signatureUsers!['Head of PMO']!.userId='selected';s.projects.push(child);d.projectId='child';
  assert.equal(syncSpkSigner(s,spk).signer,'Other Head');assert.equal(syncSpkSigner(s,spk).signerNik,'999');
});

test('missing Head/NIK cannot invent identity; historical issued SPKs retain actual saved signer, position and NIK',()=>{
  const {s,p,spk}=fixture();delete p.approvals!.find(a=>a.role==='Head of PMO')!.actorNik;s.users.find(u=>u.id==='selected')!.nik='';
  assert.equal(syncSpkSigner(s,spk).signerNik,'');
  delete p.approverUserIds;assert.equal(syncSpkSigner(s,spk).signer,'');
  assert.throws(()=>saveSpk(s,spk,'generate'),/Head of PMO/);
  const historic:Spk={...spk,status:'Terbit',signer:'Saved Head',position:'Saved job',signerNik:'000001'};s.spks=[historic];
  assert.equal(syncSpkSigner(s,spk).signer,'Saved Head');assert.equal(syncSpkSigner(s,spk).signerNik,'000001');
  delete historic.signerNik;assert.equal(syncSpkSigner(s,spk).signerNik,undefined);
});

test('both SPK previews auto-fill name, job and NIK, offer Word and keep physical signature areas empty',()=>{
  const {s,p,d,spk}=fixture(),v=syncSpkSigner(s,spk),before=JSON.stringify(s);
  const fields=renderToStaticMarkup(createElement(SpkSignerFields,{spk:v}));
  assert.equal((fields.match(/readOnly=""/g)??[]).length,3);assert.match(fields,/value="000017"/);
  for(const kind of ['Surat Perintah Kerja','Lampiran SPK']){
    const html=renderToStaticMarkup(createElement(DocumentPreview,{state:s,project:p,deal:d,spk,kind,onClose:()=>{}}));
    for(const value of ['PP Head QA','HEAD OF PMO AREA JAWA BALI','NIK: 000017','Unduh Word (.docx)'])assert.ok(html.includes(value),value);
    assert.doesNotMatch(html,/FORGED|NAMA PROYEK|APPROVED/);assert.match(html,/tanda tangan basah/);
  }
  assert.equal(JSON.stringify(s),before);
});

test('native landscape appendix Word preserves the grid, merge/repeat headers, selected quantities, identity and literal escaped text',()=>{
  const {s,p,d,spk}=fixture(),v=syncSpkSigner(s,spk);p.name='Proyek <QA> & 中文 😀';
  d.rows=[{...d.rows[0],m:true,s:true,qm:5,qs:8,pm:1000,ps:0}];
  const before=JSON.stringify(s),parts=docxParts(createSpkAppendixDocx(s,p,d,v)),xml=parts['word/document.xml'];
  assert.equal(Object.keys(parts).length,5);assert.match(xml,/w:orient="landscape"/);
  assert.equal((xml.match(/<w:gridCol /g)??[]).length,11);
  assert.equal((xml.match(/<w:tblHeader\/>/g)??[]).length,2);
  assert.match(xml,/w:gridSpan w:val="4"/);assert.match(xml,/w:vMerge w:val="restart"/);
  assert.match(xml,/w:pStyle w:val="Title"/);assert.match(xml,/Proyek &lt;QA&gt; &amp; 中文 😀/);
  for(const value of ['PP Head QA','HEAD OF PMO AREA JAWA BALI','NIK: 000017','TOTAL VOL','JUMLAH HARGA'])assert.ok(xml.includes(value));
  assert.doesNotMatch(xml,/NAMA PROYEK|FORGED|<w:drawing|altChunk|APPROVED|N\/A/);
  assert.equal(JSON.stringify(s),before);
  spk.no='../../bad:/<name>?';assert.doesNotMatch(spkAppendixWordFilename(spk),/[\\/:*?"<>|]/);
  const logo=new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url)));
  assert.match(docxParts(createSpkDocx(p,d,v,logo))['word/document.xml'],/NIK: 000017/);
});

test('1000-row Word attachment uses indexed references, preserves all rows and has no external relations or per-row catalog reads',()=>{
  const {s,p,d,spk}=fixture(),v=syncSpkSigner(s,spk),source=p.boq[0];
  d.rows=Array.from({length:1000},(_,i)=>({...d.rows[0],rowId:`qa-${i}`,m:false,s:true,qs:1,ps:100}));
  p.boq=d.rows.map(row=>({...source,id:row.rowId}));
  s.khs.find=p.boq.find=()=>{throw new Error('Per-row find');};
  const parts=docxParts(createSpkAppendixDocx(s,p,d,v)),xml=parts['word/document.xml'];
  assert.equal((xml.match(/<w:tr>/g)??[]).length,1005);
  assert.doesNotMatch(Object.values(parts).join(''),/TargetMode="External"|vbaProject|<w:drawing/);
});

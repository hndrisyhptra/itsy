import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { APPROVERS_V3, blankDeal, saveDeal, getDeal, type Deal } from '../../resources/js/area3/domain';
import { syncBakhSigner } from '../../resources/js/area3/bakh-signer';
import { BakhSignerFields } from '../../resources/js/area3/contracts';
import { DocumentPreview } from '../../resources/js/area3/ui';

function fixture(){
  const s=seed('C'),p=s.projects[0],d=structuredClone(s.deals[0]);s.schema=3;s.deals=[];p.workflowVersion=3;p.approvalScheme='project-support-first';
  p.approverUserIds={'Head of PMO':'head-selected'};
  p.approvals=APPROVERS_V3.map(role=>({role,userId:role==='Head of PMO'?'head-selected':'qa-'+role,actorName:role==='Head of PMO'?'Approved Head QA':'Other Approver QA',actorNik:role==='Head of PMO'?'QA-001':'',decision:'Setujui',note:'Synthetic test decision',at:'2026-11-06T01:00:00Z'}));
  p.signatureUsers={'Head of PMO':{userId:'head-selected',name:'PP Head QA',nik:'QA-002'}};
  s.users.push({id:'head-other',role:'Head of PMO',name:'Other Head QA',nik:'QA-OTHER',email:'other@example.test',active:true},{id:'head-selected',role:'Head of PMO',name:'Renamed Head QA',nik:'QA-003',email:'selected@example.test',active:true});
  d.status='Draft';d.evidence='Synthetic work document reference';d.signer='FORGED';d.signerNik='FORGED';d.signerUserId='head-other';
  return {s,p,d};
}

test('new and cached draft BA KH use the selected PP Head identity and ignore client signer values',()=>{
  const {s,p,d}=fixture(),before=JSON.stringify(p);
  const fresh=blankDeal(s,p.id);assert.equal(fresh.signer,'Approved Head QA');assert.equal(fresh.signerNik,'QA-001');
  for(const action of ['save','generate','attest']as const){const copy=structuredClone(s);saveDeal(copy,d,action);const saved=getDeal(copy,d.id);assert.equal(saved.signer,'Approved Head QA');assert.equal(saved.signerNik,'QA-001');assert.equal(saved.signerUserId,'head-selected');assert.equal(saved.signerRole,'Head of PMO');}
  assert.equal(JSON.stringify(p),before);
});

test('continuation selects its own Head, missing NIK stays empty and missing assignment never selects another Head',()=>{
  const {s,p,d}=fixture(),child=structuredClone(p);child.id='child-signer';child.approverUserIds!['Head of PMO']='head-other';s.projects.push(child);
  assert.equal(syncBakhSigner(s,{...d,projectId:child.id}).signer,'Other Head QA');
  delete p.approvals![4].actorNik;p.signatureUsers!['Head of PMO']!.nik='';s.users.find(u=>u.id==='head-selected')!.nik='';assert.equal(syncBakhSigner(s,d).signerNik,'');
  delete p.approverUserIds;assert.equal(syncBakhSigner(s,d).signer,'');assert.equal(syncBakhSigner(s,d).signerNik,'');assert.throws(()=>saveDeal(s,d,'generate'),/penandatangan/);
});

test('issued BA KH keeps its stored signer identity and old documents never acquire a fabricated NIK',()=>{
  const {s,d}=fixture();const original={...d,status:'Terbit' as const,signer:'Original Signer QA',signerNik:'QA-ORIGINAL',signerUserId:'original-head',signerRole:'Head of PMO' as const,signerSource:'pp-head-pmo' as const};s.deals=[original];
  const resolved=syncBakhSigner(s,d);assert.equal(resolved.signer,original.signer);assert.equal(resolved.signerNik,original.signerNik);
  saveDeal(s,d,'attest');assert.equal(getDeal(s,d.id).signerNik,original.signerNik);assert.equal(getDeal(s,d.id).signer,original.signer);
  const legacy:Deal={...original};delete legacy.signerNik;delete legacy.signerUserId;delete legacy.signerSource;s.deals=[legacy];assert.equal(syncBakhSigner(s,d).signerNik,undefined);
});

test('BA KH signer fields are read-only and preview includes the real name and NIK with blank physical signature space',()=>{
  const {s,p,d}=fixture(),resolved=syncBakhSigner(s,d);
  const fields=renderToStaticMarkup(createElement(BakhSignerFields,{deal:resolved}));assert.equal((fields.match(/readOnly=""/g)??[]).length,2);assert.match(fields,/value="Approved Head QA"/);assert.match(fields,/value="QA-001"/);
  const preview=renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'BA Kesepakatan Harga',project:p,deal:resolved,onClose:()=>{}}));assert.match(preview,/Head of PMO · Telkom Akses/);assert.match(preview,/Approved Head QA/);assert.match(preview,/NIK: QA-001/);assert.match(preview,/Area tanda tangan dokumen asli/);assert.doesNotMatch(preview,/>APPROVED</);
  const missing=renderToStaticMarkup(createElement(BakhSignerFields,{deal:{signer:'Selected Head QA',signerNik:''}}));assert.match(missing,/Lengkapi data akun Head of PMO/);
});

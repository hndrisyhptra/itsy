import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { blankDeal, blankSpk, saveDeal, saveSpk } from '../../resources/js/area3/domain';
import { contractNumber, syncDealNumber, syncSpkNumber } from '../../resources/js/area3/contract-numbering';
import { ContractNumberField, DealEditor, SpkEditor } from '../../resources/js/area3/contracts';
import { DocumentPreview } from '../../resources/js/area3/ui';

const vectors: {pp:string;bakh:string;spk:string}[]=JSON.parse(readFileSync(new URL('../Fixtures/contract-numbering.json',import.meta.url),'utf8'));
function fixture() {
  const s=seed('C'),deal=structuredClone(s.deals[0]);s.schema=3;s.projects[0].no=vectors[0].pp;
  s.deals=[];deal.no='STALE/CLIENT';deal.status='Draft';deal.evidence='Bukti QA';
  return {s,deal};
}
function spkFixture() {
  const s=seed('D'),spk=structuredClone(s.spks[0]);s.schema=3;s.projects[0].no=vectors[0].pp;
  s.deals[0].no='BAKH/HISTORIS';s.spks=[];spk.no='STALE/CLIENT';spk.status='Draft';spk.evidence='Bukti QA';
  return {s,spk};
}

test('BA KH and SPK numbers match shared PP vectors without changing padding, Branch or stored period',()=>{
  for(const v of vectors){assert.equal(contractNumber(v.pp,'01'),v.bakh);assert.equal(contractNumber(v.pp,'02'),v.spk);}
  assert.throws(()=>contractNumber(' ','01'),/Nomor PP tersimpan/);
  assert.throws(()=>contractNumber('x'.repeat(148),'02'),/150 karakter/);
  assert.equal(contractNumber('x'.repeat(147),'01').length,150);
});

test('new and saved draft BA KH gets code 01 for save, generate and attest despite stale client numbers',()=>{
  for(const action of ['save','generate','attest']as const)for(const savedDraft of [false,true]){
    const {s,deal}=fixture();if(savedDraft)s.deals.push(structuredClone(deal));
    const before=JSON.stringify(deal),projects=JSON.stringify(s.projects);
    assert.equal(blankDeal(s,deal.projectId).no,vectors[0].bakh);
    saveDeal(s,deal,action);assert.equal(s.deals[0].no,vectors[0].bakh);
    assert.equal(s.deals[0].status,action==='save'?'Draft':action==='generate'?'Terbit':'Sah');
    assert.equal(JSON.stringify(deal),before);assert.equal(JSON.stringify(s.projects),projects);
  }
});

test('SPK derives code 02 from the PP rather than the BA KH number for every save action',()=>{
  for(const action of ['save','generate','attest']as const)for(const savedDraft of [false,true]){
    const {s,spk}=spkFixture();if(savedDraft)s.spks.push(structuredClone(spk));
    const before=JSON.stringify(spk);assert.equal(blankSpk(s,spk.dealId).no,vectors[0].spk);
    saveSpk(s,spk,action);assert.equal(s.spks[0].no,vectors[0].spk);assert.equal(JSON.stringify(spk),before);
    assert.equal(s.deals[0].no,'BAKH/HISTORIS');
  }
});

test('fixed type codes cannot be bypassed with another client number to create duplicate BA KH or SPK',()=>{
  const {s,deal}=fixture();saveDeal(s,deal);const before=JSON.stringify(s);
  assert.throws(()=>saveDeal(s,{...structuredClone(deal),id:'second-bakh',no:'ANOTHER/MANUAL'}),/Nomor BA KH sudah digunakan/);
  assert.equal(JSON.stringify(s),before);
  const {s:ss,spk}=spkFixture();saveSpk(ss,spk);const snapshot=JSON.stringify(ss);
  assert.throws(()=>saveSpk(ss,{...structuredClone(spk),id:'second-spk',no:'ANOTHER/MANUAL'}),/duplikat/);
  assert.equal(JSON.stringify(ss),snapshot);
});

test('a continuation uses its own PP number, independently of the root and another PP on the same Project ID',()=>{
  const {s,deal}=fixture(),root=s.projects[0];
  const child={...structuredClone(root),id:'pp-child',parentId:root.id,no:'0099/LG.000/TA-03R502/01-2027'};
  s.projects.push(child);const input={...deal,projectId:child.id};
  assert.equal(syncDealNumber(s,input).no,'0099-01/LG.000/TA-03R502/01-2027');
  s.deals.push({...input,status:'Sah'});
  const v={...seed('D').spks[0],dealId:input.id};
  assert.equal(syncSpkNumber(s,v).no,'0099-02/LG.000/TA-03R502/01-2027');
  assert.equal(root.no,vectors[0].pp);assert.equal(child.projectCode,root.projectCode);
});

test('issued legacy numbers survive attestation and Sah documents remain locked in current account workflows',()=>{
  const {s,deal}=fixture();deal.status='Terbit';deal.no='BAKH/LAMA';s.deals.push(structuredClone(deal));
  saveDeal(s,{...deal,no:vectors[0].bakh},'attest');assert.equal(s.deals[0].no,'BAKH/LAMA');
  assert.throws(()=>saveDeal(s,deal),/sah/);
  const {s:ss,spk}=spkFixture();spk.status='Terbit';spk.no='SPK/LAMA';ss.spks.push(structuredClone(spk));
  saveSpk(ss,{...spk,no:vectors[0].spk},'attest');assert.equal(ss.spks[0].no,'SPK/LAMA');
  assert.throws(()=>saveSpk(ss,spk),/sah/);
});

test('account forms display generated read-only numbers and previews use those same document numbers',()=>{
  for(const kind of ['BA KH','SPK']as const){const html=renderToStaticMarkup(createElement(ContractNumberField,{kind,number:kind==='BA KH'?vectors[0].bakh:vectors[0].spk,ppNo:vectors[0].pp}));assert.match(html,/readOnly=""/);assert.ok(html.includes(`aria-label="Nomor ${kind} (otomatis)"`));}
  const {s,deal}=fixture();s.deals.push(deal);
  const html=renderToStaticMarkup(createElement(DealEditor,{s,id:deal.id}));assert.ok(html.includes(`value="${vectors[0].bakh}"`));assert.doesNotMatch(html,/STALE\/CLIENT/);
  const {s:ss,spk}=spkFixture();ss.spks.push(spk);
  const form=renderToStaticMarkup(createElement(SpkEditor,{s:ss,id:spk.id}));assert.ok(form.includes(`value="${vectors[0].spk}"`));assert.doesNotMatch(form,/STALE\/CLIENT/);
  const v=syncSpkNumber(ss,spk),preview=renderToStaticMarkup(createElement(DocumentPreview,{state:ss,kind:'Surat Perintah Kerja',spk:v,onClose:()=>{}}));
  assert.ok(preview.includes(vectors[0].spk));assert.ok(preview.includes(vectors[0].pp));
});

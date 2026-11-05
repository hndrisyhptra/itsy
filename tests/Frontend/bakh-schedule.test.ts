import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { bakhPeriod, syncBakhPeriod } from '../../resources/js/area3/bakh-schedule';
import { seed } from '../../resources/js/area3/seed';
import { blankDeal, saveDeal, getDeal, type Schedule } from '../../resources/js/area3/domain';
import { BakhPeriodFields } from '../../resources/js/area3/contracts';

const vectors=JSON.parse(readFileSync(new URL('../Fixtures/bakh-schedule.json',import.meta.url),'utf8')) as {name:string;schedule:Schedule[];expected:ReturnType<typeof bakhPeriod>}[];
function fixture() {
  const s=seed('C'),d=structuredClone(s.deals[0]),p=s.projects.find(p=>p.id===d.projectId)!;
  s.deals=[];p.schedule=vectors[0].schedule.map((row,i)=>({...row,name:'Stage '+i}));
  d.status='Draft';d.evidence='Bukti sintetis QA';d.start='2000-01-01';d.end='2100-12-31';
  return {s,d,p};
}

test('BA KH calendar vectors agree with PHP for month/leap boundaries, HK caps and invalid PP schedules',()=>{
  for(const v of vectors)assert.deepEqual(bakhPeriod({schedule:v.schedule}),v.expected,v.name);
});

test('new BA KH and cached drafts use PP dates in the form, save, generate and attestation',()=>{
  for(const action of ['save','generate','attest'] as const)for(const existing of [false,true]) {
    const {s,d,p}=fixture();if(existing)s.deals.push(structuredClone(d));
    assert.equal(blankDeal(s,p.id).start,'2026-11-30');assert.equal(blankDeal(s,p.id).end,'2026-12-13');
    const effective=syncBakhPeriod(s,d);assert.equal(effective.start,'2026-11-30');assert.equal(effective.end,'2026-12-13');
    assert.equal(d.start,'2000-01-01');const pp=structuredClone(p);saveDeal(s,d,action);
    assert.equal(getDeal(s,d.id).start,'2026-11-30');assert.equal(getDeal(s,d.id).end,'2026-12-13');assert.deepEqual(p,pp);
  }
});

test('selected continuation schedule is used while issued and approved snapshots keep their dates',()=>{
  const {s,d,p}=fixture(),child=structuredClone(p);child.id='pp-child-dates';child.rootId=p.id;child.parentId=p.id;
  child.schedule=[{name:'Continuation',days:2,start:'2027-01-04',end:'2027-01-05'}];s.projects.push(child);
  assert.equal(syncBakhPeriod(s,{...d,projectId:child.id}).start,'2027-01-04');
  for(const status of ['Terbit','Sah']as const) {
    s.deals=[{...d,status,start:'2026-11-30',end:'2026-12-01'}];
    assert.equal(syncBakhPeriod(s,d).end,'2026-12-01');
    if(status==='Terbit') {saveDeal(s,d,'attest');assert.equal(getDeal(s,d.id).end,'2026-12-01');}
  }
  const bad=fixture();bad.p.schedule=[];assert.throws(()=>saveDeal(bad.s,bad.d),/Jadwal PP belum lengkap/);
});

test('document date controls are read-only and show automatically derived dates without period alerts',()=>{
  const {s,d}=fixture(),deal=syncBakhPeriod(s,d);
  const html=renderToStaticMarkup(createElement(BakhPeriodFields,{deal,disabled:false}));
  assert.equal((html.match(/readOnly=""/g)??[]).length,2);
  assert.match(html,/value="2026-11-30"/);assert.match(html,/value="2026-12-13"/);
  assert.match(html,/Otomatis mengikuti jadwal PP/);assert.doesNotMatch(html,/role="alert"/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { blankDeal, saveDeal, dealErrors, getDeal, type Deal } from '../../resources/js/area3/domain';
import { mandorSphThreshold } from '../../resources/js/area3/sph';
import { AgreementPriceField, SphPriceField } from '../../resources/js/area3/contracts';

function fixture() {
  const s=seed('B'),p=s.projects[0];
  p.planBudget={version:1,mandor:p.boq.map(row=>({rowId:row.id,qtyM:row.m===null?0:row.qty,qtyS:row.s===null?0:row.qty,priceM:(row.m??0)/2,priceS:(row.s??0)/2})),materials:[],bop:[],permit:0,bopLimit:null,bopReference:'',usage:[]};
  const d=blankDeal(s,p.id),r=d.rows[0];
  Object.assign(d,{no:'SPH/QA',mandor:'Mandor QA',place:'QA',location:'QA',witel:'QA',date:p.date,start:p.schedule[0].start,end:p.schedule[0].start,signer:'QA',fo:'QA',jointer:'QA',evidence:'Bukti sintetis QA'});
  r.m=r.s=true;r.qm=r.qs=1;
  return {s,p,d,r};
}

test('new SPH defaults to each saved Mandor unit price and accepts prices exactly at the threshold',()=>{
  const {s,p,d,r}=fixture(),plan=p.planBudget!.mandor[0];
  assert.equal(r.sphM,plan.priceM);assert.equal(r.sphS,plan.priceS);
  for(const action of ['save','generate','attest']as const){
    const state=structuredClone(s);saveDeal(state,d,action);assert.equal(getDeal(state,d.id).rows[0].sphM,plan.priceM);assert.equal(getDeal(state,d.id).rows[0].sphS,plan.priceS);
  }
});

test('SPH Material and Jasa cannot offset each other or bypass saved PP thresholds via submitted status',()=>{
  const {s,d}=fixture(),baseline=JSON.stringify(s);
  for(const key of ['sphM','sphS']as const)for(const action of ['save','generate','attest']as const){
    const bad=structuredClone(d);bad.rows[0][key]+=.01;bad.rows[0][key==='sphM'?'sphS':'sphM']=0;bad.status='Sah';
    assert.throws(()=>saveDeal(s,bad,action),new RegExp(`SPH ${key==='sphM'?'Material':'Jasa'}.*melebihi threshold`));assert.equal(JSON.stringify(s),baseline);
  }
  const {s:zero,p,d:dz,r}=fixture();p.planBudget!.mandor[0].priceM=0;r.pm=0;r.sphM=.01;assert.throws(()=>saveDeal(zero,dz),/threshold/);r.sphM=0;saveDeal(zero,dz);
  const {s:missing,p:pm,d:dm}=fixture();pm.planBudget!.mandor.shift();assert.throws(()=>saveDeal(missing,dm),/belum tersedia/);
});

test('thresholds follow the selected continuation, preserve issued snapshots and keep legacy PP behavior',()=>{
  const {s,p,d}=fixture(),child=structuredClone(p);child.id='pp-child-threshold';child.parentId=p.id;child.allocations={[p.boq[0].id]:{m:5,s:5}};child.planBudget!.mandor[0].priceS=20;s.projects.push(child);
  const own=structuredClone(d);own.projectId=child.id;own.rows[0].sphS=20.01;
  assert.equal(mandorSphThreshold(child,p.boq[0].id,'s'),20);assert.ok(dealErrors(s,own).some(e=>e.includes('SPH Jasa')&&e.includes('threshold')));
  const {s:old,p:op,d:od,r}=fixture();od.status='Terbit';r.sphM=r.capM!;old.deals.push(structuredClone(od));const input=structuredClone(od);input.rows[0].sphM=999999;
  saveDeal(old,input,'attest');assert.equal(getDeal(old,od.id).rows[0].sphM,r.capM);
  const legacy=seed('B'),lp=legacy.projects[0],ld=blankDeal(legacy,lp.id);assert.equal(ld.rows[0].sphM,Math.round((lp.boq[0].m??0)*.8*100)/100);assert.equal(mandorSphThreshold(lp,lp.boq[0].id,'m'),null);
});

test('SPH price fields show accessible immediate threshold alerts, zero caps and per-unit hints',()=>{
  const props={value:100.01,threshold:100 as number|null,required:true,component:'Jasa' as const,code:'QA',disabled:false,onChange:()=>{}};
  const render=(patch:Partial<typeof props>={})=>renderToStaticMarkup(createElement(SphPriceField,{...props,...patch}));
  assert.match(render(),/aria-invalid="true"/);assert.match(render(),/max="100"/);assert.match(render(),/role="alert"/);assert.match(render(),/melebihi threshold/);assert.match(render(),/per satuan/);
  assert.doesNotMatch(render({value:100}),/role="alert"|aria-invalid="true"/);
  assert.match(render({value:.01,threshold:0}),/max="0"/);
  const missing=renderToStaticMarkup(createElement(SphPriceField,{...props,threshold:null}));assert.match(missing,/belum tersedia/);
});


test('agreement defaults and exact ceilings follow the saved Mandor Material and Jasa unit prices',()=>{
  const {s,p,d,r}=fixture(),plan=p.planBudget!.mandor[0];assert.equal(r.pm,plan.priceM);assert.equal(r.ps,plan.priceS);
  for(const action of ['save','generate','attest']as const){const copy=structuredClone(s);saveDeal(copy,d,action);assert.equal(getDeal(copy,d.id).rows[0].pm,plan.priceM);assert.equal(getDeal(copy,d.id).rows[0].ps,plan.priceS);}
  const before=JSON.stringify(s);
  for(const key of ['pm','ps']as const)for(const action of ['save','generate','attest']as const){const bad=structuredClone(d);bad.rows[0][key]+=.01;bad.rows[0][key==='pm'?'ps':'pm']=0;bad.status='Sah';assert.throws(()=>saveDeal(s,bad,action),new RegExp('Harga kesepakatan '+(key==='pm'?'Material':'Jasa')+'.*melebihi threshold'));assert.equal(JSON.stringify(s),before);}
});

test('agreement limits honor zero, missing references, excluded components and the selected continuation',()=>{
  const {s,p,d,r}=fixture();p.planBudget!.mandor[0].priceM=0;r.sphM=0;r.pm=.01;assert.throws(()=>saveDeal(s,d),/Harga kesepakatan Material/);r.pm=0;saveDeal(s,d);
  const {s:childState,p:root,d:childDeal}=fixture(),child=structuredClone(root);child.id='child-agreement';child.parentId=root.id;child.rootId=root.id;child.allocations={[root.boq[0].id]:{m:5,s:5}};child.planBudget!.mandor[0].priceS=20;childState.projects.push(child);childDeal.projectId=child.id;childDeal.rows[0].sphS=20;childDeal.rows[0].ps=20.01;assert.ok(dealErrors(childState,childDeal).some(e=>e.includes('Harga kesepakatan Jasa')));
  const {s:excluded,d:ed,r:er}=fixture();er.m=false;er.qm=0;er.pm=999999;saveDeal(excluded,ed);
  const {s:missing,p:mp,d:md}=fixture();mp.planBudget!.mandor.shift();assert.throws(()=>saveDeal(missing,md),/belum tersedia/);
});

test('issued agreement snapshots retain pre-policy prices and ignore attempted changes at attestation',()=>{
  const {s,d,r}=fixture();r.pm=r.capM!;r.ps=r.capS!;d.status='Terbit';s.deals.push(structuredClone(d));const input=structuredClone(d);input.rows[0].pm=input.rows[0].ps=999999;saveDeal(s,input,'attest');assert.deepEqual(getDeal(s,d.id).rows,d.rows);
});

test('agreement inputs expose per-component max values and immediate accessible threshold alerts',()=>{
  const props={value:100.01,threshold:100 as number|null,required:true,component:'Material' as const,code:'QA',disabled:false,onChange:()=>{}};
  const render=(patch:Partial<typeof props>={})=>renderToStaticMarkup(createElement(AgreementPriceField,{...props,...patch}));
  assert.match(render(),/aria-label="Harga kesepakatan Material QA"/);assert.match(render(),/aria-invalid="true"/);assert.match(render(),/max="100"/);assert.match(render(),/role="alert"/);assert.match(render(),/Harga kesepakatan Material melebihi threshold/);
  assert.doesNotMatch(render({value:100}),/role="alert"|aria-invalid="true"/);assert.match(render({value:.01,threshold:0}),/max="0"/);assert.match(render({threshold:null}),/belum tersedia/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import postcss from 'postcss';
import { seed } from '../../resources/js/area3/seed';
import { blankProject, normalizeWorkflow, saveProject, getProject, decideProject, selectActor, APPROVERS_V3, type Project } from '../../resources/js/area3/domain';
import { applyBudgetPlan } from '../../resources/js/area3/plan-budget';
import { BudgetPlanSection, RiskQualitySection, signaturePerson } from '../../resources/js/area3/plan-budget-ui';
import { Projects, ProjectEditor } from '../../resources/js/area3/projects';
import { DocumentPreview } from '../../resources/js/area3/ui';

function fixture() {
  const s=normalizeWorkflow(seed('B'));s.schema=3;
  for(const [i,role]of APPROVERS_V3.entries())if(!s.users.some(u=>u.role===role))s.users.push({id:'qa-approver-'+i,name:'QA '+role,email:`qa-${i}@example.test`,role,active:true});
  s.actorUserId=s.users.find(u=>u.role==='Admin Konstruksi')!.id;
  const p={...blankProject(),unit:'Konstruksi',name:'QA Header Only',branch:s.branches![0].name,projectCode:'HEADER-ONLY-QA',budget:100000,date:'2026-11-05'};
  p.schedule=[];
  return {s,p};
}

test('complete Header alone saves a numbered SQL-ready draft shown in the PP list; submission still needs BOQ and approvals',()=>{
  const {s,p}=fixture();saveProject(s,p,false);const saved=getProject(s,p.id);
  assert.equal(saved.status,'Draft');assert.equal(saved.boqStatus,'Belum diimpor');assert.equal(saved.boq.length,0);assert.equal(saved.schedule.length,5);assert.equal(saved.durationDays,0);
  assert.equal(saved.approvals?.length,0);assert.deepEqual(saved.approverUserIds,{});assert.ok(saved.no);
  const list=renderToStaticMarkup(createElement(Projects,{s}));assert.ok(list.includes(saved.no));assert.ok(list.includes('QA Header Only'));assert.match(list,/>Draft<\/span>/);
  const before=JSON.stringify(s);assert.throws(()=>saveProject(s,saved,true));assert.equal(JSON.stringify(s),before);
  const p2={...blankProject(),name:'Second PP',unit:'Konstruksi',branch:p.branch,projectCode:p.projectCode,budget:1000,date:p.date};
  saveProject(s,p2,false);assert.notEqual(getProject(s,p2.id).no,saved.no);assert.notEqual(p2.id,saved.id);
});

test('draft still rejects an incomplete Header or invalid input without creating another PP',()=>{
  for(const change of [(p:Project)=>{p.name='';},(p:Project)=>{p.projectCode='';},(p:Project)=>{p.branch='';},(p:Project)=>{p.budget=0;},(p:Project)=>{p.date='2026-02-30';},(p:Project)=>{p.schedule=[{name:'QA',days:-1,start:'',end:''}];}]){
    const {s,p}=fixture();change(p);const before=JSON.stringify(s);assert.throws(()=>saveProject(s,p,false));assert.equal(JSON.stringify(s),before);
  }
});

test('opening a new PP never restores the shared cache belonging to a previous PP',()=>{
  const {s,p}=fixture();let reads=0;const prior=Object.getOwnPropertyDescriptor(globalThis,'localStorage');
  Object.defineProperty(globalThis,'localStorage',{configurable:true,value:{getItem:()=>{reads++;return JSON.stringify({...p,name:'STALE PREVIOUS PP',boq:seed('B').projects[0].boq});},setItem:()=>{},removeItem:()=>{}}});
  try {
    const html=renderToStaticMarkup(createElement(ProjectEditor,{s,id:'new'}));
    assert.equal(reads,0);assert.doesNotMatch(html,/STALE PREVIOUS PP/);assert.match(html,/Isi Header PP/);assert.match(html,/Simpan Draft/);
  } finally {if(prior)Object.defineProperty(globalThis,'localStorage',prior);else Reflect.deleteProperty(globalThis,'localStorage');}
});

test('BOP saves and submits without a cap/reference and stale fields are ignored, while source costs remain authoritative',()=>{
  const {s,p}=fixture();p.boq=structuredClone(s.projects[0].boq);p.schedule=structuredClone(s.projects[0].schedule);p.riskLevel='RENDAH';
  p.approverUserIds=Object.fromEntries(APPROVERS_V3.map(role=>[role,s.users.find(u=>u.role===role)!.id]));
  const ready=applyBudgetPlan(s,p);ready.planBudget!.bop=[{id:'bop-qa',description:'Biaya operasional',unit:'Ls',qty:1,total:2500}];
  ready.planBudget!.bopLimit=1;ready.planBudget!.bopReference='';
  saveProject(s,ready,true);const saved=getProject(s,p.id);assert.equal(saved.status,'Diajukan');assert.equal(saved.costs.bop,2500);assert.equal(saved.planBudgetTotals!.usage.bop,2500);
  assert.equal(saved.planBudget!.bopLimit,undefined);assert.equal(saved.planBudget!.bopReference,undefined);
  const form=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:saved}));assert.doesNotMatch(form,/Batas maksimal BOP|Referensi Cost Structure|Maksimal sesuai dengan Cost Structure/);
});

test('preview stamp follows actual current-cycle approvals only; returned/rejected drafts never reuse old approvals',()=>{
  const {s,p}=fixture();p.boq=structuredClone(s.projects[0].boq);p.schedule=structuredClone(s.projects[0].schedule);p.riskLevel='RENDAH';
  p.approverUserIds=Object.fromEntries(APPROVERS_V3.map(role=>[role,s.users.find(u=>u.role===role)!.id]));saveProject(s,p,true);
  let saved=getProject(s,p.id);
  const html=()=>renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'Project Plan',project:saved,onClose:()=>{}}));
  assert.doesNotMatch(html(),/>APPROVED</);
  for(const [i,role]of APPROVERS_V3.entries()){
    s.role=role;selectActor(s,s.users.find(u=>u.role===role)!.id);decideProject(s,p.id,'Setujui','QA');saved=getProject(s,p.id);
    assert.equal((html().match(/>APPROVED</g)??[]).length,i+1);
  }
  assert.equal(saved.status,'Sah');assert.match(html(),/pp-signature-print/);
  const before=JSON.stringify(saved);renderToStaticMarkup(createElement(RiskQualitySection,{s,p:saved,printing:true}));assert.equal(JSON.stringify(saved),before);
  const row=saved.signatureRows!.find(row=>row.role==='PM Konstruksi')!;
  const fake=structuredClone(saved);fake.approvals![0].userId='different-user';assert.equal(signaturePerson(s,fake,row).approved,false);
  for(const status of ['Draft','Dikembalikan','Ditolak']as const){fake.status=status;fake.approvals=structuredClone(saved.approvals);const h=renderToStaticMarkup(createElement(RiskQualitySection,{s,p:fake,printing:true}));assert.doesNotMatch(h,/>APPROVED</);}
});

test('print stylesheet hides approval/status preview and shows an empty wet-signature container',()=>{
  const css=postcss.parse(readFileSync(new URL('../../resources/js/area3/styles.css',import.meta.url),'utf8'));
  const displays=new Map<string,string>();
  css.walkAtRules('media',media=>{if(media.params==='print')media.walkRules(rule=>{for(const selector of rule.selectors)rule.walkDecls('display',d=>{displays.set(selector,d.value+(d.important?'!important':''));});});});
  assert.equal(displays.get('.pp-approval-stamp'),'none!important');assert.equal(displays.get('.pp-signature-screen'),'none!important');assert.equal(displays.get('.pp-signature-print'),'block!important');
  const {s,p}=fixture(),result=applyBudgetPlan(s,p),html=renderToStaticMarkup(createElement(RiskQualitySection,{s,p:result,printing:true}));
  assert.match(html,/<div class="pp-signature-print" aria-hidden="true"><\/div>/);assert.doesNotMatch(html,/Ruang TTD basah/);
});

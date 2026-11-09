import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type Project, blankProject, normalizeWorkflow, saveProject, getProject, boqTotal } from '../../resources/js/area3/domain';
import { AUTO_KHS_NOTE, applyAutomaticBoqPricing } from '../../resources/js/area3/boq-pricing';
import { ProjectEditor } from '../../resources/js/area3/projects';
import { BoqDraftEditor } from '../../resources/js/area3/workflow-ui';
import { applyBudgetPlan } from '../../resources/js/area3/plan-budget';

function fixture(){
  const s=normalizeWorkflow(seed('B'));s.schema=3;s.projects=[];
  const p:Project={...blankProject(),name:'QA Automatic BOQ',unit:'Konstruksi',projectCode:'AUTO-KHS-QA',branch:s.branches![0].name,date:'2026-11-09',workflowVersion:3};
  s.khs[0]={...s.khs[0],m:100,s:20};
  p.boq=[{id:'row-auto-1',khsId:s.khs[0].id,qty:2.005,m:999,s:999,excelM:700,excelS:0,reviewed:false,note:'',version:0}];
  p.boqUpload={id:'source-auto',name:'qa.xlsx',sha256:'a'.repeat(64),size:100,sheet:'BoQ',package:s.khs[0].package,sourceRows:1,sourceTotal:1403.5,uploadedBy:'qa',uploadedAt:'2026-11-09T00:00:00Z'};
  return {s,p};
}

test('Header is first, BOQ second and total is read-only Rp0 before upload; header-only Draft saves with no invented BOQ',()=>{
  const {s,p}=fixture();p.boq=[];delete p.boqUpload;p.schedule=[];p.budget=987654;
  const html=renderToStaticMarkup(createElement(ProjectEditor,{s,id:'new'}));
  assert.match(html,/aria-selected="true" class="active"><span>1<\/span>Header PP/);
  assert.match(html,/<span>2<\/span>BOQ Plan/);assert.match(html,/Informasi Project Plan/);
  assert.match(html,/aria-label="Total Nilai BOQ Plan otomatis" readOnly="" value="Rp 0"/);
  assert.doesNotMatch(html,/Upload BOQ Plan<\/h3>/);
  saveProject(s,p,false);const saved=getProject(s,p.id);assert.equal(saved.budget,0);assert.equal(saved.boq.length,0);assert.equal(saved.boqPricingPolicy,'khs-auto-v1');
  assert.equal(saved.status,'Draft');assert.equal(saved.boqStatus,'Belum diimpor');assert.ok(saved.no);
});

test('current KHS replaces stale price/review/version and manual total without changing Excel provenance or input',()=>{
  const {s,p}=fixture(),before=JSON.stringify(p);p.budget=1;
  const priced=applyAutomaticBoqPricing(s,p),r=priced.boq[0];
  assert.equal(r.m,100);assert.equal(r.s,20);assert.equal(r.excelM,700);assert.equal(r.excelS,0);assert.equal(r.version,s.khs[0].version);assert.equal(r.reviewed,true);assert.equal(r.note,AUTO_KHS_NOTE);
  assert.equal(priced.budget,240.6);assert.equal(priced.budget,boqTotal(priced));assert.equal(JSON.stringify({...p,budget:0}),before);
  saveProject(s,p,false);const saved=getProject(s,p.id);assert.equal(saved.budget,240.6);assert.equal(saved.boq[0].excelM,700);assert.equal(saved.boq[0].note,AUTO_KHS_NOTE);
});

test('quantity changes, deletions and replacement BOQ recalculate total; zero and null components stay distinct',()=>{
  const {s,p}=fixture();s.khs[0].m=0;s.khs[0].s=null;
  let next=applyAutomaticBoqPricing(s,p);assert.equal(next.budget,0);assert.equal(next.boq[0].m,0);assert.equal(next.boq[0].s,null);
  s.khs[0].m=100;
  next=applyAutomaticBoqPricing(s,{...next,boq:next.boq.map(r=>({...r,qty:3}))});assert.equal(next.budget,300);
  next=applyAutomaticBoqPricing(s,{...next,boq:[]});assert.equal(next.budget,0);
  next=applyAutomaticBoqPricing(s,{...next,boq:[{...p.boq[0],id:'replacement',qty:1}]});assert.equal(next.budget,100);
});

test('BOQ editor shows automatic pricing and preserves comparison columns without reason/confirmation controls',()=>{
  const {s,p}=fixture();
  const html=renderToStaticMarkup(createElement(BoqDraftEditor,{s,p,onChange:()=>{},disabled:false}));
  assert.match(html,/Harga BOQ otomatis mengikuti Master KHS di database/);assert.match(html,/Material Excel \/ KHS/);
  assert.doesNotMatch(html,/Isi alasan dan konfirmasikan|aria-label="Alasan selisih|type="checkbox"/);
  assert.match(html,/Total Nilai pada Header PP/);assert.match(html,/240,6/);
});

test('submitted/approved snapshots and continuation allocations do not get repriced by the form helper',()=>{
  const {s,p}=fixture();
  for(const status of ['Diajukan','Sah'] as const){const historical={...p,status,budget:123};s.projects=[historical];assert.equal(applyAutomaticBoqPricing(s,{...historical,status:'Draft'}).budget,123);assert.equal(applyAutomaticBoqPricing(s,historical),historical);}
  s.projects=[];const child={...p,parentId:'root-pp',rootId:'root-pp',budget:55,boq:[]};assert.equal(applyAutomaticBoqPricing(s,child),child);
});

test('legacy v2 still requires explicit review and does not receive the new automatic price policy',()=>{
  const s=normalizeWorkflow(seed('B'));s.schema=2;
  const p=structuredClone(s.projects[0]);p.id=p.rootId='legacy-v2-qa';p.unit='Konstruksi';p.status='Draft';p.workflowVersion=2;
  p.pmUserId=s.users.find(u=>u.role==='PM Konstruksi')!.id;p.managerUserId=s.users.find(u=>u.role==='Manager Konstruksi')!.id;
  p.boqUpload={id:'qa-source',name:'legacy.xlsx',sha256:'b'.repeat(64),size:100,sheet:'BoQ',package:'Paket 10',sourceRows:1,sourceTotal:1,uploadedBy:'qa',uploadedAt:'2026-11-09T00:00:00Z'};
  p.boq[0].excelM=(p.boq[0].m??0)+1;p.boq[0].reviewed=false;p.boq[0].note='';
  assert.equal(applyAutomaticBoqPricing(s,p),p);assert.throws(()=>saveProject(s,p,true),/Review selisih/);
});

test('500-row BOQ pricing and Owner/Mandor budget use indexed references without collection find per row',()=>{
  const {s,p}=fixture();delete p.boqUpload;
  s.khs=Array.from({length:500},(_,i)=>({...s.khs[0],id:'khs-'+i,m:100,s:null}));
  p.boq=s.khs.map((k,i)=>({...p.boq[0],id:'row-'+i,khsId:k.id,qty:1}));
  Object.defineProperty(s.khs,'find',{value:()=>{throw new Error('Repeated catalog find');},configurable:true});
  const priced=applyAutomaticBoqPricing(s,p),budget=applyBudgetPlan(s,priced,false,true);
  assert.equal(priced.budget,50000);assert.equal(budget.planBudget!.mandor.length,500);
  saveProject(s,p,false);assert.equal(getProject(s,p.id).budget,50000);assert.equal(getProject(s,p.id).boq.length,500);
});

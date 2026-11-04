import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement, Children, isValidElement, type ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type Project, type State, type Role, APPROVERS_V3, normalizeWorkflow, saveProject, getProject, decideProject, selectActor } from '../../resources/js/area3/domain';
import { applyPlanTemplate } from '../../resources/js/area3/plan-template';
import { applyBudgetPlan, budgetSummary, lineAmount, ratioPercent, selectUsageCategory, type BudgetCategory } from '../../resources/js/area3/plan-budget';
import { BudgetPlanSection, RiskQualitySection, signaturePerson } from '../../resources/js/area3/plan-budget-ui';
import { DocumentPreview } from '../../resources/js/area3/ui';

const vector=JSON.parse(readFileSync(new URL('../Fixtures/pp-budget-vector.json',import.meta.url),'utf8'));
function fixture() {
  const s=normalizeWorkflow(seed('B'));s.schema=3;
  s.khs=[{...s.khs[0],id:'khs-qa',code:'DESIGNATOR-QA',m:100,s:200}];
  for(const [i,role]of APPROVERS_V3.entries())if(!s.users.some(u=>u.role===role))s.users.push({id:`qa-role-${i}`,name:`QA ${role}`,nik:`000${i}`,email:`qa${i}@example.test`,role,active:true});
  s.actorUserId=s.users.find(u=>u.role==='Admin Konstruksi')?.id;
  const p=applyPlanTemplate({...structuredClone(s.projects[0]),...structuredClone(vector.project),id:'pp-budget-qa',rootId:'pp-budget-qa',unit:'Konstruksi',workflowVersion:3,status:'Draft',boq:[{...s.projects[0].boq[0],id:'boq-qa',khsId:'khs-qa',qty:10,m:100,s:200}],approverUserIds:Object.fromEntries(APPROVERS_V3.map(role=>[role,s.users.find(u=>u.role===role)!.id]))} as Project);
  return {s,p};
}

test('usage category selection fills current Non Stock, BOP and permit totals without changing source data or other row fields',()=>{
  const {s,p}=fixture();p.planBudget!.usage=[{id:'usage-select',category:'mandor',term:'2',date:p.date,amount:123}];
  const before=structuredClone(p);
  for(const [category,expected]of [['material',25.03],['bop',30.02],['permit',7.03],['mandor',0]]as const){
    const rows=selectUsageCategory(s,p,'usage-select',category);
    assert.deepEqual(rows,[{...before.planBudget!.usage[0],category,amount:expected}]);
    assert.deepEqual(p,before);
  }
  p.planBudget!.permit=0;assert.equal(selectUsageCategory(s,p,'usage-select','permit')[0].amount,0);
});

test('usage category prefills only remaining cents and the actual dropdown handler persists its selected amount in a draft',()=>{
  const {s,p}=fixture();p.planBudget!.usage.find(r=>r.category==='material')!.amount=10.01;
  const target=p.planBudget!.usage[0],rows=selectUsageCategory(s,p,target.id,'material');
  assert.equal(rows[0].amount,15.02);assert.equal(rows[2].amount,10.01);
  assert.equal(selectUsageCategory(s,{...p,planBudget:{...p.planBudget!,usage:rows}},target.id,'material')[0].amount,15.02);
  p.planBudget!.usage.find(r=>r.category==='material')!.amount=25.03;
  assert.equal(selectUsageCategory(s,p,target.id,'material')[0].amount,0);

  p.planBudget!.usage=[{...target,amount:0}];let next:Project|undefined;
  type SelectProps={'aria-label'?:string;children?:ReactNode;onChange?:(event:{target:{value:BudgetCategory}})=>void};
  function findSelect(node:ReactNode):SelectProps|undefined {
    for(const child of Children.toArray(node))if(isValidElement<SelectProps>(child)){
      if(child.type==='select'&&child.props['aria-label']==='Kebutuhan Anggaran 1')return child.props;
      const found=findSelect(child.props.children);if(found)return found;
    }
  }
  const select=findSelect(BudgetPlanSection({s,p,editable:true,onChange:updated=>{next=updated;}}));
  assert.ok(select?.onChange);select.onChange({target:{value:'material'}});assert.ok(next);
  assert.equal(next.planBudget!.usage[0].amount,25.03);assert.equal(next.planBudget!.usage[0].category,'material');
  saveProject(s,next,false);assert.equal(getProject(s,p.id).planBudget!.usage[0].amount,25.03);
});

test('shared budget vector computes rounded component costs, service-only revenue and margin without counting reimbursement twice',()=>{
  const {s,p}=fixture(),result=applyBudgetPlan(s,p,true,true);
  for(const[key,expected]of Object.entries(vector.expected))assert.equal(result.planBudgetTotals![key as keyof typeof result.planBudgetTotals],expected,key);
  assert.deepEqual(result.costs,{mandor:140,material:25.03,bop:30.02,permit:7.03});
  assert.equal(result.planBudgetTotals?.usage.mandor,140);assert.equal(result.planBudgetTotals?.cogs,195.05);
  const zero=structuredClone(p);zero.boq[0].s=null;zero.planBudget=undefined;zero.costs.permit=0;
  assert.equal(budgetSummary(s,zero).margin,null);
  assert.equal(lineAmount(.001,995),1);
  assert.equal(ratioPercent(-246.9,2000),-12.35);
  const negative=structuredClone(p);negative.planBudget!.mandor.forEach(r=>{r.qtyM=r.qtyS=0;});negative.planBudget!.materials=[];negative.planBudget!.permit=0;negative.planBudget!.bop=[{id:'bop-negative-margin',description:'QA',unit:'Ls',qty:1,total:2246.9}];negative.planBudget!.bopLimit=3000;negative.planBudget!.usage=[{id:'usage-negative-margin',category:'bop',term:'1',date:p.date,amount:2246.9}];
  assert.equal(applyBudgetPlan(s,negative,true,true).planBudgetTotals?.margin,-12.35);
});

test('plan saves reject exceeded component ceilings, unmatched disbursement, duplicate IDs and missing risk without replacing saved state',()=>{
  const {s,p}=fixture();saveProject(s,p,false);
  const baseline=JSON.stringify(s),variants:Project[]=[];
  for(const[key,value]of [['priceM',101],['qtyS',11],['priceS',-.01],['priceM',.001],['qtyS',.0001]]as const){const bad=structuredClone(p);bad.planBudget!.mandor[0][key]=value;variants.push(bad);}
  let bad=structuredClone(p);bad.planBudget!.bopLimit=1;variants.push(bad);
  bad=structuredClone(p);bad.planBudget!.usage[0].amount=1;variants.push(bad);
  bad=structuredClone(p);bad.planBudget!.usage[0].date='2026-02-30';variants.push(bad);
  bad=structuredClone(p);bad.planBudget!.materials.push(bad.planBudget!.materials[0]);variants.push(bad);
  bad=structuredClone(p);bad.riskLevel='';variants.push(bad);
  bad=structuredClone(p);bad.planBudget!.mandor[0].rowId='unknown';variants.push(bad);
  for(const input of variants){assert.throws(()=>saveProject(s,input,true));assert.equal(JSON.stringify(s),baseline);}
});

test('deleting or editing print rows does not bypass five PP approvals and no unsigned person is shown as approved',()=>{
  const {s,p}=fixture();p.signatureRows=[];saveProject(s,p,true);let saved=getProject(s,p.id);assert.equal(saved.approvals?.length,0);assert.equal(saved.signatureRows?.length,0);assert.equal(saved.status,'Diajukan');
  for(const role of APPROVERS_V3){s.role=role;selectActor(s,s.users.find(u=>u.role===role)!.id);decideProject(s,saved.id,'Setujui','QA');}
  saved=getProject(s,p.id);assert.equal(saved.approvals?.length,5);assert.equal(saved.status,'Sah');assert.throws(()=>saveProject(s,{...saved,planBudget:undefined},false),/Hanya Admin|terkunci/);
  const {s:before,p:unsigned}=fixture(),row={id:'signature-qa',label:'Disetujui Oleh' as const,role:'Head of PMO' as Role,job:'QA'};
  assert.equal(signaturePerson(before,unsigned,row).status,'Belum disetujui');
  assert.equal(signaturePerson(s,saved,row).status,'Disetujui di aplikasi');
});

test('budget form exposes template columns and working row actions; risk text is fixed and curve is removed',()=>{
  const {s,p}=fixture(),result=applyBudgetPlan(s,p);
  const budget=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:result,editable:true,onChange:()=>{}}));
  for(const label of ['BoQ TA - Telkom / Owner Project','BoQ TA - Mandor','Jml_Mat','Jml_Jasa','Tambah Material','Tambah BOP','Tambah Waktu Penggunaan','Referensi Cost Structure','195,05','90,25'])assert.ok(budget.includes(label),label);
  const risk=renderToStaticMarkup(createElement(RiskQualitySection,{s,p:result,editable:true,onChange:()=>{}}));
  assert.equal((risk.match(/type="checkbox"/g)??[]).length,3);assert.equal((risk.match(/type="checkbox"[^>]*checked/g)??[]).length,1);assert.doesNotMatch(risk,/<textarea/);
  for(const text of ['KOMUNIKASI DAN DOKUMENTASI','MANAJEMEN KUALITAS','PENUTUP','Lensa Pro/Group Chat','Tambah Penandatangan','Hapus Penandatangan'])assert.ok(risk.includes(text),text);
  const preview=renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'Project Plan',project:result,onClose:()=>{}}));
  assert.doesNotMatch(preview,/Kurva S|pp-kurva-s-reference|ANDRA UTAMA|AMRAN QURAISY|HENDRA CAHYA|APPROVED/);
  assert.match(preview,/Ruang TTD basah/);assert.match(preview,/IV\. KEBUTUHAN ANGGARAN/);assert.match(preview,/VIII\. PENUTUP/);
});

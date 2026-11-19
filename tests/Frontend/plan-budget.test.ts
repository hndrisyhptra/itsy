import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type Project, type State, type Role, APPROVERS_V3, normalizeWorkflow, saveProject, getProject, decideProject, selectActor, money } from '../../resources/js/area3/domain';
import { applyPlanTemplate } from '../../resources/js/area3/plan-template';
import { EDITABLE_NONSTOCK_POLICY, OWNER_MATERIAL_POLICY, MATERIAL_REVENUE_POLICY, applyBudgetPlan, budgetSummary, lineAmount, ratioPercent, draftPlanBudget, projectPlanHeaderTotal } from '../../resources/js/area3/plan-budget';
import { BudgetPlanSection, RiskQualitySection, signaturePerson } from '../../resources/js/area3/plan-budget-ui';
import { DocumentPreview } from '../../resources/js/area3/ui';
import { ProjectEditor } from '../../resources/js/area3/projects';

const vector=JSON.parse(readFileSync(new URL('../Fixtures/pp-budget-vector.json',import.meta.url),'utf8'));
function fixture() {
  const s=normalizeWorkflow(seed('B'));s.schema=3;
  s.khs=[{...s.khs[0],id:'khs-qa',code:'DESIGNATOR-QA',m:100,s:200}];
  for(const [i,role]of APPROVERS_V3.entries())if(!s.users.some(u=>u.role===role))s.users.push({id:`qa-role-${i}`,name:`QA ${role}`,nik:`000${i}`,email:`qa${i}@example.test`,role,active:true});
  s.actorUserId=s.users.find(u=>u.role==='Admin Konstruksi')?.id;
  const p=applyPlanTemplate({...structuredClone(s.projects[0]),...structuredClone(vector.project),id:'pp-budget-qa',rootId:'pp-budget-qa',unit:'Konstruksi',workflowVersion:3,status:'Draft',boq:[{...s.projects[0].boq[0],id:'boq-qa',khsId:'khs-qa',qty:10,m:100,s:200}],approverUserIds:Object.fromEntries(APPROVERS_V3.map(role=>[role,s.users.find(u=>u.role===role)!.id]))} as Project);
  p.issueDate='2026-11-01';p.workStartDate=p.date;
  return {s,p};
}

test('budget recap derives positive source amounts without installments, dates or trusting stale usage rows',()=>{
  const {s,p}=fixture(),before=structuredClone(p);
  p.planBudget!.usage=[{id:'usage-stale',category:'mandor',amount:999999,term:'',date:'2026-02-30'}];
  const result=applyBudgetPlan(s,p,true,true);
  assert.equal(result.planBudget!.version,2);
  assert.deepEqual(result.planBudget!.usage,vector.editableMaterialUsage);
  assert.deepEqual(p.planBudget!.mandor,before.planBudget!.mandor);
  assert.equal(p.planBudget!.usage[0].date,'2026-02-30');
  saveProject(s,p,true);const saved=getProject(s,p.id);
  assert.equal(saved.status,'Diajukan');assert.deepEqual(saved.planBudget!.usage,vector.editableMaterialUsage.map((r:{amount:number})=>({...r,amount:Math.round(r.amount)})));
  assert.equal(saved.approvals!.length,0);
  assert.equal(projectPlanHeaderTotal(saved),202);assert.equal(saved.budget,3000);
});

test('source edits refresh the automatic recap and remove categories when their total becomes zero',()=>{
  const {s,p}=fixture();const initial=applyBudgetPlan(s,p);
  const changed=structuredClone(initial);changed.planBudget!.mandor[0].priceS=30;changed.planBudget!.materials=[];changed.planBudget!.bop=[];changed.planBudget!.permit=0;
  const next=applyBudgetPlan(s,changed,true,true);
  assert.deepEqual(next.planBudget!.usage,[{id:'usage-mandor-s',category:'mandor',component:'s',amount:150},{id:'usage-material',category:'material',amount:40}]);
  assert.equal(next.planBudgetTotals!.total,190);assert.equal(next.planBudgetTotals!.usage.mandor,150);
  assert.equal(next.planBudgetTotals!.usage.permit,0);assert.deepEqual(initial.planBudget!.usage,vector.editableMaterialUsage);
  const html=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:next,editable:true}));
  const recap=html.match(/<div class="table-scroll budget-recap-table">([\s\S]*?)<\/table><\/div>/)![1];
  assert.ok(recap.includes('Jasa Mandor'));assert.ok(recap.includes('Material Non Stock'));
  assert.doesNotMatch(recap,/>BOP<|Preliminary|Material BOQ Mandor/);
  assert.match(recap,new RegExp(`Total Anggaran</td><td class="numeric">${money(190)}</td>`));
  assert.equal(projectPlanHeaderTotal(next),190);
  s.projects=[next];
  const editor=renderToStaticMarkup(createElement(ProjectEditor,{s,id:next.id}));
  assert.ok(editor.includes(`aria-label="Total Nilai Anggaran PP otomatis" readOnly="" value="${money(190)}"`));
  assert.doesNotMatch(editor,/Total Nilai otomatis terisi setelah BOQ|Otomatis dari BOQ Plan langkah/);
  const empty=structuredClone(next);empty.planBudget!.mandor.forEach(row=>{row.priceM=row.priceS=0;});
  assert.equal(projectPlanHeaderTotal(applyBudgetPlan(s,empty)),0);
});

test('submitted historical budget version 1 keeps its original installment rows and totals unchanged',()=>{
  const {s,p}=fixture();p.status='Sah';p.planBudgetTotals=budgetSummary(s,p,p.planBudget);
  const before=JSON.stringify(p),budget=draftPlanBudget(s,p);
  assert.equal(budget.version,1);assert.deepEqual(budget.usage,p.planBudget!.usage);
  const html=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p,printing:true}));
  assert.ok(html.includes('Termin Bayar / Reimburse'));assert.ok(html.includes('historis'));assert.equal(JSON.stringify(p),before);
  // Two Mandor installments each display the category total; count their amounts only once.
  assert.ok(html.includes(`<td colSpan="5">Total Anggaran</td><td class="numeric">${money(202.08)}</td></tr></tbody></table>`));
  assert.equal(projectPlanHeaderTotal(p),202.08);assert.equal(JSON.stringify(p),before);
  p.status='Ditolak';assert.equal(draftPlanBudget(s,p).version,2);
});

test('continuation recap uses its component allocation and never adds the root budget again',()=>{
  const {s,p}=fixture();s.projects.push(structuredClone(p));
  const child:Project={...structuredClone(p),id:'pp-budget-child',parentId:p.id,rootId:p.id,allocations:{'boq-qa':{m:1.5,s:2}}};
  child.planBudget!.mandor[0].qtyM=1.5;child.planBudget!.mandor[0].qtyS=2;
  child.planBudget!.materials=[];child.planBudget!.bop=[];child.planBudget!.permit=0;
  const before=JSON.stringify(p),result=applyBudgetPlan(s,child,true,true);
  assert.equal(result.planBudgetTotals!.ownerTotal,550);assert.equal(result.planBudgetTotals!.total,55);
  assert.deepEqual(result.planBudget!.usage,[{id:'usage-mandor-s',category:'mandor',component:'s',amount:40},{id:'usage-material',category:'material',amount:15}]);
  assert.equal(JSON.stringify(p),before);
});

test('shared budget vector computes rounded component costs, selected Owner material plus Owner service revenue and margin without counting reimbursement twice',()=>{
  const {s,p}=fixture(),result=applyBudgetPlan(s,p,true,true);
  for(const[key,expected]of Object.entries(vector.expectedEditableNonStock))assert.equal(result.planBudgetTotals![key as keyof typeof result.planBudgetTotals],expected,key);
  assert.deepEqual(result.costs,{mandor:100,material:65.03,bop:30.02,permit:7.03});
  assert.equal(result.planBudgetTotals?.usage.mandor,100);assert.equal(result.planBudgetTotals?.cogs,195.05);
  const zero=structuredClone(p);zero.boq[0].s=null;zero.planBudget=undefined;zero.costs.permit=0;
  assert.equal(budgetSummary(s,zero).margin,null);
  assert.equal(lineAmount(.001,995),1);
  assert.equal(ratioPercent(-246.9,2000),-12.35);
  const negative=structuredClone(p);negative.planBudget!.mandor.forEach(r=>{r.qtyM=r.qtyS=0;});negative.planBudget!.materials=[];negative.planBudget!.permit=0;negative.planBudget!.bop=[{id:'bop-negative-margin',description:'QA',unit:'Ls',qty:1,total:2246.9}];negative.planBudget!.bopLimit=3000;negative.planBudget!.usage=[{id:'usage-negative-margin',category:'bop',term:'1',date:p.date,amount:2246.9}];
  assert.equal(applyBudgetPlan(s,negative,true,true).planBudgetTotals?.margin,-12.35);
});

test('Selected Owner material feeds row 1.1 and Revenue in forms/preview, with live source edits and frozen historical totals',()=>{
  const {s,p}=fixture(),current=applyBudgetPlan(s,p),before=JSON.stringify(current);
  assert.equal(current.planBudget!.marginPolicy,EDITABLE_NONSTOCK_POLICY);
  const unsaved=structuredClone(p);delete unsaved.workflowVersion;
  assert.equal(applyBudgetPlan(s,unsaved).planBudgetTotals!.revenue,2400,'New PP has no workflowVersion until its first saved command.');
  assert.equal(applyBudgetPlan({...s,schema:2},unsaved).planBudgetTotals!.revenue,2000,'Historical v2 form does not adopt the v3 rule.');
  const materialRow=`<td>1.1</td><td>Material</td><td class="numeric">${money(400)}</td>`;
  for(const printing of [false,true]){
    const html=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:current,printing}));
    assert.ok(html.includes(materialRow));assert.ok(html.includes(`<td>Revenue</td><td class="numeric">${money(2400)}</td>`));
    assert.ok(html.includes(`<td>Gross Profit Margin</td><td class="numeric">${money(2204.95)}</td>`));
  }
  assert.equal(JSON.stringify(current),before);
  const edit=structuredClone(current);edit.planBudget!.mandor[0].qtyM=2;edit.planBudget!.mandor[0].priceM=12;
  const total=budgetSummary(s,edit);assert.equal(total.mandorM,24);assert.equal(total.revenue,2200);
  assert.equal(total.cogs,179.05);assert.equal(total.grossProfit,2020.95);
  const old=structuredClone(p);old.status='Sah';old.planBudgetTotals={...vector.expected,usage:{mandor:140,material:25.03,bop:30.02,permit:7.03}};
  const oldBefore=JSON.stringify(old),html=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:old,printing:true}));
  assert.ok(html.includes(`<td>Revenue</td><td class="numeric">${money(2000)}</td>`));
  assert.match(html,/<td>1\.1<\/td><td>Material<\/td><td class="numeric"><\/td>/);
  assert.equal(JSON.stringify(old),oldBefore);
  const child={...structuredClone(current),id:'child-margin',parentId:p.id,rootId:p.id,boq:[],allocations:{'boq-qa':{m:1,s:2}}};
  child.planBudget!.mandor[0].qtyM=1;child.planBudget!.mandor[0].qtyS=2;s.projects=[p];
  assert.equal(budgetSummary(s,child).revenue,500);
});

test('Non Stock shows designators and actions, ignores forged source rows and preserves locked saved descriptions',()=>{
  const {s,p}=fixture();
  const next=applyBudgetPlan(s,p,true,true);
  assert.deepEqual(next.planBudget!.materials,[{id:'material-mandor-1',sourceRowId:'boq-qa',code:'DESIGNATOR-QA',name:s.khs[0].name,unit:s.khs[0].unit,qty:4,price:10},...p.planBudget!.materials]);
  const form=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:next,editable:true}));
  assert.match(form,/Tambah Material|Hapus Material/);
  assert.match(form,/kode designator/);assert.match(form,/<td colSpan="5">Total Nilai<\/td>/);
  const edit=structuredClone(next);edit.planBudget!.materials[0]={...edit.planBudget!.materials[0],code:'Forged',name:'Forged',qty:999,price:999};
  assert.equal(applyBudgetPlan(s,edit,true,true).planBudgetTotals!.material,65.03);
  for(const key of ['qtyM','priceM'] as const){
    const empty=structuredClone(next);empty.planBudget!.mandor[0][key]=0;
    const result=applyBudgetPlan(s,empty,true,true);
    assert.deepEqual(result.planBudget!.materials,p.planBudget!.materials);assert.equal(result.planBudgetTotals!.revenue,2000);assert.equal(result.planBudgetTotals!.material,25.03);
  }
  const locked=structuredClone(next);locked.status='Sah';const before=JSON.stringify(locked);
  s.khs[0].name='Changed master description';
  assert.deepEqual(draftPlanBudget(s,locked).materials,locked.planBudget!.materials);
  assert.equal(JSON.stringify(locked),before);
  const old=structuredClone(p);old.status='Sah';old.planBudget!.marginPolicy=MATERIAL_REVENUE_POLICY;
  old.planBudgetTotals={...vector.expectedMaterialRevenue,usage:{mandor:140,material:25.03,bop:30.02,permit:7.03}};
  const html=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:old,printing:true}));
  assert.ok(html.includes(`<td>1.1</td><td>Material</td><td class="numeric">${money(40)}</td>`));
  assert.ok(html.includes(`<td>Revenue</td><td class="numeric">${money(2040)}</td>`));
});

test('editable Non Stock additions/deletions update recap, margin and Total Nilai without changing Owner Revenue',()=>{
  const {s,p}=fixture(),base=applyBudgetPlan(s,p);
  const added=structuredClone(base);added.planBudget!.materials.push({id:'manual-extra',name:'M-EXTRA',unit:'Pcs',qty:2,price:15});
  const saved=applyBudgetPlan(s,added,true,true);
  assert.equal(saved.planBudgetTotals!.material,95.03);assert.equal(saved.planBudgetTotals!.cogs,225.05);assert.equal(saved.planBudgetTotals!.revenue,2400);
  const deleted=structuredClone(saved);deleted.planBudget!.excludedMaterialRowIds=['boq-qa'];deleted.planBudget!.materials=deleted.planBudget!.materials.filter(row=>row.id!=='material-qa');
  const result=applyBudgetPlan(s,deleted,true,true);
  assert.deepEqual(result.planBudget!.materials,[{id:'manual-extra',name:'M-EXTRA',unit:'Pcs',qty:2,price:15}]);
  assert.equal(result.planBudgetTotals!.material,30);assert.equal(result.planBudgetTotals!.cogs,160.02);assert.equal(result.planBudgetTotals!.revenue,2400);
  assert.deepEqual(result.planBudget!.mandor,base.planBudget!.mandor);
  assert.equal(projectPlanHeaderTotal(result),167.05);
  for(const printing of [false,true]){
    const html=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:result,printing,editable:!printing}));
    assert.ok(html.includes(`<td colSpan="5">Total Nilai</td><td class="numeric">${money(30)}</td>`));
    assert.equal(html.includes('Tambah Material'),!printing);
  }
  const locked=structuredClone(base);locked.status='Sah';locked.planBudget!.marginPolicy=OWNER_MATERIAL_POLICY;delete locked.planBudget!.excludedMaterialRowIds;
  locked.planBudget!.materials=locked.planBudget!.materials.filter(row=>row.sourceRowId).map(({code,...row})=>row);
  locked.planBudgetTotals={...vector.expectedSelectedOwnerRevenue,usage:{mandor:100,material:40,bop:30.02,permit:7.03}};
  const before=JSON.stringify(locked),old=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:locked,printing:true}));
  assert.ok(old.includes('DESIGNATOR-QA'));assert.ok(old.includes(`<td colSpan="5">Total Nilai</td><td class="numeric">${money(40)}</td>`));
  assert.equal(JSON.stringify(locked),before);
});

test('plan saves reject exceeded component ceilings, invalid source amounts, duplicate IDs and missing risk without replacing saved state',()=>{
  const {s,p}=fixture();saveProject(s,p,false);
  const baseline=JSON.stringify(s),variants:Project[]=[];
  for(const[key,value]of [['priceM',101],['qtyS',11],['priceS',-.01],['priceM',.001],['qtyS',.0001]]as const){const bad=structuredClone(p);bad.planBudget!.mandor[0][key]=value;variants.push(bad);}
  let bad=structuredClone(p);bad.planBudget!.bop[0].total=-1;variants.push(bad);
  bad=structuredClone(p);bad.planBudget!.materials[0].price=-1;variants.push(bad);
  bad=structuredClone(p);bad.planBudget!.materials.push(bad.planBudget!.materials[0]);variants.push(bad);
  bad=structuredClone(p);bad.planBudget!.excludedMaterialRowIds=['other-pp'];variants.push(bad);
  bad=structuredClone(p);bad.planBudget!.excludedMaterialRowIds=['boq-qa','boq-qa'];variants.push(bad);
  bad=structuredClone(p);bad.planBudget!.bop[0].qty=.0001;variants.push(bad);

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
  const before=JSON.stringify(result);
  for(const label of ['BoQ TA - Telkom / Owner Project','BoQ TA - Mandor','Jml_Mat','Jml_Jasa','Tambah BOP','Uraian Pekerjaan','Satuan','195','91,87'])assert.ok(budget.includes(label),label);
  assert.doesNotMatch(budget,/Termin Bayar|Tanggal Penggunaan|Tambah Waktu Penggunaan|Nilai Penggunaan/);
  assert.equal((budget.match(/>Uraian Pekerjaan<\/th>/g)??[]).length,2);
  assert.equal((budget.match(/class="budget-description"/g)??[]).length,2);
  const risk=renderToStaticMarkup(createElement(RiskQualitySection,{s,p:result,editable:true,onChange:()=>{}}));
  assert.equal((risk.match(/type="checkbox"/g)??[]).length,3);assert.equal((risk.match(/type="checkbox"[^>]*checked/g)??[]).length,1);assert.doesNotMatch(risk,/<textarea/);
  for(const text of ['KOMUNIKASI DAN DOKUMENTASI','MANAJEMEN KUALITAS','PENUTUP','Lensa Pro/Group Chat','Officer 1 Project Deployment Area Jawa Bali'])assert.ok(risk.includes(text),text);
  assert.doesNotMatch(risk,/Tambah Penandatangan|Hapus Penandatangan/);
  const preview=renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'Project Plan',project:result,onClose:()=>{}}));
  assert.doesNotMatch(preview,/Kurva S|pp-kurva-s-reference|ANDRA UTAMA|AMRAN QURAISY|HENDRA CAHYA|Persetujuan aplikasi/);
  assert.doesNotMatch(preview,/Dibuat, Diperiksa dan Disetujui|Baris EVP: persetujuan otomatis|Status persetujuan aplikasi tidak menggantikan tanda tangan fisik|Dokumen prototipe\. Tanda tangan fisik tidak dibuat otomatis/);
  assert.match(preview,/pp-signature-print/);assert.match(preview,/IV\. KEBUTUHAN ANGGARAN/);assert.match(preview,/VIII\. PENUTUP/);assert.match(preview,/budget-recap-table/);assert.doesNotMatch(preview,/Termin Bayar \/ Reimburse/);
  for(const html of [budget,preview]) {
    const recap=html.match(/<div class="table-scroll budget-recap-table">([\s\S]*?)<\/table><\/div>/)![1];
    assert.ok(recap.includes(`Subtotal (IV.1 + IV.2 + IV.3)</td><td class="numeric">${money(195.05)}</td>`));
    assert.ok(recap.endsWith(`Total Anggaran</td><td class="numeric">${money(202.08)}</td><td>Jumlah seluruh rincian, termasuk perizinan bila ada</td></tr></tbody>`));
  }
  assert.equal(result.planBudgetTotals!.cogs,195.05);assert.equal(result.planBudgetTotals!.margin,91.87);assert.equal(JSON.stringify(result),before);
  assert.ok(preview.includes(`<th scope="row">Total Nilai (Rp)</th><td>:</td><td>${money(202.08).replace(/^Rp\s*/,'')}</td>`));
});

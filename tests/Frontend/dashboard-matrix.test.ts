import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type State, type Term, termTotal, termTotalForDeal } from '../../resources/js/area3/domain';
import { dashboardMatrix } from '../../resources/js/area3/dashboard-matrix';
import { DashboardMatrix } from '../../resources/js/area3/dashboard-matrix-table';

function state(): State {
  const s=seed('D'),p=s.projects[0];
  p.branch='Surabaya';p.projectCode='PROJECT-1';
  p.boq=[{...p.boq[0],qty:10,m:100,s:null}];
  s.deals[0].rows=[{...s.deals[0].rows[0],rowId:p.boq[0].id,m:true,s:false,qm:10,qs:0,pm:80,ps:0}];
  s.branches=[
    {id:'sb',name:'Surabaya',code:'R501',active:true,regionId:'east',regionName:'Jawa Timur'},
    {id:'ml',name:'Malang',code:'R531',active:true,regionId:'east',regionName:'Jawa Timur'},
    {id:'sm',name:'Semarang',code:'R428',active:true,regionId:'central',regionName:'Jawa Tengah'},
  ];
  return s;
}
function invoice(s:State,id='final',number=1,qty=10):Term {
  return {id,spkId:s.spks[0].id,number,type:'Final',status:'Disetujui',actuals:{[s.deals[0].rows[0].rowId]:{m:qty,s:0}},approvals:[],history:[],paid:qty*80,paymentHistory:[],apm:'APM/QA',apmStatus:'Valid'};
}
function addPlan(s:State,id:string,code:string,branch='Surabaya') {
  const p={...structuredClone(s.projects[0]),id,rootId:id,projectCode:code,branch};s.projects.push(p);return p;
}

test('matrix counts ten unique Project IDs, all PP and only a fully paid final project as CLOSE',()=>{
  const s=state();s.terms=[invoice(s)];
  for(let i=2;i<=10;i++)addPlan(s,`p${i}`,`PROJECT-${i}`);
  addPlan(s,'extra','PROJECT-2');
  const before=JSON.stringify(s),v=dashboardMatrix(s).totals;
  assert.deepEqual(v,{projects:10,open:9,closed:1,plans:11,requested:800,paid:800,total:11000,missingCodes:0});
  assert.equal(JSON.stringify(s),before);
});

test('CLOSE requires every PP, every agreement and every SPK to finish final payment',()=>{
  const s=state();s.terms=[invoice(s)];assert.equal(dashboardMatrix(s).totals.closed,1);
  addPlan(s,'second',' project-1 ');assert.equal(dashboardMatrix(s).totals.projects,1);assert.equal(dashboardMatrix(s).totals.open,1);
  s.projects.pop();
  s.deals.push({...structuredClone(s.deals[0]),id:'deal2'});assert.equal(dashboardMatrix(s).totals.open,1);
  s.spks.push({...structuredClone(s.spks[0]),id:'spk2',dealId:'deal2'});
  s.terms.push({...invoice(s,'final2'),spkId:'spk2'});assert.equal(dashboardMatrix(s).totals.closed,1);
  s.spks.push({...structuredClone(s.spks[0]),id:'spk3'});assert.equal(dashboardMatrix(s).totals.open,1);
  s.spks.pop();s.deals[1].status='Terbit';assert.equal(dashboardMatrix(s).totals.open,1);
  s.deals.pop();s.spks.pop();
  for(const status of ['Draft','Diajukan','Dikembalikan','Ditolak'] as const){s.projects[0].status=status;assert.equal(dashboardMatrix(s).totals.open,1);}
});

test('approved final alone, nonfinal terms, partial historical payments and missing APM remain OPEN',()=>{
  const s=state(),t=invoice(s);s.terms=[t];
  t.paid=0;assert.equal(dashboardMatrix(s).totals.open,1);
  t.workflowVersion=1;t.paid=75.25;assert.equal(dashboardMatrix(s).totals.paid,75.25);assert.equal(dashboardMatrix(s).totals.open,1);
  t.paid=800;t.apmStatus='Belum Terbit';assert.equal(dashboardMatrix(s).totals.open,1);
  t.apmStatus='Valid';t.type='Parsial';assert.equal(dashboardMatrix(s).totals.open,1);
  t.type='Final';t.status='Diajukan';assert.equal(dashboardMatrix(s).totals.open,1);
  t.status='Disetujui';assert.equal(dashboardMatrix(s).totals.closed,1);
});

test('current-term actual payments sum without rebilling previous terms, and ignore unapproved/orphan amounts',()=>{
  const s=state(),partial=invoice(s,'t1',1,4),final=invoice(s,'t2',2,6);partial.type='Parsial';
  s.terms=[partial,final];assert.equal(dashboardMatrix(s).totals.paid,800);assert.equal(dashboardMatrix(s).totals.closed,1);
  final.paid=0;assert.equal(dashboardMatrix(s).totals.paid,320);assert.equal(dashboardMatrix(s).totals.open,1);
  s.terms.push({...invoice(s,'pending',3),status:'Diajukan'}, {...invoice(s,'rejected',3),status:'Ditolak'}, {...invoice(s,'draft',3),status:'Draft'}, {...invoice(s,'orphan'),spkId:'missing'});
  assert.equal(dashboardMatrix(s).totals.paid,320);
  s.spks[0].status='Draft';assert.equal(dashboardMatrix(s).totals.paid,0);
});

test('BOQ includes saved draft values, deduplicates continuation allocation and never invents missing Project IDs',()=>{
  const s=state();s.projects[0].status='Draft';s.projects[0].boqStatus='Review';
  const child=addPlan(s,'child','PROJECT-1','Malang');child.parentId=s.projects[0].id;child.rootId=s.projects[0].id;
  const missing=addPlan(s,'missing',' ');missing.boq=[];
  const data=dashboardMatrix(s);
  assert.equal(data.totals.plans,3);assert.equal(data.totals.projects,1);assert.equal(data.totals.total,1000);assert.equal(data.totals.missingCodes,1);
  assert.equal(dashboardMatrix(s,'Malang').totals.total,0);
});

test('Region/global unique counts handle shared IDs, while Branch filter keeps visible sibling status OPEN',()=>{
  const s=state();s.terms=[invoice(s)];
  addPlan(s,'other','project-1','Malang');addPlan(s,'cross','PROJECT-1','Semarang');
  const all=dashboardMatrix(s),east=all.regions.find(r=>r.id==='east')!;
  assert.equal(east.totals.projects,1);assert.equal(east.branches.reduce((n,b)=>n+b.totals.projects,0),2);
  assert.equal(all.regions.reduce((n,r)=>n+r.totals.projects,0),2);assert.equal(all.totals.projects,1);assert.equal(all.totals.plans,3);
  const selected=dashboardMatrix(s,' surabaya ');assert.equal(selected.totals.plans,1);assert.equal(selected.totals.open,1);assert.equal(selected.totals.paid,800);
  // Server supplies only authorized PP: the model cannot infer hidden siblings.
  s.projects=s.projects.slice(0,1);assert.equal(dashboardMatrix(s,'Surabaya').totals.closed,1);
});

test('empty master Branches stay zero, missing Region is explicit and neither mapping nor IDs are guessed',()=>{
  const s=state();s.branches![0].regionId=null;s.branches![0].regionName='';
  const v=dashboardMatrix(s);assert.equal(v.regions.at(-1)!.name,'Region belum ditetapkan');
  assert.equal(dashboardMatrix(s,'Semarang').totals.projects,0);
  assert.equal(dashboardMatrix(s,'Unauthorized').regions.length,0);
  delete s.branches;assert.equal(dashboardMatrix(s).regions[0].id,'unassigned');
  s.projects=[];assert.equal(dashboardMatrix(s).totals.plans,0);
});

test('indexed term total preserves combined-line cent rounding and independent Material/Jasa actuals',()=>{
  const s=state(),d=s.deals[0],t=invoice(s);d.rows[0]={...d.rows[0],m:true,s:true,pm:1,ps:1};
  t.actuals[d.rows[0].rowId]={m:.005,s:.005};t.paid=.01;s.terms=[t];
  assert.equal(termTotalForDeal(d,t),.01);assert.equal(termTotal(s,t),.01);assert.equal(dashboardMatrix(s).totals.closed,1);
  t.actuals[d.rows[0].rowId]={m:.005,s:1.005};assert.equal(termTotalForDeal(d,t),1.01);assert.equal(dashboardMatrix(s).totals.closed,0);
});

test('1000 PP use indexed relations without repeated collection find and preserve unique/financial totals',()=>{
  const s=state();s.terms=[invoice(s)];
  for(let i=1;i<1000;i++){
    const p=addPlan(s,`bulk-pp-${i}`,`BULK-${i}`),d={...structuredClone(s.deals[0]),id:`d-${i}`,projectId:p.id},v={...structuredClone(s.spks[0]),id:`v-${i}`,dealId:d.id};
    s.deals.push(d);s.spks.push(v);s.terms.push({...invoice(s,`t-${i}`),spkId:v.id});
  }
  for(const rows of [s.projects,s.deals,s.spks,s.terms,s.khs])Object.defineProperty(rows,'find',{value:()=>{throw new Error('Repeated collection find');},configurable:true});
  const totals=dashboardMatrix(s).totals;assert.equal(totals.projects,1000);assert.equal(totals.closed,1000);assert.equal(totals.paid,800000);assert.equal(totals.total,1000000);
});

test('matrix markup includes grouped headings, accessible Region disclosure, Branch rows and distinct total',()=>{
  const s=state();s.branches![0].regionName='Jawa <Timur>';
  const html=renderToStaticMarkup(createElement(DashboardMatrix,{s}));
  for(const label of ['Matriks Project per Region','JMLH','OPEN','CLOSE','Jumlah PP','Nilai Pengajuan','Nilai Terbayar','Total Nilai','Surabaya','Malang','Semarang','TOTAL · ID Project unik'])assert.ok(html.includes(label));
  assert.match(html,/colSpan="3"/);assert.match(html,/aria-expanded="true" aria-controls="([^"]+)"/);assert.match(html,/<tbody id="/);assert.match(html,/Jawa &lt;Timur&gt;/);assert.doesNotMatch(html,/NaN|Infinity/);
  const empty=renderToStaticMarkup(createElement(DashboardMatrix,{s,branch:'hidden'}));assert.match(empty,/Belum ada Branch dalam cakupan filter dan akses Anda/);
});

test('submitted gross and actual paid remain separate across pending, returned, rejected and resubmitted terms',()=>{
  const s=state(),t=invoice(s);t.paid=780;
  t.kasbon={id:'net-quote',version:1,amount:780,issuedBy:'admin',issuedAt:'2026-11-17',settlement:{policy:'mandor-service-pph25-v1',gross:800,material:0,service:800,withheld:20,net:780}};
  const pending={...structuredClone(t),id:'pending',status:'Diajukan' as const,paid:0};
  s.terms=[t,pending,...(['Draft','Dikembalikan','Ditolak'] as const).map(status=>({...structuredClone(t),id:status,status}))];
  const before=JSON.stringify(s),data=dashboardMatrix(s);
  assert.equal(data.totals.requested,1600);assert.equal(data.totals.paid,780);
  assert.equal(data.payments.length,2);assert.equal(data.payments.find(p=>p.id==='pending')!.amount,0);
  assert.equal(JSON.stringify(s),before);
  pending.status='Diajukan'; // Reusing the same document never sums its historical approval cycles.
  pending.history=[{role:'PM Konstruksi',decision:'Tolak',note:'Revisi',at:'2026-11-16'}];
  assert.equal(dashboardMatrix(s).totals.requested,1600);
  s.spks[0].status='Draft';assert.equal(dashboardMatrix(s).totals.requested,0);
});

test('Finance settlement without APM contributes net paid and closes final while gross request stays unchanged',()=>{
  const s=state(),t=invoice(s);t.workflowVersion=3;t.paymentFlow='kasbon-settlement-v2';t.paid=780;t.apm='';t.apmStatus='Belum Terbit';
  t.kasbon={id:'k1',version:1,amount:780,issuedBy:'admin',issuedAt:'2026-11-17',settlement:{policy:'mandor-service-pph25-v1',gross:800,material:0,service:800,withheld:20,net:780}};
  t.transferProof={id:'proof',kasbonId:'k1',uploadedBy:'finance',uploadedAt:'2026-11-17'};
  t.financePayment={status:'Dibayar',settledAt:'2026-11-17',settledBy:'finance',kasbonId:'k1',transferFileId:'proof'};
  s.terms=[t];const v=dashboardMatrix(s).totals;
  assert.equal(v.requested,800);assert.equal(v.paid,780);assert.equal(v.closed,1);
});

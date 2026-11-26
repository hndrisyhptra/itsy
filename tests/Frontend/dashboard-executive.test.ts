import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {type State,type Term,type Role} from '../../resources/js/area3/domain';
import {executiveDashboard,monthlyPayments,paidMonth} from '../../resources/js/area3/dashboard-executive';
import {ExecutiveDashboard} from '../../resources/js/area3/dashboard-executive-view';
import {APPROVERS_V3} from '../../resources/js/area3/workflow';
import {OPERATION_APPROVERS,stateForUnit} from '../../resources/js/area3/work-unit';
import {readDashboardBudget} from '../../resources/js/area3/dashboard-budget';
function fixture(){
 const s=seed('D'),p=s.projects[0],d=s.deals[0];s.schema=3;s.role='Superadmin';s.actorUserId='qa-admin';
 s.users.push({id:'qa-admin',name:'QA Admin',role:'Superadmin',email:'qa@example.test',active:true,branches:['Surabaya','Malang'],workUnits:['Konstruksi','Operation']});
 p.unit='Konstruksi';p.branch='Surabaya';p.projectCode='PID-1';p.status='Sah';p.workflowVersion=3;p.approvalScheme='project-support-first';
 p.approverUserIds=Object.fromEntries(APPROVERS_V3.map((r,i)=>[r,'actor-'+i]));
 d.rows=[{...d.rows[0],rowId:p.boq[0].id,m:false,s:true,qm:0,qs:10,pm:0,ps:100}];
 s.branches=[{id:'sby',name:'Surabaya',code:'R501',active:true,regionId:'east',regionName:'Jawa Timur'},{id:'mlg',name:'Malang',code:'R531',active:true,regionId:'east',regionName:'Jawa Timur'}];s.terms=[];s.bapps=[];
 return s;
}
function term(s:State,id:string,status:Term['status']='Diajukan'):Term{
 const roles=[...APPROVERS_V3,'Mgr Finance & Settlement','Finance'] as Role[];
 return {id,spkId:s.spks[0].id,number:1,type:'Final',status,workflowVersion:3,paymentFlow:'kasbon-settlement-v2',approvalScheme:'project-support-first',actuals:{[s.deals[0].rows[0].rowId]:{m:0,s:2}},approverUserIds:Object.fromEntries(roles.map((r,i)=>[r,'actor-'+i])),approvals:status==='Disetujui'?roles.map((role,i)=>({role,userId:'actor-'+i,kasbonId:'kasbon-'+id,decision:'Setujui',note:'QA',at:'2026-11-02T02:00:00Z'})):[],history:[],paid:0,apm:'',apmStatus:'Belum Terbit',paymentHistory:[],submitted:'2026-11-01T02:00:00Z',kasbon:{id:'kasbon-'+id,version:1,amount:195,issuedBy:'qa-admin',issuedAt:'2026-11-01T01:00:00Z',settlement:{policy:'mandor-service-pph25-tax-round-v4',gross:200,material:0,service:200,withheld:5,net:195}}};
}
function pay(t:Term){t.paid=195;t.financePayment={status:'Dibayar',settledAt:'2026-11-30T18:00:00Z',kasbonId:t.kasbon!.id,transferFileId:'proof-'+t.id};t.transferProof={id:'proof-'+t.id,kasbonId:t.kasbon!.id,uploadedAt:'2026-11-29T00:00:00Z',uploadedBy:'finance'};}
test('nine cards are real read models, without monthly expenditure, gradients or removed panels',()=>{
 const s=fixture(),before=JSON.stringify(s),html=renderToStaticMarkup(createElement(ExecutiveDashboard,{s,unit:'Konstruksi',branch:''}));
 for(const label of ['Total ID Project','Paket PP Berjalan','Total Anggaran PP','Total Sudah Dibayar','Outstanding Pembayaran','Menunggu Approval','Menunggu Pelunasan','Nilai Pengajuan Pembayaran','Berkas Belum Lengkap'])assert(html.includes(label));
 assert.equal((html.match(/class="executive-card[ "]/g)??[]).length,9);assert(!/Pengeluaran Bulan Ini|gradient|Progress Pekerjaan per PP|Aktivitas Terbaru|Inbox Approval/.test(html));assert.equal(JSON.stringify(s),before);
 assert.doesNotMatch(html,/Pembayaran Bulanan|executive-chart|executive-month|Tahun pembayaran bulanan|Sisa Budget PID|Perbarui budget/);
});
test('Outstanding includes active pending and ready net invoices once, excludes revisions and cancelled parents',()=>{
 const s=fixture(),pending=term(s,'pending'),ready=term(s,'ready','Disetujui'),returned=term(s,'return','Disetujui');returned.financePayment={status:'Dikembalikan'};
 s.terms=[pending,ready,returned,term(s,'draft','Draft'),term(s,'reject','Ditolak')];const before=JSON.stringify(s),v=executiveDashboard(s);
 assert.equal(v.values.outstanding,390);assert.equal(v.values.settlement,195);assert.equal(v.pendingOutstanding,195);assert.equal(v.values.approval,1);assert.equal(v.values.documents,2);assert.equal(v.values.paid,0);assert.equal(JSON.stringify(s),before);
 s.projects[0].status='Cancel';const cancelled=executiveDashboard(s);for(const key of Object.keys(cancelled.values) as (keyof typeof cancelled.values)[])assert.equal(cancelled.values[key],0);
});
test('real proof-bound payment without APM contributes saved net, closes exact PP and uses settlement month WIB',()=>{
 const s=fixture(),t=term(s,'paid','Disetujui');pay(t);s.terms=[t];const before=JSON.stringify(s),v=executiveDashboard(s);
 assert.equal(v.values.paid,195);assert.equal(v.values.outstanding,0);assert.equal(v.closed,1);assert.equal(v.values.plans,0);assert.equal(v.paidMissing,1);assert.equal(v.payments[0].key,'2026-12');
 assert.equal(monthlyPayments(v.payments,2026)[11].amount,195);assert.equal(monthlyPayments(v.payments,2026)[10].amount,0);assert.equal(JSON.stringify(s),before);
 t.financePayment!.transferFileId='wrong';assert.equal(executiveDashboard(s).values.paid,0);
});
test('paid partial leaves PP open; PID closes only when all same-ID visible plans finish, not BAPP attestation',()=>{
 const s=fixture(),t=term(s,'paid','Disetujui');pay(t);t.type='Parsial';s.terms=[t];assert.equal(executiveDashboard(s).open,1);
 t.type='Final';s.projects.push({...structuredClone(s.projects[0]),id:'sibling',rootId:'sibling',branch:'Malang',status:'Draft'});
 let v=executiveDashboard(s);assert.equal(v.values.projects,1);assert.equal(v.open,1);assert.equal(v.values.plans,1);assert.equal(v.rows.projects[0].plans,2);
 assert.equal(executiveDashboard(s,'Surabaya').open,1);assert.equal(executiveDashboard(s,'Malang').values.paid,0);assert.equal(executiveDashboard(s,'unknown').values.projects,0);
 s.terms=[];s.bapps=[{id:'early',termId:t.id,status:'Sah',no:'BA',evidence:'',released:{},releaseHistory:[]}];assert.equal(executiveDashboard(s).open,1);
});
test('budget uses saved point5 recap on approved roots and never continuation or Owner revaluation',()=>{
 const s=fixture(),p=s.projects[0];p.planBudget={version:2,mandor:[],materials:[],bop:[],permit:200,usage:[{id:'jasa',category:'mandor',amount:800},{id:'permit',category:'permit',amount:200}]};
 s.projects.push({...structuredClone(p),id:'child',parentId:p.id},{...structuredClone(p),id:'draft',status:'Draft'});const before=JSON.stringify(s);
 assert.equal(executiveDashboard(s).values.budget,1000);assert.equal(executiveDashboard(s).rows.budget.length,1);assert.equal(JSON.stringify(s),before);
});
test('historical gross and old cashbon policies stay frozen; chart uses actual paid history and reports missing dates',()=>{
 const s=fixture(),t=term(s,'legacy','Disetujui');delete t.kasbon;delete t.paymentFlow;t.workflowVersion=1;t.apmStatus='Valid';t.paid=175.25;t.paymentHistory=[{amount:75.25,ref:'old1',at:'2026-01-31T18:00:00Z'},{amount:100,ref:'old2',at:'2026-03-01T00:00:00Z'}];s.terms=[t];
 let v=executiveDashboard(s);assert.equal(v.values.paid,175.25);assert.equal(v.values.outstanding,24.75);assert.equal(monthlyPayments(v.payments,2026)[1].amount,75.25);assert.equal(monthlyPayments(v.payments,2026)[2].amount,100);
 t.paymentHistory=[];v=executiveDashboard(s);assert.equal(v.values.paid,175.25);assert.equal(v.undated,175.25);assert.equal(v.payments.length,0);
 t.kasbon={id:'old',version:1,amount:194,issuedBy:'a',issuedAt:'2026-01-01',settlement:{policy:'mandor-service-pph25-v1',gross:200,material:0,service:200,withheld:6,net:194}};assert.equal(executiveDashboard(s).values.outstanding,18.75);
});
test('Operation stages and scopes use Operation actors with no Construction or other-unit data',()=>{
 const s=fixture(),p=s.projects[0];p.unit='Operation';p.status='Diajukan';p.approvalScheme='operation-v1';p.approvals=[];p.approverUserIds=Object.fromEntries(OPERATION_APPROVERS.map((r,i)=>[r,'op-'+i]));
 const v=executiveDashboard(stateForUnit(s,'Operation'),'','Operation');assert.equal(v.values.approval,1);assert.equal(v.rows.approval[0].status,'Menunggu Officer 1 Operation');assert.equal(executiveDashboard(stateForUnit(s,'Konstruksi')).values.projects,0);
});
test('month keys are calendar WIB and chart contains exactly twelve months including zero',()=>{
 assert.equal(paidMonth('2026-11-30T17:00:00Z'),'2026-12');assert.equal(paidMonth('2026-11-30 16:59:59'),'2026-11');assert.equal(paidMonth('invalid'),null);assert.equal(paidMonth(undefined),null);assert.equal(monthlyPayments([],2026).length,12);assert(monthlyPayments([],2026).every(m=>m.amount===0));
});
test('budget transport binds exact scope/query/page and rejects stale or oversized data without writes',async()=>{
 const original=globalThis.fetch;let calls=0;
 try{
  const data={unit:'Operation',branch:'Surabaya',q:'PID',page:2,per_page:20,total:50,remaining:100,exhausted:0,deficit:0,rows:[],new_pp_only:true};
  globalThis.fetch=async(input,init)=>{calls++;assert(!init?.method||init.method==='GET');assert.equal(init?.cache,'no-store');if(calls===1)assert(String(input).includes('unit=Operation'));return new Response(JSON.stringify(data),{status:200});};
  assert.equal((await readDashboardBudget('Operation','Surabaya',true,'PID',2)).remaining,100);
  await assert.rejects(readDashboardBudget('Konstruksi','Surabaya',true,'PID',2),/tidak sesuai/);
  data.rows=Array.from({length:21},()=>({})) as never;await assert.rejects(readDashboardBudget('Operation','Surabaya',true,'PID',2),/tidak sesuai/);assert.equal(calls,3);
 }finally{globalThis.fetch=original;}
});


test('requested card equals matrix gross, includes paid and pending but excludes draft/revision/cancel without changing net money',()=>{
 const s=fixture(),pending=term(s,'pending'),ready=term(s,'ready','Disetujui'),paid=term(s,'paid','Disetujui'),returned=term(s,'returned','Disetujui');pay(paid);returned.financePayment={status:'Dikembalikan'};
 s.terms=[pending,ready,paid,returned,term(s,'draft','Draft'),term(s,'rejected','Ditolak'),term(s,'revision','Dikembalikan')];
 const before=JSON.stringify(s),v=executiveDashboard(s);
 assert.equal(v.values.requested,600);assert.equal(v.values.requested,v.matrix.totals.requested);assert.equal(v.rows.requested.length,3);assert.equal(v.requestedPids,1);assert(v.rows.requested.every(r=>r.amount===200));
 assert.equal(v.values.paid,195);assert.equal(v.values.outstanding,390);assert.equal(v.rows.requested.find(r=>r.id==='pending')?.route,'/termin/pending');assert.equal(v.rows.requested.find(r=>r.id==='paid')?.payment,'Pembayaran Final');assert.equal(JSON.stringify(s),before);
 assert.equal(executiveDashboard(s,'Malang').values.requested,0);s.projects[0].status='Cancel';assert.equal(executiveDashboard(s).values.requested,0);
});
test('late scan correction on a paid legacy invoice keeps requested gross and recorded paid totals',()=>{
 const s=fixture(),paid=term(s,'paid','Disetujui');pay(paid);paid.scan={id:'old-scan',status:'Perlu Koreksi'} as Term['scan'];s.terms=[paid];const before=JSON.stringify(s),v=executiveDashboard(s);
 assert.equal(v.values.requested,200);assert.equal(v.matrix.totals.requested,200);assert.equal(v.values.paid,195);assert.equal(v.matrix.totals.paid,195);assert.equal(JSON.stringify(s),before);
});

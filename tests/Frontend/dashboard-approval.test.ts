import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {type State,type Role,type Project,type Term,termTotalForDeal} from '../../resources/js/area3/domain';
import {APPROVERS_V3} from '../../resources/js/area3/workflow';
import {OPERATION_APPROVERS} from '../../resources/js/area3/work-unit';
import {Dashboard} from '../../resources/js/area3/dashboard';
import {dashboardApprovalData,approvalSelectionRows,approvalRowsPage} from '../../resources/js/area3/dashboard-approval';
import {ApprovalDetailModal,DashboardApproval} from '../../resources/js/area3/dashboard-approval-table';
function fixture(){
 const s=seed('D');s.schema=3;s.role='Superadmin';s.actorUserId='qa-admin';s.users.push({id:'qa-admin',name:'Admin QA',role:'Superadmin',email:'qa@example.test',active:true,branches:['Surabaya','Malang'],workUnits:['Konstruksi','Operation']});
 const p=s.projects[0];Object.assign(p,{unit:'Konstruksi',branch:'Surabaya',workflowVersion:3,approvalScheme:'project-support-first',status:'Diajukan',approvals:[],approverUserIds:Object.fromEntries(APPROVERS_V3.map((r,i)=>[r,'approver-'+i]))});
 for(const [i,role]of APPROVERS_V3.entries())s.users.push({id:'approver-'+i,role,name:'Actor '+role,email:'a'+i+'@example.test',active:true});
 s.branches=[{id:'sb',name:'Surabaya',code:'R501',active:true,regionId:'east',regionName:'Jawa Timur'},{id:'ml',name:'Malang',code:'R531',active:true,regionId:'east',regionName:'Jawa Timur'},{id:'sm',name:'Semarang',code:'R428',active:true,regionId:'central',regionName:'Jawa Tengah'}];
 s.terms=[];return {s,p};
}
function payment(s:State,p:Project,id:string):Term{
 const t={...structuredClone(seed('E').terms[0]),id,spkId:s.spks[0].id,workflowVersion:3,paymentFlow:'kasbon-settlement-v2',approvalScheme:'project-support-first',status:'Diajukan',approvals:[],paid:0,apm:'',apmStatus:'Belum Terbit',number:1,type:'Final',kasbon:{id:'kasbon-'+id,version:1,amount:100,issuedBy:'qa-admin',issuedAt:'2026-11-18T01:00:00Z'},approverUserIds:{...p.approverUserIds,'Mgr Finance & Settlement':'mgr'}} as Term;
 s.terms.push(t);return t;
}
function approve(t:Term,roles:Role[]){t.approvals=roles.map(role=>({role,userId:t.approverUserIds?.[role]||'finance-actor',decision:'Setujui',note:'QA',at:'2026-11-18T02:00:00Z',kasbonId:t.kasbon?.id}));}

test('approval matrix partitions every PP once, scopes account Branch/unit before totals and groups duplicate Project IDs as separate PP',()=>{
 const {s,p}=fixture();s.projects=[...(['Draft','Diajukan','Dikembalikan','Ditolak','Sah']as const).map((status,i)=>({...structuredClone(p),id:'pp-'+i,status}))];
 s.projects.push({...structuredClone(p),id:'foreign',branch:'Semarang'},{...structuredClone(p),id:'op',unit:'Operation',approvalScheme:'operation-v1'});
 const before=JSON.stringify(s),data=dashboardApprovalData(s).project;
 assert.equal(data.total.rows.length,5);assert.deepEqual(data.total.counts,{draft:1,'role:PM Konstruksi':1,revision:2,approved:1});assert.equal(Object.values(data.total.counts).reduce((a,b)=>a+b,0),5);assert.equal(data.regions.length,1);assert.equal(data.regions[0].branches.length,2);assert.equal(data.regions[0].branches[1].name,'Surabaya');
 assert.equal(dashboardApprovalData(s,'Semarang').project.total.rows.length,0);assert.equal(dashboardApprovalData(s,'Malang').project.total.rows.length,0);assert.equal(JSON.stringify(s),before);
});
test('current PP position uses the actual selected actor, ignores approval history and adds legacy roles rather than relabeling old chains',()=>{
 const {s,p}=fixture();p.approvals=[{role:'PM Konstruksi',userId:'wrong',decision:'Setujui',at:'2026-11-18',note:''}];p.approvalHistory=[{...p.approvals[0],userId:'approver-0'}];
 assert.equal(dashboardApprovalData(s).project.total.rows[0].bucket,'role:PM Konstruksi');p.approvals[0].userId='approver-0';assert.equal(dashboardApprovalData(s).project.total.rows[0].bucket,'role:Admin Area');
 p.workflowVersion=2;p.pmUserId='approver-0';p.managerUserId='old-manager';p.approvals=[{...p.approvals[0]}];const data=dashboardApprovalData(s).project;assert.equal(data.total.rows[0].bucket,'role:Manager Konstruksi');assert(data.columns.some(c=>c.label==='Manager Konstruksi · Alur lama'));
 p.workflowVersion=3;p.approvalScheme='business-support';p.approverUserIds!['Mgr Bussiness Support']='old-bs';approve(p as unknown as Term,['PM Konstruksi','Admin Area','Mgr Deployment']);assert.equal(dashboardApprovalData(s).project.total.rows[0].bucket,'role:Mgr Bussiness Support');
});
test('new payment distinguishes seven current-version approvals, revision, complete approval and proof-bound settlement without mandatory APM',()=>{
 const {s,p}=fixture(),t=payment(s,p,'new');const roles=[...APPROVERS_V3,'Mgr Finance & Settlement','Finance']as Role[];
 for(let i=0;i<roles.length;i++){approve(t,roles.slice(0,i));assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'role:'+roles[i]);}
 approve(t,roles);t.approvals[0].kasbonId='prior';assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'role:PM Konstruksi');approve(t,roles);
 t.status='Disetujui';assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'settlement');t.financePayment={status:'Dikembalikan'};assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'revision');
 const gross=termTotalForDeal(s.deals[0],t);t.kasbon!.amount=gross;t.paid=gross;t.financePayment={status:'Dibayar',settledAt:'2026-11-18T03:00:00Z',kasbonId:t.kasbon!.id,transferFileId:'proof'};t.transferProof={id:'proof',kasbonId:t.kasbon!.id,uploadedAt:'2026-11-18T03:00:00Z',uploadedBy:'finance'};
 assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'paid');t.financePayment.transferFileId='wrong';assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'settlement');assert.equal(dashboardApprovalData(s).payment.total.rows[0].payment,'Pembayaran Final');
});
test('legacy kasbon Admin preparation, Manager supplemental review, Finance review and partial request remain distinct',()=>{
 const {s,p}=fixture(),t=payment(s,p,'old');t.paymentFlow='kasbon-v1';t.status='Disetujui';approve(t,APPROVERS_V3);delete t.kasbon;
 assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'draft');t.kasbon={id:'current',version:1,amount:100,issuedAt:'2026-11-18',issuedBy:'qa-admin'};t.financePayment={status:'Kasbon Terbit'};
 t.legacySettlementReview={version:1,kasbonId:'current',userId:'mgr',name:'Manager QA',assignedAt:'2026-11-18',assignedBy:'qa-admin'};
 t.legacySettlementReview.kasbonId='prior';assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'other');t.legacySettlementReview.kasbonId='current';
 assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'role:Mgr Finance & Settlement');t.legacySettlementReview.decision={role:'Mgr Finance & Settlement',userId:'mgr',kasbonId:'current',decision:'Setujui',note:'QA',at:'2026-11-18'};
 assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'role:Finance');t.financePayment={status:'Diverifikasi',decision:{role:'Finance',userId:'finance',kasbonId:'current',decision:'Setujui',note:'QA',at:'2026-11-18'}};assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'settlement');
 t.paymentFlow='partial-payment-v1';t.type='Parsial';delete t.paymentRequest;assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'draft');t.paymentRequest={id:'req',cycle:1,status:'Diajukan',amount:100,requestedBy:'admin',requestedByName:'Admin',requestedAt:'2026-11-18',documentsAtRequest:'NOK'};assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'role:Finance');t.paymentRequest.status='Dikembalikan';assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'revision');
 delete t.paymentFlow;delete t.paymentRequest;t.workflowVersion=2;t.scan={status:'Perlu Koreksi'} as Term['scan'];assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'revision');t.scan!.status='Menunggu Verifikasi';assert.equal(dashboardApprovalData(s).payment.total.rows[0].bucket,'role:Finance');
});
test('Operation columns use its five roles and legacy Operation PP can keep its actual Construction chain without mixing units',()=>{
 const {s,p}=fixture();p.unit='Operation';p.approvalScheme='operation-v1';p.approverUserIds=Object.fromEntries(OPERATION_APPROVERS.map((r,i)=>[r,'op-'+i]));p.approvals=[];
 const v=dashboardApprovalData(s,'','Operation');assert.equal(v.project.total.rows[0].bucket,'role:Officer 1 Operation');assert.deepEqual(v.project.columns.filter(c=>c.key.startsWith('role:')).map(c=>c.label),OPERATION_APPROVERS);assert.equal(v.payment.columns.filter(c=>c.key.startsWith('role:')).length,7);assert.equal(dashboardApprovalData(s).project.total.rows.length,0);
 delete p.approvalScheme;assert.equal(dashboardApprovalData(s,'','Operation').project.total.rows[0].bucket,'role:PM Konstruksi');s.users.find(u=>u.id==='qa-admin')!.workUnits=['Konstruksi'];assert.equal(dashboardApprovalData(s,'','Operation').project.regions.length,0);
});
test('count drilldowns agree with exact Branch/Region/total, paginate 20 and pin own PP and payment IDs, with no master per-row lookups',()=>{
 const {s,p}=fixture();s.projects=Array.from({length:45},(_,i)=>({...structuredClone(p),id:'pp-'+i,no:'QA-'+i}));s.projects[1].branch='Malang';s.khs.find=()=>{throw Error('Unexpected master lookup');};
 const matrix=dashboardApprovalData(s).project,selection={kind:'project',scope:'branch',id:'sb',label:'Surabaya',bucket:'role:PM Konstruksi'}as const,rows=approvalSelectionRows(matrix,selection);assert.equal(rows.length,44);assert.equal(approvalRowsPage(rows).rows.length,20);assert.equal(approvalRowsPage(rows,'',999).rows.length,4);assert.equal(approvalRowsPage(rows,'QA-44').count,1);assert.equal(approvalSelectionRows(matrix,{...selection,id:'missing'}).length,0);
 const html=renderToStaticMarkup(createElement(ApprovalDetailModal,{s,matrix,selection,onClose:()=>{}}));assert.equal((html.match(/<tr>/g)??[]).length,21);assert(html.includes('Timeline QA-0'));assert(!html.includes('Timeline QA-1"'));assert(html.includes('Cari daftar status approval'));assert(html.includes('Halaman 1/3'));
});
test('Dashboard defaults Summary and mounts counts only on Status Approval; read-only matrices show numeric cells and no document list until clicked',()=>{
 const {s}=fixture(),before=JSON.stringify(s);const summary=renderToStaticMarkup(createElement(Dashboard,{s}));assert(summary.includes('Summary'));assert(summary.includes('Status Approval'));assert(summary.includes('Matriks Project per Region'));assert(!summary.includes('Approval Paket PP'));
 const html=renderToStaticMarkup(createElement(DashboardApproval,{s}));assert(html.includes('Approval Paket PP'));assert(html.includes('Approval Pembayaran'));for(const text of ['Draf','Revisi','Disetujui','Menunggu Pelunasan','Sudah Dibayar'])assert(html.includes(text));assert(!html.includes('Cari daftar status approval'));assert(!html.includes('Semarang'));assert.equal(JSON.stringify(s),before);
});

test('every operational/approver/Finance role uses the same Branch intersection and empty assignments never show other Branches',()=>{
 for(const role of ['Admin Konstruksi',...APPROVERS_V3,...OPERATION_APPROVERS,'Mgr Finance & Settlement','Finance'] as Role[]){
  const {s,p}=fixture();s.role=role;const actor=s.users.find(u=>u.id==='qa-admin')!;actor.role=role;actor.branches=['Malang'];s.projects.push({...structuredClone(p),id:'malang',branch:'Malang'});payment(s,p,'foreign-payment');
  assert.equal(dashboardApprovalData(s).project.total.rows.length,1);assert.equal(dashboardApprovalData(s).project.total.rows[0].branch,'Malang');assert.equal(dashboardApprovalData(s).payment.total.rows.length,0);
  actor.branches=[];assert.equal(dashboardApprovalData(s).project.regions.length,0);
 }
});

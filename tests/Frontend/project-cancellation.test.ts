import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement as h} from 'react';
import {renderToStaticMarkup as render} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {type Project,type Role,canApproveProject} from '../../resources/js/area3/domain';
import {cancelProject,projectCancellationPlans,assertCancellationCommand} from '../../resources/js/area3/project-cancellation';
import {ContractFollowUp} from '../../resources/js/area3/contracts';
import {Projects,ProjectDetail} from '../../resources/js/area3/projects';
import {projectPosition} from '../../resources/js/area3/project-timeline';
import {dashboardApprovalData} from '../../resources/js/area3/dashboard-approval';
import {dashboardMatrix} from '../../resources/js/area3/dashboard-matrix';
import {dashboardSummary} from '../../resources/js/area3/dashboard-summary';
function fixture(operation=false,role:Role=operation?'Officer 1 Operation':'Admin Area'){
 const s=seed('D'),p=s.projects[0];s.schema=3;s.role=role;s.actorUserId='cancel-actor';s.terms=[];s.bapps=[];p.unit=operation?'Operation':'Konstruksi';p.approvalScheme=operation?'operation-v1':'project-support-first';s.users.push({id:'cancel-actor',name:'Cancellation QA',email:'qa@example.test',role,active:true,branches:[p.branch],workUnits:[p.unit]});return {s,p};
}
for(const operation of [false,true])for(const status of ['Draft','Dikembalikan','Ditolak','Diajukan','Sah'] as Project['status'][]){
 test(`terminal cancellation ${operation?'Operation':'Construction'} ${status} retains saved graph`,()=>{
  const {s,p}=fixture(operation);p.status=status;const before=structuredClone(s),expected=projectCancellationPlans(s).get(p.id)!.expected;
  cancelProject(s,p.id,expected,'Alasan QA');assert.equal(p.status,'Cancel');assert.equal(p.cancellation?.previousStatus,status);assert.equal(p.cancellation?.actorUserId,'cancel-actor');assert.deepEqual(p.approvals,before.projects[0].approvals);assert.deepEqual(p.approvalHistory,before.projects[0].approvalHistory);assert.deepEqual(s.deals,before.deals);assert.deepEqual(s.spks,before.spks);assert.equal(p.budget,before.projects[0].budget);assert.equal(canApproveProject(s,p),false);assert.match(projectPosition(p),/Cancel/);
  assert.throws(()=>cancelProject(s,p.id,expected,'Again'),/Cancel/);
  for(const [command,args]of [['decideProject',[p.id,'Setujui','']],['resetProjectApproval',[p.id,{},'Again']],['saveProject',[p,false]],['submitProjectBundle',[p.id]],['saveDeal',[s.deals[0],'save']],['saveSpk',[s.spks[0],'save']],['createTerm',[s.spks[0].id]]] as const)assert.throws(()=>assertCancellationCommand(s,command,[...args]),/Cancel/);
 });
}
test('cancellation role, Branch, unit, active actor, downstream and fingerprint guards',()=>{
 for(const operation of [false,true]){
  for(const role of ['Admin Konstruksi','Admin Operation','Finance','PM Konstruksi','Mgr Project Support',operation?'Admin Area':'Officer 1 Operation'] as Role[]){const {s,p}=fixture(operation,role);assert.equal(projectCancellationPlans(s).get(p.id)?.own,false);assert.throws(()=>cancelProject(s,p.id,projectCancellationPlans(s).get(p.id)!.expected,'QA'),/berwenang/);}
  const {s,p}=fixture(operation,'Superadmin');assert.equal(projectCancellationPlans(s).get(p.id)?.own,true);const actor=s.users.at(-1)!;actor.branches=['OTHER BRANCH'];assert.equal(projectCancellationPlans(s).get(p.id)?.own,false);actor.branches=[p.branch];actor.workUnits=[operation?'Konstruksi':'Operation'];assert.equal(projectCancellationPlans(s).get(p.id)?.own,false);actor.workUnits=[p.unit];actor.active=false;assert.equal(projectCancellationPlans(s).get(p.id)?.own,false);actor.active=true;
  const expected=structuredClone(projectCancellationPlans(s).get(p.id)!.expected);p.status='Draft';assert.throws(()=>cancelProject(s,p.id,expected,'QA'),/berubah/);const current=projectCancellationPlans(s).get(p.id)!.expected;assert.throws(()=>cancelProject(s,p.id,current,''),/alasan/);assert.throws(()=>cancelProject(s,p.id,current,'x'.repeat(1001)),/1000/);
  s.terms=seed('E').terms;s.terms[0].spkId=s.spks[0].id;assert.match(projectCancellationPlans(s).get(p.id)!.reason,/BA RP/);s.terms=[];s.projects.push({...structuredClone(p),id:'child',parentId:p.id});assert.match(projectCancellationPlans(s).get(p.id)!.reason,/lanjutan/);
 }
});
test('cancelled PP is visible in list and approval matrix terminal bucket, absent from active summary',()=>{
 const {s,p}=fixture();p.status='Diajukan';const html=render(h(Projects,{s}));assert(html.includes('Cancel paket PP '+p.no));cancelProject(s,p.id,projectCancellationPlans(s).get(p.id)!.expected,'QA stop');const list=render(h(Projects,{s}));assert(list.includes('<option>Cancel</option>'));assert(!list.includes('aria-label="Cancel paket PP '+p.no+'"'));assert(render(h(ProjectDetail,{s,id:p.id})).includes('QA stop'));
 assert(render(h(ContractFollowUp,{deal:s.deals[0],spk:s.spks[0],project:p})).includes('proses BA KH/SPK dihentikan'));
 const matrices=dashboardApprovalData(s);assert.equal(matrices.project.total.counts.cancelled,1);assert(!dashboardMatrix(s).plans.some(row=>row.id===p.id));assert(!dashboardSummary(s).projects.some(row=>row.id===p.id));
});

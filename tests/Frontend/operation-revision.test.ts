import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement as h} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {type Project,type State,approvalRoles,projectApprovalScheme,saveProject} from '../../resources/js/area3/domain';
import {OPERATION_APPROVERS} from '../../resources/js/area3/work-unit';
import {projectEditorValue} from '../../resources/js/area3/project-revision';
import {ProjectEditor} from '../../resources/js/area3/projects';
import {ProjectApproverFields,ProjectApprovalPanel,SubmissionRejectionNotice} from '../../resources/js/area3/workflow-ui';
import {projectTimeline,projectPosition,paymentPosition} from '../../resources/js/area3/project-timeline';
import {ProjectTimeline} from '../../resources/js/area3/project-timeline-page';

function fixture(){
 const s=seed('D');s.schema=3;s.role='Admin Operation';s.actorUserId='ops-admin';s.users.push({id:'ops-admin',name:'Admin Operation QA',email:'qa@example.test',role:'Admin Operation',active:true,branches:['Surabaya'],workUnits:['Operation']});
 for(const [i,role]of OPERATION_APPROVERS.entries())s.users.push({id:'ops-'+i,name:'QA '+role,email:i+'@example.test',role,active:true,branches:['Surabaya'],workUnits:['Operation'],accessScope:i>=3?'REGION':'BRANCH'});
 const p=s.projects[0];p.unit='Operation';p.workflowVersion=3;p.approvalScheme='project-support-first';p.status='Dikembalikan';p.approvals=[];p.approvalHistory=[{role:'PM Konstruksi',userId:'old-pm',actorName:'PM Historis',decision:'Setujui',note:'History',at:'2026-11-19T00:00:00Z'},{role:'Admin Area',userId:'old-area',actorName:'Admin Area Historis',decision:'Kembalikan',note:'Perbaiki uraian Operation',at:'2026-11-19T01:00:00Z'}];
 p.approverUserIds=Object.fromEntries(OPERATION_APPROVERS.map((r,i)=>[r,'ops-'+i]));s.terms=[];s.bapps=[];return {s,p};
}

test('returned legacy Operation editor and approver fields use Operation while history remains verbatim',()=>{
 const {s,p}=fixture(),before=JSON.stringify(s);
 assert.equal(projectApprovalScheme(p),'operation-v1');
 for(const component of [h(ProjectEditor,{s,id:p.id}),h(ProjectApproverFields,{s,p,onChange:()=>{},disabled:false})]){
  const html=renderToStaticMarkup(component);for(const role of OPERATION_APPROVERS)assert.match(html,new RegExp('Approval '+(OPERATION_APPROVERS.indexOf(role)+1)+' · '+role));assert.doesNotMatch(html,/Approval \d · (?:PM Konstruksi|Admin Area|Mgr Project Support|Mgr Deployment|Head of PMO)/);
 }
 const panel=renderToStaticMarkup(h(ProjectApprovalPanel,{s,p}));for(const role of OPERATION_APPROVERS)assert(panel.includes('<span>'+role+'</span>'));
 const notice=renderToStaticMarkup(h(SubmissionRejectionNotice,{document:p,history:p.approvalHistory}));assert.match(notice,/Admin Operation perlu/);assert.match(notice,/dimulai dari Officer 1 Operation/);assert.match(notice,/Perbaiki uraian Operation/);assert.equal(JSON.stringify(s),before);
});

test('cached Construction routing cannot replace saved Operation unit, returned status or actual history',()=>{
 const {p}=fixture(),raw={...structuredClone(p),id:'wrong-cached-pp',no:'wrong-number',rootId:'wrong-root',unit:'Konstruksi',status:'Draft' as const,approvalScheme:'project-support-first' as const,approvalHistory:[],name:'Isian pengguna tetap'};
 const value=projectEditorValue(raw,p);assert.equal(value.id,p.id);assert.equal(value.no,p.no);assert.equal(value.rootId,p.rootId);assert.equal(value.unit,'Operation');assert.equal(value.approvalScheme,'operation-v1');assert.equal(value.status,'Dikembalikan');assert.deepEqual(value.approvalHistory,p.approvalHistory);assert.equal(value.name,raw.name);assert.equal(raw.unit,'Konstruksi');
 p.status='Diajukan';assert.equal(projectApprovalScheme(p),'project-support-first');assert.equal(projectEditorValue(raw,p),p);p.status='Sah';assert.equal(projectEditorValue(raw,p),p);
});

test('explicit revised save captures the old Construction cycle once and preserves it on subsequent drafts',()=>{
 const {s,p}=fixture(),history=structuredClone(p.approvalHistory),oldRouting=p.approvalScheme;
 saveProject(s,p,false);const saved=s.projects.find(v=>v.id===p.id)!;assert.equal(saved.approvalScheme,'operation-v1');assert.deepEqual(approvalRoles(saved,true),OPERATION_APPROVERS);assert.deepEqual(saved.approvalHistory,history);assert.equal(saved.operationApprovalRevision!.firstCycleNo,2);assert.equal(saved.operationApprovalRevision!.before.approvalScheme,oldRouting);assert.equal(saved.approvals!.length,0);
 const marker=structuredClone(saved.operationApprovalRevision);saveProject(s,{...saved,operationApprovalRevision:{...marker!,firstCycleNo:999}},false);assert.deepEqual(s.projects.find(v=>v.id===p.id)!.operationApprovalRevision,marker);
});

test('timeline isolates the selected work unit, keeps exact BARP activity and shows actual Operation stages',()=>{
 const {s,p}=fixture();p.approvalScheme='operation-v1';const other={...structuredClone(p),id:'construction-sibling',rootId:'construction-sibling',unit:'Konstruksi',no:'PP-KONSTRUKSI',approvalScheme:'project-support-first' as const};s.projects.push(other);
 s.terms.push({id:'ops-term',spkId:s.spks[0].id,number:1,type:'Parsial',workflowVersion:3,unit:'Operation',approvalScheme:'operation-v1',paymentFlow:'kasbon-settlement-v2',status:'Dikembalikan',actuals:{},approvals:[],history:[],apm:'',apmStatus:'Belum Terbit',paid:0,paymentHistory:[]});
 s.events.push({id:'barp-event',title:'BA RP Operation tersimpan',detail:'QA',actor:'Admin Operation',at:'2026-11-20T01:00:00Z',route:'/ba-rp/ops-term'},{id:'foreign-unit',title:'Aktivitas Konstruksi',detail:'QA',actor:'Admin Konstruksi',at:'2026-11-20T02:00:00Z',route:'/project-plan/'+other.id});
 const before=JSON.stringify(s),data=projectTimeline(s,p.id)!;assert.deepEqual(data.projects.map(p=>p.id),[p.id]);assert(data.events.some(e=>e.id==='barp-event'));assert(!data.events.some(e=>e.id==='foreign-unit'));
 const html=renderToStaticMarkup(h(ProjectTimeline,{s,id:p.id}));assert.match(html,/Timeline unit Operation/);assert.match(html,/Admin Operation/);assert.match(html,/General Manager Operation/);assert.doesNotMatch(html,/PP-KONSTRUKSI|Aktivitas Konstruksi|perbaikan Admin Konstruksi/);assert.equal(projectPosition(p),'Dikembalikan · menunggu perbaikan Admin Operation');assert.equal(paymentPosition(s.terms[0],p),'Dikembalikan · menunggu perbaikan Admin Operation');assert.equal(JSON.stringify(s),before);
});

test('existing Operation historical submissions and Construction revision routing stay authoritative',()=>{
 const {p}=fixture();for(const status of ['Diajukan','Sah'] as const){p.status=status;assert.equal(projectApprovalScheme(p),'project-support-first');}
 p.status='Dikembalikan';p.unit='Konstruksi';assert.equal(projectApprovalScheme(p),'project-support-first');assert.match(projectPosition(p),/Admin Konstruksi/);p.status='Ditolak';assert.match(projectPosition(p),/dari PM Konstruksi/);
});


test('legacy Operation conversion is held when downstream payment data already exists',()=>{
 const {s,p}=fixture();s.terms.push({id:'paid',spkId:s.spks[0].id,paid:100} as State['terms'][number]);const before=JSON.stringify(s);assert.throws(()=>saveProject(s,p,false),/proses lanjutan/);assert.equal(JSON.stringify(s),before);
});

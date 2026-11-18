import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement as h} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {approvalRoles,canApproveProject,decideProject,saveProject,blankProject,type State,type Role,type Project} from '../../resources/js/area3/domain';
import {OPERATION_APPROVERS,eligibleApprover,stateForUnit,unitOptions} from '../../resources/js/area3/work-unit';
import {defaultSignatureRows} from '../../resources/js/area3/plan-budget';
import {projectHeadIdentity} from '../../resources/js/area3/bakh-signer';
import {KasbonDocument} from '../../resources/js/area3/kasbon-document';
import {createKasbonDocx} from '../../resources/js/area3/kasbon-docx';
import {docxParts} from './helpers/docx-parts';
import type {KasbonData} from '../../resources/js/area3/kasbon-content';
import {ProjectPlanDocument} from '../../resources/js/area3/plan-document';
import {ProjectApproverFields} from '../../resources/js/area3/workflow-ui';

function state(){
  const s=seed('D');s.schema=3;s.role='Admin Operation';s.actorUserId='ops-admin';s.users.push({id:'ops-admin',name:'Admin Ops QA',email:'ops@example.test',role:'Admin Operation',active:true,branches:['Surabaya'],workUnits:['Operation']});
  OPERATION_APPROVERS.forEach((role,i)=>s.users.push({id:'ops-'+i,name:'Ops QA '+role,email:i+'@example.test',role,active:true,branches:['Surabaya'],workUnits:['Operation'],accessScope:i>=3?'REGION':'BRANCH',jabatan:'Jabatan '+role}));
  return s;
}

test('Operation draft chooses its own five approvals and preserves genuine GM identity and Jabatan',()=>{
  const s=state(),p={...blankProject(),unit:'Operation',branch:'Surabaya',projectCode:'OPS-QA',name:'Operation QA',date:'2026-11-18'};
  saveProject(s,p,false);const saved=s.projects.find(v=>v.id===p.id)!;
  assert(renderToStaticMarkup(h(ProjectPlanDocument,{p:saved})).includes('PROJECT PLAN SWAKELOLA OPERATION'));
  assert.equal(saved.approvalScheme,'operation-v1');assert.deepEqual(approvalRoles(saved,true),OPERATION_APPROVERS);
  assert.deepEqual(approvalRoles({...saved,paymentFlow:'kasbon-settlement-v2'}),[...OPERATION_APPROVERS,'Mgr Finance & Settlement','Finance']);
  for(const [i,role] of OPERATION_APPROVERS.entries())saved.approverUserIds![role]='ops-'+i;
  const identity=projectHeadIdentity(s,saved);assert.equal(identity.signerRole,'General Manager Operation');assert.equal(identity.signerJob,'Jabatan General Manager Operation');assert.equal(identity.signerSource,'pp-gm-operation');
  assert.deepEqual(defaultSignatureRows(saved).map(r=>r.role),[OPERATION_APPROVERS[0],...OPERATION_APPROVERS.slice(2)]);
  const html=renderToStaticMarkup(h(ProjectApproverFields,{s,p:saved,onChange:()=>{},disabled:false}));
  for(const role of OPERATION_APPROVERS)assert(html.includes(role));assert(!html.includes('Head of PMO'));assert(!html.includes('PM Konstruksi'));
  const history=structuredClone(saved);history.status='Sah';history.approvalScheme='project-support-first';assert(approvalRoles(history,true).includes('Head of PMO'));assert.equal(history.unit,'Operation');
});

test('Operation approvers cannot replace one another or approve Construction; Region and unit guards remain independent',()=>{
  const s=state(),p={...s.projects[0],workflowVersion:3,approvalScheme:'operation-v1',unit:'Operation',status:'Diajukan',approvals:[],approvalHistory:[],approverUserIds:Object.fromEntries(OPERATION_APPROVERS.map((r,i)=>[r,'ops-'+i]))} as Project;s.projects=[p];
  s.role='Officer 1 Operation';s.actorUserId='ops-0';assert(canApproveProject(s,p));
  decideProject(s,p.id,'Setujui','QA');assert.equal(p.approvals!.length,1);assert(!canApproveProject(s,p));
  s.role='Manager Branch Operation';s.actorUserId='ops-1';decideProject(s,p.id,'Tolak','Perbaiki data');assert.equal(p.status,'Ditolak');assert.equal(p.approvalHistory!.length,2);assert.equal(p.approvals!.length,0);
  const manager=s.users.find(u=>u.role==='Manager SS')!;assert(eligibleApprover(manager,{unit:'Operation',branch:'Surabaya'},'Manager SS'));
  assert(!eligibleApprover({...manager,accessScope:'BRANCH'},{unit:'Operation',branch:'Surabaya'},'Manager SS'));
  assert(!eligibleApprover(manager,{unit:'Konstruksi',branch:'Surabaya'},'Manager SS'));
  assert(!eligibleApprover(manager,{unit:'Operation',branch:'Other'},'Manager SS'));
  assert.deepEqual(unitOptions(s),['Operation']);
  s.role='Admin Operation';s.actorUserId='ops-admin';assert.throws(()=>saveProject(s,{...blankProject(),unit:'Konstruksi',branch:'Surabaya',date:'2026-11-18'},false),/Unit kerja/);
});

test('Operation kasbon uses actual Operation signers and automatic marks while native Word stays blank',()=>{
  const d=JSON.parse(readFileSync(new URL('../Fixtures/kasbon-vector.json',import.meta.url),'utf8')) as KasbonData;
  d.approvalScheme='operation-v1';d.unit='Operation · SURABAYA';d.signers={};
  for(const [i,role] of [...OPERATION_APPROVERS,'Mgr Finance & Settlement' as Role].entries())d.signers[role]={userId:'ops-'+i,name:'Nama '+role,job:'Jabatan '+role};
  const before=JSON.stringify(d),html=renderToStaticMarkup(h(KasbonDocument,{data:d}));
  assert(html.includes('Nama General Manager Operation'));assert(html.includes('Jabatan Officer 1 SS'));assert(!html.includes('Head of PMO'));assert(!html.includes('PM Konstruksi'));assert(!html.includes('APPROVED'));
  const parts=docxParts(createKasbonDocx(d,new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url))))),xml=parts['word/document.xml'];assert(xml.includes('Nama General Manager Operation'));assert(!xml.includes('HEAD OF PMO'));assert(!xml.includes('APPROVED'));assert.equal(JSON.stringify(d),before);
});

test('dashboard unit filtering follows PP identity even when Project code is shared and does not mutate state',()=>{
  const s=state(),source=s.projects[0];s.projects.push({...source,id:'ops-pp',rootId:'ops-pp',unit:'Operation'});
  const before=JSON.stringify(s),ops=stateForUnit(s,'Operation'),construction=stateForUnit(s,'Konstruksi');assert.deepEqual(ops.projects.map(p=>p.id),['ops-pp']);assert(construction.projects.some(p=>p.id===source.id));assert(!construction.projects.some(p=>p.id==='ops-pp'));assert.equal(JSON.stringify(s),before);
});

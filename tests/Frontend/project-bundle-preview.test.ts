import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { normalizeWorkflow, APPROVERS_V3, type Role, canApproveProject } from '../../resources/js/area3/domain';
import { ProjectBundleReview, projectBundlePreviewContext, type BundlePreviewTarget } from '../../resources/js/area3/project-bundle-review';
import { ProjectApprovalPanel, ProjectInbox } from '../../resources/js/area3/workflow-ui';

function fixture(){
  const s=normalizeWorkflow(seed('D')),p=s.projects[0];s.schema=3;
  p.workflowVersion=3;p.approvalScheme='project-support-first';p.status='Diajukan';p.contractBundle={version:1,submissions:[]};p.approverUserIds={};p.approvals=[];
  APPROVERS_V3.forEach((role,i)=>{const id='preview-approver-'+i;s.users.push({id,name:'Reviewer '+i,role,active:true,email:'review'+i+'@example.test'});p.approverUserIds![role]=id;});
  const d=s.deals.find(d=>d.projectId===p.id)!,v=s.spks.find(v=>v.dealId===d.id)!;
  return {s,p,d,v};
}
const labels=['Preview PP','Preview BA KH','Preview Lampiran BA KH','Preview SPK','Preview Lampiran SPK'];

test('package preview context pins exact PP, BA KH and SPK identities, including shared Project IDs and multiple contracts',()=>{
  const {s,p,d,v}=fixture(),other={...structuredClone(p),id:'other-pp',rootId:'other-pp'};
  s.projects.unshift(other);const foreign={...structuredClone(d),id:'foreign-deal',projectId:other.id};s.deals.unshift(foreign);const foreignSpk={...structuredClone(v),id:'foreign-spk',dealId:foreign.id};s.spks.unshift(foreignSpk);
  const second={...structuredClone(d),id:'second-deal',no:'SECOND-BA'};s.deals.push(second);const secondSpk={...structuredClone(v),id:'second-spk',dealId:second.id,no:'SECOND-SPK'};s.spks.push(secondSpk);
  const before=JSON.stringify(s);
  for(const kind of ['Project Plan','BA Kesepakatan Harga','Lampiran BA KH','Surat Perintah Kerja','Lampiran SPK'] as const){
    const context=projectBundlePreviewContext(s,p,{projectId:p.id,kind,dealId:d.id,spkId:v.id})!;
    assert.equal(context.project,p);assert.equal(context.kind,kind);
    if(kind!=='Project Plan')assert.equal(context.deal,d);
    if(kind==='Surat Perintah Kerja'||kind==='Lampiran SPK')assert.equal(context.spk,v);
  }
  assert.equal(projectBundlePreviewContext(s,p,{projectId:p.id,kind:'Lampiran SPK',dealId:second.id,spkId:secondSpk.id})!.spk,secondSpk);
  for(const target of [
    {projectId:other.id,kind:'Project Plan'},
    {projectId:p.id,kind:'BA Kesepakatan Harga',dealId:foreign.id},
    {projectId:p.id,kind:'Lampiran SPK',dealId:d.id,spkId:foreignSpk.id},
    {projectId:p.id,kind:'Lampiran SPK',dealId:d.id,spkId:secondSpk.id},
    {projectId:p.id,kind:'Lampiran SPK',dealId:d.id,spkId:'removed'},
  ] as BundlePreviewTarget[])assert.equal(projectBundlePreviewContext(s,p,target),null);
  assert.equal(projectBundlePreviewContext(s,{...p,contractBundle:undefined},{projectId:p.id,kind:'Project Plan'}),null);
  const html=renderToStaticMarkup(createElement(ProjectBundleReview,{s,p}));assert.ok(html.includes('SECOND-BA'));assert.ok(html.includes('SECOND-SPK'));assert.ok(!html.includes('foreign-spk'));assert.equal(JSON.stringify(s),before);
});

test('all five assigned approvers receive five package preview buttons before decision and an Inbox entry without state mutation',()=>{
  const {s,p}=fixture();
  for(const [i,role]of APPROVERS_V3.entries()){
    s.role=role;s.actorUserId=p.approverUserIds![role];p.approvals=APPROVERS_V3.slice(0,i).map(previous=>({role:previous,userId:p.approverUserIds![previous]!,decision:'Setujui',note:'',at:'2026-11-17T00:00:00Z'}));
    assert.equal(canApproveProject(s,p),true);const before=JSON.stringify(s);
    const html=renderToStaticMarkup(createElement(ProjectApprovalPanel,{s,p}));
    for(const label of labels){assert.ok(html.includes(label),role+' '+label);assert.ok(html.indexOf(label)<html.indexOf('>Setujui PP</button>'));}
    assert.doesNotMatch(html,/Lanjut isi BA KH|Lanjut buat SPK|Buka BA KH|Buka SPK|SPK tersedia setelah seluruh approval lengkap/);assert.ok(html.includes('pengesahan mengikuti persetujuan Head of PMO'));
    const inbox=renderToStaticMarkup(createElement(ProjectInbox,{s}));assert.ok(inbox.includes('Preview Paket'));assert.ok(inbox.includes('Tinjau PP'));
    assert.doesNotMatch(inbox,/Berkas Paket untuk Ditinjau|role="dialog"/,'Document picker and previews stay unmounted until requested.');
    assert.equal(JSON.stringify(s),before);
  }
  s.role='PM Konstruksi';s.actorUserId='unassigned';assert.doesNotMatch(renderToStaticMarkup(createElement(ProjectInbox,{s})),/Preview Paket/);
});

test('missing package documents show their actual availability, with creation links limited to editable operational users',()=>{
  const {s,p,d}=fixture();s.role='PM Konstruksi';s.spks=[];
  let html=renderToStaticMarkup(createElement(ProjectBundleReview,{s,p,embedded:true}));assert.ok(html.includes('Preview PP'));assert.ok(html.includes('Preview BA KH'));assert.doesNotMatch(html,/> Preview SPK|Lanjut buat SPK/);assert.ok(html.includes('SPK belum tersedia'));
  s.deals=[];html=renderToStaticMarkup(createElement(ProjectBundleReview,{s,p}));assert.ok(html.includes('Preview PP'));assert.doesNotMatch(html,/Preview BA KH|Lanjut isi BA KH/);
  s.role='Admin Konstruksi';p.status='Draft';html=renderToStaticMarkup(createElement(ProjectBundleReview,{s,p}));assert.ok(html.includes('Lanjut isi BA KH'));
  s.deals=[d];html=renderToStaticMarkup(createElement(ProjectBundleReview,{s,p}));assert.ok(html.includes('Lanjut buat SPK'));
  html=renderToStaticMarkup(createElement(ProjectBundleReview,{s,p,modal:true}));assert.doesNotMatch(html,/Lanjut buat SPK|Buka BA KH/);
  delete p.contractBundle;assert.equal(renderToStaticMarkup(createElement(ProjectBundleReview,{s,p})), '');
});

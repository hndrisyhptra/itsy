import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type State, type Term, APPROVERS_V3, normalizeWorkflow, saveProject, getProject } from '../../resources/js/area3/domain';
import { Projects } from '../../resources/js/area3/projects';
import { ProjectTimeline } from '../../resources/js/area3/project-timeline-page';
import { projectTimeline, projectPosition, paymentPosition } from '../../resources/js/area3/project-timeline';

function state():State {
  const s=normalizeWorkflow(seed('D'));s.schema=3;
  s.projects[0].projectCode='QA-SHARED';
  const other=structuredClone(s.projects[0]);other.id=other.rootId='pp-other';other.projectCode='OTHER';s.projects.push(other);
  const sibling=structuredClone(other);sibling.id=sibling.rootId='pp-sibling';sibling.no='PP/QA/SIBLING';sibling.projectCode=' qa-shared ';sibling.status='Draft';s.projects.push(sibling);
  s.events=[{id:'event-pp',title:'PP diperbarui',detail:'QA',route:'/project-plan/'+s.projects[0].id+'/edit',actor:'Admin Konstruksi',at:'2026-11-04T01:00:00Z'},
    {id:'event-spk',title:'SPK disahkan',detail:'QA',route:'/spk/'+s.spks[0].id,actor:'Admin Konstruksi',at:'2026-11-04T02:00:00Z'},
    {id:'event-sibling',title:'Draft sibling',detail:'QA',route:'/project-plan/pp-sibling',actor:'Admin Konstruksi',at:'2026-11-04T03:00:00Z'},
    {id:'event-foreign',title:'Unrelated',detail:'QA',route:'/project-plan/pp-other',actor:'Admin Konstruksi',at:'2026-11-04T04:00:00Z'},
    {id:'event-prefix',title:'Prefix collision',detail:'QA',route:'/project-plan/'+s.projects[0].id+'0',actor:'Admin Konstruksi',at:'2026-11-04T05:00:00Z'}];
  return s;
}
function term():Term {return {id:'qa-term',spkId:'spk-1',number:1,type:'Parsial',workflowVersion:3,approvalScheme:'project-support-first',status:'Diajukan',actuals:{},approvals:[],history:[],apm:'',apmStatus:'Belum Terbit',paid:0,paymentHistory:[]};}

test('one business Project ID admits independent PP numbers, BOQs and roots in the client preview',()=>{
  const s=state(),input=structuredClone(s.projects[0]);input.id=input.rootId='pp-shared-a';input.unit='Konstruksi';input.status='Draft';delete input.pmUserId;delete input.managerUserId;delete input.approverUserIds;
  saveProject(s,input);const second=structuredClone(input);second.id=second.rootId='pp-shared-b';second.boq[0].qty=77;saveProject(s,second);
  const a=getProject(s,input.id),b=getProject(s,second.id);assert.equal(a.projectCode,b.projectCode);assert.notEqual(a.no,b.no);assert.notEqual(a.rootId,b.rootId);assert.notEqual(a.boq[0].qty,b.boq[0].qty);
});

test('project timeline joins all PP siblings and exact related-document events without mutating saved state',()=>{
  const s=state(),before=JSON.stringify(s),data=projectTimeline(s,s.projects[0].id)!;
  assert.deepEqual(data.projects.map(p=>p.id),[s.projects[0].id,'pp-sibling']);assert.equal(data.deals.length,s.deals.length);assert.equal(data.spks.length,s.spks.length);
  assert.deepEqual(data.events.map(e=>e.id),['event-sibling','event-spk','event-pp']);assert.equal(JSON.stringify(s),before);
  assert.equal(projectTimeline(s,'missing'),undefined);s.projects[0].projectCode='';s.projects[1].projectCode='';assert.equal(projectTimeline(s,s.projects[0].id)!.projects.length,1);
});

test('timeline defaults to the clicked PP rather than the first PP sharing its Project ID',()=>{
  const s=state(),first=s.projects[0],clicked=s.projects.find(p=>p.id==='pp-sibling')!,before=JSON.stringify(s);
  const html=renderToStaticMarkup(createElement(ProjectTimeline,{s,id:clicked.id}));
  assert.ok(html.includes('<option value="'+clicked.id+'" selected="">'));
  assert.ok(html.includes('<h2>'+clicked.no+'</h2>'));
  assert.ok(html.includes('href="/project-plan/'+clicked.id+'"'));
  assert.ok(!html.includes('href="/project-plan/'+first.id+'"'));
  assert.ok(html.includes('Draft sibling'));
  assert.ok(!html.includes('PP diperbarui'));
  assert.ok(!html.includes('SPK disahkan'));
  assert.ok(html.includes('Seluruh PP pada ID Project ini'));
  assert.equal(JSON.stringify(s),before);
  // Direct links and reopening another PP must select that PP independently.
  const reopened=renderToStaticMarkup(createElement(ProjectTimeline,{s,id:first.id}));
  assert.ok(reopened.includes('<option value="'+first.id+'" selected="">'));
  assert.ok(reopened.includes('PP diperbarui'));
  assert.ok(!reopened.includes('Draft sibling</strong>'));
});

test('an unavailable timeline PP never falls back to the first accessible PP',()=>{
  const s=state(),html=renderToStaticMarkup(createElement(ProjectTimeline,{s,id:'missing-pp'}));
  assert.ok(html.includes('Project Plan tidak ditemukan'));
  assert.ok(!html.includes('project-timeline-card'));
});

test('current position distinguishes five approval levels, returned documents, scan, Finance and APM',()=>{
  const t=term();for(const role of APPROVERS_V3){assert.equal(paymentPosition(t),'Menunggu approval '+role);t.approvals.push({role,decision:'Setujui',at:'2026-11-04T01:00:00Z',note:'QA'});}
  t.status='Disetujui';assert.match(paymentPosition(t),/Admin Ajukan Payment/);
  t.scan={id:'qa-scan',name:'qa.pdf',extension:'pdf',mime:'application/pdf',size:1,sha256:'a'.repeat(64),uploadedAt:'2026-11-04T01:00:00Z',status:'Menunggu Verifikasi'};
  assert.equal(paymentPosition(t),'Menunggu approval Finance');t.scan.status='Perlu Koreksi';assert.match(paymentPosition(t),/unggah ulang/);
  t.scan.status='Valid';assert.match(paymentPosition(t),/scan terbaru/);t.financeApproval={role:'Finance',decision:'Setujui',at:'2026-11-04T02:00:00Z',note:'QA',scanId:'wrong'};assert.match(paymentPosition(t),/scan terbaru/);
  t.financeApproval.scanId=t.scan.id;assert.match(paymentPosition(t),/APM oleh Finance/);t.apmStatus='Valid';assert.match(paymentPosition(t),/tagihan lunas/);
  t.workflowVersion=1;assert.doesNotMatch(paymentPosition(t),/lunas/);t.status='Dikembalikan';assert.match(paymentPosition(t),/perbaikan Admin/);t.status='Ditolak';assert.match(paymentPosition(t),/berhenti/);
});

test('PP table removes Jenis PP and provides accessible timeline links for each PP',()=>{
  const s=state(),html=renderToStaticMarkup(createElement(Projects,{s}));assert.doesNotMatch(html,/<th[^>]*>Jenis PP<\/th>/);
  for(const p of s.projects){assert.ok(html.includes('/project-plan/'+p.id+'/timeline'));assert.ok(html.includes('aria-label="Timeline ID Project '+p.projectCode+' dari '+p.no+'"'));}
});

test('timeline renders current approval progress and preserves historical decisions without inventing approvals',()=>{
  const s=state(),p=s.projects[0];p.workflowVersion=3;p.approvalScheme='project-support-first';p.status='Diajukan';p.approvals=APPROVERS_V3.slice(0,2).map(role=>({role,actorName:'QA '+role,decision:'Setujui',note:'QA',at:'2026-11-04T02:00:00Z'}));p.approvalHistory=[...p.approvals];
  assert.equal(projectPosition(p),'Menunggu approval Mgr Project Support');const before=JSON.stringify(s),html=renderToStaticMarkup(createElement(ProjectTimeline,{s,id:p.id}));
  assert.ok(html.includes('Menunggu approval Mgr Project Support'));assert.ok(html.includes('2/5 approval PP'));assert.ok(html.includes('PP/QA/SIBLING'));assert.ok(!html.includes('Prefix collision'));assert.ok(!html.includes('Unrelated'));assert.equal(JSON.stringify(s),before);
  p.status='Dikembalikan';p.approvals=[];p.approvalHistory.push({role:'Mgr Deployment',actorName:'QA Return',decision:'Kembalikan',note:'Lengkapi dokumen QA',at:'2026-11-04T03:00:00Z'});
  const returned=renderToStaticMarkup(createElement(ProjectTimeline,{s,id:p.id}));assert.ok(returned.includes('0/5 approval PP'));assert.ok(returned.includes('Lengkapi dokumen QA'));assert.ok(returned.includes('QA Return'));
});

test('timeline relates term, scan and Finance histories through the PP exact SPK relationship',()=>{
  const s=state(),t=term();t.spkId=s.spks[0].id;t.status='Disetujui';t.financeHistory=[{role:'Finance',actorName:'QA Finance',decision:'Kembalikan',note:'Scan belum lengkap',at:'2026-11-04T04:00:00Z'}];s.terms.push(t);
  s.events.push({id:'event-term',title:'Scan perlu koreksi',detail:'QA',route:'/termin/'+t.id,actor:'Finance',at:'2026-11-04T04:00:00Z'});
  const data=projectTimeline(s,s.projects[0].id)!;assert.equal(data.terms.length,1);assert.equal(data.events[0].id,'event-term');
  const html=renderToStaticMarkup(createElement(ProjectTimeline,{s,id:s.projects[0].id}));assert.ok(html.includes('Scan belum lengkap'));assert.ok(html.includes('Verifikasi berkas · scan TTD fisik'));assert.ok(html.includes('/termin/'+t.id));
});

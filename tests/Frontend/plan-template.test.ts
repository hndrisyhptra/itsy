import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { applyPlanTemplate } from '../../resources/js/area3/plan-template';
import { PlanIntroduction, PlanSchedule } from '../../resources/js/area3/plan-document';
import { DocumentPreview } from '../../resources/js/area3/ui';
import { normalizeWorkflow, saveProject, getProject } from '../../resources/js/area3/domain';

test('template derives leap-year dates from Header and HK, ignoring client prose, team and date changes', () => {
  const p = structuredClone(seed('B').projects[0]); p.date = '2028-02-28'; p.intro = 'client'; p.team = [{name:'rekaan',job:'rekaan'}];
  p.schedule = [2,0,3,1,1].map(days => ({name:'client',days,start:'2000-01-01',end:'2000-01-02'}));
  const before = structuredClone(p), result = applyPlanTemplate(p,true);
  assert.deepEqual(p,before); assert.equal(result.durationDays,7); assert.equal(result.planTemplateVersion,1); assert.deepEqual(result.team,[]);
  assert.match(result.intro,/Mekanisme swakelola berpotensi meningkatkan margin/); assert.match(result.teamText!,/Teknisi\/Jointer, dan Mandor/);
  assert.deepEqual(result.schedule[0],{name:'Persiapan',days:2,start:'2028-02-28',end:'2028-02-29'});
  assert.deepEqual(result.schedule[1],{name:'Material Delivery',days:0,start:'',end:''});
  assert.equal(result.schedule[2].start,'2028-03-01'); assert.equal(result.schedule[4].end,'2028-03-05');
  for (const days of [-1,0.5,1000001,NaN]) { p.schedule[0].days=days; assert.throws(()=>applyPlanTemplate(p,true),/HK/); }
  p.schedule.forEach(row=>row.days=0); assert.throws(()=>applyPlanTemplate(p,true),/HK/);
  p.schedule.forEach(row=>row.days=1); p.date='2028-02-30'; assert.throws(()=>applyPlanTemplate(p,true),/Saat Penggunaan/);
  p.date='9999-12-31';p.schedule.forEach((row,i)=>row.days=i===0?1:0);assert.equal(applyPlanTemplate(p,true).schedule[0].end,'9999-12-31');
});

test('template tabs expose exactly five HK fields, fixed prose and derived dates', () => {
  const p=applyPlanTemplate(seed('B').projects[0]);
  const intro=renderToStaticMarkup(createElement(PlanIntroduction));
  assert.doesNotMatch(intro,/<input|<textarea|<select|<button/); assert.match(intro,/Latar Belakang/);assert.match(intro,/Lingkup Pekerjaan/);
  const html=renderToStaticMarkup(createElement(PlanSchedule,{p,editable:true,onChange:()=>{}}));
  assert.equal((html.match(/<input/g)??[]).length,5); assert.equal((html.match(/type="number"/g)??[]).length,5);
  assert.doesNotMatch(html,/<textarea|type="date"|<select/); assert.match(html,/step="1"/); assert.match(html,/Saat Penggunaan/);
  const locked=renderToStaticMarkup(createElement(PlanSchedule,{p,editable:false,onChange:()=>{}})); assert.equal((locked.match(/disabled=""/g)??[]).length,5);
});

test('template print maps Header values, HK and reference curve while historical preview keeps its contents', () => {
  const s=seed('B'), p=applyPlanTemplate({...s.projects[0],no:'0001/LG.000/TA-03R502/11-2026',name:'Proyek QA',budget:250000000});
  const html=renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'Project Plan',project:p,onClose:()=>{}}));
  for(const text of ['PROJECT PLAN SWAKELOLA KONSTRUKSI',p.no,p.name,'250.000.000','II. STRUKTUR ORGANISASI','III. JADWAL PROYEK','Jml HK','2020','belum dihitung','LAMPIRAN RENCANA PROYEK','BOQ Owner','Komunikasi','Manajemen Kualitas']) assert.ok(html.includes(text),text);
  assert.doesNotMatch(html,/Nama rekaan|Leonita|Proactive|23KT05R210-0031/);
  const original=structuredClone(s.projects[0]);original.intro='Pendahuluan historis tersimpan';
  const legacy=renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'Project Plan',project:original,onClose:()=>{}}));
  assert.match(legacy,/Pendahuluan historis tersimpan/); assert.doesNotMatch(legacy,/pp-template-document/);
});

test('saving a historical v2 PP in current workspace retains its own narrative and date rules', () => {
  const s=normalizeWorkflow(seed('B')),p=s.projects[0];s.schema=3;p.workflowVersion=2;p.unit='Konstruksi';p.status='Draft';p.pmUserId='user-1';p.managerUserId='user-2';p.intro='Redaksi historis';
  const schedule=structuredClone(p.schedule),team=structuredClone(p.team);
  saveProject(s,p,false);const saved=getProject(s,p.id);
  assert.equal(saved.intro,'Redaksi historis');assert.deepEqual(saved.schedule,schedule);assert.deepEqual(saved.team,team);assert.equal(saved.planTemplateVersion,undefined);
});

test('templated continuation appendix retains only its allocated BOQ total and planning narratives', () => {
  const s=seed(), original=s.projects.find(p=>p.parentId)!, p=applyPlanTemplate({...original,risk:'Risiko QA dipertahankan',communication:'Komunikasi QA dipertahankan',quality:'Kualitas QA dipertahankan'});
  const html=renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'Project Plan',project:p,onClose:()=>{}}));
  const total=html.match(/class="doc-total"[\s\S]*?<\/div>/)?.[0]??'';
  assert.match(total,/33\.695\.000/);assert.doesNotMatch(total,/134\.780\.000/);
  for(const text of [p.risk,p.communication,p.quality]) assert.ok(html.includes(text));
  assert.equal(original.planTemplateVersion,undefined);
});

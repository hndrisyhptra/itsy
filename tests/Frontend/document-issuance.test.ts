import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { type Project, blankDeal, blankSpk, blankProject, normalizeWorkflow, saveProject, getProject } from '../../resources/js/area3/domain';
import { DOCUMENT_DATE_POLICY, planningDates, projectIssueError, spkIssuanceError, issueDateSuggestion } from '../../resources/js/area3/document-issuance';
import { applyPlanTemplate } from '../../resources/js/area3/plan-template';
import { ProjectPlanDocument } from '../../resources/js/area3/plan-document';

test('shared issuance vectors enforce chronology and calendars with equal dates allowed',()=>{
  const s=seed('D');
  const vectors=JSON.parse(readFileSync(new URL('../Fixtures/document-issuance.json',import.meta.url),'utf8'));
  for(const v of vectors){
    const p={...s.projects[0],documentDatePolicy:DOCUMENT_DATE_POLICY,issueDate:v.pp,workStartDate:v.start,date:'2099-01-01',schedule:[]};
    const d={...s.deals[0],date:v.bakh},spk={...s.spks[0],date:v.spk},before=JSON.stringify([p,d,spk]);
    assert.equal(!spkIssuanceError(p,d,spk,true),v.valid,v.name);assert.equal(JSON.stringify([p,d,spk]),before);
  }
});

test('independent inclusive HK schedule never takes its start from Saat Penggunaan or forged stage dates',()=>{
  const p=planningDates({...seed('B').projects[0],issueDate:'2028-02-27',date:'2099-01-01',workStartDate:'2028-02-28'});
  p.schedule=[2,0,3,1,1].map(days=>({name:'FORGED',days,start:'2000-01-01',end:'2100-01-01'}));
  const result=applyPlanTemplate(p,true);
  assert.equal(result.schedule[0].end,'2028-02-29');assert.equal(result.schedule[1].start,'');assert.equal(result.schedule[4].end,'2028-03-05');assert.equal(result.date,'2099-01-01');assert.equal(result.durationDays,7);
  assert.deepEqual(applyPlanTemplate({...p,date:'2001-01-01'},true).schedule,result.schedule);
  const draft=applyPlanTemplate({...p,workStartDate:''},true,false);assert.ok(draft.schedule.every(row=>!row.start&&!row.end));
  assert.equal(projectIssueError(draft),'');assert.match(projectIssueError(draft,true),/Tanggal Mulai/);
  for(const days of [-1,.5,1000001])assert.throws(()=>applyPlanTemplate({...draft,schedule:[{name:'QA',days,start:'',end:''}]},true,false),/HK/);
});

test('new BA and SPK suggest ordered dates, and valid choices are never clamped by validation',()=>{
  const s=seed('D'),p=s.projects[0];Object.assign(p,{documentDatePolicy:DOCUMENT_DATE_POLICY,issueDate:'2026-11-02',workStartDate:'2026-11-04'});
  assert.equal(issueDateSuggestion(p,'bakh'),'2026-11-03');assert.equal(issueDateSuggestion(p,'spk'),'2026-11-04');
  const d=blankDeal(s,p.id);assert.equal(d.date,'2026-11-03');s.deals=[d];assert.equal(blankSpk(s,d.id).date,'2026-11-04');
  const v={...s.spks[0],date:'2026-11-05'};assert.match(spkIssuanceError(p,d,v,true),/tidak boleh melewati/);assert.equal(v.date,'2026-11-05');
  p.issueDate='2026-11-04';assert.equal(issueDateSuggestion(p,'bakh'),'2026-11-04');
});

test('PP preview omits the removed issue-date header while keeping the independent work period',()=>{
  const old=applyPlanTemplate(seed('B').projects[0]),before=JSON.stringify(old);
  const historical=renderToStaticMarkup(createElement(ProjectPlanDocument,{p:old}));assert.doesNotMatch(historical,/Tanggal Terbit PP/);
  const p=applyPlanTemplate(planningDates({...old,issueDate:'2026-11-02',workStartDate:'2026-11-04',date:'2026-11-01',schedule:[1,0,1,0,0].map(days=>({name:'',days,start:'',end:''}))}),true);
  const html=renderToStaticMarkup(createElement(ProjectPlanDocument,{p}));
  assert.doesNotMatch(html,/Tanggal Terbit PP|2 Nov 2026/);
  for(const text of ['4 Nov 2026','5 Nov 2026','1 Nov 2026'])assert.ok(html.includes(text),text);
  assert.equal(JSON.stringify(old),before);
  const legacy:Project={...old},d={...seed('D').deals[0],date:'2099-01-01'},v={...seed('D').spks[0],date:'2100-01-01'};delete d.documentDatePolicy;delete v.documentDatePolicy;assert.equal(spkIssuanceError(legacy,d,v,true),'');
  assert.equal(planningDates(legacy).workStartDate,'');assert.equal(planningDates({...legacy,schedule:[]},legacy).workStartDate,legacy.schedule[0].start);
});

test('PP uses the visible Header date, ignores a forged hidden issue date, and usage edits do not move work dates',()=>{
  const s=normalizeWorkflow(seed('B'));s.schema=3;s.actorUserId=s.users.find(u=>u.role==='Admin Konstruksi')!.id;
  const p={...blankProject(),name:'Date persistence QA',unit:'Konstruksi',branch:s.branches![0].name,projectCode:'DATE-QA',date:'2026-11-02',issueDate:'2099-01-01',workStartDate:'2026-11-04'};
  saveProject(s,p,false);const old=getProject(s,p.id),input={...structuredClone(old),date:'2026-11-03'};delete input.issueDate;delete input.workStartDate;
  saveProject(s,input,false);const saved=getProject(s,p.id);assert.equal(saved.issueDate,'2026-11-03');assert.equal(saved.workStartDate,'2026-11-04');assert.deepEqual(saved.schedule,old.schedule);
});

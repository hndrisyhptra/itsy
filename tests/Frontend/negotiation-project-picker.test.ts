import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { localPlans, localProjects, planChoice } from '../../resources/js/area3/negotiation-projects';
import { NegotiationProjectPicker, PlanChoices } from '../../resources/js/area3/negotiation-project-picker';
import { DealEditor } from '../../resources/js/area3/contracts';

test('new BA KH starts with a search and explicit PP choice, never the first eligible PP or long dropdown',()=>{
  const s=seed('B'),before=JSON.stringify(s);
  const html=renderToStaticMarkup(createElement(DealEditor,{s,id:'new'}));
  assert.match(html,/Cari ID Project atau nama pekerjaan/);
  assert.match(html,/minimal 2 karakter/);
  assert.doesNotMatch(html,/<select|Penugasan Mandor &amp; Lingkup|Lanjut Negosiasi|Nama Mandor/);
  assert.equal(JSON.stringify(s),before);
  s.role='Finance';
  const readOnly=renderToStaticMarkup(createElement(DealEditor,{s,id:'new'}));
  assert.match(readOnly,/hanya untuk Admin Konstruksi/);
  assert.doesNotMatch(readOnly,/Cari ID Project atau nama pekerjaan/);
});

test('search groups all PPs under the same ID, matches sibling activities and paginates without choosing one',()=>{
  const s=seed('B'),p=s.projects[0];s.projects=[];
  for(let i=0;i<120;i++)s.projects.push({...structuredClone(p),id:'choice-'+i,rootId:'choice-'+i,projectCode:'BULK-'+String(i%30).padStart(3,'0'),name:i===90?'Needle activity':'Other work',no:'PP-'+i});
  const before=JSON.stringify(s),found=localProjects(s,'Needle',1);
  assert.equal(found.items.length,1);assert.equal(found.items[0].ppCount,4);assert.equal(found.items[0].name,'Needle activity');
  const a=localProjects(s,'BULK',1),b=localProjects(s,'BULK',2);
  assert.equal(a.items.length,20);assert.equal(a.hasMore,true);assert.equal(b.items.length,10);assert.equal(b.hasMore,false);
  assert.equal(new Set([...a.items,...b.items].map(p=>p.id)).size,30);
  assert.equal(localProjects(s,'a',1).items.length,0);
  assert.equal(localProjects(s,'missing',1).items.length,0);
  assert.equal(localPlans(s,found.items[0].code,1).items.length,4);
  assert.equal(JSON.stringify(s),before);
});

test('eligibility explains approval, BOQ and exhausted volume while preserving continuation-owned capacity',()=>{
  const s=seed('B'),p=s.projects[0];
  assert.equal(planChoice(s,p).eligible,true);
  assert.equal(planChoice(s,{...p,status:'Draft'}).reason,'PP belum Sah.');
  assert.equal(planChoice(s,{...p,boqStatus:'Review'}).reason,'BOQ belum Final.');
  const exhausted=seed('C');
  // Cover complete exhaustion of both components, not a mixed-unit sum.
  for(const row of exhausted.deals[0].rows){row.m=row.capM!==null;row.s=row.capS!==null;row.qm=row.m?exhausted.projects[0].boq.find(b=>b.id===row.rowId)!.qty:0;row.qs=row.s?exhausted.projects[0].boq.find(b=>b.id===row.rowId)!.qty:0;}
  assert.equal(planChoice(exhausted,exhausted.projects[0]).reason,'Volume tersedia sudah habis.');
  const childState=seed('overview'),child=childState.projects.find(p=>p.parentId)!;
  child.status='Sah';child.boqStatus='Final';
  assert.equal(planChoice(childState,child).eligible,true);
});

test('compact PP table has explicit selection, disabled reasons, accessible state and escaped user text',()=>{
  const s=seed('B'),p=s.projects[0],choice=planChoice(s,p),blocked=planChoice(s,{...p,id:'draft',status:'Draft',name:'<script>bad</script>'});
  const html=renderToStaticMarkup(createElement(PlanChoices,{data:{items:[choice,blocked],page:1,hasMore:false},picked:choice,onPick:()=>{}}));
  assert.match(html,/No PP/);assert.match(html,/Nama Kegiatan/);assert.match(html,/Branch/);assert.match(html,/aria-pressed="true"/);
  assert.match(html,/PP belum Sah/);assert.match(html,/disabled=""/);assert.match(html,/&lt;script&gt;bad&lt;\/script&gt;/);
  assert.doesNotMatch(html,/<select|<script>/);
});

test('existing BA KH keeps its own PP read-only and a new search does not mutate or restore another PP form',()=>{
  const s=seed('C'),before=JSON.stringify(s);
  const old=renderToStaticMarkup(createElement(DealEditor,{s,id:s.deals[0].id}));
  assert.match(old,/Project Plan terpilih/);assert.match(old,/readonly=""/i);assert.doesNotMatch(old,/Ganti PP|<select/);
  const fresh=renderToStaticMarkup(createElement(NegotiationProjectPicker,{s,onSelect:()=>{}}));
  assert.match(fresh,/value=""/);assert.doesNotMatch(fresh,/PP terpilih|Nama Mandor/);
  assert.equal(JSON.stringify(s),before);
});

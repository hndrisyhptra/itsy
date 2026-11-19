import test from 'node:test';
import assert from 'node:assert/strict';
import {seed} from '../../resources/js/area3/seed';
import {ROLES,canApproveProject,canApproveTerm,operational,type State} from '../../resources/js/area3/domain';
import {canReviewLegacySettlement} from '../../resources/js/area3/legacy-settlement-review';
import {adminKasbonPending} from '../../resources/js/area3/finance-payment';
import {financeTaskPending} from '../../resources/js/area3/partial-payment';
import {workspaceSearch,workspaceInboxCount} from '../../resources/js/area3/shell-read-model';

function originalSearch(s:State,query:string){return query.trim()?[
 ...s.projects.filter(p=>`${p.no} ${p.name}`.toLowerCase().includes(query.toLowerCase())).map(p=>({title:p.no,detail:p.name,route:'/project-plan/'+p.id})),
 ...s.spks.filter(v=>{const d=s.deals.find(d=>d.id===v.dealId);return d&&`${v.no} ${d.mandor}`.toLowerCase().includes(query.toLowerCase());}).map(v=>({title:v.no,detail:s.deals.find(d=>d.id===v.dealId)!.mandor,route:'/spk/'+v.id})),
].slice(0,6):[];}
test('indexed capped search retains PP-first ordering, exact UUID, case/spacing and empty behavior',()=>{
 for(const name of ['overview','B','D','E']){
  const s=seed(name),before=JSON.stringify(s);
  for(const query of ['',' ','p','PP','SPK','Surabaya','Budi',' PP','nonexistent'])assert.deepEqual(workspaceSearch(s,query),originalSearch(s,query));
  const source=s.spks[0];if(source){s.spks.push({...source,id:'detached',dealId:'not-loaded'});assert.doesNotThrow(()=>workspaceSearch(s,'SPK'));}
  s.spks=s.spks.filter(v=>v.id!=='detached');assert.equal(JSON.stringify(s),before);
 }
});
test('large search stops after six PP matches without touching unrelated SPK or deal records',()=>{
 const s=seed('E'),p=s.projects[0];let visits=0;
 s.projects=Array.from({length:10000},(_,i)=>({...p,id:'p-'+i,get no(){visits++;return 'MATCH-'+i;}}));
 Object.defineProperty(s,'deals',{get(){throw Error('Unrelated deal scan');}});Object.defineProperty(s,'spks',{get(){throw Error('Unrelated SPK scan');}});
 const found=workspaceSearch(s,'match');assert.equal(found.length,6);assert.equal(visits,12); // matching and result title read exactly six rows each.
 assert.deepEqual(found.map(r=>r.route),Array.from({length:6},(_,i)=>'/project-plan/p-'+i));
});
test('SPK search uses exact deal map and the inbox count preserves actual role/actor and per-term OR semantics',()=>{
 const s=seed('E'),source=s.spks[0],deal=s.deals[0];s.projects=[];
 s.deals=Array.from({length:5000},(_,i)=>({...deal,id:'d-'+i,mandor:'MANDOR-'+i}));s.spks=Array.from({length:5000},(_,i)=>({...source,id:'v-'+i,dealId:'d-'+i,no:'SPK-'+i}));
 assert.deepEqual(workspaceSearch(s,'MANDOR-4999'),[{title:'SPK-4999',detail:'MANDOR-4999',route:'/spk/v-4999'}]);
 for(const scenario of ['overview','D','E'])for(const role of ROLES){const state=seed(scenario);state.role=role;state.actorUserId=state.users.find(u=>u.role===role&&u.active)?.id;
  const before=JSON.stringify(state),expected=state.projects.filter(p=>canApproveProject(state,p)).length+state.terms.filter(t=>canApproveTerm(state,t)||canReviewLegacySettlement(state,t)||(role==='Finance'&&financeTaskPending(t))||(operational(state)&&adminKasbonPending(t))).length;
  assert.equal(workspaceInboxCount(state),expected);assert.equal(JSON.stringify(state),before);
 }
});

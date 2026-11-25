import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {applyBudgetPlan} from '../../resources/js/area3/plan-budget';
import {MANDOR_BUDGET_POLICY,OWNER_BUDGET_POLICY,pidBudgetCosts,tracksPidBudget} from '../../resources/js/area3/budget-pid-policy';
import {PidReferenceView} from '../../resources/js/area3/budget-pid-reference';
const s=seed('B'),base=applyBudgetPlan(s,s.projects[0]);
const p={...base,budgetPidPolicy:MANDOR_BUDGET_POLICY,planBudgetTotals:{...base.planBudgetTotals!,ownerM:9000,ownerS:10000,ownerTotal:19000,mandorM:400,mandorS:500,mandor:900,material:1700,bop:50,total:2250}};
const limits={TOTAL:1000,Material:500,'Non Material':600,Operasional:100},zero={TOTAL:0,Material:0,'Non Material':0,Operasional:0};
const result={status:'found' as const,pid:p.projectCode,unit:p.unit,branch:p.branch,name:'QA PID',import_id:'qa-import',updated_at:'2026-11-25',available:limits,remaining:limits,application_used:zero,new_pp_only:true as const};
const lookup={result,error:undefined,loading:false,blocked:false,nameLocked:true,adopt:()=>{},refresh:()=>{}};

test('PID caps use gross Mandor M/J total and separate BOP, never Owner, nonstock additions, Header or PPh',()=>{
 const before=JSON.stringify(p),costs=pidBudgetCosts(p);assert(costs.ready);assert(costs.mandor);
 assert.deepEqual(costs.amounts,{TOTAL:900,Material:400,'Non Material':500,Operasional:50});
 const html=renderToStaticMarkup(createElement(PidReferenceView,{p,lookup}));assert.match(html,/Material dan Jasa bruto dari BOQ TA - Mandor/);assert.doesNotMatch(html,/error-text/);assert.doesNotMatch(html,/19\.000/);assert.equal(JSON.stringify(p),before);
 const smaller={...lookup,result:{...result,remaining:{...limits,TOTAL:899,Material:399,'Non Material':499}}};
 const warning=renderToStaticMarkup(createElement(PidReferenceView,{p,lookup:smaller}));assert.equal((warning.match(/error-text/g)||[]).length,3);
});

test('before positive Mandor input reference remains visible without falsely validating uploaded Owner values',()=>{
 const empty={...p,planBudgetTotals:{...p.planBudgetTotals,mandorM:0,mandorS:0,mandor:0}};
 assert.equal(pidBudgetCosts(empty).ready,false);
 const html=renderToStaticMarkup(createElement(PidReferenceView,{p:empty,lookup}));assert.match(html,/Validasi nominal budget dilakukan setelah BOQ Mandor diisi/);assert.match(html,/Available Excel/);assert.match(html,/1\.000/);assert.equal((html.match(/>—<\/td>/g)||[]).length,8);assert.doesNotMatch(html,/error-text/);
 for(const [m,j] of [[0,500],[400,0]])assert(pidBudgetCosts({...p,planBudgetTotals:{...p.planBudgetTotals,mandorM:m,mandorS:j}}).ready);
});

test('frozen v1 retains Owner reference while editable v1 previews its next Mandor save without modifying policy or history',()=>{
 const old={...p,budgetPidPolicy:OWNER_BUDGET_POLICY},before=JSON.stringify(old);
 assert(tracksPidBudget(old));assert(tracksPidBudget(p));assert(!tracksPidBudget({...p,budgetPidPolicy:undefined}));
 assert.deepEqual(pidBudgetCosts(old).amounts,{TOTAL:19000,Material:9000,'Non Material':10000,Operasional:50});
 assert.deepEqual(pidBudgetCosts(old,true).amounts,pidBudgetCosts(p).amounts);
 const frozen=renderToStaticMarkup(createElement(PidReferenceView,{p:old,lookup}));assert.match(frozen,/alokasi historis BOQ Owner/);
 const edited=renderToStaticMarkup(createElement(PidReferenceView,{p:old,lookup,editing:true}));assert.match(edited,/Material dan Jasa bruto dari BOQ TA - Mandor/);assert.doesNotMatch(edited,/19\.000/);assert.equal(JSON.stringify(old),before);
});

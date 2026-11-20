import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {VolumeInput} from '../../resources/js/area3/volume-input';
import {isWholeVolume,parseVolumeInput,MAX_VOLUME} from '../../resources/js/area3/whole-volume';
import {seed} from '../../resources/js/area3/seed';
import {DealEditor} from '../../resources/js/area3/contracts';
import {TermEditor} from '../../resources/js/area3/payments';
import {BoqDraftEditor} from '../../resources/js/area3/workflow-ui';
import {BudgetPlanSection} from '../../resources/js/area3/plan-budget-ui';
import {blankDeal,saveDeal,createTerm,saveTerm,getDeal,termTotal,remaining,releaseVolume,createContinuation,attestBapp} from '../../resources/js/area3/domain';

test('physical input accepts only whole ASCII digits and never removes a decimal separator to change its meaning',()=>{
 for(const [raw,value] of [['',0],['0',0],['1',1],['0012',12],['1000000000',MAX_VOLUME]] as const)assert.equal(parseVolumeInput(raw),value);
 for(const raw of ['1,5','1.5','1.000','1e3','-1','+1',' 12','12 ','1\n','1\t2','١٢','1000000001','Infinity','NaN'])assert.equal(parseVolumeInput(raw),null,raw);
 for(const v of [-1,.001,1.5,Infinity,NaN,MAX_VOLUME+1])assert.equal(isWholeVolume(v),false);
});
test('shared volume control uses numeric keyboard and digit-only pattern, labels, disabled state and preserves old fractions visibly',()=>{
 const render=(value:number,disabled=false)=>renderToStaticMarkup(createElement(VolumeInput,{value,disabled,label:'Volume Material QA',onChange:()=>{throw Error('Render must not change state');}}));
 const html=render(12);assert.match(html,/type="text"/);assert.match(html,/inputMode="numeric"/);assert.match(html,/pattern="\[0-9\]\*"/);assert.match(html,/aria-label="Volume Material QA"/);assert.doesNotMatch(html,/step="0\.001"/);
 const old=render(1.5);assert.match(old,/value="1.5"/);assert.match(old,/aria-invalid="true"/);assert.match(old,/bilangan bulat/);
 const locked=render(1.5,true);assert.match(locked,/disabled=""/);assert.match(locked,/value="1.5"/);assert.doesNotMatch(locked,/role="status"/);
});
test('agreement and payment mutations reject new fractions but retain actual previously approved snapshot values',()=>{
 const s=seed('B'),deal=blankDeal(s,s.projects[0].id);deal.mandor='QA';deal.rows[0].m=true;deal.rows[0].qm=1.5;
 const before=JSON.stringify(s);assert.throws(()=>saveDeal(s,deal),/bilangan bulat/);assert.equal(JSON.stringify(s),before);
 const work=seed('D'),id=createTerm(work,work.spks[0].id),t=work.terms.find(t=>t.id===id)!,row=getDeal(work,work.spks[0].dealId).rows[0];
 t.actuals[row.rowId]={m:1.5,s:0};assert.throws(()=>saveTerm(work,t),/bilangan bulat/);
 // Loaded historical values are read at their saved precision, without adoption or new decisions.
 t.status='Disetujui';const historical=JSON.stringify(work);assert.equal(termTotal(work,t),1.5*row.pm);assert.equal(remaining(work,t.spkId,row,'m'),row.qm-1.5);assert.equal(JSON.stringify(work),historical);
});
test('whole volume restriction covers release and continuation without mutating a valid ledger on rejection',()=>{
 const s=seed('E'),b=s.bapps[0],row=s.deals[0].rows[0].rowId;attestBapp(s,b.id,b.no);
 const before=JSON.stringify(s);assert.throws(()=>releaseVolume(s,b.id,{[row]:{m:.5,s:0}}),/bilangan bulat/);assert.equal(JSON.stringify(s),before);
 releaseVolume(s,b.id,{[row]:{m:10,s:0}});const released=JSON.stringify(s);assert.throws(()=>createContinuation(s,b.id,'PP/WHOLE/QA',{[row]:{m:.5,s:0}},''),/bilangan bulat/);assert.equal(JSON.stringify(s),released);
});
test('actual BOQ, negotiation, budget and BA RP/payment forms render the shared digit-only volume field',()=>{
 const s=seed('C');s.deals[0].status='Draft';
 const agreement=renderToStaticMarkup(createElement(DealEditor,{s,id:s.deals[0].id}));
 const boq=renderToStaticMarkup(createElement(BoqDraftEditor,{s,p:s.projects[0],disabled:false,onChange:()=>{}}));
 const budget=renderToStaticMarkup(createElement(BudgetPlanSection,{s,p:s.projects[0],editable:true,onChange:()=>{}}));
 const work=seed('D'),id=createTerm(work,work.spks[0].id);
 const barp=renderToStaticMarkup(createElement(TermEditor,{s:work,id,stage:'work'}));
 const payment=renderToStaticMarkup(createElement(TermEditor,{s:work,id}));
 for(const [name,html] of Object.entries({agreement,boq,budget,barp,payment})){
  const fields=[...html.matchAll(/<input\b[^>]*aria-label="(?:Volume |Aktual )[^>]*>/g)].map(match=>match[0]);
  assert.ok(fields.length>0,name);
  for(const field of fields){assert.match(field,/type="text"/,name);assert.match(field,/inputMode="numeric"/,name);assert.match(field,/pattern="\[0-9\]\*"/,name);}
 }
});

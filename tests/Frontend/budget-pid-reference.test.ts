import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement as h} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
Object.assign(globalThis,{window:{addEventListener:()=>{},__AREA3__:{account:{id:'qa-admin',role:'Admin Konstruksi'},csrf:'qa',basePath:'/its-area3'}}});
const {readPidReference,pidDraftChange,samePidCount,PidReferenceView,pidScope}=await import('../../resources/js/area3/budget-pid-reference');
const {BudgetBranchMapping}=await import('../../resources/js/area3/budget-pid-branch');
const {mapBudgetBranches,BudgetRequestError}=await import('../../resources/js/area3/budgeting-pid-api');
const s=seed('D'),p={...s.projects[0],unit:'Konstruksi',branch:'SURABAYA',projectCode:'PID-X',name:'Nama tersimpan'};
const found={status:'found' as const,pid:'PID-X',unit:'Konstruksi',branch:'SURABAYA',name:'Nama Master < >',import_id:'batch-1',updated_at:'2026-11-24',available:{TOTAL:100000,Material:50000,'Non Material':40000,Operasional:10000}};

test('PID reference is exact current origin/Branch/unit, no-store and abortable, and rejects mismatched responses',async()=>{
 const old=fetch,signal=new AbortController().signal;
 try{
  globalThis.fetch=async(url,opt)=>{assert.equal(url,'/its-area3/api/budgeting-pid/reference?pid=PID-X&unit=Konstruksi&branch=SURABAYA');assert.equal(opt?.credentials,'same-origin');assert.equal(opt?.cache,'no-store');assert.equal(opt?.signal,signal);return Response.json(found);};
  assert.deepEqual(await readPidReference({...p,projectCode:' pid-x '},signal),found);
  for(const data of [{...found,unit:'Operation'},{...found,branch:'OTHER'},{...found,pid:'OTHER'},{...found,available:{}},{...found,status:'invalid'}]){globalThis.fetch=async()=>Response.json(data);await assert.rejects(()=>readPidReference(p));}
  globalThis.fetch=async()=>Response.json({message:'Forbidden'},{status:403});await assert.rejects(()=>readPidReference(p),/Forbidden/);
 }finally{globalThis.fetch=old;}
});

test('scope changes clear adopted master name and selection but keep manual name; duplicate count separates units/existing',()=>{
 const adopted={...p,budgetPidSelection:{pid:'PID-X',unit:p.unit,branch:p.branch,importId:'batch'}};
 for(const change of [{unit:'Operation'},{branch:'OTHER'},{projectCode:'NEXT'}]){const next=pidDraftChange(adopted,change);assert.equal(next.budgetPidSelection,undefined);assert.equal(next.name,'');assert.notEqual(pidScope(next),pidScope(p));}
 const manual={...p,budgetPidSelection:{pid:'PID-X',unit:p.unit,branch:p.branch,importId:null}};
 assert.equal(pidDraftChange(manual,{projectCode:'UNKNOWN'}).name,p.name);
 const state={...s,projects:[p,{...p,id:'different',projectCode:' pid-x '},{...p,id:'ops',unit:'Operation'}]};
 assert.equal(samePidCount(state,p),1);assert.equal(samePidCount(state,p,p),0);
});

test('reference output has four independent amounts; unknown/unmapped/conflict never invent zero budgets or leak name',()=>{
 const base={error:undefined,loading:false,refresh:()=>{},adopt:()=>{},blocked:false,nameLocked:false};
 const html=renderToStaticMarkup(h(PidReferenceView,{p,lookup:{...base,result:found},duplicate:2}));assert.match(html,/Nama Master &lt; &gt;/);assert.match(html,/Material Owner/);assert.match(html,/Jasa Owner/);assert.match(html,/BOP/);assert.match(html,/belum dikurangi alokasi PP lain/);assert.match(html,/mandor berbeda/);assert.match(html,/data-confirm="Gunakan Nama Kegiatan/);
 for(const status of ['not_found','branch_unmapped','unit_conflict','branch_conflict'] as const){const markup=renderToStaticMarkup(h(PidReferenceView,{p,lookup:{...base,result:{pid:p.projectCode,branch:p.branch,unit:p.unit,status}}}));assert(!markup.includes('<table>'));assert(!markup.includes('Nama Master'));assert(!markup.includes('Rp'));}
});

test('mapping uses explicit IDs/Branch/version/UUID/CSRF; retry remains manual and dialog states actual mapping scope',async()=>{
 const old=fetch;try{let calls=0;const payload={unit:'Konstruksi' as const,ids:['pid-uuid'],branch_id:1,version:2,request_id:'same-uuid'};
 globalThis.fetch=async(url,opt)=>{calls++;assert.equal(url,'/its-area3/api/budgeting-pid/branch');assert.equal((opt?.headers as Record<string,string>)['X-CSRF-TOKEN'],'qa');assert.deepEqual(JSON.parse(String(opt?.body)),payload);return Response.json({message:'uncertain'},{status:500});};
 await assert.rejects(()=>mapBudgetBranches(payload),e=>e instanceof BudgetRequestError&&e.uncertain);assert.equal(calls,1);
 }finally{globalThis.fetch=old;}
 const row={...p,unit:'Konstruksi' as const,branch:null,id_budget_pid:'pid-uuid',pid:p.projectCode,warnings:[],updated_at:'2026-11-24',items:{} as any};
 const html=renderToStaticMarkup(h(BudgetBranchMapping,{unit:'Konstruksi',rows:[row],version:2,branches:[{id_branch:1,name:'SURABAYA'}],onClose:()=>{},onComplete:()=>{}}));assert.match(html,/Kode Rxxx tidak digunakan/);assert.match(html,/Branch Tujuan/);assert.match(html,/data-confirm="Petakan 1 PID/);assert.match(html,/Belum dipetakan/);
});

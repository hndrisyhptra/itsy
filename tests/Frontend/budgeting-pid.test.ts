import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement as h} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';

Object.assign(globalThis,{window:{addEventListener:()=>{},__AREA3__:{account:{id:'db-user-1',role:'Finance'},csrf:'qa-csrf',basePath:'/its-area3'}}});
const {loadBudget,importBudget,BudgetRequestError}=await import('../../resources/js/area3/budgeting-pid-api');
const {BudgetPreviewTable,BudgetSnapshotTable,BudgetImportModal,canManageBudget}=await import('../../resources/js/area3/budgeting-pid');
const item={source_title:'PID',source_row:2,release:1000,commitment:100,actual:200,available:700};
const snapshot={name:'Kegiatan <script>unsafe</script>',unit:'Operation' as const,items:{TOTAL:item,Material:item,'Non Material':item,Operasional:item,'Tenaga Kerja':item,Depresiasi:item}};
const preview={unit:'Operation' as const,version:2,preview_hash:'a'.repeat(64),sha256:'b'.repeat(64),summary:{created:1,updated:0,unchanged:0,warnings:0,pids:1},rows:[{pid:'26KT03R501-0001',name:snapshot.name,row:2,action:'created' as const,available:{TOTAL:700,Material:700,'Non Material':700,Operasional:700},warnings:['TOTAL defisit <b>warning</b>']}]};

test('budget master role boundary, preview pagination and paper-safe text do not change workflow',()=>{
  for(const role of ['Finance','Superadmin'])assert(canManageBudget(role));
  for(const role of ['Admin Konstruksi','Mgr Finance & Settlement','Admin Operation','Head of PMO'])assert(!canManageBudget(role));
  const html=renderToStaticMarkup(h(BudgetPreviewTable,{rows:preview.rows}));
  assert.match(html,/Non Material \/ Jasa/);assert.match(html,/Operasional \/ BOP/);assert.match(html,/Baris 2/);assert.match(html,/&lt;script&gt;/);assert(!html.includes('<script>'));assert.match(html,/&lt;b&gt;warning/);
  const detail=renderToStaticMarkup(h(BudgetSnapshotTable,{value:snapshot}));assert.match(detail,/Tot.cst.cm/);assert.match(detail,/Tenaga Kerja dan Depresiasi disimpan/);assert.equal((detail.match(/<tr>/g)||[]).length,5);
  const modal=renderToStaticMarkup(h(BudgetImportModal,{initialUnit:'Operation',units:['Operation'],onClose:()=>{},onComplete:()=>{}}));assert.match(modal,/10.000 PID/);assert.match(modal,/20 MB/);assert.match(modal,/data-confirm="Unggah dan periksa/);assert.match(modal,/data-confirm="Simpan hasil impor/);assert(!modal.includes('<option>Konstruksi'));
});

test('budget list and import use same-origin scoped URLs, no-store, CSRF, preview fingerprint and same request UUID',async()=>{
  const prior=fetch,signal=new AbortController().signal;
  try{
    globalThis.fetch=async(url,options)=>{assert.equal(url,'/its-area3/api/budgeting-pid?unit=Operation&q=Kegiatan&page=2&history=1&import_id=import-uuid');assert.equal(options?.cache,'no-store');assert.equal(options?.credentials,'same-origin');assert.equal(options?.signal,signal);return Response.json({rows:[]});};
    await loadBudget('Operation','Kegiatan',2,true,'import-uuid',signal);
    let count=0;globalThis.fetch=async(url,options)=>{count++;assert.equal(url,'/its-area3/api/budgeting-pid/import');assert.equal(options?.method,'POST');assert.equal((options?.headers as Record<string,string>)['X-CSRF-TOKEN'],'qa-csrf');const form=options?.body as FormData;assert.equal(form.get('unit'),'Operation');assert.equal(form.get('stage'),count===1?'preview':'commit');assert.equal(form.get('actor_user_id'),null);if(count===2){assert.equal(form.get('version'),'2');assert.equal(form.get('preview_hash'),preview.preview_hash);assert.equal(form.get('request_id'),'same-request-id');}return Response.json(count===1?preview:{version:3,summary:preview.summary});};
    const file=new File(['xlsx-fixture'],'budget.xlsx');await importBudget(file,'Operation',null,'');await importBudget(file,'Operation',preview,'same-request-id');assert.equal(count,2);
  }finally{globalThis.fetch=prior;}
});

test('budget failed imports never retry automatically; network and 5xx remain uncertain while conflict requires preview',async()=>{
  const prior=fetch,file=new File(['fixture'],'budget.xlsx');
  try{
    for(const status of [401,403,419,422,429,409,500]){let calls=0;globalThis.fetch=async()=>{calls++;return Response.json({message:'Data berubah'},{status});};await assert.rejects(()=>importBudget(file,'Operation',preview,'uuid'),e=>e instanceof BudgetRequestError&&e.status===status&&e.uncertain===(status>=500));assert.equal(calls,1);}
    let calls=0;globalThis.fetch=async()=>{calls++;throw Error('offline');};await assert.rejects(()=>importBudget(file,'Operation',preview,'uuid'),e=>e instanceof BudgetRequestError&&e.uncertain);assert.equal(calls,1);
  }finally{globalThis.fetch=prior;}
});

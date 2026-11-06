import test from 'node:test';
import assert from 'node:assert/strict';
import { seed } from '../../resources/js/area3/seed';

test('bulk import uses authenticated multipart/CSRF, keeps preview read-only, retries same request identity and publishes only confirmed state',async()=>{
  const state=seed('B');state.role='Superadmin';
  const win=new EventTarget();Object.assign(win,{__AREA3__:{state,revision:4,csrf:'csrf-initial',basePath:'/its/area3-prototype/public',scenarios:{},account:{id:'db-user-qa',name:'QA',role:'Superadmin',email:'qa@example.test',branches:[]},urls:{state:'/state',commands:'/commands',csv:'/csv',backup:'/backup'}}});
  const originals=new Map(['window','fetch'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
  const requests:{url:string;options:RequestInit;form:FormData}[]=[];
  let fail=true;
  const preview={revision:4,sha256:'a'.repeat(64),summary:{created:2,updated:0,unchanged:0},rows:[]};
  Object.defineProperty(globalThis,'window',{configurable:true,value:win});
  Object.defineProperty(globalThis,'fetch',{configurable:true,value:async(url:string,options:RequestInit)=>{
    const form=options.body as FormData;requests.push({url,options,form});
    if(form.get('stage')==='preview')return Response.json(preview);
    if(fail){fail=false;throw new Error('Koneksi terputus');}
    return Response.json({state,revision:5,csrf:'csrf-next',catalog:{designators:[],packages:[],prices:[],types:[]},summary:preview.summary,replayed:true});
  }});
  try{
    const store=await import('../../resources/js/area3/store');
    const file=new File(['test'],'bulk.xlsx');
    // A stale screen can inspect current data; commit must use the returned revision.
    const checked=await store.previewMasterImport('designator',file,'create',1);
    assert.equal(checked.sha256,preview.sha256);assert.equal(store.persistencePending,false);assert.equal(requests[0].form.get('stage'),'preview');
    assert.equal(requests[0].form.get('revision'),'1');assert.equal(checked.revision,4);
    const requestId=crypto.randomUUID();
    await assert.rejects(store.commitMasterImport('designator',file,'create',checked,requestId),/Koneksi terputus/);
    assert.equal(store.persistencePending,false);
    const saved=await store.commitMasterImport('designator',file,'create',checked,requestId);
    assert.equal(saved.revision,5);assert.equal(saved.replayed,true);
    assert.equal(requests[1].form.get('request_id'),requestId);assert.equal(requests[2].form.get('request_id'),requestId);
    assert.equal(requests[2].form.get('revision'),'4');assert.equal(requests[2].form.get('sha256'),preview.sha256);
    assert.equal(requests[2].options.credentials,'same-origin');assert.equal((requests[2].options.headers as Record<string,string>)['X-CSRF-TOKEN'],'csrf-initial');
    assert.equal(requests[2].url,'/its/area3-prototype/public/api/masters/designator/import');
    assert.equal(requests.length,3); // No additional full catalog/state fetch to recover the revision.
    await store.previewMasterImport('khs',file,'upsert',5);
    assert.equal((requests[3].options.headers as Record<string,string>)['X-CSRF-TOKEN'],'csrf-next');
  }finally{for(const [key,descriptor]of originals){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else Reflect.deleteProperty(globalThis,key);}}
});

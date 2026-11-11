import test from 'node:test';
import assert from 'node:assert/strict';
import { seed } from '../../resources/js/area3/seed';
import { normalizeWorkflow, setNumberFormat } from '../../resources/js/area3/domain';

test('same-origin inbox reads preserve drafts, skip stale responses and cannot overwrite a concurrent confirmed write',async()=>{
  const state=normalizeWorkflow(seed('B')),keys=['window','localStorage','fetch'];
  const originals=new Map(keys.map(k=>[k,Object.getOwnPropertyDescriptor(globalThis,k)])),win=new EventTarget(),cache=new Map<string,string>();
  const base='/its/area3-prototype/public';
  Object.assign(win,{__AREA3__:{state,revision:0,csrf:'qa',basePath:base,scenarios:{},account:{id:'qa',name:'QA',role:'Admin Konstruksi',branches:[state.projects[0].branch]},urls:{state:'http://untrusted/state'}}});
  Object.defineProperty(globalThis,'window',{configurable:true,value:win});
  Object.defineProperty(globalThis,'localStorage',{configurable:true,value:{getItem:(k:string)=>cache.get(k)??null,setItem:(k:string,v:string)=>cache.set(k,v),removeItem:(k:string)=>cache.delete(k)}});
  let mode='revision',eligible=true,resolveState!:(value:Response)=>void;const calls:{url:string;method?:string}[]=[];
  Object.defineProperty(globalThis,'fetch',{configurable:true,value:async(url:string,options:RequestInit)=>{
    calls.push({url,method:options.method});assert.ok(url.startsWith(base+'/api/prototype/'));assert.equal(options.credentials,'same-origin');
    if(options.method==='POST')return Response.json({state:{...state,settings:{...state.settings,ppNumberFormat:'QA/{SEQ}'}},revision:3,csrf:'after-write'});
    if(url.endsWith('/revision'))return Response.json({revision:1});
    if(mode==='deferred')return new Promise<Response>(r=>{resolveState=r;});
    if(mode==='malformed')return Response.json({message:'Not a state'});
    return Response.json({state,revision:1,csrf:'read'});
  }});
  try{
    const store=await import('../../resources/js/area3/store');const signal=new AbortController().signal;
    store.saveDraft('typed-note',{note:'Keep me'});const beforeCache=JSON.stringify([...cache]);
    assert.equal(await store.inboxRevision(signal),1);assert.equal(calls.length,1);assert.equal(calls[0].url,base+'/api/prototype/revision');
    await store.refreshInboxState(signal,()=>false);assert.equal(calls.length,1);assert.equal(store.confirmedRevision(),0);
    await store.refreshInboxState(signal,()=>true);assert.equal(store.confirmedRevision(),1);assert.equal(JSON.stringify([...cache]),beforeCache);
    mode='deferred';const stale=store.refreshInboxState(signal,()=>eligible);await Promise.resolve();eligible=false;
    resolveState(Response.json({state,revision:2}));await stale;assert.equal(store.confirmedRevision(),1);
    eligible=true;const racing=store.refreshInboxState(signal,()=>true);await Promise.resolve();
    store.mutate(s=>setNumberFormat(s,'QA/{SEQ}'));await store.waitForPersistence();assert.equal(store.confirmedRevision(),3);
    resolveState(Response.json({state,revision:2}));await racing;assert.equal(store.confirmedRevision(),3);
    mode='malformed';await assert.rejects(store.refreshInboxState(signal,()=>true),/tidak valid/);assert.equal(store.confirmedRevision(),3);
    assert.equal(calls.filter(c=>c.method==='POST').length,1);assert.equal(JSON.stringify([...cache]),beforeCache);
  }finally{for(const [key,descriptor]of originals){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete(globalThis as Record<string,unknown>)[key];}}
});

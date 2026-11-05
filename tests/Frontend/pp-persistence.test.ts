import test from 'node:test';
import assert from 'node:assert/strict';
import { seed } from '../../resources/js/area3/seed';
import { blankProject, normalizeWorkflow, saveProject } from '../../resources/js/area3/domain';

test('failed PP persistence keeps cached edits and editor navigation intact; successful retry clears only after confirmation',async()=>{
  const state=normalizeWorkflow(seed('B'));state.schema=3;
  const p={...blankProject(),unit:'Konstruksi',name:'Header QA',branch:state.branches![0].name,projectCode:'PERSIST-QA',budget:10000,date:'2026-11-05'};
  const originals=new Map(['window','localStorage','fetch'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
  const values=new Map<string,string>(),win=new EventTarget();let navigationEvents=0,release:((response:Response)=>void)|undefined;
  win.addEventListener('area3:server-error',()=>navigationEvents++);
  Object.assign(win,{__AREA3__:{state,revision:0,csrf:'qa',basePath:'',scenarios:{},account:{id:'qa',name:'QA',email:'qa@example.test',role:'Admin Konstruksi',branches:[p.branch]},urls:{state:'https://qa.invalid/state',commands:'https://qa.invalid/commands',csv:'https://qa.invalid/csv',backup:'https://qa.invalid/backup'}}});
  Object.defineProperty(globalThis,'window',{configurable:true,value:win});
  Object.defineProperty(globalThis,'localStorage',{configurable:true,value:{getItem:(key:string)=>values.get(key)??null,setItem:(key:string,value:string)=>values.set(key,value),removeItem:(key:string)=>values.delete(key)}});
  Object.defineProperty(globalThis,'fetch',{configurable:true,value:(url:string)=>url.endsWith('/commands')?new Promise<Response>(resolve=>{release=resolve;}):Promise.resolve(Response.json({state,revision:0,csrf:'qa'}))});
  try {
    const store=await import('../../resources/js/area3/store');
    store.saveDraft('pp-v2-qa',p);store.mutate(s=>saveProject(s,p,false));store.clearDraft('pp-v2-qa');
    assert.equal(store.persistencePending,true);assert.equal(values.size,1);
    release!(Response.json({message:'Validation failed',errors:{project:['QA server rejection']}},{status:422}));
    await assert.rejects(store.waitForPersistence(),/QA server rejection/);
    assert.equal(store.persistencePending,false);assert.equal(values.size,1);assert.equal(navigationEvents,0);assert.match(store.persistenceError,/QA server rejection/);
    const expected=structuredClone(state);saveProject(expected,p,false);
    store.mutate(s=>saveProject(s,p,false));store.clearDraft('pp-v2-qa');assert.equal(values.size,1);
    release!(Response.json({state:expected,revision:1,csrf:'qa'}));await store.waitForPersistence();
    assert.equal(values.size,0);assert.equal(store.persistenceError,'');assert.equal(navigationEvents,0);
  } finally {for(const [key,descriptor]of originals){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else Reflect.deleteProperty(globalThis,key);}}
});

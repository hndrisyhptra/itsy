import test from 'node:test';
import assert from 'node:assert/strict';
import {seed} from '../../resources/js/area3/seed';
import {blankProject,normalizeWorkflow,saveProject} from '../../resources/js/area3/domain';
import {saveProjectBundle} from '../../resources/js/area3/project-bundle';
import {confirmsProjectSave,isProjectDraftSave,isWorkspacePayload} from '../../resources/js/area3/pp-save-confirmation';

test('lost PP draft response resolves only from its own committed receipt and canonical state, with one POST and preserved input otherwise',async()=>{
 const initial=normalizeWorkflow(seed('B'));initial.schema=3;
 const keys=['window','localStorage','fetch','setTimeout'],originals=new Map(keys.map(k=>[k,Object.getOwnPropertyDescriptor(globalThis,k)]));
 const win=new EventTarget(),cache=new Map<string,string>(),calls:{url:string;options:RequestInit}[]=[],timers:number[]=[];
 const realTimer=globalThis.setTimeout;let mode='network',server=structuredClone(initial),serverRevision=0,lastBody:any,localErrors=0;
 win.addEventListener('area3:server-error',()=>localErrors++);
 Object.assign(win,{__AREA3__:{state:initial,revision:0,csrf:'qa',basePath:'/its',scenarios:{},account:{id:'qa',role:'Admin Konstruksi',branches:[initial.branches![0].name]}}});
 Object.defineProperty(globalThis,'window',{configurable:true,value:win});
 Object.defineProperty(globalThis,'localStorage',{configurable:true,value:{getItem:(k:string)=>cache.get(k)??null,setItem:(k:string,v:string)=>cache.set(k,v),removeItem:(k:string)=>cache.delete(k)}});
 Object.defineProperty(globalThis,'setTimeout',{configurable:true,value:(fn:()=>void,ms:number,...args:unknown[])=>{timers.push(ms);return realTimer(fn,mode==='timeout'&&ms===60000?0:ms,...args);}});
 Object.defineProperty(globalThis,'fetch',{configurable:true,value:async(url:string,options:RequestInit)=>{
  calls.push({url,options});assert.ok(url.startsWith('/its/api/prototype/'));assert.equal(options.credentials,'same-origin');
  if(options.method==='POST'){
   lastBody=JSON.parse(options.body as string);saveProjectBundle(server,lastBody.args[0],false);serverRevision++;
   const p=server.projects.find(p=>p.id===lastBody.args[0].id)!;p.no='SERVER/'+serverRevision;
   if(mode==='timeout')return new Promise<Response>((_,reject)=>options.signal!.addEventListener('abort',()=>reject(new DOMException('Timed out','AbortError')),{once:true}));
   if(mode==='gateway')return new Response('Gateway Timeout',{status:504});
   if(mode==='direct')return Response.json({state:server,revision:serverRevision});
   throw new TypeError('Failed to fetch');
  }
  const confirmation={requestId:lastBody.request_id,projectId:lastBody.args[0].id,command:lastBody.command,expectedRevision:lastBody.revision,resultingRevision:serverRevision};
  if(mode==='wrong-request')confirmation.requestId=crypto.randomUUID();
  if(mode==='wrong-command')confirmation.command='decideProject';
  if(mode==='wrong-version')confirmation.expectedRevision++;
  if(mode==='ahead-of-state')confirmation.resultingRevision++;
  return Response.json({state:server,revision:serverRevision,confirmation:mode==='no-receipt'?null:confirmation});
 }});
 try{
  const store=await import('../../resources/js/area3/store');
  for(const scenario of ['network','timeout','gateway','direct','no-receipt','wrong-request','wrong-command','wrong-version','ahead-of-state']){
   mode=scenario;calls.length=0;timers.length=0;
   const p={...blankProject(),unit:'Konstruksi',name:'Receipt '+scenario,branch:initial.branches![0].name,projectCode:'RECEIPT-'+scenario,date:'2026-11-10',budget:10000};
   const key='receipt-'+scenario;store.saveDraft(key,p);store.mutate(s=>scenario==='gateway'?saveProject(s,p,false):saveProjectBundle(s,p,false));store.clearDraft(key);
   assert.equal(store.persistencePending,true);assert.ok(cache.has('area3-laravel-draft-qa-'+key));
   if(['network','timeout','gateway','direct'].includes(scenario)){
    await store.waitForPersistence();assert.equal(store.persistenceError,'');assert.equal(cache.has('area3-laravel-draft-qa-'+key),false);
   }else{
    await assert.rejects(store.waitForPersistence(),/Perubahan belum dikonfirmasi/);assert.ok(cache.has('area3-laravel-draft-qa-'+key));
   }
   assert.equal(calls.filter(c=>c.options.method==='POST').length,1,'Never automatically send the write again.');
   assert.ok(timers.includes(60000),'Draft writes have a 60-second response budget.');
   const reads=calls.filter(c=>c.options.method!=='POST');assert.equal(reads.length,scenario==='direct'?0:1);
   if(reads.length){const url=new URL(reads[0].url,'https://qa.invalid');assert.equal(url.searchParams.get('request_id'),lastBody.request_id);assert.equal(url.searchParams.get('project_id'),p.id);assert.equal(reads[0].options.cache,'no-store');}
   assert.equal(store.persistencePending,false);assert.equal(localErrors,0,'Keep both PP save aliases mounted when confirmation is uncertain.');
  }
 }finally{for(const [key,descriptor]of originals){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else Reflect.deleteProperty(globalThis,key);}}
});

test('confirmation requires matching source PP, committed revision and a valid authorized state, never just existence',()=>{
 const state=normalizeWorkflow(seed('B')),id=state.projects[0].id,requestId=crypto.randomUUID(),command={command:'saveProjectBundle',args:[{id},false]};
 const data={state,revision:3,confirmation:{requestId,projectId:id,command:command.command,expectedRevision:1,resultingRevision:2}};
 assert.equal(confirmsProjectSave(data,command,requestId,1),true);
 for(const confirmation of [null,{...data.confirmation,projectId:'other-pp'},{...data.confirmation,resultingRevision:1},{...data.confirmation,resultingRevision:'2'}])assert.equal(confirmsProjectSave({...data,confirmation},command,requestId,1),false);
 assert.equal(confirmsProjectSave({...data,state:{...state,projects:[]}},command,requestId,1),false);
 for(const payload of [null,{}, {...data,revision:'3'},{...data,revision:-1},{...data,state:{projects:null}}])assert.equal(isWorkspacePayload(payload),false);
 assert.equal(isProjectDraftSave({...command,args:[{id},true]}),false);
 assert.equal(isProjectDraftSave({command:'decideProject',args:[id,'Setujui']}),false);
});

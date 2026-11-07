import test from 'node:test';
import assert from 'node:assert/strict';

test('SQL selection requests use the application subpath/session and GET only, support cancellation and expose server errors',async()=>{
  const keys=['window','fetch'] as const,originals=new Map(keys.map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
  const calls:{url:string;options:RequestInit}[]=[];
  Object.defineProperty(globalThis,'window',{configurable:true,value:{__AREA3__:{account:{id:'db-user-1'},basePath:'/its/area3-prototype/public'}}});
  Object.defineProperty(globalThis,'fetch',{configurable:true,value:async(url:string,options:RequestInit)=>{calls.push({url,options});return new Response(JSON.stringify({items:[],page:1,hasMore:false}),{status:200});}});
  try{
    const {choiceRequest,hasCatalogSession}=await import('../../resources/js/area3/negotiation-projects');
    assert.equal(hasCatalogSession(),true);
    const controller=new AbortController();
    assert.deepEqual(await choiceRequest('?q=PRJ%20A&page=1',controller.signal),{items:[],page:1,hasMore:false});
    assert.equal(calls[0].url,'/its/area3-prototype/public/api/prototype/negotiation/projects?q=PRJ%20A&page=1');
    assert.equal(calls[0].options.credentials,'same-origin');assert.equal(calls[0].options.body,undefined);
    controller.abort();assert.equal(calls[0].options.signal?.aborted,true);
    Object.defineProperty(globalThis,'fetch',{configurable:true,value:async()=>new Response(JSON.stringify({message:'Branch tidak tersedia.'}),{status:403})});
    await assert.rejects(choiceRequest('/hidden/plans?page=1',new AbortController().signal),/Branch tidak tersedia/);
  }finally{for(const key of keys){const original=originals.get(key);if(original)Object.defineProperty(globalThis,key,original);else Reflect.deleteProperty(globalThis,key);}}
});

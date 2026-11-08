import test from 'node:test';
import assert from 'node:assert/strict';

test('payment SQL catalog uses authenticated GET/subpath, cancellation and server errors without a client workspace or file path',async()=>{
  const keys=['window','fetch'] as const,originals=new Map(keys.map(k=>[k,Object.getOwnPropertyDescriptor(globalThis,k)])),calls:{url:string;options:RequestInit}[]=[];
  Object.defineProperty(globalThis,'window',{configurable:true,value:{__AREA3__:{account:{id:'db-user-1'},basePath:'/its/area3-prototype/public'}}});
  Object.defineProperty(globalThis,'fetch',{configurable:true,value:async(url:string,options:RequestInit)=>{calls.push({url,options});return new Response(JSON.stringify({items:[],page:1,hasMore:false}),{status:200});}});
  try{
    const {paymentChoiceRequest,hasPaymentSession}=await import('../../resources/js/area3/payment-projects');
    assert.equal(hasPaymentSession(),true);const controller=new AbortController();
    await paymentChoiceRequest('?q=ID%20Project&page=1',controller.signal);
    await paymentChoiceRequest('/uuid/orders?page=2',controller.signal);
    assert.equal(calls[0].url,'/its/area3-prototype/public/api/prototype/payment-selection/projects?q=ID%20Project&page=1');
    assert.equal(calls[1].url,'/its/area3-prototype/public/api/prototype/payment-selection/projects/uuid/orders?page=2');
    assert.equal(calls[0].options.credentials,'same-origin');assert.equal(calls[0].options.body,undefined);assert.equal(calls[0].options.method,undefined);
    controller.abort();assert.equal(calls[0].options.signal?.aborted,true);
    Object.defineProperty(globalThis,'fetch',{configurable:true,value:async()=>new Response(JSON.stringify({message:'SPK di luar Branch.'}),{status:404})});
    await assert.rejects(paymentChoiceRequest('/private/orders?page=1',new AbortController().signal),/di luar Branch/);
  }finally{for(const k of keys){const v=originals.get(k);if(v)Object.defineProperty(globalThis,k,v);else Reflect.deleteProperty(globalThis,k);}}
});

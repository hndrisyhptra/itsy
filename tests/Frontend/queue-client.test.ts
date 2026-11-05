import test from 'node:test';
import assert from 'node:assert/strict';

test('queue polling waits for real completion, propagates validation failures and cancels without further requests',async()=>{
  const originals=new Map(['fetch','setTimeout','clearTimeout'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
  let calls=0;const responses=['processing','completed'];
  Object.defineProperty(globalThis,'setTimeout',{configurable:true,value:(fn:()=>void)=>{queueMicrotask(fn);return 1;}});
  Object.defineProperty(globalThis,'clearTimeout',{configurable:true,value:()=>{}});
  Object.defineProperty(globalThis,'fetch',{configurable:true,value:async()=>{calls++;return Response.json({task:{id:'qa',kind:'boq',documentId:'pp-1',name:'qa.xlsx',status:responses.shift()??'completed',error:null}});}});
  try{
    const {waitUpload}=await import('../../resources/js/area3/upload-queue');
    const messages:string[]=[];
    const queued={id:'qa',kind:'boq' as const,documentId:'pp-1',name:'qa.xlsx',status:'queued' as const,error:null,attempts:0,createdAt:'2026-11-06'};
    assert.equal((await waitUpload(queued,v=>messages.push(v))).status,'completed');assert.equal(calls,2);assert.match(messages[0],/Dalam antrean/);assert.match(messages[1],/Sedang diproses/);
    await assert.rejects(waitUpload({...queued,status:'failed',error:'Invalid template'}),/Invalid template/);assert.equal(calls,2);
    const abort=new AbortController();abort.abort();await assert.rejects(waitUpload(queued,undefined,abort.signal),{name:'AbortError'});assert.equal(calls,2);
  }finally{for(const [key,value]of originals){if(value)Object.defineProperty(globalThis,key,value);else Reflect.deleteProperty(globalThis,key);}}
});

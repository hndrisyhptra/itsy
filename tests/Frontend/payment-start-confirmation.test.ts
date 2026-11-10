import test from 'node:test';
import assert from 'node:assert/strict';
import { seed } from '../../resources/js/area3/seed';

test('payment start waits for server confirmation, reuses the latest existing draft, and rejects failed/stale/unknown SPKs without phantom navigation',async()=>{
  const keys=['window','fetch','CustomEvent'] as const,originals=new Map(keys.map(k=>[k,Object.getOwnPropertyDescriptor(globalThis,k)]));
  let server=seed('D'),revision=0,posts=0,fail=false,failRead=false,uncertain=false;
  const spkId=server.spks[0].id;
  const payload=()=>({state:structuredClone(server),revision,csrf:'qa-csrf'});
  let confirm:undefined|(()=>void),postStarted:undefined|(()=>void);
  Object.defineProperty(globalThis,'window',{configurable:true,value:{__AREA3__:{account:{id:'qa'},state:structuredClone(server),revision,csrf:'qa-csrf',basePath:'',urls:{state:'/state',commands:'/commands'}},addEventListener:()=>{},dispatchEvent:()=>{}}});
  Object.defineProperty(globalThis,'CustomEvent',{configurable:true,value:class{constructor(public type:string,public options:unknown){}}});
  Object.defineProperty(globalThis,'fetch',{configurable:true,value:async(url:string,options?:RequestInit)=>{
    if(url==='/api/prototype/state')return new Response(JSON.stringify(failRead?{message:'Read failed'}:payload()),{status:failRead?503:200});
    assert.equal(url,'/api/prototype/commands');posts++;const input=JSON.parse(options!.body as string);
    return new Promise<Response>((resolve,reject)=>{
      confirm=()=>{
        if(fail){resolve(new Response(JSON.stringify({message:'Server menolak',...payload()}),{status:409}));return;}
        server.terms.push({id:input.result_id,spkId:input.args[0],number:1,type:'Parsial',status:'Draft',actuals:{},approvals:[],history:[],apm:'',apmStatus:'Belum Terbit',paid:0,paymentHistory:[]});revision++;
        if(uncertain){reject(new Error('Jaringan terputus setelah penyimpanan'));return;}
        resolve(new Response(JSON.stringify(payload()),{status:200}));
      };postStarted?.();
    });
  }});
  try{
    const {startPaymentFromSpk,persistencePending}=await import('../../resources/js/area3/store');
    let completed=false;
    const started=new Promise<void>(r=>{postStarted=r;});
    const pending=startPaymentFromSpk(spkId).then(id=>{completed=true;return id;});
    await started;assert.equal(completed,false);assert.equal(server.terms.length,0);assert.equal(posts,1);
    await assert.rejects(startPaymentFromSpk(spkId),/Tunggu konfirmasi/);
    confirm!();const id=await pending;assert.equal(id,server.terms[0].id);assert.equal(completed,true);
    assert.equal(await startPaymentFromSpk(spkId),id);assert.equal(posts,1);
    server.terms=[];fail=true;
    const failedStarted=new Promise<void>(r=>{postStarted=r;});
    const failed=startPaymentFromSpk(spkId);await failedStarted;confirm!();await assert.rejects(failed,/Server menolak/);
    assert.equal(server.terms.length,0);assert.equal(posts,2);
    await assert.rejects(startPaymentFromSpk('unknown'),/SPK Sah tidak tersedia/);assert.equal(posts,2);
    failRead=true;await assert.rejects(startPaymentFromSpk(spkId),/Data terbaru/);assert.equal(posts,2);
    failRead=false;fail=false;uncertain=true;
    const uncertainStarted=new Promise<void>(r=>{postStarted=r;});
    const uncertainRequest=startPaymentFromSpk(spkId);await uncertainStarted;confirm!();await assert.rejects(uncertainRequest,/Jaringan terputus/);
    assert.equal(server.terms.length,1);assert.equal(posts,3);
    assert.equal(await startPaymentFromSpk(spkId),server.terms[0].id);assert.equal(posts,3);
  }finally{for(const k of keys){const v=originals.get(k);if(v)Object.defineProperty(globalThis,k,v);else Reflect.deleteProperty(globalThis,k);}}
});

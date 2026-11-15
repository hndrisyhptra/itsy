import test from 'node:test';
import assert from 'node:assert/strict';
import { seed } from '../../resources/js/area3/seed';

test('explicit private upload retry replaces only confirmed failed tasks and preserves UUID on uncertain POST or polling',async()=>{
  const originals=new Map(['window','fetch','setTimeout'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
  const state=seed('D'),win=new EventTarget(),posts:{id:string;url:string}[]=[];
  Object.assign(win,{__AREA3__:{state,revision:4,csrf:'csrf-private',basePath:'/its/area3-prototype/public',scenarios:{},account:{id:'db-user-qa',name:'QA',role:'Superadmin',branches:[]},urls:{state:'/state',commands:'/commands',csv:'/csv',backup:'/backup'}}});
  let mode='failed',reads=0,polls=0;
  const nativeTimeout=globalThis.setTimeout;
  Object.defineProperty(globalThis,'window',{configurable:true,value:win});
  Object.defineProperty(globalThis,'setTimeout',{configurable:true,value:(fn:()=>void,ms:number)=>ms===2000?(queueMicrotask(fn),1):nativeTimeout(fn,ms)});
  Object.defineProperty(globalThis,'fetch',{configurable:true,value:async(url:string,options:RequestInit={})=>{
    if(options.method==='POST'){
      const body=options.body as FormData;posts.push({id:String(body.get('request_id')),url});
      assert.equal(options.credentials,'same-origin');assert.equal((options.headers as Record<string,string>)['X-CSRF-TOKEN'],'csrf-private');
      if(mode==='network')throw new TypeError('Failed to fetch');
      return Response.json({task:{id:'task-'+posts.at(-1)!.id,kind:'mandor-ktp',documentId:'spk-qa',name:'mandor-ktp',status:mode==='poll-network'?'queued':mode,error:mode==='failed'?'Worker lama menolak file':null,attempts:1,createdAt:'2026-11-15'}},{status:202});
    }
    if(url.includes('/api/uploads/')){polls++;throw new TypeError('Polling unavailable');}
    reads++;return Response.json({state,revision:4,csrf:'csrf-private'});
  }});
  try{
    const {uploadPrivateDocument}=await import('../../resources/js/area3/store');
    const file=new File(['private test bytes'],'ktp.png');
    await assert.rejects(uploadPrivateDocument('mandor-ktp','spk-qa',file),{name:'UploadTaskFailedError',message:'Worker lama menolak file'});
    assert.equal(posts.length,1);assert.equal(reads,0);
    mode='network';await assert.rejects(uploadPrivateDocument('mandor-ktp','spk-qa',file),/Failed to fetch/);
    assert.equal(posts.length,2);assert.notEqual(posts[0].id,posts[1].id);
    mode='completed';await uploadPrivateDocument('mandor-ktp','spk-qa',file);
    assert.equal(posts.length,3);assert.equal(posts[1].id,posts[2].id);assert.equal(reads,1);
    mode='poll-network';await assert.rejects(uploadPrivateDocument('mandor-ktp','spk-other',file),/Polling unavailable/);
    assert.equal(posts.length,4);assert.equal(polls,1);
    mode='completed';await uploadPrivateDocument('mandor-ktp','spk-other',file);
    assert.equal(posts.length,5);assert.equal(posts[3].id,posts[4].id);assert.equal(reads,2);
    assert.ok(posts.every(p=>p.url.startsWith('/its/area3-prototype/public/api/finance/uploads/mandor-ktp/')));
  }finally{for(const [key,value]of originals){if(value)Object.defineProperty(globalThis,key,value);else Reflect.deleteProperty(globalThis,key);}}
});

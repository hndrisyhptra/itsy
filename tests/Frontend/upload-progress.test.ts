import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { postUpload, startingUpload, taskUploadProgress, type UploadProgress } from '../../resources/js/area3/upload-progress';
import { UploadProgressView } from '../../resources/js/area3/upload-progress-view';

class TestXHR {
  static requests:TestXHR[]=[];
  upload:{onprogress:((event:{loaded:number;total:number;lengthComputable:boolean})=>void)|null;onload:(()=>void)|null}={onprogress:null,onload:null};
  onload:(()=>void)|null=null;onerror:(()=>void)|null=null;ontimeout:(()=>void)|null=null;onabort:(()=>void)|null=null;
  method='';url='';headers:Record<string,string>={};body?:FormData;withCredentials=false;timeout=0;status=0;responseText='';aborted=false;
  constructor(){TestXHR.requests.push(this);}
  open(method:string,url:string){this.method=method;this.url=url;}
  setRequestHeader(key:string,value:string){this.headers[key]=value;}
  getResponseHeader(){return 'application/json';}
  send(body:FormData){this.body=body;}
  abort(){this.aborted=true;this.onabort?.();}
  progress(loaded:number,total:number,known=true){this.upload.onprogress?.({loaded,total,lengthComputable:known});}
  uploaded(){this.upload.onload?.();}
  respond(status:number,body:unknown){this.status=status;this.responseText=JSON.stringify(body);this.onload?.();}
}
function globals(t:{after:(fn:()=>void)=>void},values:Record<string,unknown>){
  const before=new Map(Object.keys(values).map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
  for(const [key,value]of Object.entries(values))Object.defineProperty(globalThis,key,{configurable:true,writable:true,value});
  t.after(()=>{for(const [key,value]of before){if(value)Object.defineProperty(globalThis,key,value);else Reflect.deleteProperty(globalThis,key);}});
}
function install(t:{after:(fn:()=>void)=>void}){TestXHR.requests=[];globals(t,{XMLHttpRequest:TestXHR});}
const latest=()=>TestXHR.requests.at(-1)!;

test('multipart progress follows actual byte events, deduplicates whole percentages and waits for server response',async t=>{
  install(t);const values:UploadProgress[]=[];const body=new FormData();body.set('file',new File(['sample'],'test.xlsx'));body.set('revision','5');
  const pending=postUpload('/its/api/upload',body,{Accept:'application/json','X-CSRF-TOKEN':'csrf-qa'},v=>values.push(v));
  const xhr=latest();assert.equal(xhr.method,'POST');assert.equal(xhr.url,'/its/api/upload');assert.equal(xhr.withCredentials,true);assert.equal(xhr.timeout,60000);assert.equal(xhr.body,body);assert.equal(xhr.headers['X-CSRF-TOKEN'],'csrf-qa');assert.equal(xhr.headers['Content-Type'],undefined);
  xhr.progress(11,100);xhr.progress(11.7,100);xhr.progress(63,100);xhr.uploaded();
  assert.deepEqual(values.map(v=>[v.phase,v.percent]),[['uploading',0],['uploading',11],['uploading',63],['confirming',100]]);
  assert.equal(values.some(v=>v.phase==='completed'),false);
  xhr.respond(202,{accepted:true});assert.deepEqual(await (await pending).json(),{accepted:true});assert.equal(xhr.upload.onprogress,null);assert.equal(xhr.onload,null);
});

test('unmeasurable lengths remain indeterminate and do not invent percentages or timer-based movement',async t=>{
  install(t);const values:UploadProgress[]=[];
  const pending=postUpload('/upload',new FormData(),{},v=>values.push(v));const xhr=latest();xhr.progress(100,0,false);xhr.progress(200,0,false);
  assert.deepEqual(values,[startingUpload(),{phase:'uploading',percent:null}]);xhr.uploaded();assert.equal(values.at(-1)?.percent,100);xhr.respond(200,{});await pending;
});

test('validation responses retain HTTP status and never become successful processing',async t=>{
  install(t);const values:UploadProgress[]=[];const pending=postUpload('/upload',new FormData(),{},v=>values.push(v));latest().uploaded();latest().respond(422,{errors:{file:['Invalid workbook']}});
  const response=await pending;assert.equal(response.ok,false);assert.equal(response.status,422);assert.deepEqual(await response.json(),{errors:{file:['Invalid workbook']}});assert.equal(values.some(v=>v.phase==='completed'),false);
});

test('abort, timeout and network failure preserve uncertainty, release handlers and never retry a POST',async t=>{
  install(t);
  for(const event of ['abort','timeout','network'] as const){
    const controller=new AbortController(),values:UploadProgress[]=[];
    const pending=postUpload('/upload',new FormData(),{},v=>values.push(v),controller.signal,20000);const xhr=latest();xhr.progress(42,100);
    if(event==='abort')controller.abort();else if(event==='timeout')xhr.ontimeout?.();else xhr.onerror?.();
    await assert.rejects(pending,event==='abort'?{name:'AbortError'}:/Periksa status/);assert.equal(values.at(-1)?.phase,'error');assert.equal(values.at(-1)?.percent,42);assert.equal(xhr.onload,null);assert.equal(xhr.upload.onprogress,null);
    controller.abort();assert.equal(values.filter(v=>v.phase==='error').length,1);
  }
  assert.equal(TestXHR.requests.length,3);
  const controller=new AbortController();controller.abort();await assert.rejects(postUpload('/upload',new FormData(),{},()=>{},controller.signal),{name:'AbortError'});assert.equal(latest().body,undefined);
});

test('queue progress shows 100% received bytes with separate unknown processing, including reload and failure',()=>{
  for(const status of ['queued','processing','completed','failed'] as const){
    const value=taskUploadProgress({status,error:status==='failed'?'Template tidak sesuai':null});assert.equal(value.percent,100);assert.equal(value.phase,status);
    const html=renderToStaticMarkup(createElement(UploadProgressView,{name:'BOQ <test>.xlsx',progress:value}));
    assert.match(html,/100%/);assert.match(html,/BOQ &lt;test&gt;.xlsx/);assert.match(html,/aria-label="Pengiriman BOQ/);assert.match(html,/value="100"/);
    if(status==='queued'||status==='processing'){assert.match(html,/Persentase di atas hanya untuk pengiriman file/);assert.match(html,/aria-label="Pemrosesan BOQ[^>]*><\/progress>/);assert.doesNotMatch(html,/aria-label="Pemrosesan BOQ[^>]*value="/);assert.doesNotMatch(html,/>Selesai</);}
    if(status==='failed'){assert.match(html,/Pemrosesan gagal/);assert.match(html,/Template tidak sesuai/);}
  }
  const unknown=renderToStaticMarkup(createElement(UploadProgressView,{progress:{phase:'uploading',percent:null}}));assert.match(unknown,/Ukuran belum terukur/);assert.doesNotMatch(unknown,/value="/);
});

test('all authenticated upload transports expose progress while retaining queue gates, CSRF, multipart revision and retry UUID',async t=>{
  install(t);
  const {seed}=await import('../../resources/js/area3/seed');const state=seed('B');state.role='Superadmin';
  const win=new EventTarget();Object.assign(win,{__AREA3__:{state,revision:4,csrf:'csrf-upload',basePath:'/its/area3-prototype/public',scenarios:{},account:{id:'db-user-qa',name:'QA',role:'Superadmin',email:'qa@example.test',branches:[]},urls:{state:'/state',commands:'/commands',csv:'/csv',backup:'/backup'}}});
  const nativeTimeout=globalThis.setTimeout,fetches:string[]=[];let checks=0;
  const task={id:'qa-upload-task',kind:'boq' as 'boq'|'evidence',documentId:'pp-progress',name:'boq.xlsx',status:'queued' as 'queued'|'processing'|'completed',attempts:0,error:null,createdAt:'2026-11-09'};
  const result={rows:[],source:{id:'qa-upload-task',name:'boq.xlsx'}};
  globals(t,{window:win,setTimeout:(fn:()=>void,ms:number)=>ms===2000?(queueMicrotask(fn),1):nativeTimeout(fn,ms),fetch:async(url:string)=>{
    fetches.push(url);
    if(url==='/its/area3-prototype/public/api/prototype/state')return Response.json({state,revision:5,csrf:'csrf-next'});
    if(url.endsWith('?result=1'))return Response.json({task:{...task,status:'completed'},result});
    return Response.json({task:{...task,status:++checks%2===1?'processing':'completed'}});
  }});
  const store=await import('../../resources/js/area3/store');const file=new File(['workbook'],'boq.xlsx'),values:UploadProgress[]=[];
  const boq=store.uploadBoq(file,'pp-progress',undefined,undefined,v=>values.push(v));let xhr=latest();
  assert.equal(xhr.url,'/its/area3-prototype/public/api/uploads/boq');assert.equal(xhr.body?.get('document_id'),'pp-progress');assert.equal(xhr.headers['X-CSRF-TOKEN'],'csrf-upload');assert.ok(xhr.body?.get('request_id'));
  xhr.progress(55,100);xhr.uploaded();assert.equal(values.at(-1)?.phase,'confirming');xhr.respond(202,{task});
  assert.deepEqual(await boq,result);assert.deepEqual(values.map(v=>v.phase),['uploading','uploading','confirming','queued','queued','processing','completed']);assert.equal(fetches.length,3);
  assert.equal(fetches.filter(url=>url.endsWith('?result=1')).length,1);
  const evidenceValues:UploadProgress[]=[];task.kind='evidence';task.documentId='term-progress';
  const evidence=store.uploadTermWorkEvidence('term-progress',new File(['pdf'],'proof.pdf'),undefined,v=>evidenceValues.push(v));xhr=latest();
  assert.match(xhr.url,/\/api\/uploads\/payments\/term-progress\/evidence$/);xhr.progress(30,100);xhr.uploaded();xhr.respond(202,{task});await evidence;
  assert.equal(evidenceValues.some(v=>v.percent===30),true);assert.equal(evidenceValues.at(-1)?.phase,'completed');assert.equal(fetches.length,6);assert.equal(fetches.at(-1),'/its/area3-prototype/public/api/prototype/state');
  const preview={revision:5,sha256:'a'.repeat(64),rows:[],summary:{created:1,updated:0,unchanged:0}};
  for(const entity of ['designator','khs'] as const){
    const report:UploadProgress[]=[];const inspect=store.previewMasterImport(entity,file,'upsert',3,v=>report.push(v));xhr=latest();
    assert.equal(xhr.body?.get('revision'),'3');assert.equal(xhr.body?.get('stage'),'preview');assert.equal(xhr.headers['X-CSRF-TOKEN'],'csrf-next');xhr.progress(67,100);xhr.uploaded();xhr.respond(200,preview);await inspect;
    assert.equal(report.at(-1)?.phase,'completed');assert.match(report.at(-1)?.message??'',/Data belum diimpor/);
  }
  const requestId=crypto.randomUUID(),commitValues:UploadProgress[]=[];
  const failed=store.commitMasterImport('designator',file,'upsert',preview,requestId,v=>commitValues.push(v));xhr=latest();assert.equal(xhr.body?.get('request_id'),requestId);xhr.uploaded();xhr.onerror?.();await assert.rejects(failed,/Koneksi terputus/);assert.equal(store.persistencePending,false);assert.equal(commitValues.some(v=>v.phase==='completed'),false);
  const retry=store.commitMasterImport('designator',file,'upsert',preview,requestId,v=>commitValues.push(v));xhr=latest();assert.equal(xhr.body?.get('request_id'),requestId);assert.equal(xhr.body?.get('revision'),'5');assert.equal(xhr.body?.get('sha256'),preview.sha256);
  xhr.uploaded();xhr.respond(200,{state,revision:5,csrf:'csrf-next',catalog:{designators:[],packages:[],prices:[],types:[]},summary:preview.summary,replayed:true});await retry;assert.equal(commitValues.at(-1)?.phase,'completed');
  const scanValues:UploadProgress[]=[];
  const rejectedScan=store.uploadTermScan('term-progress',new File(['scan'],'scan.pdf'),v=>scanValues.push(v));xhr=latest();assert.match(xhr.url,/\/api\/prototype\/payments\/term-progress\/scan$/);assert.equal(xhr.body?.get('revision'),'5');assert.equal(xhr.timeout,20000);
  xhr.uploaded();xhr.respond(422,{errors:{file:['Scan harus memenuhi approval']}});await assert.rejects(rejectedScan,/Scan harus memenuhi approval/);assert.equal(scanValues.some(v=>v.phase==='completed'),false);assert.equal(store.persistencePending,false);
  const scan=store.uploadTermScan('term-progress',new File(['scan'],'scan.pdf'),v=>scanValues.push(v));xhr=latest();xhr.progress(80,100);xhr.uploaded();xhr.respond(200,{state,revision:6,csrf:'csrf-scan'});await scan;assert.equal(scanValues.at(-1)?.phase,'completed');assert.match(scanValues.at(-1)?.message??'',/verifikasi Finance/);
  assert.equal(fetches.length,7,'Only existing queue polling/result/state recovery reads, no progress-only API calls.');
});

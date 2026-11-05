import { appUrl, boot } from './laravel';
export interface UploadTask {id:string;kind:'boq'|'evidence';documentId:string;name:string;status:'queued'|'processing'|'completed'|'failed';attempts:number;error:string|null;createdAt:string}
export const pendingUpload=(task:UploadTask)=>task.status==='queued'||task.status==='processing';
export const uploadStatus=(task:UploadTask)=>({queued:'Dalam antrean',processing:'Sedang diproses',completed:'Selesai',failed:'Gagal'}[task.status]);
async function json(response:Response) {
  if((response.status===401||response.status===419)&&boot?.accountUrls)window.location.assign(boot.accountUrls.login);
  let data;try{data=await response.json();}catch{throw new Error('Server belum dapat menerima file. Muat ulang status antrean sebelum mengunggah kembali.');}
  if(!response.ok)throw new Error((Object.values(data.errors??{}).flat() as string[]).join('\n')||data.message||'Antrean upload tidak dapat diakses.');
  return data;
}
export async function enqueueUpload(kind:'boq'|'evidence',documentId:string,file:File,revision?:number,signal?:AbortSignal):Promise<UploadTask> {
  if(!boot?.account)throw new Error('Gunakan sesi login aktif untuk upload.');
  const body=new FormData();body.set('file',file);body.set('request_id',crypto.randomUUID());body.set('document_id',documentId);if(revision!==undefined)body.set('revision',String(revision));
  const path=kind==='boq'?'/api/uploads/boq':'/api/uploads/payments/'+encodeURIComponent(documentId)+'/evidence';
  const data=await json(await fetch(appUrl(path),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':boot.csrf},body,signal:signal?AbortSignal.any([signal,AbortSignal.timeout(60000)]):AbortSignal.timeout(60000)}));
  window.dispatchEvent(new Event('area3:upload-queued'));
  return data.task;
}
export async function uploadTask(id:string,result=false,signal?:AbortSignal) {
  return json(await fetch(appUrl('/api/uploads/'+encodeURIComponent(id)+(result?'?result=1':'')),{credentials:'same-origin',headers:{Accept:'application/json'},signal:signal?AbortSignal.any([signal,AbortSignal.timeout(15000)]):AbortSignal.timeout(15000)}));
}
export async function recentUploads(kind:'boq'|'evidence',documentId:string,signal?:AbortSignal):Promise<{tasks:UploadTask[];workerOnline:boolean}> {
  return json(await fetch(appUrl('/api/uploads?'+new URLSearchParams({kind,document_id:documentId})),{credentials:'same-origin',headers:{Accept:'application/json'},signal:signal?AbortSignal.any([signal,AbortSignal.timeout(15000)]):AbortSignal.timeout(15000)}));
}
export async function waitUpload(task:UploadTask,onProgress?:(message:string)=>void,signal?:AbortSignal):Promise<UploadTask> {
  const started=Date.now();let current=task;
  while(pendingUpload(current)) {
    signal?.throwIfAborted();
    if(Date.now()-started>180000)throw new Error('File tetap tersimpan dalam antrean. Pantau status di daftar upload dan gunakan hasil setelah selesai.');
    onProgress?.(uploadStatus(current)+' · '+current.name);
    await new Promise<void>((resolve,reject)=>{const done=()=>{signal?.removeEventListener('abort',abort);resolve();};const timer=setTimeout(done,typeof document!=='undefined'&&document.hidden?10000:2000);const abort=()=>{clearTimeout(timer);reject(new DOMException('Upload tetap diproses di server.','AbortError'));};signal?.addEventListener('abort',abort,{once:true});});
    current=(await uploadTask(current.id,false,signal)).task;
  }
  if(current.status==='failed')throw new Error(current.error||'Pemrosesan file gagal.');
  return current;
}

export async function resumeUpload(id:string):Promise<void>{await json(await fetch(appUrl('/api/uploads/'+encodeURIComponent(id)+'/resume'),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':boot?.csrf??''},signal:AbortSignal.timeout(10000)}));}

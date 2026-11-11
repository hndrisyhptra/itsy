import { appUrl, boot } from './laravel';
import { postUpload, taskUploadProgress, type UploadProgressHandler } from './upload-progress';
export type UploadKind='boq'|'evidence'|'mandor-ktp'|'mandor-bank'|'transfer-proof';
export interface UploadTask {id:string;kind:UploadKind;documentId:string;name:string;status:'queued'|'processing'|'completed'|'failed';attempts:number;error:string|null;createdAt:string}
export const pendingUpload=(task:UploadTask)=>task.status==='queued'||task.status==='processing';
export const uploadStatus=(task:UploadTask)=>({queued:'Dalam antrean',processing:'Sedang diproses',completed:'Selesai',failed:'Gagal'}[task.status]);
async function json(response:Response) {
  if((response.status===401||response.status===419)&&boot?.accountUrls)window.location.assign(boot.accountUrls.login);
  let data;try{data=await response.json();}catch{throw new Error('Server belum dapat menerima file. Muat ulang status antrean sebelum mengunggah kembali.');}
  if(!response.ok)throw new Error((Object.values(data.errors??{}).flat() as string[]).join('\n')||data.message||'Antrean upload tidak dapat diakses.');
  return data;
}
export async function enqueueUpload(kind:UploadKind,documentId:string,file:File,revision?:number,signal?:AbortSignal,onProgress?:UploadProgressHandler,requestId?:string):Promise<UploadTask> {
  if(!boot?.account)throw new Error('Gunakan sesi login aktif untuk upload.');
  const body=new FormData();body.set('file',file);body.set('request_id',requestId??crypto.randomUUID());body.set('document_id',documentId);if(revision!==undefined)body.set('revision',String(revision));
  const path=kind==='boq'?'/api/uploads/boq':kind==='evidence'?'/api/uploads/payments/'+encodeURIComponent(documentId)+'/evidence':'/api/finance/uploads/'+kind+'/'+encodeURIComponent(documentId);
  const data=await json(await postUpload(appUrl(path),body,{Accept:'application/json','X-CSRF-TOKEN':boot.csrf},onProgress,signal));
  onProgress?.(taskUploadProgress(data.task));
  window.dispatchEvent(new Event('area3:upload-queued'));
  return data.task;
}
export async function uploadTask(id:string,result=false,signal?:AbortSignal) {
  return json(await fetch(appUrl('/api/uploads/'+encodeURIComponent(id)+(result?'?result=1':'')),{credentials:'same-origin',headers:{Accept:'application/json'},signal:signal?AbortSignal.any([signal,AbortSignal.timeout(15000)]):AbortSignal.timeout(15000)}));
}
export async function recentUploads(kind:UploadKind,documentId:string,signal?:AbortSignal):Promise<{tasks:UploadTask[];workerOnline:boolean}> {
  return json(await fetch(appUrl('/api/uploads?'+new URLSearchParams({kind,document_id:documentId})),{credentials:'same-origin',headers:{Accept:'application/json'},signal:signal?AbortSignal.any([signal,AbortSignal.timeout(15000)]):AbortSignal.timeout(15000)}));
}
export async function waitUpload(task:UploadTask,onProgress?:(message:string)=>void,signal?:AbortSignal,onTaskProgress?:UploadProgressHandler):Promise<UploadTask> {
  const started=Date.now();let current=task;
  onTaskProgress?.(taskUploadProgress(current));
  while(pendingUpload(current)) {
    signal?.throwIfAborted();
    if(Date.now()-started>180000)throw new Error('File tetap tersimpan dalam antrean. Pantau status di daftar upload dan gunakan hasil setelah selesai.');
    onProgress?.(uploadStatus(current)+' · '+current.name);
    await new Promise<void>((resolve,reject)=>{const done=()=>{signal?.removeEventListener('abort',abort);resolve();};const timer=setTimeout(done,typeof document!=='undefined'&&document.hidden?10000:2000);const abort=()=>{clearTimeout(timer);reject(new DOMException('Upload tetap diproses di server.','AbortError'));};signal?.addEventListener('abort',abort,{once:true});});
    const previous=current.status;current=(await uploadTask(current.id,false,signal)).task;
    if(current.status!==previous)onTaskProgress?.(taskUploadProgress(current));
  }
  if(current.status==='failed')throw new Error(current.error||'Pemrosesan file gagal.');
  return current;
}

export async function resumeUpload(id:string):Promise<void>{await json(await fetch(appUrl('/api/uploads/'+encodeURIComponent(id)+'/resume'),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':boot?.csrf??''},signal:AbortSignal.timeout(10000)}));}

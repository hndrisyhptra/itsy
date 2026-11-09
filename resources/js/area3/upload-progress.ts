/** Percent measures browser request bytes only; queue/processing have no measured percentage. */
export interface UploadProgress {
  phase: 'pending' | 'uploading' | 'confirming' | 'queued' | 'processing' | 'completed' | 'failed' | 'error';
  percent: number | null;
  message?: string;
}
export type UploadProgressHandler = (progress: UploadProgress) => void;
export const startingUpload = (): UploadProgress => ({phase:'uploading',percent:0});
export const uploadPhaseLabel = (phase:UploadProgress['phase']) => ({pending:'Belum dikirim',uploading:'Mengirim file',confirming:'Menunggu konfirmasi server',queued:'Dalam antrean',processing:'Sedang diproses',completed:'Selesai',failed:'Pemrosesan gagal',error:'Belum dikonfirmasi'}[phase]);
export function taskUploadProgress(task:{status:'queued'|'processing'|'completed'|'failed';error:string|null}):UploadProgress {
  return {phase:task.status,percent:100,...(task.error?{message:task.error}:{})};
}

/** Same-origin multipart POST; JSON callers retain their existing validation/revision/error handling. */
export function postUpload(url:string,body:FormData,headers:Record<string,string>,onProgress?:UploadProgressHandler,signal?:AbortSignal,timeout=60000):Promise<Response> {
  // Compatibility callers without a progress consumer retain fetch (including AbortSignal semantics).
  if(!onProgress)return fetch(url,{method:'POST',credentials:'same-origin',headers,body,signal:signal?AbortSignal.any([signal,AbortSignal.timeout(timeout)]):AbortSignal.timeout(timeout)});
  return new Promise((resolve,reject)=>{
    const xhr=new XMLHttpRequest();let settled=false,last:UploadProgress|undefined;
    const report=(next:UploadProgress)=>{if(last?.phase===next.phase&&last.percent===next.percent)return;last=next;onProgress(next);};
    const cleanup=()=>{signal?.removeEventListener('abort',abort);xhr.upload.onprogress=null;xhr.upload.onload=null;xhr.onload=null;xhr.onerror=null;xhr.ontimeout=null;xhr.onabort=null;};
    const fail=(error:Error)=>{if(settled)return;settled=true;cleanup();report({phase:'error',percent:last?.percent??null});reject(error);};
    const abort=()=>{xhr.abort();fail(new DOMException('Pengiriman dibatalkan. File yang sudah diterima server dapat tetap diproses.','AbortError'));};
    if(signal?.aborted){fail(new DOMException('Pengiriman dibatalkan.','AbortError'));return;}
    xhr.open('POST',url);xhr.withCredentials=true;xhr.timeout=timeout;
    for(const [key,value] of Object.entries(headers))xhr.setRequestHeader(key,value);
    xhr.upload.onprogress=e=>report({phase:'uploading',percent:e.lengthComputable&&e.total>0?Math.max(0,Math.min(100,Math.floor(e.loaded/e.total*100))):null});
    xhr.upload.onload=()=>report({phase:'confirming',percent:100});
    xhr.onload=()=>{
      if(settled)return;
      if(xhr.status===0){fail(new Error('Koneksi terputus. Periksa status upload sebelum mengunggah ulang.'));return;}
      try {
        const response=new Response([204,205,304].includes(xhr.status)?null:xhr.responseText,{status:xhr.status,headers:{'Content-Type':xhr.getResponseHeader('Content-Type')??'application/json'}});
        settled=true;cleanup();resolve(response);
      } catch {fail(new Error('Respons upload belum dapat dibaca. Periksa status sebelum mengunggah ulang.'));}
    };
    xhr.onerror=()=>fail(new Error('Koneksi terputus. Periksa status upload sebelum mengunggah ulang.'));
    xhr.ontimeout=()=>fail(new Error('Konfirmasi upload melewati batas waktu. Periksa status antrean/data sebelum mengunggah ulang.'));
    xhr.onabort=()=>fail(new DOMException('Pengiriman dibatalkan. File yang sudah diterima server dapat tetap diproses.','AbortError'));
    signal?.addEventListener('abort',abort,{once:true});report(startingUpload());
    try{xhr.send(body);}catch(error){fail(error instanceof Error?error:new Error('File belum dapat dikirim.'));}
  });
}

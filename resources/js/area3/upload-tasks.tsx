import { useEffect, useRef, useState } from 'react';
import { RefreshCw, Check } from 'lucide-react';
import { pendingUpload, recentUploads, resumeUpload, type UploadKind, type UploadTask } from './upload-queue';
import { Button, Notice } from './ui';
import { taskUploadProgress } from './upload-progress';
import { UploadProgressView } from './upload-progress-view';
export function UploadTasks({kind,documentId,onUse,onReady,onPending}:{kind:UploadKind;documentId:string;onUse?:(task:UploadTask)=>void;onReady?:(task:UploadTask)=>void;onPending?:(count:number)=>void}) {
  const [tasks,setTasks]=useState<UploadTask[]>([]),[error,setError]=useState(''),[online,setOnline]=useState(true),[reload,setReload]=useState(0);
  const callbacks=useRef({onReady,onPending});callbacks.current={onReady,onPending};
  useEffect(()=>{
    const controller=new AbortController();let timer:ReturnType<typeof setTimeout>;let pending=false,inFlight=false;
    async function load(){
      if(inFlight)return;inFlight=true;
      try {const data=await recentUploads(kind,documentId,controller.signal);if(controller.signal.aborted)return;setTasks(data.tasks);pending=data.tasks.some(pendingUpload);callbacks.current.onPending?.(data.tasks.filter(pendingUpload).length);setOnline(data.workerOnline);setError('');
        for(const task of data.tasks)if(task.status==='completed')callbacks.current.onReady?.(task);
      }catch(e){if(!controller.signal.aborted){pending=true;setError(e instanceof Error?e.message:'Status antrean belum dapat dimuat.');}}
      inFlight=false;
      if(!controller.signal.aborted&&pending)timer=setTimeout(()=>void load(),typeof document!=='undefined'&&document.hidden?15000:4000);
    }
    const refresh=()=>{clearTimeout(timer);void load();};window.addEventListener('area3:upload-queued',refresh);window.addEventListener('focus',refresh);
    void load();return()=>{controller.abort();clearTimeout(timer);window.removeEventListener('area3:upload-queued',refresh);window.removeEventListener('focus',refresh);callbacks.current.onPending?.(0);};
  },[kind,documentId,reload]);
  if(!tasks.length&&!error)return null;
  return <div className="upload-task-list"><div className="section-heading"><strong>Antrean upload</strong><Button title="Muat ulang antrean" aria-label="Muat ulang antrean" onClick={()=>setReload(v=>v+1)}><RefreshCw size={14}/></Button></div>{error&&<Notice tone="error">{error}</Notice>}{!online&&tasks.some(pendingUpload)&&<Notice tone="warning">File tersimpan dalam antrean. Worker belum aktif. <Button confirm="Lanjutkan pemrosesan antrean upload?" onClick={()=>{const task=tasks.find(pendingUpload);if(task)void resumeUpload(task.id).then(()=>setReload(v=>v+1)).catch(e=>setError(e instanceof Error?e.message:String(e)));}}>Lanjutkan antrean</Button></Notice>}<ul>{tasks.map(task=><li key={task.id}><div><UploadProgressView name={task.name} progress={taskUploadProgress(task)}/>{task.attempts>1&&<small>Percobaan {task.attempts}</small>}</div>{onUse&&task.status==='completed'&&<Button confirm="Gunakan hasil upload ini pada BOQ draf? Isi BOQ draf akan diganti." title={'Gunakan BOQ '+task.name} onClick={()=>onUse(task)}><Check size={14}/> Gunakan hasil</Button>}</li>)}</ul></div>;
}

import { useEffect, useRef, useState } from 'react';
import { UploadTasks } from './upload-tasks';
import { waitUpload } from './upload-queue';
import { startingUpload, type UploadProgress } from './upload-progress';
import { UploadProgressView } from './upload-progress-view';
import { Upload, FileText, Eye, Download, Trash2, ExternalLink } from 'lucide-react';
import { type State, type Term, type WorkEvidence, operational, canReviseSubmission, dateLabel, removeTermWorkEvidence } from './domain';
import { appUrl, boot } from './laravel';
import { mutate, queueTermWorkEvidence, persistencePending, refreshState } from './store';
import { Button, Card, Table, Notice, Modal, attempt, confirmAction, ActionLink } from './ui';

export const workEvidenceUrl=(termId:string,fileId:string)=>appUrl('/api/prototype/payments/'+encodeURIComponent(termId)+'/work-evidence/'+encodeURIComponent(fileId));

export function WorkEvidencePreview({termId,file}:{termId:string;file:WorkEvidence}) {
  const url=workEvidenceUrl(termId,file.id);
  return file.mime==='application/pdf'?<iframe className="work-evidence-frame" title={'Preview PDF '+file.name} src={url}/>:<img className="work-evidence-image" alt={'Eviden pekerjaan '+file.name} src={url}/>;
}

export function TermWorkEvidencePanel({s,term,embedded=false,onBusy}:{s:State;term:Term;embedded?:boolean;onBusy?:(busy:boolean)=>void}) {
  const [busy,setBusy]=useState(false),[progress,setProgress]=useState<{name:string;progress:UploadProgress}[]>([]),[error,setError]=useState(''),[preview,setPreview]=useState<WorkEvidence|null>(null),[pendingCount,setPendingCount]=useState(0);
  useEffect(()=>{onBusy?.(busy||pendingCount>0);return()=>onBusy?.(false);},[busy,pendingCount,onBusy]);
  const refreshing=useRef(false),batch=useRef(0);
  useEffect(()=>()=>{batch.current++;},[]);
  const files=term.workEvidence??[],active=files.filter(f=>!f.removedAt),removed=files.filter(f=>f.removedAt);
  const editable=!embedded&&operational(s)&&canReviseSubmission(term);
  async function upload(selected:File[]) {
    if(!selected.length)return;
    if(!await confirmAction(`Unggah ${selected.length} file eviden pekerjaan ke termin ini?`))return;
    setError('');
    if(active.length+pendingCount+selected.length>10){setError('Maksimal 10 lampiran eviden pekerjaan aktif per termin.');return;}
    if(selected.some(f=>!(/\.(pdf|jpe?g|png|webp)$/i).test(f.name)||f.size<=0||f.size>10*1024*1024)) {setError('Pilih PDF atau foto JPG, PNG, WebP; maksimal 10 MB per file.');return;}
    setBusy(true);const token=++batch.current;let sending=-1;
    setProgress(selected.map(file=>({name:file.name,progress:{phase:'pending',percent:0}})));
    const report=(index:number,value:UploadProgress)=>{if(batch.current!==token)return;setProgress(rows=>rows.map((row,i)=>i===index?{...row,progress:value}:row));};
    try {const tasks=[];for(let i=0;i<selected.length;i++){sending=i;report(i,startingUpload());tasks.push(await queueTermWorkEvidence(term.id,selected[i],value=>report(i,value)));}sending=-1;await Promise.all(tasks.map((task,i)=>waitUpload(task,undefined,undefined,value=>report(i,value))));await refreshState();}
    catch(e){if(sending>=0)setProgress(rows=>rows.map((row,i)=>i===sending?{...row,progress:{phase:'error',percent:row.progress.percent}}:row));setError(e instanceof Error?e.message:'Eviden gagal diunggah.');}
    finally{setBusy(false);}
  }
  const rows=(items:WorkEvidence[])=> <Table headers={['Nama file','Jenis / Ukuran','Diunggah','Aksi']}>{items.map(file=><tr key={file.id}><td><FileText size={14}/> {file.name}{file.removedAt&&<small>Dikeluarkan dari pengajuan · {dateLabel(file.removedAt)}</small>}</td><td>{file.mime==='application/pdf'?'PDF':'Foto'}<small>{Math.ceil(file.size/1024)} KB</small></td><td>{dateLabel(file.uploadedAt)}</td><td><div className="actions"><Button onClick={()=>setPreview(file)}><Eye size={14}/> Preview</Button><ActionLink confirm="Unduh file ini?" className="btn" href={workEvidenceUrl(term.id,file.id)+'?download=1'}><Download size={14}/> Unduh</ActionLink>{editable&&!file.removedAt&&<Button confirm={`Keluarkan eviden ${file.name} dari pengajuan? Riwayat file tetap disimpan.`} danger disabled={busy||persistencePending} aria-label={'Hapus eviden '+file.name} onClick={()=>attempt(()=>mutate(state=>removeTermWorkEvidence(state,term.id,file.id)),'Lampiran dikeluarkan; riwayat tetap tersimpan.')}><Trash2 size={14}/></Button>}</div></td></tr>)}</Table>;
  const content=<div className="stack">
    {embedded&&<h3>Eviden pekerjaan ({active.length})</h3>}
    {editable&&<div className="upload-box"><Upload size={24}/><p>Upload PDF atau foto pekerjaan. Maksimal 10 file aktif, masing-masing 10 MB.</p><input aria-label="Upload eviden pekerjaan" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" disabled={!boot||busy||persistencePending||active.length>=10} onChange={e=>{const selected=Array.from(e.target.files??[]);e.target.value='';void upload(selected);}}/></div>}
    {!embedded&&progress.length>0&&<div className="upload-progress-list">{progress.map((row,i)=><UploadProgressView key={i} name={row.name} progress={row.progress}/>)}</div>}
    {!embedded&&boot?.account&&<UploadTasks kind="evidence" documentId={term.id} onPending={setPendingCount} onReady={task=>{if(!refreshing.current&&!persistencePending&&!files.some(f=>f.id===task.id)){refreshing.current=true;void refreshState().catch(e=>setError(e instanceof Error?e.message:String(e))).finally(()=>{refreshing.current=false;});}}}/>}
    {error&&<Notice tone="error"><span role="alert">{error}</span></Notice>}
    {active.length?rows(active):<p className="muted">Belum ada eviden pekerjaan yang diunggah.</p>}
    {!editable&&active.length>0&&<small>File yang diunggah Admin tersedia untuk ditinjau selama approval.</small>}
    {removed.length>0&&<details><summary>Riwayat lampiran yang dikeluarkan ({removed.length})</summary>{rows(removed)}</details>}
    {preview&&<Modal title={'Eviden pekerjaan · '+preview.name} wide onClose={()=>setPreview(null)}><div className="preview-toolbar"><span>{preview.mime==='application/pdf'?'PDF':'Foto pekerjaan'}</span><div className="actions"><a className="btn" href={workEvidenceUrl(term.id,preview.id)} target="_blank" rel="noopener noreferrer"><ExternalLink size={14}/> Buka tab baru</a><ActionLink confirm="Unduh file ini?" className="btn" href={workEvidenceUrl(term.id,preview.id)+'?download=1'}><Download size={14}/> Unduh</ActionLink></div></div><WorkEvidencePreview termId={term.id} file={preview}/></Modal>}
  </div>;
  return embedded?<section className="work-evidence-section">{content}</section>:<Card title="Eviden Pekerjaan" subtitle="Lampiran pekerjaan untuk penilaian pengajuan pembayaran. Upload dan penghapusan tersedia saat pengajuan dapat diperbaiki Admin.">{content}</Card>;
}

import { useState } from 'react';
import { Upload, FileText, Eye, Download, Trash2, ExternalLink } from 'lucide-react';
import { type State, type Term, type WorkEvidence, operational, canReviseSubmission, dateLabel, removeTermWorkEvidence } from './domain';
import { appUrl, boot } from './laravel';
import { mutate, uploadTermWorkEvidence, persistencePending } from './store';
import { Button, Card, Table, Notice, Modal, attempt } from './ui';

export const workEvidenceUrl=(termId:string,fileId:string)=>appUrl('/api/prototype/payments/'+encodeURIComponent(termId)+'/work-evidence/'+encodeURIComponent(fileId));

export function WorkEvidencePreview({termId,file}:{termId:string;file:WorkEvidence}) {
  const url=workEvidenceUrl(termId,file.id);
  return file.mime==='application/pdf'?<iframe className="work-evidence-frame" title={'Preview PDF '+file.name} src={url}/>:<img className="work-evidence-image" alt={'Eviden pekerjaan '+file.name} src={url}/>;
}

export function TermWorkEvidencePanel({s,term,embedded=false}:{s:State;term:Term;embedded?:boolean}) {
  const [busy,setBusy]=useState(false),[progress,setProgress]=useState(''),[error,setError]=useState(''),[preview,setPreview]=useState<WorkEvidence|null>(null);
  const files=term.workEvidence??[],active=files.filter(f=>!f.removedAt),removed=files.filter(f=>f.removedAt);
  const editable=!embedded&&operational(s)&&canReviseSubmission(term);
  async function upload(selected:File[]) {
    if(!selected.length)return;
    setError('');
    if(active.length+selected.length>10){setError('Maksimal 10 lampiran eviden pekerjaan aktif per termin.');return;}
    if(selected.some(f=>!(/\.(pdf|jpe?g|png|webp)$/i).test(f.name)||f.size<=0||f.size>10*1024*1024)) {setError('Pilih PDF atau foto JPG, PNG, WebP; maksimal 10 MB per file.');return;}
    setBusy(true);
    try {for(let i=0;i<selected.length;i++){setProgress(`Mengunggah eviden ${i+1} dari ${selected.length}…`);await uploadTermWorkEvidence(term.id,selected[i]);}}
    catch(e){setError(e instanceof Error?e.message:'Eviden gagal diunggah.');}
    finally{setBusy(false);setProgress('');}
  }
  const rows=(items:WorkEvidence[])=> <Table headers={['Nama file','Jenis / Ukuran','Diunggah','Aksi']}>{items.map(file=><tr key={file.id}><td><FileText size={14}/> {file.name}{file.removedAt&&<small>Dikeluarkan dari pengajuan · {dateLabel(file.removedAt)}</small>}</td><td>{file.mime==='application/pdf'?'PDF':'Foto'}<small>{Math.ceil(file.size/1024)} KB</small></td><td>{dateLabel(file.uploadedAt)}</td><td><div className="actions"><Button onClick={()=>setPreview(file)}><Eye size={14}/> Preview</Button><a className="btn" href={workEvidenceUrl(term.id,file.id)+'?download=1'}><Download size={14}/> Unduh</a>{editable&&!file.removedAt&&<Button danger disabled={busy||persistencePending} aria-label={'Hapus eviden '+file.name} onClick={()=>attempt(()=>mutate(state=>removeTermWorkEvidence(state,term.id,file.id)),'Lampiran dikeluarkan; riwayat tetap tersimpan.')}><Trash2 size={14}/></Button>}</div></td></tr>)}</Table>;
  const content=<div className="stack">
    {embedded&&<h3>Eviden pekerjaan ({active.length})</h3>}
    {editable&&<div className="upload-box"><Upload size={24}/><p>Upload PDF atau foto pekerjaan. Maksimal 10 file aktif, masing-masing 10 MB.</p><input aria-label="Upload eviden pekerjaan" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" disabled={!boot||busy||persistencePending||active.length>=10} onChange={e=>{const selected=Array.from(e.target.files??[]);e.target.value='';void upload(selected);}}/>{busy&&<p role="status">{progress}</p>}</div>}
    {error&&<Notice tone="error"><span role="alert">{error}</span></Notice>}
    {active.length?rows(active):<p className="muted">Belum ada eviden pekerjaan yang diunggah.</p>}
    {!editable&&active.length>0&&<small>File yang diunggah Admin tersedia untuk ditinjau selama approval.</small>}
    {removed.length>0&&<details><summary>Riwayat lampiran yang dikeluarkan ({removed.length})</summary>{rows(removed)}</details>}
    {preview&&<Modal title={'Eviden pekerjaan · '+preview.name} wide onClose={()=>setPreview(null)}><div className="preview-toolbar"><span>{preview.mime==='application/pdf'?'PDF':'Foto pekerjaan'}</span><div className="actions"><a className="btn" href={workEvidenceUrl(term.id,preview.id)} target="_blank" rel="noopener noreferrer"><ExternalLink size={14}/> Buka tab baru</a><a className="btn" href={workEvidenceUrl(term.id,preview.id)+'?download=1'}><Download size={14}/> Unduh</a></div></div><WorkEvidencePreview termId={term.id} file={preview}/></Modal>}
  </div>;
  return embedded?<section className="work-evidence-section">{content}</section>:<Card title="Eviden Pekerjaan" subtitle="Lampiran pekerjaan untuk penilaian pengajuan pembayaran. Upload dan penghapusan tersedia saat pengajuan dapat diperbaiki Admin.">{content}</Card>;
}

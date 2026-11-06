import { useState } from 'react';
import { Download, Upload, FileCheck } from 'lucide-react';
import { appUrl } from './laravel';
import { commitMasterImport, previewMasterImport, MasterRequestError, type MasterImportPreview, type MasterImportSummary } from './store';
import type { MasterCatalog, MasterEntity } from './master-data';
import { Button, Field, Modal, Notice, Table, toast } from './ui';

export function MasterImportActions({entity,canManage,disabled,onOpen}:{entity:MasterEntity;canManage:boolean;disabled:boolean;onOpen:()=>void}) {
  if(!canManage||entity==='paket-khs')return null;
  const label=entity==='designator'?'Designator':'KHS';
  return <><a className="btn" href={appUrl('/api/masters/'+entity+'/template')} title={`Download template ${label}`} aria-label={`Download template ${label}`}><Download size={15}/> Download Template</a><Button title={`Bulk import ${label}`} aria-label={`Bulk import ${label}`} disabled={disabled} onClick={onOpen}><Upload size={15}/> Bulk Import</Button></>;
}
export function MasterImportSummaryView({summary}:{summary:MasterImportSummary}) {
  return <Notice>{summary.created} data baru · {summary.updated} diperbarui · {summary.unchanged} tetap sama.</Notice>;
}
export function masterImportFailure(error:unknown):{inspectAgain:boolean;message:string} {
  if(error instanceof MasterRequestError&&error.status===409)return {inspectAgain:true,message:'Data berubah setelah pemeriksaan. Klik Periksa file kembali untuk meninjau data terbaru; file yang dipilih tetap tersedia.'};
  return {inspectAgain:false,message:(error instanceof Error?error.message:'Penyimpanan belum dikonfirmasi.')+' Jika koneksi terputus, coba Simpan impor lagi pada dialog ini untuk memeriksa permintaan yang sama.'};
}
export function MasterBulkImport({entity,revision,onClose,onComplete}:{entity:'designator'|'khs';revision:number;onClose:()=>void;onComplete:(catalog:MasterCatalog,revision:number)=>void}) {
  const [file,setFile]=useState<File|null>(null),[mode,setMode]=useState('create'),[busy,setBusy]=useState(false),[error,setError]=useState(''),[preview,setPreview]=useState<MasterImportPreview|null>(null),[requestId,setRequestId]=useState('');
  const label=entity==='designator'?'Designator':'KHS';
  function reset(){setPreview(null);setError('');setRequestId('');}
  async function inspect(){
    if(!file||busy)return;setBusy(true);setError('');setPreview(null);
    try{const result=await previewMasterImport(entity,file,mode,revision);setPreview(result);setRequestId(crypto.randomUUID());}
    catch(e){setError(e instanceof Error?e.message:'Pemeriksaan gagal.');}finally{setBusy(false);}
  }
  async function commit(){
    if(!file||!preview||busy)return;setBusy(true);setError('');
    try{const result=await commitMasterImport(entity,file,mode,preview,requestId);onComplete(result.catalog,result.revision);toast(`Impor ${label} selesai: ${result.summary?.created??0} baru, ${result.summary?.updated??0} diperbarui.`);}
    catch(e){const failure=masterImportFailure(e);if(failure.inspectAgain){setPreview(null);setRequestId('');}setError(failure.message);}finally{setBusy(false);}
  }
  return <Modal wide title={`Bulk Import ${label}`} onClose={()=>{if(!busy)onClose();}}><div className="stack">
    <Notice>Gunakan template Excel dari submenu ini. Isi sheet Data mulai baris 2, maksimal 1000 baris dan 5 MB. Seluruh baris harus valid sebelum disimpan.{entity==='khs'&&' Designator dan Paket KHS harus sudah tersedia.'}</Notice>
    <a className="btn" href={appUrl('/api/masters/'+entity+'/template')} title={`Download template ${label}`}><Download size={15}/> Download Template {label}</a>
    <Field label="File Excel (.xlsx)"><input aria-label={`File bulk import ${label}`} type="file" accept=".xlsx" disabled={busy} onChange={e=>{reset();const selected=e.target.files?.[0]??null;if(selected&&(!selected.name.toLowerCase().endsWith('.xlsx')||selected.size>5*1024*1024)){setFile(null);setError('Gunakan file .xlsx maksimal 5 MB.');e.target.value='';}else setFile(selected);}}/></Field>
    <Field label="Mode impor"><select aria-label="Mode impor master" disabled={busy} value={mode} onChange={e=>{setMode(e.target.value);reset();}}><option value="create">Tambah baru saja</option><option value="upsert">Tambah dan perbarui data cocok</option></select></Field>
    <small className="muted">{mode==='create'?'Kode/pasangan yang sudah ada akan ditolak.':'Designator dicocokkan berdasarkan kode; KHS berdasarkan pasangan kode designator dan kode paket. Identitas yang sudah digunakan BOQ tetap dilindungi.'} ID dibuat otomatis. Data arsip tidak dipulihkan.</small>
    {error&&<div className="master-import-errors" role="alert"><Notice tone="error">{error.split('\n').map((line,i)=><div key={i}>{line}</div>)}</Notice></div>}
    {preview&&<><MasterImportSummaryView summary={preview.summary}/><MasterImportPreviewTable preview={preview}/></>}
    <div className="modal-actions"><Button disabled={busy} onClick={onClose}>Tutup</Button><Button disabled={!file||busy} onClick={()=>void inspect()}><FileCheck size={15}/>{busy?'Memproses…':'Periksa file'}</Button><Button primary disabled={!preview||busy} onClick={()=>void commit()}><Upload size={15}/> Simpan impor</Button></div>
  </div></Modal>;
}
export function MasterImportPreviewTable({preview}:{preview:MasterImportPreview}) {
  const action={created:'Tambah',updated:'Perbarui',unchanged:'Tetap'};
  return <div className="master-import-preview"><Table className="master-table" headers={['Baris','Designator','Paket / Uraian','Harga / Satuan','Aksi']}>{preview.rows.map(r=><tr key={r.row}><td>{r.row}</td><td>{r.code}</td><td>{r.package??r.data.item_name}</td><td>{r.data.price??r.data.unit}</td><td>{action[r.action]}</td></tr>)}</Table></div>;
}

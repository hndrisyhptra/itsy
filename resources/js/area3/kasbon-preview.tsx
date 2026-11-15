import { useRef, useState } from 'react';
import { type Term } from './domain';
import { KasbonDocument, type KasbonData } from './kasbon-document';
import { Button, Notice } from './ui';
import { SPK_LOGO_URL } from './spk-assets';

export function KasbonPreview({data,term,kasbonId}:{data:KasbonData;term:Term;kasbonId?:string}){
  const [busy,setBusy]=useState(false),[error,setError]=useState('');const running=useRef(false);
  async function download(){if(running.current)return;running.current=true;setBusy(true);setError('');try{
    const snapshot=structuredClone(data),termSnapshot=structuredClone(term),{createKasbonDocx,kasbonWordFilename}=await import('./kasbon-docx');
    const response=await fetch(SPK_LOGO_URL,{credentials:'same-origin',signal:AbortSignal.timeout(15000)});if(!response.ok)throw new Error('Logo belum dapat dimuat. Coba unduh kembali.');
    const bytes=createKasbonDocx(snapshot,new Uint8Array(await response.arrayBuffer()),termSnapshot,kasbonId);
    const url=URL.createObjectURL(new Blob([bytes as BlobPart],{type:'application/vnd.openxmlformats-officedocument.wordprocessingml.document'}));
    const link=document.createElement('a');link.href=url;link.download=kasbonWordFilename(snapshot);document.body.appendChild(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
  }catch(e){setError(e instanceof Error?e.message:'Word belum dapat dibuat.');}finally{setBusy(false);running.current=false;}}
  return <><div className="preview-toolbar"><span>Kasbon v{term.kasbon?.id===kasbonId?term.kasbon?.version:'arsip'}</span><Button confirm="Unduh Word kasbon dengan kolom tanda tangan kosong?" disabled={busy} aria-busy={busy} onClick={()=>void download()}>{busy?'Menyiapkan Word…':'Unduh Word (.docx)'}</Button><Button confirm="Cetak / Simpan PDF kasbon dengan kolom tanda tangan kosong?" onClick={()=>window.print()}>Cetak / Simpan PDF</Button></div>{error&&<Notice tone="error">{error}</Notice>}<KasbonDocument data={data} term={term} kasbonId={kasbonId}/></>;
}

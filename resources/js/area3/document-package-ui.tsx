import { useRef,useState,useEffect } from 'react';
import { Files,Download,Printer } from 'lucide-react';
import type { State,Bapp } from './domain';
import { documentPackage,documentPackageFilename,type DocumentPackage } from './document-package';
import { ProjectPlanPreviewContent,Modal,Notice,Button,download,toast } from './ui';
import { BakhDocument } from './bakh-document';
import { BakhAppendix } from './bakh-appendix';
import { SpkDocument } from './spk-document';
import { SpkAppendix } from './spk-appendix';
import { BarpDocument } from './barp-document';
import { BarpAppendix } from './barp-appendix';
import { BappDocument } from './bapp-document';
import { BappAppendix } from './bapp-appendix';
import { KasbonDocument } from './kasbon-document';
import type { KasbonData } from './kasbon-content';
import { barpStageLabel } from './barp-labels';
import { SPK_LOGO_URL } from './spk-assets';
import { appUrl } from './laravel';

export function DocumentPackageButton({s,bappId,bapp}:{s:State;bappId:string;bapp?:Bapp}){
  const [open,setOpen]=useState(false);
  const selected=bapp??s.bapps.find(b=>b.id===bappId);
  if(selected?.status!=='Sah'||s.terms.find(t=>t.id===selected.termId)?.type!=='Final')return null;
  return <><Button className="icon-action" aria-label="Unduh semua dokumen PP sampai Lampiran BA PP" title="Unduh semua dokumen · Word / PDF" onClick={()=>setOpen(true)}><Download size={16}/></Button>{open&&<DocumentPackageModal s={s} bappId={bappId} onClose={()=>setOpen(false)}/>}</>;
}
export function DocumentPackageContents({s,c,kasbons=new Map()}:{s:State;c:DocumentPackage;kasbons?:Map<string,KasbonData>}){
  const {project:p,deal:d,spk:v,final}=c;
  const section=(key:string,label:string,content:React.ReactNode)=><section key={key} className="document-package-part" aria-label={label}><h3 className="document-package-label">{label}</h3>{content}</section>;
  return <div className="document-package-content">
    {section('pp','Project Plan',<div data-package-pp><ProjectPlanPreviewContent s={s} p={p}/></div>)}
    {section('bakh','BA Kesepakatan Harga',<BakhDocument project={p} deal={d}/>)}
    {section('bakh-appendix','Lampiran BA KH',<BakhAppendix state={s} project={p} deal={d}/>)}
    {section('spk','Surat Perintah Kerja',<SpkDocument project={p} deal={d} spk={v}/>)}
    {section('spk-appendix','Lampiran SPK',<SpkAppendix state={s} project={p} deal={d} spk={v}/>)}
    {c.terms.map(t=><div key={t.id}>
      {section('barp','BA Rekonsiliasi Pekerjaan '+barpStageLabel(t),<BarpDocument project={p} deal={d} spk={v} term={t}/>)}
      {section('barp-appendix','Lampiran Rekonsiliasi '+barpStageLabel(t),<BarpAppendix state={s} project={p} deal={d} spk={v} term={t}/>)}
      {t.id!==final.id&&c.completions.has(t.id)&&<>{section('bapp-stage','BA PP '+barpStageLabel(t),<BappDocument project={p} deal={d} spk={v} term={t} bapp={c.completions.get(t.id)}/>)}{section('bapp-stage-appendix','Lampiran BA PP '+barpStageLabel(t),<BappAppendix state={s} project={p} deal={d} spk={v} term={t} bapp={c.completions.get(t.id)}/>)}</>}
      {kasbons.has(t.id)&&section('kasbon','Form Kasbon '+barpStageLabel(t),<KasbonDocument data={kasbons.get(t.id)!} term={t} kasbonId={t.kasbon?.id}/>)}
    </div>)}
    {section('bapp','BA Penyelesaian Pekerjaan',<BappDocument project={p} deal={d} spk={v} term={final}/>)}
    {section('bapp-appendix','Lampiran BA PP',<BappAppendix state={s} project={p} deal={d} spk={v} term={final}/>)}
  </div>;
}
export function DocumentPackageModal({s,bappId,onClose}:{s:State;bappId:string;onClose:()=>void}){
  const content=useRef<HTMLDivElement>(null),running=useRef(false),[busy,setBusy]=useState(false),[error,setError]=useState(''),[includeKasbon]=useState(true),[kasbons,setKasbons]=useState(new Map<string,KasbonData>()),[loadedIdentity,setLoadedIdentity]=useState(''),[loading,setLoading]=useState(false),[retry,setRetry]=useState(0);
  let c:DocumentPackage|undefined,invalid='';try{c=documentPackage(s,bappId);}catch(e){invalid=(e as Error).message;}
  const identity=c?.terms.map(t=>t.id+':'+(t.kasbon?.id??'')).join('|')??'';
  useEffect(()=>{
    setKasbons(new Map());setLoadedIdentity('');setError('');if(!includeKasbon||!c)return;
    const a=new AbortController();setLoading(true);
    void (async()=>{const result=new Map<string,KasbonData>();for(const t of c!.terms){if(!t.kasbon)continue;
      const r=await fetch(appUrl('/api/finance/terms/'+t.id+'/kasbon?documents_only=1&current_only=1'),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([a.signal,AbortSignal.timeout(15000)])});
      if(!r.ok)throw new Error('Kasbon tidak dapat dimuat dengan akses akun ini. Paket belum dapat diunduh bersama kasbon.');
      const response=await r.json() as {documents:{id:string;data:KasbonData}[]},current=response.documents.find(d=>d.id===t.kasbon!.id);
      if(!current)throw new Error('Versi kasbon berubah. Muat ulang data sebelum mengunduh.');result.set(t.id,current.data);
    }if(!a.signal.aborted){setKasbons(result);setLoadedIdentity(identity);}})().catch(e=>{if(!a.signal.aborted)setError(e.message);}).finally(()=>{if(!a.signal.aborted)setLoading(false);});
    return()=>a.abort();
  },[includeKasbon,identity,retry]);
  const ready=!!c&&!busy&&!loading&&!error&&(!includeKasbon||loadedIdentity===identity&&c.terms.every(t=>!t.kasbon||kasbons.has(t.id)));
  async function word(){
    if(running.current||!ready||!content.current||!c)return;running.current=true;setBusy(true);
    try{
      const pp=content.current.querySelector<HTMLElement>('[data-project-plan-export="true"]');if(!pp)throw new Error('Isi PP belum siap.');
      const snapshot=pp.cloneNode(true) as HTMLElement,state=structuredClone(s),context=documentPackage(state,bappId),privateData=structuredClone(kasbons);
      const [{captureProjectPlan,PP_DOCX_MIME},{createDocumentPackageDocx}]=await Promise.all([import('./pp-docx'),import('./document-package-docx')]);
      const logo=await fetch(SPK_LOGO_URL,{credentials:'same-origin',signal:AbortSignal.timeout(15000)});if(!logo.ok)throw new Error('Logo belum dapat dimuat. Coba kembali.');
      const bytes=createDocumentPackageDocx(state,context,captureProjectPlan(snapshot),new Uint8Array(await logo.arrayBuffer()),privateData);
      download(documentPackageFilename(context.project.no,'docx'),new Blob([bytes],{type:PP_DOCX_MIME}));
    }catch(e){toast(e instanceof Error?e.message:'Paket Word belum berhasil dibuat.',true);}finally{running.current=false;setBusy(false);}
  }
  async function print(){if(!ready||!content.current||!c)return;const title=document.title;setBusy(true);try{await document.fonts.ready;await Promise.all([...content.current.querySelectorAll('img')].map(img=>img.decode()));document.title=documentPackageFilename(c.project.no,'pdf').replace(/\.pdf$/,'');window.print();}catch{toast('Gambar dokumen belum selesai dimuat. Coba cetak kembali.',true);}finally{document.title=title;setBusy(false);}}
  return <Modal title="Paket Dokumen · PP sampai Lampiran BA PP" wide onClose={()=>{if(!busy)onClose();}}>
    <div className="preview-toolbar"><span><Files size={15}/> Satu berkas gabungan</span><div className="actions"><Button primary confirm="Unduh seluruh dokumen terkait sebagai satu file Word dengan ruang tanda tangan kosong?" disabled={!ready} aria-busy={busy} onClick={()=>void word()}><Download size={14}/>{busy?'Menyiapkan…':'Unduh Semua · Word (.docx)'}</Button><Button confirm="Cetak seluruh paket menjadi satu PDF dengan ruang tanda tangan kosong?" disabled={!ready} onClick={()=>void print()}><Printer size={14}/> Cetak / Simpan PDF</Button></div></div>
    {invalid?<Notice tone="warning">{invalid}</Notice>:c&&<><div className="document-package-info"><strong>{c.project.no}</strong><span>{c.project.name} · {c.spk.no}</span><p>PP → BA KH & lampiran → SPK & lampiran → BARP, lampiran & kasbon setiap pembayaran → BA PP & lampiran.</p><span>Kasbon aktif setiap pembayaran disertakan otomatis sesuai akses akun.{c.terms.some(t=>!t.kasbon)&&' Pembayaran historis yang belum memiliki kasbon tidak dibuatkan berkas baru.'}</span><small>Foto KTP, buku tabungan, eviden, scan TTD dan bukti transfer tetap pada berkas privat masing-masing.</small></div>{loading&&<Notice>Memuat kasbon tersimpan…</Notice>}{error&&<Notice tone="error">{error} <Button disabled={loading} onClick={()=>setRetry(n=>n+1)}>Coba Lagi</Button></Notice>}<div ref={content}><DocumentPackageContents s={s} c={c} kasbons={includeKasbon&&loadedIdentity===identity?kasbons:undefined}/></div></>}
  </Modal>;
}

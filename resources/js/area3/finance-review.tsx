import { useState, type ReactNode } from 'react';
import { Files, ArrowLeft } from 'lucide-react';
import { type State, type Term, getSpk, getDeal, getProject } from './domain';
import { Button, Modal, DocumentPreview, Notice } from './ui';
import { BoqMandorDocument } from './boq-mandor-preview';
import { TermWorkEvidencePanel } from './term-work-evidence';
import { appUrl } from './laravel';
import { KasbonDocument, type KasbonData } from './kasbon-document';

export const FINANCE_REVIEW_DOCUMENTS=[
  'Project Plan','BOQ TA - Mandor','BA Kesepakatan Harga','Lampiran BA KH','Surat Perintah Kerja','Lampiran SPK',
  'BA Rekonsiliasi Pekerjaan – Termin','Lampiran Rekonsiliasi Pekerjaan','Eviden Pekerjaan','Scan TTD','Identitas & Rekening Mandor','Form Kasbon',
] as const;

export function financeReviewContext(s:State,t:Term){const spk=getSpk(s,t.spkId),deal=getDeal(s,spk.dealId),p=getProject(s,deal.projectId);return {spk,deal,p};}

export function FinanceReviewButton({s,t,kasbon,identity}:{s:State;t:Term;kasbon?:KasbonData;identity:ReactNode}) {
  const [open,setOpen]=useState(false),[view,setView]=useState<string>('');
  if(s.role!=='Finance')return null;
  const {spk,deal,p}=financeReviewContext(s,t);
  const close=()=>setView(''),back=<Button onClick={close}><ArrowLeft size={14}/> Kembali ke daftar berkas</Button>;
  const generic=['Project Plan','BA Kesepakatan Harga','Lampiran BA KH','Surat Perintah Kerja','Lampiran SPK','BA Rekonsiliasi Pekerjaan – Termin','Lampiran Rekonsiliasi Pekerjaan'].includes(view);
  const isSpk=view==='Surat Perintah Kerja'||view==='Lampiran SPK',isTerm=view==='BA Rekonsiliasi Pekerjaan – Termin'||view==='Lampiran Rekonsiliasi Pekerjaan';
  return <><Button title="Review berkas PP, BOQ Mandor, BA KH, SPK, rekonsiliasi, eviden dan kasbon" onClick={()=>{setOpen(true);setView('');}}><Files size={15}/> Review Berkas Swakelola</Button>{open&&!view&&<Modal title={'Review Berkas Swakelola · Termin '+t.number} onClose={()=>setOpen(false)} wide><Notice>{p.no} · {p.name}. Periksa data pada setiap berkas dan kelengkapan yang tersedia sebelum menyetujui pembayaran. Membuka preview tidak otomatis menyatakan berkas sesuai.</Notice><div className="finance-review-grid">{FINANCE_REVIEW_DOCUMENTS.map(label=><Button key={label} disabled={label==='Scan TTD'&&!t.scan||label==='Form Kasbon'&&!kasbon} onClick={()=>setView(label)}>{label}{label==='Scan TTD'&&!t.scan?' · belum diunggah':label==='Form Kasbon'&&!kasbon?' · belum diajukan':''}</Button>)}</div></Modal>}
    {open&&generic&&<DocumentPreview state={s} kind={view} project={p} deal={view==='Project Plan'?undefined:deal} spk={isSpk||isTerm?spk:undefined} term={isTerm?t:undefined} onClose={close}/>}
    {open&&view&&!generic&&<Modal title={'Review · '+view} onClose={close} wide>{back}{view==='BOQ TA - Mandor'&&<BoqMandorDocument s={s} p={p}/>} {view==='Eviden Pekerjaan'&&<TermWorkEvidencePanel s={s} term={t} embedded/>}{view==='Scan TTD'&&t.scan&&<iframe className="private-file-preview" sandbox="allow-same-origin" title="Scan TTD pembayaran" src={appUrl('/api/prototype/payments/'+t.id+'/scan')}/>} {view==='Identitas & Rekening Mandor'&&identity}{view==='Form Kasbon'&&kasbon&&<KasbonDocument data={kasbon}/>}</Modal>}
  </>;
}

import {barpCurrent,requiresBarp,barpKasbonReturned} from './bapp-workflow';
import {Check,ArrowRight,FileText} from 'lucide-react';
import type {State,Term} from './domain';
import {operational,getSpk,getDeal,getProject} from './domain';
import {workBappReady,workPaymentReady} from './work-document-flow';
import {Button,Card,Link,Notice} from './ui';
export function WorkDocumentSteps({s,t,stage}:{s:State;t:Term;stage:'barp'|'bapp'|'payment'}){
 const b=s.bapps.find(b=>b.termId===t.id),ready=workBappReady(t,b);
 const steps=[{id:'barp',label:'BA RP & Lampiran',to:'/ba-rp/'+t.id,done:requiresBarp(t)?barpCurrent(t):!!b||t.status!=='Draft'},{id:'bapp',label:'Sahkan BA PP & Lampiran',to:'/ba-rp/'+t.id+'?step=bapp',done:ready},{id:'payment',label:'Kasbon & Pembayaran',to:'/termin/'+t.id,done:!!t.kasbon&&!barpKasbonReturned(t)}];
 return <ol className="work-document-steps" aria-label="Tahapan dokumen pekerjaan">{steps.map((step,i)=><li key={step.id} className={step.id===stage?'active':step.done?'done':''}><Link to={step.to} aria-current={step.id===stage?'step':undefined}><span>{step.done?<Check size={14}/>:i+1}</span><strong>{step.label}</strong></Link></li>)}</ol>;
}
export function WorkDocumentReview({s,t,onPreview}:{s:State;t:Term;onPreview:(kind:string)=>void}){
 const b=s.bapps.find(b=>b.termId===t.id),v=getSpk(s,t.spkId),d=getDeal(s,v.dealId),p=getProject(s,d.projectId),ready=workPaymentReady(t,b);
 return <Card title="Dokumen Pekerjaan" subtitle={`${p.no} · ${v.no}`}><Notice tone={ready?'success':'warning'}>{ready?'Dokumen pekerjaan siap. Lanjutkan pengajuan kasbon dan pembayaran.':'Sahkan BA RP, buat dan sahkan BA PP sebelum mengajukan kasbon dan pembayaran.'}</Notice><div className="work-document-buttons">{[['Preview BA RP','BA Rekonsiliasi Pekerjaan – Termin',!!t.barp],['Lampiran BA RP','Lampiran Rekonsiliasi Pekerjaan',!!t.barp],['Preview BA PP','BA Penyelesaian Pekerjaan',!!b],['Lampiran BA PP','Lampiran BA Penyelesaian Pekerjaan',!!b]].map(([label,kind,available])=><Button key={String(kind)} disabled={!available} onClick={()=>onPreview(String(kind))}><FileText size={14}/>{label}</Button>)}</div>{operational(s)&&<div className="work-document-next"><Link className={ready?'text-link':'btn primary'} to={'/ba-rp/'+t.id+(requiresBarp(t)&&!barpCurrent(t)||ready?'':'?step=bapp')}>{ready?'Buka BA RP & BA PP':requiresBarp(t)&&!barpCurrent(t)?'Lengkapi / Sahkan BA RP':'Lengkapi / Sahkan BA PP'}<ArrowRight size={14}/></Link></div>}</Card>;
}

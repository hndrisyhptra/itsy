import { bappCurrent } from './bapp-workflow';
import { Check,Clock3 } from 'lucide-react';
import type { State,Project,Term,Role,Deal,Spk,Bapp } from './domain';
import { approvalRoles,selectedApprover,nextApprovalRole } from './domain';
import { usesContractBundle } from './project-bundle';
import { usesFinancePayment,usesSettlementPayment } from './finance-payment';
import { settlementRecorded } from './payment-settlement';
import { documentStatus } from './partial-payment';

export function currentFlowApproval(document:Project|Term,role:Role,project=false){return document.approvals?.find(a=>a.role===role&&a.decision==='Setujui'&&((document.workflowVersion??1)<2||a.userId===selectedApprover(document,role))&&(project||!usesSettlementPayment(document as Term)||a.kasbonId===(document as Term).kasbon?.id));}
export interface FlowStep {label:string;detail:string;done:boolean;current?:boolean}
export function paymentFlowSteps(t:Term,bapp?:Bapp):FlowStep[]{
  const paid=settlementRecorded(t),finance=usesFinancePayment(t),modern=usesSettlementPayment(t);
  const approved=approvalRoles(t).every(role=>!!currentFlowApproval(t,role));
  const kasbon={label:'Kasbon Admin',detail:t.kasbon?`Kasbon v${t.kasbon.version} tersimpan`:'Admin melengkapi dan mengajukan kasbon',done:!!t.kasbon,current:modern&&!t.kasbon||!modern&&approved&&!t.kasbon};
  const approvals={label:modern?'7 Approval':'Approval Dokumen',detail:t.status==='Diajukan'?`Menunggu ${nextApprovalRole(t)??'approval'}`:['Ditolak','Dikembalikan'].includes(t.status)?`Perbaikan dan ajukan ulang dari ${approvalRoles(t)[0]}`:approved?'Seluruh persetujuan selesai':`${t.approvals.length}/${approvalRoles(t).length} persetujuan`,done:approved,current:t.status==='Diajukan'};
  if(!finance)return [approvals,{label:'Berkas & Finance',detail:`Scan TTD: ${t.scan?.status??'belum diunggah'}`,done:t.scan?.status==='Valid'},{label:'Realisasi',detail:t.apm?`APM ${t.apm}`:'Mengikuti alur pembayaran tersimpan',done:paid}];
  const proof=!!t.transferProof&&t.transferProof.kasbonId===t.kasbon?.id;
  return [...(t.type==='Final'&&t.completionFlow==='bapp-before-payment-v1'?[{label:'BA RP',detail:'Rekonsiliasi Final tersimpan',done:!!t.barp},{label:'Sahkan BA PP',detail:bappCurrent(bapp,t)?'BA PP Sah sesuai BA RP':'Admin mengesahkan sebelum pengajuan kasbon/pembayaran',done:bappCurrent(bapp,t),current:!bappCurrent(bapp,t)}]:[]),...(modern?[kasbon,approvals]:[approvals,kasbon]),...(!modern?[{label:'Review Finance',detail:t.financePayment?.status??'Menunggu review',done:['Diverifikasi','Dibayar'].includes(t.financePayment?.status??''),current:t.financePayment?.status==='Kasbon Terbit'}]:[]),
    {label:'Bukti Transfer',detail:proof?'Bukti transfer versi kasbon aktif tersimpan':'Diunggah Finance setelah persetujuan',done:proof,current:approved&&!proof},
    {label:'Tandai Lunas',detail:paid?(t.apm?`Lunas · APM ${t.apm}`:'Lunas · APM belum diisi (opsional)'):'Finance menandai lunas setelah upload bukti',done:paid,current:approved&&proof&&!paid}];
}
function FlowStrip({steps,label}:{steps:FlowStep[];label:string}){return <ol className="workflow-overview" aria-label={label}>{steps.map((step,i)=><li key={step.label} className={step.done?'done':step.current?'current':''}><span className="workflow-overview-mark">{step.done?<Check size={14}/>:step.current?<Clock3 size={14}/>:i+1}</span><div><strong>{step.label}</strong><small>{step.detail}</small></div></li>)}</ol>;}
export function PaymentFlowOverview({t,bapp}:{t:Term;bapp?:Bapp}){return <div className="workflow-overview-panel"><FlowStrip steps={paymentFlowSteps(t,bapp)} label="Alur pembayaran tersimpan"/>{usesFinancePayment(t)&&<p className="workflow-overview-note">Berkas {documentStatus(t)} · {t.scan?`Scan ${t.scan.status}`:'Scan TTD dapat disusulkan; pembayaran tidak menunggu upload berkas.'} {t.scan&&t.scan.status!=='Valid'?'Scan yang tersedia perlu diverifikasi Finance.':''}</p>}</div>;}
export function ProjectFlowOverview({s,p,documents}:{s:State;p:Project;documents?:{deals:Deal[];spks:Spk[]}}){
  const deals=documents?.deals??s.deals.filter(d=>d.projectId===p.id),ids=new Set(deals.map(d=>d.id)),spks=documents?.spks??s.spks.filter(v=>ids.has(v.dealId)),bundle=usesContractBundle(p);
  const submitted=p.status==='Diajukan'||p.status==='Sah',done=p.status==='Sah';
  const steps:FlowStep[]=bundle?[
    {label:'Draf PP',detail:'Header, BOQ dan perencanaan',done:submitted||!!p.boq.length},
    {label:'BA KH & SPK',detail:`${deals.length} BA KH · ${spks.length} SPK`,done:!!deals.length&&!!spks.length,current:!submitted},
    {label:'Ajukan Paket',detail:'PP + BA KH + SPK satu pengajuan',done:submitted},
    {label:'5 Approval',detail:p.status==='Diajukan'?`Menunggu ${nextApprovalRole(p,true)}`:done?'Paket Sah':p.status==='Ditolak'||p.status==='Dikembalikan'?`Perbaiki paket; ulang dari ${approvalRoles(p,true)[0]}`:approvalRoles(p,true).join(' → '),done,current:p.status==='Diajukan'},
  ]:[{label:'PP Historis',detail:'Mengikuti alur dan keputusan tersimpan',done},{label:'BA KH & SPK',detail:`${deals.length} BA KH · ${spks.length} SPK`,done:spks.some(v=>v.status==='Sah')}];
  return <FlowStrip steps={steps} label={bundle?'Alur paket PP, BA KH dan SPK':'Alur PP historis'}/>;
}

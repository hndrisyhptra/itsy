import {useMemo,useRef,useState} from 'react';
import {Check,Undo2,RotateCcw} from 'lucide-react';
import {type State,type Project,type Term,approvalRoles,canApproveProject,canApproveTerm,decideProject,decide,dateLabel,nextApprovalRole} from './domain';
import {approvalResetPlan,resetProjectApproval,resetPaymentApproval} from './approval-reset';
import {usesFinancePayment,usesSettlementPayment,decideFinancePayment} from './finance-payment';
import {canReviewLegacySettlement,decideLegacySettlementReview,legacyReviewApproved} from './legacy-settlement-review';
import {mutate,waitForPersistence,persistencePending} from './store';
import {Card,Field,Button,Notice,Table,toast} from './ui';

/** Decisions stay outside document review cards; the server repeats every ownership/version guard. */
export function ApprovalDecisionCard({s,document,project=false,onSelectTarget}:{s:State;document:Project|Term;project?:boolean;onSelectTarget?:()=>void}) {
 const [note,setNote]=useState(''),[busy,setBusy]=useState(false),running=useRef(false),t=document as Term;
 const plan=useMemo(()=>approvalResetPlan(s,document,project),[s,document,project]),main=project?canApproveProject(s,document as Project):canApproveTerm(s,t);
 const manager=!project&&canReviewLegacySettlement(s,t);
 const finance=!project&&s.role==='Finance'&&usesFinancePayment(t)&&!usesSettlementPayment(t)&&t.status==='Disetujui'&&!!t.kasbon&&t.financePayment?.status==='Kasbon Terbit'&&legacyReviewApproved(t);
 const canDecide=main||manager||finance,locked=busy||persistencePending,role=nextApprovalRole(document,project);
 const approveBlocked=!project&&s.role==='Finance'&&!!t.scan&&t.scan.status!=='Valid';
 async function run(action:'Setujui'|'Kembalikan'|'reset'){
  if(running.current||persistencePending)return;
  if(action!=='Setujui'&&!note.trim()){toast(action==='reset'?'Isi alasan pembatalan persetujuan.':'Isi alasan pengembalian untuk revisi.',true);return;}
  running.current=true;setBusy(true);onSelectTarget?.();
  try{
   mutate(draft=>{
    if(action==='reset'){if(project)resetProjectApproval(draft,document.id,plan.expected,note);else resetPaymentApproval(draft,document.id,plan.expected,note);}
    else if(project)decideProject(draft,document.id,action,note);
    else if(manager)decideLegacySettlementReview(draft,t.id,t.kasbon!.id,action,note);
    else if(finance)decideFinancePayment(draft,t.id,action,note,t.kasbon!.id);
    else decide(draft,t.id,action,note);
   });
   await waitForPersistence();setNote('');toast(action==='reset'?'Persetujuan dibatalkan. Giliran dan riwayat telah diperbarui.':'Keputusan tersimpan.');
  }catch(error){toast(error instanceof Error?error.message:String(error),true);}
  finally{running.current=false;setBusy(false);}
 }
 const affected=plan.affected.map(a=>a.role).join(', ');
 return <Card title={project?'Approve Paket PP, BA KH, SPK':'Approve Pembayaran'} subtitle={project?approvalRoles(document,true).join(' → '):usesSettlementPayment(t)?approvalRoles(t).join(' → '):'Pengajuan historis mengikuti susunan approval dan review tersimpan.'} className="approval-decision-card">
  {canDecide||plan.own&&!plan.reason?<Field label="Catatan / alasan pengembalian atau pembatalan persetujuan" hint="Wajib untuk pengembalian dan pembatalan persetujuan."><textarea aria-label="Catatan approval" rows={3} maxLength={1000} value={note} disabled={locked} onChange={event=>setNote(event.target.value)}/></Field>:null}
  {approveBlocked&&canDecide&&<Notice tone="warning">Verifikasi scan yang tersedia sebelum menyetujui pembayaran.</Notice>}
  {!canDecide&&!plan.own&&<Notice>{document.status==='Diajukan'?`Menunggu ${role??'approver yang ditugaskan'}.`:`Status pengajuan: ${document.status}.`}</Notice>}
  {plan.own&&plan.reason&&<Notice tone="warning">{plan.reason}</Notice>}
  {plan.own&&!plan.reason&&<Notice>Pembatalan akan mengulang persetujuan {affected}. Keputusan sebelumnya tetap tercatat; persetujuan sebelum tahap tersebut tetap berlaku.</Notice>}
  <div className="actions">
   {canDecide&&<><Button confirm={`Kembalikan ${project?'paket PP':'pengajuan pembayaran'} untuk revisi dengan catatan ini? ${main?`Admin mengajukan ulang dari ${approvalRoles(document,project)[0]}.`:'Pengajuan historis tetap mengikuti alur tersimpan.'}`} disabled={locked} onClick={()=>void run('Kembalikan')}><Undo2 size={15}/> Kembalikan untuk Revisi</Button><Button primary confirm={`Setujui ${project?'paket PP, BA KH dan SPK':'pengajuan pembayaran'} ini? Keputusan dicatat atas akun Anda.`} disabled={locked||approveBlocked} onClick={()=>void run('Setujui')}><Check size={15}/>{project?'Setujui PP':'Setujui Pembayaran'}</Button></>}
   {plan.own&&<Button danger confirm={`Batalkan persetujuan Anda pada pengajuan ini? Persetujuan yang diulang: ${affected}. Riwayat lama tetap tersimpan.`} disabled={locked||!!plan.reason} reason={plan.reason||undefined} onClick={()=>void run('reset')}><RotateCcw size={15}/> Batalkan Persetujuan Saya</Button>}
  </div>
 </Card>;
}

export function ApprovalResetHistory({document}:{document:Project|Term}) {
 const [page,setPage]=useState(1),rows=document.approvalResets??[];
 if(!rows.length)return null;
 const pages=Math.max(1,Math.ceil(rows.length/20)),current=Math.min(page,pages),end=rows.length-(current-1)*20,visible=rows.slice(Math.max(0,end-20),end).reverse();
 return <Card title="Riwayat Pembatalan Persetujuan"><Table headers={['Akun / Peran','Persetujuan yang diulang','Alasan','Waktu']}>{visible.map(row=><tr key={row.id}><td>{row.actorName}<small>{row.role}</small></td><td>{row.affectedApprovals.map(a=>a.role).join(', ')}</td><td>{row.reason}</td><td>{dateLabel(row.at)}<small>{new Date(row.at).toLocaleTimeString('id-ID',{timeZone:'Asia/Jakarta'})} WIB</small></td></tr>)}</Table><div className="table-foot"><span>{rows.length} pembatalan · {current}/{pages}</span><div className="actions"><Button disabled={current===1} onClick={()=>setPage(current-1)}>Sebelumnya</Button><Button disabled={current===pages} onClick={()=>setPage(current+1)}>Berikutnya</Button></div></div></Card>;
}

import { ApprovalResetHistory } from './approval-actions';
import { ProjectFlowOverview,PaymentFlowOverview,currentFlowApproval } from './workflow-overview';
import { DocumentPackageButton } from './document-package-ui';
import { usesContractBundle } from './project-bundle';
import { barpStageLabel } from './barp-labels';
import {legacyReviewDecisions,legacyReviewApproved} from './legacy-settlement-review';
import { settlementRecorded } from './payment-settlement';
import { paymentDue } from './kasbon-settlement';
import { usesSettlementPayment, usesFinancePayment, financePaymentLabel } from './finance-payment';
import { usesPartialPayment, documentStatus, partialFinanceHistory, partialPaymentLabel } from './partial-payment';
import { useState } from 'react';
import { Check, Clock3, ArrowUpRight } from 'lucide-react';
import { type State, type Project, type Term, type Approval, nextApprovalRole, approvalRoles, selectedApprover, money, termTotal } from './domain';
import { projectTimeline, projectPosition, paymentPosition } from './project-timeline';
import { PageTitle, Card, Badge, Link, Notice, Table, Empty, Field } from './ui';
import { appUrl } from './laravel';

const stamp=(value?:string)=>value&&Number.isFinite(Date.parse(value))?new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',...(value.includes('T')?{timeStyle:'short' as const}:{}),timeZone:'Asia/Jakarta'}).format(new Date(value)):'Waktu tidak tercatat';

export function ApprovalTrack({s,document,project=false}:{s:State;document:Project|Term;project?:boolean}) {
  const roles=approvalRoles(document,project), approvals=document.approvals??[],waiting=document.status==='Diajukan'?nextApprovalRole(document,project):undefined;
  return <ol className="project-approval-track" aria-label={project?'Tahap approval PP':'Tahap approval pembayaran'}>{roles.map((role,i)=>{
    const decision=currentFlowApproval(document,role,project),current=role===waiting;
    const name=decision?.actorName??s.users.find(u=>u.id===(decision?.userId??selectedApprover(document,role)))?.name;
    const status=decision?'Disetujui':current?'Menunggu approval':document.status==='Draft'?'Belum diajukan':['Ditolak','Dikembalikan'].includes(document.status)?'Alur berhenti':'Belum giliran';
    return <li key={role} className={decision?'done':current?'waiting':'pending'}><span className="project-stage-mark" aria-hidden="true">{decision?<Check size={14}/>:current?<Clock3 size={14}/>:i+1}</span><div><strong>{role}</strong><span>{name??(!project&&role==='Finance'&&usesSettlementPayment(document as Term)?'Finance sesuai Branch':(document.workflowVersion??1)<2?'Nama tidak tercatat':'Belum dipilih')}</span><b>{status}</b>{decision&&<small>{stamp(decision.at)}</small>}</div></li>;
  })}</ol>;
}

function DecisionHistory({s,rows}:{s:State;rows:Approval[]}) {
  if(!rows.length) return <p className="muted">Belum ada keputusan approval tercatat.</p>;
  return <Table headers={['Approver / Peran','Keputusan','Catatan','Waktu']}>
    {[...rows].sort((a,b)=>(Date.parse(b.at)||0)-(Date.parse(a.at)||0)).map((a,i)=><tr key={i}><td>{a.actorName??s.users.find(u=>u.id===a.userId)?.name??'Nama tidak tercatat'}<small>{a.role}</small></td><td><Badge>{a.decision==='Setujui'?'Disetujui':a.decision==='Kembalikan'?'Dikembalikan':'Ditolak'}</Badge></td><td>{a.note||'—'}</td><td>{stamp(a.at)}</td></tr>)}
  </Table>;
}

function PaymentTimeline({s,t}:{s:State;t:Term}) {
  const spk=s.spks.find(v=>v.id===t.spkId)!,deal=s.deals.find(d=>d.id===spk.dealId)!;
  const amount=termTotal(s,t),modern=(t.workflowVersion??1)>=2;
  const validFinance=(t.workflowVersion??1)>=3?t.scan?.status==='Valid'&&t.financeApproval?.decision==='Setujui'&&t.financeApproval.scanId===t.scan.id:t.scan?.status==='Valid';
  const financeDecisions=[...legacyReviewDecisions(t),...(usesSettlementPayment(t)?[]:(t.financePaymentHistory??[]))];
  const scans=[...(t.scanHistory??[]),...(t.scan?[t.scan]:[])];
  return <article className="project-payment-timeline"><div className="project-timeline-heading"><div><h3>Pembayaran · {barpStageLabel(t)||'Final'}</h3><p>{spk.no} · {deal.mandor}</p></div><Link className="btn" to={'/termin/'+t.id}>Lihat pembayaran <ArrowUpRight size={14}/></Link></div>
    <div className="project-current-position"><Badge>{t.status}</Badge><strong>{paymentPosition(t)}</strong><span>{t.approvals.length}/{approvalRoles(t).length} approval dokumen</span></div>
    <ApprovalResetHistory document={t}/><PaymentFlowOverview t={t} bapp={s.bapps.find(b=>b.termId===t.id)}/><ApprovalTrack s={s} document={t}/>
    {usesPartialPayment(t)&&<div className="project-finance-stage"><strong>Finance · Payment Parsial</strong><Badge>{t.paymentRequest?.status??'Belum diajukan'}</Badge><span>{partialPaymentLabel(t)} · Berkas {documentStatus(t)}{t.paymentRequest?.decision&&` · ${t.paymentRequest.decision.actorName}`}</span></div>}
    {t.legacySettlementReview&&<div className="project-finance-stage"><strong>Mgr Finance &amp; Settlement · Review tambahan kasbon</strong><Badge>{legacyReviewApproved(t)?'Disetujui':t.legacySettlementReview.decision?.decision==='Kembalikan'?'Dikembalikan':'Menunggu'}</Badge><span>{t.legacySettlementReview.name} · Kasbon v{t.kasbon?.version}{t.legacySettlementReview.decision&&' · '+stamp(t.legacySettlementReview.decision.at)}</span></div>}
    {usesFinancePayment(t)&&!usesSettlementPayment(t)&&<div className="project-finance-stage"><strong>Finance · Kasbon &amp; Payment</strong><Badge>{financePaymentLabel(t)}</Badge><span>Kasbon {t.kasbon?'v'+t.kasbon.version:'belum terbit'} · Bukti transfer {t.transferProof?'tersimpan':'belum diunggah'} · Berkas {documentStatus(t)}</span></div>}
    {modern&&<div className="project-finance-stage"><strong>{(usesFinancePayment(t)||t.workflowVersion===3&&t.type==='Parsial')?'Verifikasi berkas · scan TTD fisik':'Finance · setelah scan TTD fisik'}</strong><Badge>{validFinance?(t.workflowVersion??1)>=3?'Disetujui':'Valid':t.scan?.status==='Perlu Koreksi'?'Perlu Koreksi':'Menunggu'}</Badge><span>{validFinance?((t.financeApproval?.actorName??s.users.find(u=>u.id===t.scan?.verifiedBy)?.name??'Finance')+' · '+stamp(t.financeApproval?.at??t.scan?.verifiedAt)):t.scan?'Menunggu scan yang valid dan keputusan Finance.':'Belum ada scan TTD fisik diunggah.'}</span></div>}
    <dl className="project-payment-facts"><div><dt>Tagihan bruto</dt><dd>{money(amount)}</dd></div><div><dt>Nilai bayar / kasbon</dt><dd>{money(paymentDue(t,amount))}</dd></div><div><dt>Terbayar</dt><dd>{money(t.paid)}</dd></div><div><dt>APM</dt><dd>{t.apm||'Belum dicatat'}<small>{usesFinancePayment(t)&&!t.apm?'Opsional; tidak menahan pelunasan':t.apmStatus}</small></dd></div></dl>
    {!!scans.length&&<details><summary>Riwayat scan TTD fisik ({scans.length})</summary><Table headers={['Berkas','Status','Diunggah','Verifikasi / Catatan']}>{scans.map(file=><tr key={file.id}><td>{file.id===t.scan?.id?<a href={appUrl('/api/prototype/payments/'+t.id+'/scan')}>{file.name}</a>:file.name}<small>{file.id===t.scan?.id?'Berkas saat ini':'Berkas sebelumnya'}</small></td><td><Badge>{file.status}</Badge></td><td>{stamp(file.uploadedAt)}</td><td>{file.verifiedAt?stamp(file.verifiedAt):'Belum diverifikasi'}<small>{file.note||'—'}</small></td></tr>)}</Table></details>}
    <details><summary>Riwayat keputusan pembayaran ({t.history.length+(t.financeHistory?.length??0)+partialFinanceHistory(t).length+financeDecisions.length})</summary><DecisionHistory s={s} rows={[...t.history,...(t.financeHistory??[]),...partialFinanceHistory(t),...financeDecisions]}/></details>
    {!!t.paymentHistory.length&&<details><summary>Riwayat pencatatan pembayaran ({t.paymentHistory.length})</summary><Table headers={['Referensi','Jumlah','Waktu']}>{t.paymentHistory.map((v,i)=><tr key={i}><td>{v.ref}</td><td className="numeric">{money(v.amount)}</td><td>{stamp(v.at)}</td></tr>)}</Table></details>}
  </article>;
}

export function ProjectTimeline({s,id}:{s:State;id:string}) {
  const [selection,setSelection]=useState({anchor:id,value:id}),[activityPage,setActivityPage]=useState(1),data=projectTimeline(s,id);
  const filter=selection.anchor===id?selection.value:id;
  if(!data) return <Empty title="Project Plan tidak ditemukan" description="PP tidak tersedia atau berada di luar Branch penugasan akun Anda."/>;
  const shown=data.projects.filter(p=>!filter||p.id===filter),events=data.events.filter(e=>!filter||e.projectId===filter);
  const settled=data.terms.filter(t=>t.status==='Disetujui'&&t.paid>=paymentDue(t,termTotal(s,t))&&termTotal(s,t)>0&&((t.workflowVersion??1)<2||settlementRecorded(t))).length;
  return <><PageTitle back="/project-plan" title="Timeline ID Project" description={data.anchor.projectCode}><Badge>{data.projects.length} PP</Badge></PageTitle>
    <Notice>Satu ID Project dapat memiliki beberapa PP. Timeline mengikuti akses Branch akun; BOQ, approval dan pembayaran setiap PP tetap terpisah.</Notice>
    <div className="summary-cards compact"><div><span>Project Plan</span><strong>{data.projects.length}</strong></div><div><span>PP Sah</span><strong>{data.projects.filter(p=>p.status==='Sah').length}</strong></div><div><span>Pengajuan pembayaran</span><strong>{data.terms.length}</strong></div><div><span>Tagihan lunas</span><strong>{settled}</strong></div></div>
    <div className="project-timeline-filter"><Field label="Tampilkan PP"><select value={filter} onChange={e=>{setSelection({anchor:id,value:e.target.value});setActivityPage(1);}}><option value="">Seluruh PP pada ID Project ini</option>{data.projects.map(p=><option key={p.id} value={p.id}>{p.no} · {p.branch}</option>)}</select></Field></div>
    {shown.map(p=>{
      const {deals,spks,terms:relatedTerms,bapps}=data.byProject.get(p.id)!;
      const terms=[...relatedTerms].sort((a,b)=>a.spkId.localeCompare(b.spkId)||a.number-b.number);
      return <Card key={p.id} title={p.no} subtitle={p.name+' · '+p.branch} className={'project-timeline-card '+(p.id===id?'selected':'')} action={<Link className="btn" to={'/project-plan/'+p.id}>Detail PP <ArrowUpRight size={14}/></Link>}>
        <div className="project-current-position"><Badge>{p.status}</Badge><strong>{usesContractBundle(p)?projectPosition(p).replace('PP Sah','Paket PP, BA KH & SPK Sah'):projectPosition(p)}</strong>{(p.workflowVersion??1)>=2&&<span>{p.approvals?.length??0}/{approvalRoles(p,true).length} approval PP</span>}</div>
        {p.headApprovalReopen && <Notice tone="warning">Koreksi approval Head · {stamp(p.headApprovalReopen.at)}: {p.headApprovalReopen.reason} Keputusan Head sebelum koreksi disimpan sebagai riwayat. {p.status !== 'Sah' && 'Proses dokumen turunan ditahan sampai PP Sah kembali.'}</Notice>}
        <ProjectFlowOverview s={s} p={p} documents={{deals,spks}}/>
        {(p.workflowVersion??1)>=2?<><ApprovalTrack s={s} document={p} project/><ApprovalResetHistory document={p}/></>:<p className="muted">PP menggunakan alur historis. Persetujuan yang tidak tercatat tidak dibuat otomatis.</p>}
        <details><summary>Riwayat keputusan PP ({p.approvalHistory?.length??0})</summary><DecisionHistory s={s} rows={p.approvalHistory??[]}/></details>
        <h3 className="project-timeline-section">BA KH &amp; SPK</h3>{deals.length?<Table headers={['BA KH','Mandor','Status BA KH','SPK / Status']}>{deals.map(d=><tr key={d.id}><td><Link to={'/spk/ba-kh/'+d.id}>{d.no}</Link></td><td>{d.mandor}</td><td><Badge>{d.status}</Badge></td><td>{spks.filter(v=>v.dealId===d.id).map(v=><div key={v.id}><Link to={'/spk/'+v.id}>{v.no}</Link> <Badge>{v.status}</Badge></div>)}{!spks.some(v=>v.dealId===d.id)&&'SPK belum dibuat'}</td></tr>)}</Table>:<p className="muted">BA KH dan SPK belum dibuat.</p>}
        <h3 className="project-timeline-section">Pengajuan pembayaran</h3>{terms.length?terms.map(t=><PaymentTimeline key={t.id} s={s} t={t}/>):<p className="muted">Belum ada pengajuan pembayaran pada PP ini.</p>}
        {!!bapps.length&&<><h3 className="project-timeline-section">BA PP &amp; pelepasan volume</h3><Table headers={['BA PP','Status','Pelepasan volume','Dokumen']}>{bapps.map(b=><tr key={b.id}><td><Link to={'/ba-pp/'+b.id}>{b.no}</Link></td><td><Badge>{b.status}</Badge></td><td>{b.releaseHistory.length} aktivitas pelepasan</td><td><DocumentPackageButton s={s} bappId={b.id} bapp={b}/></td></tr>)}</Table></>}
      </Card>;
    })}
    <Card title="Riwayat seluruh aktivitas" subtitle="Terbaru lebih dahulu. Menampilkan aktivitas yang tercatat untuk PP dan dokumen terkait.">
      {events.length?<ol className="project-activity-timeline">{events.slice((activityPage-1)*20,activityPage*20).map(e=><li key={e.id}><span className="project-activity-dot" aria-hidden="true"/><div><time>{stamp(e.at)} WIB</time><Link to={e.route}><strong>{e.title}</strong></Link><p>{e.detail}</p><small>{data.projects.find(p=>p.id===e.projectId)?.no} · {e.actor}</small></div></li>)}</ol>:<p className="muted">Belum ada aktivitas tercatat. Posisi dokumen dan riwayat keputusan yang tersedia ditampilkan di atas.</p>}
      {events.length>20&&<div className="table-foot"><span>{events.length} aktivitas · Halaman {activityPage}</span><div className="actions"><button className="btn" disabled={activityPage===1} onClick={()=>setActivityPage(n=>n-1)}>Sebelumnya</button><button className="btn" disabled={activityPage*20>=events.length} onClick={()=>setActivityPage(n=>n+1)}>Berikutnya</button></div></div>}
    </Card>
  </>;
}

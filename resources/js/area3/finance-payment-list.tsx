import { paymentDescription } from './payment-labels';
import { useEffect, useRef, useState } from 'react';
import { ArrowUpRight, CheckCircle2, Clock3, CreditCard, FolderKanban, RefreshCw, Search, Wallet } from 'lucide-react';
import type { State } from './domain';
import { money, dateLabel } from './domain';
import { appUrl, boot } from './laravel';
import { createInboxSync } from './inbox-sync';
import { inboxRevision } from './store';
import { Badge, Button, Card, Empty, Link, Notice, PageTitle, Table } from './ui';

export const canSeePaymentList=(role:string)=>['Finance','Mgr Finance & Settlement'].includes(role);
export interface PaymentListItem {id:string;ppId:string;ppNo:string;projectCode:string;name:string;spkNo:string;mandor:string;branch:string;number:number;type:string;amount:number;paid:boolean;paidAmount:number;approval:string;documents:'OK'|'NOK';scanStatus:string|null;apm:string|null;paidAt:string|null;submittedAt:string|null}
export interface PaymentListData {items:PaymentListItem[];page:number;hasMore:boolean;revision:number;summary:{total:number;projects:number;paid:number;unpaid:number;paidAmount:number;unpaidAmount:number}}
interface Filters {q:string;branch:string;status:'all'|'paid'|'unpaid';page:number}
const zero={total:0,projects:0,paid:0,unpaid:0,paidAmount:0,unpaidAmount:0};
async function readPaymentList(path:string,signal:AbortSignal):Promise<PaymentListData>{
  const response=await fetch(appUrl(path),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([signal,AbortSignal.timeout(15000)])});
  if(!response.ok)throw new Error(response.status===403?'Akses List Payment tidak tersedia untuk akun ini.':'Daftar Payment belum dapat dimuat. Periksa koneksi lalu coba lagi.');
  return response.json();
}

export function PaymentListView({data,filters,branches,busy,error,onFilter,onRefresh}:{data?:PaymentListData;filters:Filters;branches:string[];busy:boolean;error:string;onFilter:(f:Partial<Filters>)=>void;onRefresh:()=>void}){
  const summary=data?.summary??zero;
  return <section className="finance-payment-list" aria-busy={busy}>
    <PageTitle title="List Payment" description="Pantau pengajuan pembayaran LOP, progres persetujuan, dan realisasi transfer sesuai Branch Anda."><Button disabled={busy} onClick={onRefresh}><RefreshCw size={15}/> Perbarui</Button></PageTitle>
    <div className="payment-list-banner"><div><span className="payment-list-eyebrow"><CreditCard size={15}/> MONITORING PEMBAYARAN</span><h2>Setiap pengajuan, jelas statusnya.</h2><p>Telusuri LOP yang menunggu pembayaran hingga bukti transfer dan pelunasan tercatat.</p></div><div className="payment-list-progress"><strong>{summary.total?Math.floor(summary.paid/summary.total*100):0}%</strong><span>pengajuan terbayar</span><progress aria-label="Persentase pengajuan terbayar" max={Math.max(1,summary.total)} value={summary.paid}/></div></div>
    <div className="payment-list-stats">
      {[{label:'Seluruh pengajuan',value:summary.total,detail:`${summary.projects} ID Project`,icon:FolderKanban,status:'all' as const},{label:'Belum terbayar',value:summary.unpaid,detail:money(summary.unpaidAmount),icon:Clock3,status:'unpaid' as const},{label:'Sudah terbayar',value:summary.paid,detail:money(summary.paidAmount),icon:CheckCircle2,status:'paid' as const}].map(card=><button key={card.status} type="button" className={`payment-list-stat ${card.status} ${filters.status===card.status?'selected':''}`} aria-pressed={filters.status===card.status} disabled={busy&&!data} onClick={()=>onFilter({status:card.status,page:1})}><span className="payment-list-stat-icon"><card.icon size={20}/></span><span><small>{card.label}</small><strong>{busy&&!data?'—':card.value}</strong><span>{busy&&!data?'Memuat…':card.detail}</span></span></button>)}
    </div>
    <Card title="Daftar Pengajuan Payment" subtitle="Nominal pengajuan mengikuti kasbon tersimpan. Status berkas terpisah dari status pembayaran." className="no-padding">
      <div className="payment-list-filters"><div className="payment-list-search"><Search size={17}/><input aria-label="Cari LOP atau Payment" value={filters.q} maxLength={100} placeholder="Cari LOP, ID Project, PP, SPK, mandor, atau APM…" onChange={e=>onFilter({q:e.target.value,page:1})}/></div><select aria-label="Filter Branch Payment" value={filters.branch} onChange={e=>onFilter({branch:e.target.value,page:1})}><option value="">Semua Branch</option>{branches.map(branch=><option key={branch}>{branch}</option>)}</select><select aria-label="Filter status Payment" value={filters.status} onChange={e=>onFilter({status:e.target.value as Filters['status'],page:1})}><option value="all">Semua status pembayaran</option><option value="unpaid">Belum terbayar</option><option value="paid">Sudah terbayar</option></select></div>
      {error&&<Notice tone="error"><span role="alert">{error}</span> <Button disabled={busy} onClick={onRefresh}>Coba lagi</Button></Notice>}
      {busy&&!data&&<div className="payment-list-loading" role="status"><Wallet size={24}/><span>Memuat daftar pembayaran…</span></div>}
      {!busy&&!error&&!data?.items.length&&<Empty title="Belum ada Payment yang sesuai" description="Pengajuan akan tampil setelah kasbon atau permintaan Payment diajukan. Coba ubah pencarian atau filter."/>}
      {data&&data.items.length>0&&<Table className="payment-list-table" headers={['LOP / ID Project','Dokumen / Mandor','Pengajuan','Nilai Pengajuan','Status Pembayaran','Approval / Berkas','Aksi']}>{data.items.map(row=><tr key={row.id}>
        <td><strong className="payment-list-job">{row.name}</strong><small className="payment-list-code">{row.projectCode}</small><span className="payment-list-branch">{row.branch}</span></td>
        <td><Link className="record-no" to={'/project-plan/'+row.ppId}>{row.ppNo}</Link><small>{row.spkNo}</small><small>{row.mandor}</small></td>
        <td><b>{paymentDescription(row)}</b><small>{row.submittedAt?dateLabel(row.submittedAt):'Tanggal belum tersedia'}</small></td>
        <td className="numeric"><strong>{money(row.amount)}</strong></td>
        <td><span className={`payment-list-status ${row.paid?'paid':'unpaid'}`}>{row.paid?<CheckCircle2 size={14}/>:<Clock3 size={14}/>} {row.paid?'Sudah Terbayar':'Belum Terbayar'}</span>{row.paid&&<><small>{money(row.paidAmount)}</small><small>{row.apm?`APM ${row.apm}`:'APM belum diisi (opsional)'}</small><small>{row.paidAt?dateLabel(row.paidAt):''}</small></>}</td>
        <td><small className="payment-list-approval">{row.approval}</small><Badge>{row.documents}</Badge><small>{row.documents==='NOK'?'Scan TTD belum diunggah':row.scanStatus==='Valid'?'Berkas terverifikasi':'Scan TTD tersedia'}</small></td>
        <td><Link className="btn payment-list-detail" title={'Lihat detail Payment '+row.ppNo} to={'/termin/'+row.id}>Detail <ArrowUpRight size={14}/></Link></td>
      </tr>)}</Table>}
      <div className="table-foot"><span>{data?.items.length??0} pengajuan ditampilkan · Halaman {filters.page}</span><div className="actions"><Button disabled={busy||filters.page===1} onClick={()=>onFilter({page:filters.page-1})}>Sebelumnya</Button><Button disabled={busy||!data?.hasMore} onClick={()=>onFilter({page:filters.page+1})}>Berikutnya</Button></div></div>
    </Card><p className="payment-list-footnote">Sudah terbayar berarti Finance telah menandai lunas dengan bukti transfer. APM opsional untuk pembayaran kasbon. Transfer dilakukan oleh Finance di luar aplikasi.</p>
  </section>;
}

export function FinancePaymentList({s}:{s:State}){
  const [filters,setFilters]=useState<Filters>({q:'',branch:'',status:'all',page:1}),[data,setData]=useState<PaymentListData>(),[busy,setBusy]=useState(true),[error,setError]=useState(''),[retry,setRetry]=useState(0);
  const revision=useRef(0),allowed=canSeePaymentList(s.role)&&!!boot?.account;
  useEffect(()=>{
    if(!allowed)return;
    let ready=false;const controller=new AbortController();setBusy(true);setData(undefined);setError('');
    const fetchList=async(signal:AbortSignal)=>{const query=new URLSearchParams({q:filters.q.trim(),branch:filters.branch,status:filters.status,page:String(filters.page)});const next=await readPaymentList('/api/finance/payments?'+query,signal);if(!signal.aborted){ready=true;revision.current=next.revision;setData(next);setError('');}};
    const timer=setTimeout(()=>void fetchList(controller.signal).catch(e=>{if(!controller.signal.aborted)setError(e.message);}).finally(()=>{if(!controller.signal.aborted)setBusy(false);}),300);
    const sync=createInboxSync({visible:()=>document.visibilityState!=='hidden',eligible:()=>ready&&!document.querySelector('[role="dialog"], [role="alertdialog"]'),revision:()=>revision.current,probe:inboxRevision,refresh:fetchList});
    const wake=()=>sync.wake();document.addEventListener('visibilitychange',wake);window.addEventListener('focus',wake);
    return()=>{clearTimeout(timer);controller.abort();sync.stop();document.removeEventListener('visibilitychange',wake);window.removeEventListener('focus',wake);};
  },[allowed,filters,retry]);
  if(!allowed)return <Empty title="Akses List Payment terbatas" description="Halaman ini tersedia untuk Mgr Finance & Settlement dan Finance sesuai Branch akun."/>;
  return <PaymentListView data={data} filters={filters} branches={boot?.account?.branches??[]} busy={busy} error={error} onFilter={next=>setFilters(f=>({...f,...next}))} onRefresh={()=>setRetry(n=>n+1)}/>;
}

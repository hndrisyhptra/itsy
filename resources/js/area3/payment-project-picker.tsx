import { useEffect, useRef, useState } from 'react';
import { ArrowRight, Check, Search } from 'lucide-react';
import { money, operational, type State } from './domain';
import { startPaymentFromSpk } from './store';
import { explicitPaymentChoice, hasPaymentSession, localPaymentOrders, localPaymentProjects, paymentChoiceRequest, paymentStage, type PaymentProjectChoice, type PaymentSpkChoice } from './payment-projects';
import type { ChoicePage } from './negotiation-projects';
import { Button, Card, Empty, Field, Notice, PageTitle, Table, navigate } from './ui';

const empty=<T,>():ChoicePage<T>=>({items:[],page:1,hasMore:false});
function Pages({page,hasMore,busy,onPage}:{page:number;hasMore:boolean;busy:boolean;onPage:(page:number)=>void}) {
  return <div className="choice-pagination"><Button disabled={busy||page<=1} onClick={()=>onPage(page-1)}>Sebelumnya</Button><span>Halaman {page}</span><Button disabled={busy||!hasMore} onClick={()=>onPage(page+1)}>Berikutnya</Button></div>;
}
export function PaymentSpkChoices({data,picked,onPick,onOpen,busy=false}:{data:ChoicePage<PaymentSpkChoice>;picked?:PaymentSpkChoice;onPick:(v:PaymentSpkChoice)=>void;onOpen:(v:PaymentSpkChoice)=>void;busy?:boolean}) {
  return <Table headers={['No PP / Nama Kegiatan','No SPK','Mandor / Branch','Nilai SPK','Pembayaran Terakhir','Aksi']} className="payment-spk-table">{data.items.map(v=><tr key={v.id} className={picked?.id===v.id?'choice-selected':''}>
    <td><b>{v.ppNo}</b><small>{v.name}</small></td><td>{v.no}</td><td>{v.mandor}<small>{v.branch}</small></td><td className="numeric">{money(v.amount)}</td><td>{paymentStage(v.latest)}{v.reason&&<small>{v.reason}</small>}</td>
    <td><Button disabled={busy||v.action==='blocked'} reason={v.reason||`${v.label} · ${v.no}`} aria-pressed={picked?.id===v.id} onClick={()=>v.action==='open'?onOpen(v):onPick(v)}>{picked?.id===v.id?<><Check size={14}/> Terpilih</>:v.label}</Button></td>
  </tr>)}</Table>;
}
export function PaymentSelectionSummary({picked,busy=false,onOpen,onChange}:{picked:PaymentSpkChoice;busy?:boolean;onOpen:()=>void;onChange:()=>void}) {
  return <Card title="SPK terpilih" action={<Button disabled={busy} onClick={onChange}>Ganti SPK</Button>}>
    <div className="chosen-plan-body"><div><strong>{picked.no}</strong><p>{picked.name}</p><small>{picked.projectCode} · {picked.ppNo} · {picked.branch}</small><p>Mandor: {picked.mandor}</p></div><strong>{money(picked.amount)}</strong></div>
    <p>{paymentStage(picked.latest)}</p>{picked.reason&&<Notice tone="warning">{picked.reason}</Notice>}
    {picked.action==='create'&&<p>Pengajuan berikutnya: <strong>Termin {picked.nextNumber}{picked.nextNumber===3?' · Final':''}</strong></p>}
    <div className="form-footer"><Button confirm={picked.action==='create'?`Buat draf Termin ${picked.nextNumber} untuk SPK ${picked.no}?`:undefined} primary disabled={busy||picked.action==='blocked'} reason={picked.reason} onClick={onOpen}>{busy?'Memeriksa data terbaru…':picked.action==='create'?'Buat Draf Pembayaran':picked.label}<ArrowRight size={15}/></Button></div>
  </Card>;
}
export function PaymentProjectPicker({s,initialSpkId=''}:{s:State;initialSpkId?:string}) {
  const [query,setQuery]=useState(''),[projectPage,setProjectPage]=useState(1),[project,setProject]=useState<PaymentProjectChoice>(),[orderPage,setOrderPage]=useState(1);
  const [picked,setPicked]=useState<PaymentSpkChoice|undefined>(()=>initialSpkId?explicitPaymentChoice(s,initialSpkId):undefined);
  const [projects,setProjects]=useState(empty<PaymentProjectChoice>),[orders,setOrders]=useState(empty<PaymentSpkChoice>),[busy,setBusy]=useState(false),[opening,setOpening]=useState(false),[error,setError]=useState(''),[retry,setRetry]=useState(0);
  const running=useRef(false),mounted=useRef(true);
  useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;};},[]);
  useEffect(()=>{
    if(project||picked||opening||query.trim().length<2){setBusy(false);return;}
    const controller=new AbortController();let current=true;setBusy(true);
    const timer=setTimeout(()=>{
      const req=hasPaymentSession()?paymentChoiceRequest<PaymentProjectChoice>('?'+new URLSearchParams({q:query.trim(),page:String(projectPage)}),controller.signal):Promise.resolve(localPaymentProjects(s,query,projectPage));
      void req.then(data=>{if(current)setProjects(data);}).catch(e=>{if(current&&!controller.signal.aborted)setError(e instanceof Error?e.message:'Pencarian gagal.');}).finally(()=>{if(current)setBusy(false);});
    },300);
    return()=>{current=false;clearTimeout(timer);controller.abort();};
  },[query,projectPage,project,picked,opening,retry,s]);
  useEffect(()=>{
    if(!project||opening)return;
    const controller=new AbortController();let current=true;setBusy(true);
    const req=hasPaymentSession()?paymentChoiceRequest<PaymentSpkChoice>('/'+encodeURIComponent(project.id)+'/orders?page='+orderPage,controller.signal):Promise.resolve(localPaymentOrders(s,project.code,orderPage));
    void req.then(data=>{if(current){setOrders(data);setPicked(previous=>previous?data.items.find(v=>v.id===previous.id):undefined);}}).catch(e=>{if(current&&!controller.signal.aborted){setError(e instanceof Error?e.message:'SPK gagal dimuat.');setPicked(undefined);setOrders(empty());}}).finally(()=>{if(current)setBusy(false);});
    return()=>{current=false;controller.abort();};
  },[project,orderPage,opening,retry,s]);
  function search(value:string){setQuery(value);setProjectPage(1);setProject(undefined);setPicked(undefined);setProjects(empty());setOrders(empty());setError('');setBusy(value.trim().length>=2);}
  async function open(choice=picked){
    if(!choice||choice.action==='blocked'||running.current)return;
    setPicked(choice);
    running.current=true;setOpening(true);setError('');
    try {const id=await startPaymentFromSpk(choice.id);if(mounted.current)navigate(`/termin/${id}`);}
    catch(e){if(mounted.current){setPicked(undefined);setError(e instanceof Error?e.message:'Pengajuan belum dapat dibuka.');}}
    finally{running.current=false;if(mounted.current)setOpening(false);}
  }
  if(!operational(s))return <><PageTitle title="Pengajuan Pembayaran Baru" back="/termin"/><Notice>Pengajuan pembayaran hanya untuk Admin Konstruksi atau Superadmin.</Notice></>;
  return <><PageTitle title="Pengajuan Pembayaran Baru" back="/termin" description="Cari ID Project, pilih SPK Sah, lalu lanjutkan pengajuan pembayaran."/>
    <div className="choice-steps"><span className={!project&&!picked?'active':''}>1. Cari ID Project</span><span className={project&&!picked?'active':''}>2. Pilih SPK Sah</span><span className={picked?'active':''}>3. Pengajuan Pembayaran</span></div>
    <Card title="Cari ID Project" subtitle="Hanya proyek dengan SPK Sah dalam akses Branch Anda."><Field label="ID Project / Nama Kegiatan" hint="Ketik minimal 2 karakter. Maksimal 20 hasil per halaman."><div className="choice-search"><Search size={17} aria-hidden="true"/><input aria-label="Cari ID Project atau nama kegiatan" placeholder="Ketik ID Project atau nama kegiatan…" autoComplete="off" maxLength={100} disabled={opening} value={query} onChange={e=>search(e.target.value)}/></div></Field>
      <div role="status" aria-live="polite">{busy?'Memuat hasil…':!project&&!picked&&query.trim().length<2?'Mulai dengan mencari ID Project atau nama kegiatan.':''}</div>
      {!project&&!picked&&!busy&&!error&&query.trim().length>=2&&(projects.items.length?<><ul className="project-choice-results" aria-label="Hasil ID Project dengan SPK Sah">{projects.items.map(p=><li key={p.id}><button type="button" disabled={opening} onClick={()=>{setProject(p);setOrderPage(1);setOrders(empty());setPicked(undefined);setBusy(true);}}><span><strong>{p.code}</strong><span>{p.name}</span><small>{p.branchCount>1?`${p.branchCount} Branch dalam akses Anda`:p.branch} · {p.spkCount} SPK Sah</small></span><ArrowRight size={17}/></button></li>)}</ul><Pages page={projectPage} hasMore={projects.hasMore} busy={busy||opening} onPage={n=>{setProjectPage(n);setProjects(empty());setBusy(true);}}/></>:<Empty title="ID Project dengan SPK Sah tidak ditemukan" description="Periksa pencarian dan akses Branch, atau selesaikan pengesahan SPK terlebih dahulu."/>)}
    </Card>
    {error&&<Notice tone="error"><span role="alert">{error}</span> <Button disabled={opening} onClick={()=>{setError('');setRetry(n=>n+1);}}>Muat ulang daftar</Button></Notice>}
    {initialSpkId&&!project&&!picked&&!error&&<Notice>Referensi SPK belum tersedia. Cari dan pilih SPK Sah dalam akses Anda.</Notice>}
    {project&&<Card title={`SPK Sah · ${project.code}`} subtitle="Pilih SPK atau buka pengajuan yang sudah ada. Kelayakan membuat draf diperiksa kembali sebelum disimpan." action={<Button disabled={opening} onClick={()=>{setProject(undefined);setPicked(undefined);setOrders(empty());setBusy(true);}}>Ganti ID Project</Button>}>
      {!busy&&!error&&(orders.items.length?<><PaymentSpkChoices data={orders} picked={picked} onPick={setPicked} onOpen={v=>void open(v)} busy={opening}/><Pages page={orderPage} hasMore={orders.hasMore} busy={busy||opening} onPage={n=>{setOrderPage(n);setOrders(empty());setPicked(undefined);setBusy(true);}}/></>:<Empty title="Tidak ada SPK Sah pada halaman ini"/>)}
    </Card>}
    {picked&&!error&&<PaymentSelectionSummary picked={picked} busy={busy||opening} onOpen={()=>void open()} onChange={()=>{setPicked(undefined);if(!project)setQuery('');}}/>}
  </>;
}

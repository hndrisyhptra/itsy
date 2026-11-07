import { useEffect, useState } from 'react';
import { ArrowRight, Check, Search } from 'lucide-react';
import type { State } from './domain';
import { refreshState } from './store';
import { choiceRequest, hasCatalogSession, localPlans, localProjects, type ChoicePage, type PlanChoice, type ProjectChoice } from './negotiation-projects';
import { Badge, Button, Card, Empty, Field, Notice, PageTitle, Table } from './ui';

const empty=<T,>():ChoicePage<T>=>({items:[],page:1,hasMore:false});
export function PlanChoices({data,picked,onPick,busy=false}:{data:ChoicePage<PlanChoice>;picked?:PlanChoice;onPick:(p:PlanChoice)=>void;busy?:boolean}) {
  return <Table headers={['No PP','Nama Kegiatan','Branch','Status','Aksi']} className="negotiation-plan-table">{data.items.map(p=><tr key={p.id} className={picked?.id===p.id?'choice-selected':''}><td><b>{p.no}</b></td><td>{p.name}</td><td>{p.branch}</td><td><Badge>{p.status}</Badge></td><td><Button disabled={busy||!p.eligible} reason={p.reason||`Pilih PP ${p.no}`} aria-pressed={picked?.id===p.id} onClick={()=>onPick(p)}>{picked?.id===p.id?<><Check size={14}/> Terpilih</>:'Pilih PP'}</Button>{p.reason&&<small>{p.reason}</small>}</td></tr>)}</Table>;
}
function Pages({page,hasMore,busy,onPage}:{page:number;hasMore:boolean;busy:boolean;onPage:(p:number)=>void}) {
  return <div className="choice-pagination"><Button disabled={busy||page<=1} onClick={()=>onPage(page-1)}>Sebelumnya</Button><span>Halaman {page}</span><Button disabled={busy||!hasMore} onClick={()=>onPage(page+1)}>Berikutnya</Button></div>;
}
export function NegotiationProjectPicker({s,onSelect}:{s:State;onSelect:(id:string)=>void}) {
  const [query,setQuery]=useState(''),[projectPage,setProjectPage]=useState(1),[project,setProject]=useState<ProjectChoice>(),[planPage,setPlanPage]=useState(1),[picked,setPicked]=useState<PlanChoice>();
  const [projects,setProjects]=useState(empty<ProjectChoice>),[plans,setPlans]=useState(empty<PlanChoice>),[busy,setBusy]=useState(false),[opening,setOpening]=useState(false),[error,setError]=useState(''),[retry,setRetry]=useState(0);
  useEffect(()=>{
    if(project||query.trim().length<2){setBusy(false);return;}
    const controller=new AbortController();let current=true;setBusy(true);setError('');
    const timer=setTimeout(()=>{
      const request=hasCatalogSession()?choiceRequest<ProjectChoice>('?'+new URLSearchParams({q:query.trim(),page:String(projectPage)}),controller.signal):Promise.resolve(localProjects(s,query,projectPage));
      void request.then(data=>{if(current)setProjects(data);}).catch(e=>{if(current&&!controller.signal.aborted)setError(e instanceof Error?e.message:'Pencarian gagal.');}).finally(()=>{if(current)setBusy(false);});
    },300);
    return ()=>{current=false;clearTimeout(timer);controller.abort();};
  },[query,projectPage,project,retry,s]);
  useEffect(()=>{
    if(!project)return;
    const controller=new AbortController();let current=true;setBusy(true);setError('');
    const request=hasCatalogSession()?choiceRequest<PlanChoice>('/'+encodeURIComponent(project.id)+'/plans?page='+planPage,controller.signal):Promise.resolve(localPlans(s,project.code,planPage));
    void request.then(data=>{if(current){setPlans(data);setPicked(previous=>previous?data.items.find(row=>row.id===previous.id&&row.eligible):undefined);}}).catch(e=>{if(current&&!controller.signal.aborted)setError(e instanceof Error?e.message:'Daftar PP gagal dimuat.');}).finally(()=>{if(current)setBusy(false);});
    return ()=>{current=false;controller.abort();};
  },[project,planPage,retry,s]);
  function search(value:string){setQuery(value);setProjectPage(1);setProject(undefined);setPicked(undefined);setProjects(empty());setPlans(empty());setError('');}
  async function open(){
    if(!picked||!picked.eligible||opening)return;
    setOpening(true);setError('');
    try {if(hasCatalogSession())await refreshState();onSelect(picked.id);}catch(e){setError(e instanceof Error?e.message:'PP belum dapat dibuka.');}finally{setOpening(false);}
  }
  return <><PageTitle title="Negosiasi & BA KH Baru" back="/spk/ba-kh" description="Cari ID Project, pilih PP, lalu lanjutkan penugasan mandor."/><div className="choice-steps"><span className={!project?'active':''}>1. Cari ID Project</span><span className={project&&!picked?'active':''}>2. Pilih Project Plan</span><span className={picked?'active':''}>3. Lanjut negosiasi</span></div>
    <Card title="Cari ID Project" subtitle="Hasil mengikuti akses Branch akun Anda."><Field label="ID Project / Nama Pekerjaan" hint="Ketik minimal 2 karakter. Maksimal 20 hasil per halaman."><div className="choice-search"><Search size={17} aria-hidden="true"/><input autoComplete="off" maxLength={100} value={query} disabled={opening} onChange={e=>search(e.target.value)} placeholder="Ketik ID Project atau nama pekerjaan…" aria-label="Cari ID Project atau nama pekerjaan"/></div></Field>
      {error&&<Notice tone="warning"><span role="alert">{error}</span> <Button onClick={()=>setRetry(n=>n+1)} disabled={opening}>Coba lagi</Button></Notice>}
      <div role="status" aria-live="polite">{busy?'Memuat hasil…':!project&&query.trim().length<2?'Mulai dengan mencari ID Project atau nama pekerjaan.':''}</div>
      {!project&&!busy&&!error&&query.trim().length>=2&&(projects.items.length?<><ul className="project-choice-results" aria-label="Hasil pencarian ID Project">{projects.items.map(item=><li key={item.id}><button type="button" disabled={opening} onClick={()=>{setProject(item);setPlanPage(1);setPlans(empty());setPicked(undefined);}}><span><strong>{item.code}</strong><span>{item.name}</span><small>{item.branchCount>1?`${item.branchCount} Branch dalam akses Anda`:item.branch} · {item.ppCount} Project Plan</small></span><ArrowRight size={17}/></button></li>)}</ul><Pages page={projectPage} hasMore={projects.hasMore} busy={busy} onPage={p=>{setProjectPage(p);setProjects(empty());}}/></>:<Empty title="ID Project tidak ditemukan" description="Periksa kata pencarian atau akses Branch akun Anda."/>)}
    </Card>
    {project&&<Card title={`Project Plan · ${project.code}`} subtitle={`${project.ppCount} PP dalam akses Anda. Pilih satu PP; daftar juga menampilkan alasan PP belum dapat dipilih.`} action={<Button disabled={opening} onClick={()=>{setProject(undefined);setPicked(undefined);setPlans(empty());}}>Ganti ID Project</Button>}>
      {!busy&&!error&&(plans.items.length?<><PlanChoices data={plans} picked={picked} onPick={setPicked} busy={opening}/><Pages page={planPage} hasMore={plans.hasMore} busy={busy||opening} onPage={p=>{setPlanPage(p);setPicked(undefined);setPlans(empty());}}/></>:<Empty title="Tidak ada PP pada halaman ini"/>)}
    </Card>}
    {picked&&<Card title="PP terpilih" className="chosen-plan"><div className="chosen-plan-body"><div><strong>{picked.no}</strong><p>{picked.name}</p><small>{picked.projectCode} · {picked.branch}</small></div><Badge>{picked.status}</Badge></div><div className="form-footer"><Button primary disabled={busy||opening||!!error} onClick={()=>void open()}>{opening?'Memuat PP terbaru…':'Lanjut Negosiasi'}<ArrowRight size={15}/></Button></div></Card>}
  </>;
}

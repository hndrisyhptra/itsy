import {pidBudgetCosts,tracksPidBudget} from './budget-pid-policy';
import {useEffect,useRef,useState} from 'react';
import type {Project,State} from './domain';
import {money} from './domain';
import {appUrl,boot} from './laravel';
import {Button,Notice,Table} from './ui';

export interface BudgetPidSelection {pid:string;unit:string;branch:string;importId:string|null}
type Costs=Record<'TOTAL'|'Material'|'Non Material'|'Operasional',number>;
export type PidReference={pid:string;unit:string;branch:string;pp_id?:string}&({status:'found';name:string;import_id:string;updated_at:string;available:Costs;remaining:Costs;application_used:Costs;new_pp_only:true}|{status:'not_found'|'unit_conflict'|'branch_conflict'|'branch_unmapped'});
export const pidCode=(value:string)=>value.trim().toUpperCase();
export const pidScope=(p:Pick<Project,'projectCode'|'unit'|'branch'>)=>JSON.stringify([pidCode(p.projectCode),p.unit,p.branch]);
export function matchingPidSelection(p:Project){const v=p.budgetPidSelection;return !!v&&v.pid===pidCode(p.projectCode)&&v.unit===p.unit&&v.branch===p.branch;}
export async function readPidReference(p:Pick<Project,'projectCode'|'unit'|'branch'>,signal?:AbortSignal,ppId?:string):Promise<PidReference>{
 const query=new URLSearchParams({pid:pidCode(p.projectCode),unit:p.unit,branch:p.branch});
 if(ppId)query.set('pp_id',ppId);
 const response=await fetch(appUrl('/api/budgeting-pid/reference?')+query,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal});
 const data=await response.json();if(!response.ok)throw Error(data.message||'Referensi PID belum dapat diperiksa.');
 if(data.pid!==pidCode(p.projectCode)||data.unit!==p.unit||data.branch!==p.branch||data.pp_id!==ppId||!['found','not_found','unit_conflict','branch_conflict','branch_unmapped'].includes(data.status))throw Error('Respons referensi tidak sesuai formulir. Periksa ulang PID.');
 if(data.status==='found'&&(typeof data.name!=='string'||typeof data.import_id!=='string'||data.new_pp_only!==true||!['TOTAL','Material','Non Material','Operasional'].every(c=>Number.isSafeInteger(data.available?.[c])&&Number.isSafeInteger(data.remaining?.[c])&&Number.isSafeInteger(data.application_used?.[c]))))throw Error('Rincian referensi PID belum lengkap.');
 return data;
}
/** Request state never outlives its exact PID/unit/Branch; imported amounts never enter the PP payload. */
export function usePidReference(p:Project,existing:Project|undefined,enabled:boolean,setP:React.Dispatch<React.SetStateAction<Project>>){
 const scope=pidScope(p),key=JSON.stringify([scope,existing?.id]),live=useRef(key);live.current=key;
 const [tick,refresh]=useState(0),[state,setState]=useState<{key:string;result?:PidReference;error?:string;loading:boolean}|null>(null);
 const ready=enabled&&!!boot?.account&&pidCode(p.projectCode)!==''&&!!p.branch&&p.projectCode.length<=100;
 useEffect(()=>{if(!ready){setState(null);return;}const control=new AbortController();setState({key,loading:true});
  const timer=setTimeout(()=>{void readPidReference(p,control.signal,existing?.id).then(result=>{
   if(control.signal.aborted||live.current!==key)return;setState({key,result,loading:false});
   const automatic=!existing||pidCode(existing.projectCode)!==pidCode(p.projectCode);
   if(automatic&&['found','not_found','branch_unmapped'].includes(result.status))setP(current=>{
    if(pidScope(current)!==scope)return current;
    return {...current,...(result.status==='found'?{name:result.name}:{}),budgetPidSelection:{pid:result.pid,unit:result.unit,branch:result.branch,importId:result.status==='found'?result.import_id:null}};
   });
  }).catch(e=>{if(!control.signal.aborted&&live.current===key)setState({key,error:e instanceof Error?e.message:'Referensi belum dapat diperiksa.',loading:false});});},350);
  return()=>{clearTimeout(timer);control.abort();};
 },[key,tick,ready]);
 const current=state?.key===key?state:null,result=current?.result;
 return {result,error:current?.error,loading:ready&&(!current||current.loading),refresh:()=>refresh(t=>t+1),
  blocked:result?.status==='unit_conflict'||result?.status==='branch_conflict',
  nameLocked:result?.status==='found'&&matchingPidSelection(p)&&p.budgetPidSelection?.importId===result.import_id,
  adopt:()=>{if(result?.status!=='found'||live.current!==key)return;setP(current=>pidScope(current)!==scope?current:{...current,name:result.name,budgetPidSelection:{pid:result.pid,unit:result.unit,branch:result.branch,importId:result.import_id}});}};
}
export function samePidCount(s:State,p:Project,existing?:Project){return existing||p.parentId?0:s.projects.filter(v=>v.id!==p.id&&v.unit===p.unit&&pidCode(v.projectCode)===pidCode(p.projectCode)).length;}
export function pidDraftChange(p:Project,patch:Partial<Project>):Project{return {...p,...patch,...(Object.hasOwn(patch,'projectCode')||Object.hasOwn(patch,'unit')||Object.hasOwn(patch,'branch')?{budgetPidSelection:undefined,...(p.budgetPidSelection?.importId?{name:''}:{})}:{})};}
export function PidReferenceView({p,lookup,duplicate=0,legacy=false,adopt=true,editing=false}:{p:Project;lookup:ReturnType<typeof usePidReference>;duplicate?:number;legacy?:boolean;adopt?:boolean;editing?:boolean}){
 const result=lookup.result;
 const {amounts,mandor,ready}=pidBudgetCosts(p,editing);
 return <div className="pid-reference stack" aria-label="Referensi Budgeting PID">
  {duplicate>0&&<Notice tone="warning">ID Project ini pernah digunakan pada {duplicate} PP dalam cakupan akses Anda. Pastikan PP baru menggunakan mandor berbeda.</Notice>}
  {!p.branch&&pidCode(p.projectCode)&&<Notice>Pilih Branch untuk memeriksa referensi PID sesuai unit kerja.</Notice>}
  {lookup.loading&&<p role="status">Memeriksa PID pada master budgeting…</p>}
  {lookup.error&&<Notice tone="warning">{lookup.error} <Button onClick={lookup.refresh}>Periksa Ulang PID</Button></Notice>}
  {result?.status==='not_found'&&<Notice tone="warning">ID Project tidak ada di master budgeting. Isi Nama Kegiatan secara manual; pengajuan tetap diperbolehkan. Referensi budget belum tersedia.</Notice>}
  {result?.status==='branch_unmapped'&&<Notice tone="warning">PID belum dipetakan ke Branch. Hubungi Finance untuk pemetaan; sementara Nama Kegiatan dapat diisi manual dan referensi budget belum tersedia.</Notice>}
  {(result?.status==='branch_conflict'||result?.status==='unit_conflict')&&<Notice tone="error">PID terdaftar pada {result.status==='unit_conflict'?'unit kerja':'Branch'} lain. Periksa ID Project, Branch dan Unit Kerja sebelum menyimpan.</Notice>}
  {result?.status==='found'&&<><div className="section-heading"><div><h3>Sisa Budget PID</h3><p>{result.pid} · {result.name}</p></div><div className="actions"><Button onClick={lookup.refresh}>Periksa Ulang PID</Button>{adopt&&!lookup.nameLocked&&<Button confirm="Gunakan Nama Kegiatan dari master pada PP ini?" onClick={lookup.adopt}>Gunakan Nama Master</Button>}</div></div>
   <Table className="budget-pid-table" headers={['Komponen','Available Excel','Alokasi PP Lain','Sisa untuk PP Ini','Nilai PP Ini','Sisa Setelah PP Ini']}>{(['TOTAL','Material','Non Material','Operasional'] as const).map(c=><tr key={c}><td>{c==='TOTAL'?'Total Budget':c==='Non Material'?'Non Material':c==='Material'?'Material':'Operasional'}</td><td className="numeric">{money(result.available[c])}</td><td className="numeric">{money(result.application_used[c])}</td><td className={`numeric ${result.remaining[c]<0?'error-text':''}`}><b>{money(result.remaining[c])}</b></td><td className="numeric">{ready?money(amounts[c]):'—'}</td><td className={`numeric ${ready&&amounts[c]>result.remaining[c]?'error-text':''}`}>{ready?money(result.remaining[c]-amounts[c]):'—'}</td></tr>)}</Table>
   {!ready&&<Notice>Isi volume dan harga pada BOQ TA - Mandor di tab Anggaran &amp; Margin. Validasi nominal budget dilakukan setelah BOQ Mandor diisi.</Notice>}
   {ready&&<Notice>{mandor?'Dasar validasi: Material dan Jasa bruto dari BOQ TA - Mandor; Total = Material + Jasa. BOP diperiksa terpisah.':'PP ini masih memakai alokasi historis BOQ Owner. Dasar Mandor digunakan saat draf/revisi disimpan dan diajukan kembali.'}</Notice>}
   {legacy?<Notice>PP lama ini dikecualikan dari alokasi dan batas budget baru. Status, nominal dan riwayatnya tetap.</Notice>:<Notice tone={ready&&Object.keys(amounts).some(c=>amounts[c as keyof Costs]>result.remaining[c as keyof Costs])?'warning':'info'}>Draf belum mengurangi budget. Saat Ajukan Paket, sistem memeriksa ulang empat batas dan mereservasi budget secara bersamaan. PP lama sebelum fitur ini dikecualikan; pembayaran lunas tetap terpakai. Nominal budgeting tidak memakai potongan PPh atau nilai transfer kasbon.</Notice>}
  </>}
 </div>;
}

export function ProjectBudgetReference({p,enabled,editing=false}:{p:Project;enabled:boolean;editing?:boolean}){
 const lookup=usePidReference(p,p,enabled,()=>{});
 return <PidReferenceView p={p} lookup={lookup} legacy={!tracksPidBudget(p)} adopt={false} editing={editing}/>;
}

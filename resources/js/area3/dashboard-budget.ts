import {useEffect,useRef,useState} from 'react';
import {appUrl,boot} from './laravel';
export interface DashboardBudgetRow {id:string;pid:string;name:string;branch:string;available:number;used:number;remaining:number;deficit:boolean}
export interface DashboardBudgetData {unit:string;branch:string;q:string;page:number;per_page:number;total:number;remaining:number;exhausted:number;deficit:number;rows:DashboardBudgetRow[];new_pp_only:true}
export async function readDashboardBudget(unit:string,branch:string,detail=false,q='',page=1,signal?:AbortSignal):Promise<DashboardBudgetData>{
 const params=new URLSearchParams({unit,branch,detail:detail?'1':'0',q,page:String(page)});
 const response=await fetch(appUrl('/api/dashboard/budget?')+params,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:signal?AbortSignal.any([signal,AbortSignal.timeout(20000)]):AbortSignal.timeout(20000)});
 const result=await response.json();if(!response.ok)throw Error(result.message||'Ringkasan budget belum dapat dimuat.');
 if(result.unit!==unit||result.branch!==branch||result.q!==q||result.page!==page||result.per_page!==20||result.new_pp_only!==true||!['total','remaining','exhausted','deficit'].every(k=>Number.isSafeInteger(result[k]))||!Array.isArray(result.rows)||result.rows.length>20||result.rows.some((r:DashboardBudgetRow)=>typeof r.id!=='string'||typeof r.pid!=='string'||typeof r.name!=='string'||typeof r.branch!=='string'||!['available','used','remaining'].every(k=>Number.isSafeInteger(r[k as keyof DashboardBudgetRow]))))throw Error('Ringkasan budget tidak sesuai filter. Muat ulang ringkasan.');
 return result;
}
/** Scoped on-demand GET only. Never keep an earlier scope visible, poll, or retry automatically. */
export function useDashboardBudget(unit:string,branch:string,revision:string,detail=false,q='',page=1){
 const key=JSON.stringify([unit,branch,revision,detail,q,page]),live=useRef(key);live.current=key;
 const [tick,setTick]=useState(0),[state,setState]=useState<{key:string;data?:DashboardBudgetData;error?:string}>();
 useEffect(()=>{if(!boot?.account)return;const control=new AbortController();const timer=setTimeout(()=>{void readDashboardBudget(unit,branch,detail,q,page,control.signal).then(data=>{if(!control.signal.aborted&&live.current===key)setState({key,data});}).catch(e=>{if(!control.signal.aborted&&live.current===key)setState({key,error:e instanceof Error?e.message:'Ringkasan belum dapat dimuat.'});});},detail?300:0);return()=>{clearTimeout(timer);control.abort();};},[key,tick]);
 const current=state?.key===key?state:undefined;
 return {...current,loading:!!boot?.account&&!current,data:current?.data,error:!boot?.account?'Budget master tersedia pada aplikasi dengan akun login.':current?.error,retry:()=>{setState(undefined);setTick(n=>n+1);}};
}

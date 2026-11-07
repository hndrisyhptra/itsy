import { capacity, type Project, type State } from './domain';
import { appUrl, boot } from './laravel';

export interface ProjectChoice { id:string;code:string;name:string;ppCount:number;branch:string;branchCount:number }
export interface PlanChoice { id:string;no:string;name:string;branch:string;projectCode:string;status:string;eligible:boolean;reason:string }
export interface ChoicePage<T> { items:T[];page:number;hasMore:boolean }
export const CHOICE_PAGE_SIZE=20;

export function planChoice(s:State,p:Project):PlanChoice {
  const root=s.projects.find(row=>row.id===p.rootId);
  let reason=p.status!=='Sah'?'PP belum Sah.':p.boqStatus!=='Final'?'BOQ belum Final.':'';
  if(!reason&&(!root||!root.boq.some(row=>(['m','s'] as const).some(c=>row[c]!==null&&capacity(s,p,row.id,c)>0)))) reason='Volume tersedia sudah habis.';
  return {id:p.id,no:p.no,name:p.name,branch:p.branch,projectCode:p.projectCode,status:p.status,eligible:!reason,reason};
}
const pageOf=<T>(rows:T[],page:number):ChoicePage<T>=>({items:rows.slice((page-1)*CHOICE_PAGE_SIZE,page*CHOICE_PAGE_SIZE),page,hasMore:rows.length>page*CHOICE_PAGE_SIZE});

/** Offline only. Logged-in selection always queries the scoped SQL catalog. */
export function localProjects(s:State,q:string,page:number):ChoicePage<ProjectChoice> {
  const groups=new Map<string,{choice:ProjectChoice;branches:Set<string>;matches:boolean;matchedName?:string}>(),text=q.trim().toLocaleLowerCase();
  if(text.length<2)return pageOf([],page);
  for(const p of s.projects){
    const key=p.projectCode.trim().toLocaleLowerCase();
    let group=groups.get(key);
    if(!group){group={choice:{id:p.projectCode,code:p.projectCode,name:p.name,ppCount:0,branch:p.branch,branchCount:0},branches:new Set(),matches:false};groups.set(key,group);}
    group.choice.ppCount++;group.branches.add(p.branch);
    if(p.name.toLocaleLowerCase().includes(text)&&(!group.matchedName||p.name.localeCompare(group.matchedName)<0))group.matchedName=p.name;
    group.matches ||= p.projectCode.toLocaleLowerCase().includes(text)||p.name.toLocaleLowerCase().includes(text);
  }
  return pageOf([...groups.values()].filter(g=>g.matches).map(g=>({...g.choice,name:g.matchedName??g.choice.name,branchCount:g.branches.size})).sort((a,b)=>a.code.localeCompare(b.code)),page);
}
export function localPlans(s:State,code:string,page:number):ChoicePage<PlanChoice> {
  const rows=s.projects.filter(p=>p.projectCode.trim().toLocaleLowerCase()===code.trim().toLocaleLowerCase()).sort((a,b)=>b.date.localeCompare(a.date)||a.id.localeCompare(b.id));
  const visible=pageOf(rows,page);return {...visible,items:visible.items.map(p=>planChoice(s,p))};
}
export async function choiceRequest<T>(path:string,signal:AbortSignal):Promise<ChoicePage<T>> {
  const response=await fetch(appUrl('/api/prototype/negotiation/projects'+path),{credentials:'same-origin',headers:{Accept:'application/json'},signal:AbortSignal.any([signal,AbortSignal.timeout(15000)])});
  const data=await response.json();
  if(!response.ok)throw new Error((Object.values(data.errors??{}).flat() as string[]).join(' ')||data.message||'Pencarian belum berhasil. Coba lagi.');
  return data as ChoicePage<T>;
}
export const hasCatalogSession=()=>!!boot?.account;

import { paymentAction, paymentLatest, type PaymentLatest, type PaymentAction } from './payment-selection';
export { paymentAction, paymentLatest, paymentStage, paymentStartAction } from './payment-selection';
export type { PaymentLatest, PaymentAction } from './payment-selection';
import { dealTotal, type State, type Term } from './domain';
import { appUrl, boot } from './laravel';
import type { ChoicePage } from './negotiation-projects';

export interface PaymentProjectChoice { id:string;code:string;name:string;spkCount:number;branch:string;branchCount:number }
export interface PaymentSpkChoice extends PaymentAction { id:string;no:string;ppNo:string;name:string;projectCode:string;branch:string;mandor:string;amount:number;latest:PaymentLatest|null }

const pageOf=<T>(items:T[],page:number):ChoicePage<T>=>({items:items.slice((page-1)*20,page*20),page,hasMore:items.length>page*20});
function localIndex(s:State) {
  const projects=new Map(s.projects.map(p=>[p.id,p])),deals=new Map(s.deals.map(d=>[d.id,d]));
  const terms=new Map<string,Term[]>();for(const t of s.terms){const rows=terms.get(t.spkId)??[];rows.push(t);terms.set(t.spkId,rows);}
  return {projects,deals,terms};
}
/** Offline fallback only; account-mode search uses the bounded SQL endpoints. */
export function localPaymentProjects(s:State,q:string,page:number):ChoicePage<PaymentProjectChoice> {
  if(q.trim().length<2)return pageOf([],page);
  const {projects,deals}=localIndex(s),text=q.trim().toLocaleLowerCase(),groups=new Map<string,{item:PaymentProjectChoice;branches:Set<string>;matches:boolean;matched?:string}>();
  for(const spk of s.spks){
    if(spk.status!=='Sah')continue;const deal=deals.get(spk.dealId),p=deal&&projects.get(deal.projectId);if(!p)continue;
    const key=p.projectCode.trim().toLocaleLowerCase();let group=groups.get(key);
    if(!group){group={item:{id:p.projectCode,code:p.projectCode,name:p.name,spkCount:0,branch:p.branch,branchCount:0},branches:new Set(),matches:false};groups.set(key,group);}
    group.item.spkCount++;group.branches.add(p.branch);
    if(p.name.toLocaleLowerCase().includes(text)&&(!group.matched||p.name.localeCompare(group.matched)<0))group.matched=p.name;
    group.matches ||= p.projectCode.toLocaleLowerCase().includes(text)||p.name.toLocaleLowerCase().includes(text);
  }
  return pageOf([...groups.values()].filter(g=>g.matches).map(g=>({...g.item,name:g.matched??g.item.name,branchCount:g.branches.size})).sort((a,b)=>a.code.localeCompare(b.code)),page);
}
export function localPaymentOrders(s:State,code:string,page:number):ChoicePage<PaymentSpkChoice> {
  const {projects,deals,terms}=localIndex(s);
  const orders=s.spks.filter(v=>v.status==='Sah'&&projects.get(deals.get(v.dealId)?.projectId??'')?.projectCode.trim().toLocaleLowerCase()===code.trim().toLocaleLowerCase())
    .sort((a,b)=>(projects.get(deals.get(b.dealId)!.projectId)!.date.localeCompare(projects.get(deals.get(a.dealId)!.projectId)!.date))||a.id.localeCompare(b.id));
  const result=pageOf(orders,page);
  return {...result,items:result.items.map(v=>{
    const d=deals.get(v.dealId)!,p=projects.get(d.projectId)!,history=(terms.get(v.id)??[]).map(paymentLatest).sort((a,b)=>a.number-b.number);
    return {id:v.id,no:v.no,ppNo:p.no,name:p.name,projectCode:p.projectCode,branch:p.branch,mandor:d.mandor,amount:dealTotal(d),latest:history.at(-1)??null,...paymentAction(history,p.status==='Sah')};
  })};
}
export function explicitPaymentChoice(s:State,id:string):PaymentSpkChoice|undefined {
  const v=s.spks.find(v=>v.id===id&&v.status==='Sah'),d=v&&s.deals.find(d=>d.id===v.dealId),p=d&&s.projects.find(p=>p.id===d.projectId);
  if(!v||!d||!p)return undefined;
  const history=s.terms.filter(t=>t.spkId===v.id).map(paymentLatest).sort((a,b)=>a.number-b.number);
  return {id:v.id,no:v.no,ppNo:p.no,name:p.name,projectCode:p.projectCode,branch:p.branch,mandor:d.mandor,amount:dealTotal(d),latest:history.at(-1)??null,...paymentAction(history,p.status==='Sah')};
}
export async function paymentChoiceRequest<T>(path:string,signal:AbortSignal):Promise<ChoicePage<T>> {
  const response=await fetch(appUrl('/api/prototype/payment-selection/projects'+path),{credentials:'same-origin',headers:{Accept:'application/json'},signal:AbortSignal.any([signal,AbortSignal.timeout(15000)])});
  const data=await response.json();
  if(!response.ok)throw new Error((Object.values(data.errors??{}).flat() as string[]).join(' ')||data.message||'Pencarian SPK belum berhasil. Coba lagi.');
  return data as ChoicePage<T>;
}
export const hasPaymentSession=()=>!!boot?.account;

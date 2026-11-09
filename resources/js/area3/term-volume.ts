import type { State, Project, Deal, Spk, Term, Component } from './domain';

const round=(n:number)=>Math.round((n+Number.EPSILON)*100)/100;
export interface TermAmounts { material:number; service:number; total:number }
export interface TermVolumeRow {
  id:string; rowId:string; component:Component; code:string; description:string; unit:string;
  quantity:number; actuals:(number|null)[]; current:number; accepted:number; remaining:number; price:number; amount:number;
}
/** Same component ledger for the editor and attachment. Never sum volumes across units/components. */
export function termVolumeData(state:Pick<State,'projects'|'khs'|'terms'>,project:Project,deal:Deal,spk:Spk,term:Term){
  const root=project.rootId===project.id?project:state.projects.find(p=>p.id===project.rootId);
  const source=new Map(root?.boq.map(r=>[r.id,r.khsId])??[]),catalog=new Map(state.khs.map(k=>[k.id,k]));
  const previous=state.terms.filter(t=>t.spkId===spk.id&&t.number<term.number&&t.status==='Disetujui');
  const previousByNumber=new Map(previous.map(t=>[t.number,t]));
  const accepted=new Map<string,{m:number;s:number}>();
  for(const t of previous)for(const [id,qty] of Object.entries(t.actuals)){
    const a=accepted.get(id)??{m:0,s:0};a.m+=qty.m??0;a.s+=qty.s??0;accepted.set(id,a);
  }
  function amounts(t:Term):TermAmounts {
    let material=0,total=0;
    for(const r of deal.rows){const m=r.m?(t.actuals[r.rowId]?.m??0)*r.pm:0,j=r.s?(t.actuals[r.rowId]?.s??0)*r.ps:0;material+=round(m);total+=round(m+j);}
    material=round(material);total=round(total);
    return {material,service:round(total-material),total};
  }
  const current=amounts(term),prior=previous.map(t=>({number:t.number,...amounts(t)}));
  const priorTotal=round(prior.reduce((sum,t)=>sum+t.total,0));
  const rows:TermVolumeRow[]=[];
  for(const r of deal.rows){
    const k=catalog.get(source.get(r.rowId)??''),m=r.m?round((term.actuals[r.rowId]?.m??0)*r.pm):0;
    const total=round((r.m?(term.actuals[r.rowId]?.m??0)*r.pm:0)+(r.s?(term.actuals[r.rowId]?.s??0)*r.ps:0));
    for(const c of ['m','s'] as const){
      if(!r[c])continue;
      const quantity=c==='m'?r.qm:r.qs,value=term.actuals[r.rowId]?.[c]??0,priorQty=accepted.get(r.rowId)?.[c]??0;
      rows.push({id:`${r.rowId}-${c}`,rowId:r.rowId,component:c,code:k?.code??'Referensi tidak tersedia',description:k?.name??'Referensi tidak tersedia',unit:k?.unit??'',quantity,
        actuals:[1,2,3].map(n=>n===term.number?value:n<term.number&&previousByNumber.has(n)?previousByNumber.get(n)!.actuals[r.rowId]?.[c]??0:null),
        current:value,accepted:priorQty,remaining:Math.round((quantity-priorQty-value)*1000)/1000,price:c==='m'?r.pm:r.ps,amount:c==='m'?m:round(total-m)});
    }
  }
  return {rows,current,prior,priorTotal,cumulativeTotal:round(priorTotal+current.total),hasMaterial:deal.rows.some(r=>r.m),hasService:deal.rows.some(r=>r.s)};
}

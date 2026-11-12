import { round } from './domain';
import type { DashboardMatrixData, MatrixPlanDetail } from './dashboard-matrix';

export type MatrixMetric = 'projects' | 'open' | 'closed' | 'plans' | 'realized' | 'total';
export type MatrixScope = { kind:'all' } | { kind:'region'|'branch'; id:string };
export interface MatrixSelection { metric:MatrixMetric; scope:MatrixScope; label:string; code?:string }
export const MATRIX_LABELS:Record<MatrixMetric,string> = {
  projects:'ID Project · JMLH',open:'ID Project · OPEN',closed:'ID Project · CLOSE',
  plans:'Jumlah PP',realized:'Nilai Realisasi',total:'Total Nilai BOQ Plan',
};
export interface MatrixDetailRow {
  id:string; code:string; name:string; branch:string; status:string; plans:number;
  realized:number; total:number; route?:string; parentId?:string; no?:string; spkNo?:string; term?:string; apm?:string;
}

/** Derive contributors from the same authorized model used by the visible cell. Never load another Branch. */
export function matrixDetailRows(data:DashboardMatrixData,selection:MatrixSelection):MatrixDetailRow[] {
  const scope=selection.scope;
  const plans=data.plans.filter(p=>(scope.kind==='all'||(scope.kind==='region'?p.regionId:p.branchId)===scope.id)&&(!selection.code||p.code===selection.code));
  const row=(p:MatrixPlanDetail):MatrixDetailRow=>({id:p.id,code:p.code,name:p.name,branch:p.branch,status:p.status,plans:1,realized:p.realized,total:p.total,parentId:p.parentId,no:p.no,route:'/project-plan/'+p.id});
  if(selection.metric==='realized'){
    const index=new Map(plans.map(p=>[p.id,p]));
    return data.payments.flatMap(t=>{const p=index.get(t.planId);return p?[{...row(p),id:t.id,status:'Pembayaran tercatat',realized:t.amount,spkNo:t.spkNo,term:`${t.number} · ${t.type}`,apm:t.apm,route:'/termin/'+t.id}]:[];});
  }
  // Show all saved root plans, including zero drafts, because those are the source set for Total Nilai.
  if(selection.metric==='total'||selection.metric==='plans')return plans.filter(p=>selection.metric!=='total'||!p.parentId).map(row);
  const groups=new Map<string,{row:MatrixDetailRow;branches:Set<string>}>();
  for(const p of plans){
    if(!p.code||(selection.metric==='open'&&p.closed)||(selection.metric==='closed'&&!p.closed))continue;
    let group=groups.get(p.code);
    if(!group){group={row:{id:p.code,code:p.code,name:p.name,branch:'',status:p.closed?'CLOSE':'OPEN',plans:0,realized:0,total:0},branches:new Set()};groups.set(p.code,group);}
    group.branches.add(p.branch);group.row.plans++;group.row.realized+=p.realized;group.row.total+=p.total;
  }
  return [...groups.values()].map(({row,branches})=>({...row,branch:[...branches].join(', '),realized:round(row.realized),total:round(row.total)}));
}

export function matrixDetailPage(rows:MatrixDetailRow[],query='',page=1){
  const q=query.trim().toLocaleLowerCase('id-ID');
  const matches=q?rows.filter(r=>[r.code,r.name,r.branch,r.no,r.spkNo,r.term,r.apm,r.status].join(' ').toLocaleLowerCase('id-ID').includes(q)):rows;
  const pages=Math.max(1,Math.ceil(matches.length/20)),current=Math.max(1,Math.min(pages,page));
  return {rows:matches.slice((current-1)*20,current*20),count:matches.length,sourceCount:rows.length,pages,current};
}

import { type State, type Project, type Deal, type Spk, type Term, boqTotal, round, termTotalForDeal } from './domain';

export interface MatrixTotals { projects:number; open:number; closed:number; plans:number; realized:number; total:number; missingCodes:number }
export interface MatrixBranch { id:string; name:string; regionId:string; regionName:string; totals:MatrixTotals }
export interface MatrixRegion { id:string; name:string; branches:MatrixBranch[]; totals:MatrixTotals }
const normalized=(value:string)=>value.trim().toUpperCase();
const empty=():MatrixTotals=>({projects:0,open:0,closed:0,plans:0,realized:0,total:0,missingCodes:0});

/** Read model of authorized saved rows only. Classification sees all visible PP, even when a Branch is selected. */
export function dashboardMatrix(s:State,branch=''):{regions:MatrixRegion[];totals:MatrixTotals} {
  const branches=new Map<string,MatrixBranch>();
  for(const b of s.branches??[]){
    const key=normalized(b.name);if(!key)continue;
    branches.set(key,{id:b.id,name:b.name,regionId:b.regionId||'unassigned',regionName:b.regionName?.trim()||'Region belum ditetapkan',totals:empty()});
  }
  const plans=new Map<string,{project:Project;deals:Deal[];realized:number;closed:boolean}>();
  const codes=new Map<string,{closed:boolean}>();
  for(const p of s.projects){
    plans.set(p.id,{project:p,deals:[],realized:0,closed:false});
    const code=normalized(p.projectCode);if(code)codes.set(code,{closed:true});
    const key=normalized(p.branch);
    if(!branches.has(key))branches.set(key,{id:`legacy:${key}`,name:p.branch,regionId:'unassigned',regionName:'Region belum ditetapkan',totals:empty()});
  }
  const deals=new Map<string,{deal:Deal;spks:Spk[]}>();
  for(const d of s.deals){const p=plans.get(d.projectId);if(!p)continue;p.deals.push(d);deals.set(d.id,{deal:d,spks:[]});}
  const orders=new Map<string,{spk:Spk;terms:Term[];closed:boolean}>();
  for(const v of s.spks){const d=deals.get(v.dealId);if(!d)continue;d.spks.push(v);orders.set(v.id,{spk:v,terms:[],closed:false});}
  const settled=new Map<string,boolean>();
  for(const t of s.terms){
    const order=orders.get(t.spkId);if(!order)continue;
    order.terms.push(t);const d=deals.get(order.spk.dealId)!.deal;
    const amount=termTotalForDeal(d,t),paid=Number.isFinite(t.paid)?Math.max(0,round(t.paid)):0;
    settled.set(t.id,t.status==='Disetujui'&&t.apmStatus==='Valid'&&amount>0&&paid>=amount);
    if(order.spk.status==='Sah'&&t.status==='Disetujui')plans.get(d.projectId)!.realized+=paid;
  }
  for(const order of orders.values()){
    const last=order.terms.reduce<Term|undefined>((previous,t)=>!previous||t.number>previous.number?t:previous,undefined);
    order.closed=order.spk.status==='Sah'&&!!last&&last.type==='Final'&&order.terms.every(t=>settled.get(t.id)===true);
  }
  for(const p of plans.values()){
    p.closed=p.project.status==='Sah'&&p.deals.length>0&&p.deals.every(d=>{
      const record=deals.get(d.id)!;return d.status==='Sah'&&record.spks.length>0&&record.spks.every(v=>orders.get(v.id)!.closed);
    });
    const code=normalized(p.project.projectCode);if(code&&!p.closed)codes.get(code)!.closed=false;
  }
  const regions=new Map<string,MatrixRegion>();
  const branchCodes=new Map<string,Set<string>>(),regionCodes=new Map<string,Set<string>>(),allCodes=new Set<string>();
  const totals=empty();
  for(const [key,b] of branches){
    if(branch&&key!==normalized(branch))continue;
    let region=regions.get(b.regionId);
    if(!region){region={id:b.regionId,name:b.regionName,branches:[],totals:empty()};regions.set(b.regionId,region);regionCodes.set(b.regionId,new Set());}
    region.branches.push(b);branchCodes.set(key,new Set());
  }
  for(const {project:p,realized} of plans.values()){
    const key=normalized(p.branch);if(!branchCodes.has(key))continue;
    const b=branches.get(key)!,r=regions.get(b.regionId)!;
    const code=normalized(p.projectCode);
    if(code){branchCodes.get(key)!.add(code);regionCodes.get(r.id)!.add(code);allCodes.add(code);}
    const boq=p.parentId?0:boqTotal(p);
    for(const sum of [b.totals,r.totals,totals]){sum.plans++;sum.realized+=realized;sum.total+=boq;if(!code)sum.missingCodes++;}
  }
  function finish(sum:MatrixTotals,ids:Set<string>){sum.projects=ids.size;sum.closed=[...ids].filter(code=>codes.get(code)!.closed).length;sum.open=sum.projects-sum.closed;sum.realized=round(sum.realized);sum.total=round(sum.total);}
  for(const [key,b] of branches)if(branchCodes.has(key))finish(b.totals,branchCodes.get(key)!);
  for(const r of regions.values()){finish(r.totals,regionCodes.get(r.id)!);r.branches.sort((a,b)=>a.name.localeCompare(b.name,'id'));}
  finish(totals,allCodes);
  return {regions:[...regions.values()].sort((a,b)=>a.id==='unassigned'?1:b.id==='unassigned'?-1:a.name.localeCompare(b.name,'id')),totals};
}

import type { State, Project, Role } from './domain';
import { ppTemplate } from './plan-template';
import { canReviseSubmission } from './workflow';

export type BudgetCategory = 'mandor' | 'material' | 'bop' | 'permit';
export interface MandorPlanRow { rowId:string; qtyM:number; qtyS:number; priceM:number; priceS:number }
export interface MaterialPlanRow { id:string; name:string; qty:number; unit:string; price:number }
export interface BopPlanRow { id:string; description:string; unit:string; qty:number; total:number }
export interface UsagePlanRow { id:string; category:BudgetCategory; component?:'m'|'s'; term?:string; date?:string; amount:number }
export interface SignaturePlanRow { id:string; label:'Dibuat Oleh'|'Diperiksa Oleh'|'Disetujui Oleh'; role:Role; job:string }
export interface PlanBudget { version:1|2; mandor:MandorPlanRow[]; materials:MaterialPlanRow[]; bop:BopPlanRow[]; permit:number; bopLimit?:number|null; bopReference?:string; usage:UsagePlanRow[] }
export interface OwnerPlanRow { rowId:string; code:string; name:string; unit:string; m:number|null; s:number|null; qm:number; qs:number }
export const categories: Record<BudgetCategory,string> = {mandor:'Jasa Mandor',material:'Material Non Stock',bop:'BOP',permit:'Perizinan (Preliminary) Reimburse'};
export const printRoles:Role[]=['Admin Konstruksi','PM Konstruksi','Admin Area','Mgr Deployment','Mgr Bussiness Support','Mgr Project Support','Head of PMO'];
export const defaultSignatureRows = ():SignaturePlanRow[] => ppTemplate.signatures.map((row,i)=>({id:`signature-${i}`,label:row.label as SignaturePlanRow['label'],role:row.role as Role,job:row.job}));
export function ownerPlanRows(s:State,p:Project):OwnerPlanRow[] {
  const root=p.parentId?s.projects.find(x=>x.id===p.rootId):p;
  const catalog=new Map(s.khs.map(k=>[k.id,k]));
  return (root?.boq??[]).filter(row=>!p.parentId||p.allocations[row.id]).map(row=>{
    const k=catalog.get(row.khsId);
    return {rowId:row.id,code:k?.code??row.khsId,name:k?.name??'',unit:k?.unit??'',m:row.m,s:row.s,qm:row.m===null?0:p.parentId?p.allocations[row.id]?.m??0:row.qty,qs:row.s===null?0:p.parentId?p.allocations[row.id]?.s??0:row.qty};
  });
}
export const cents = (v:number) => Math.sign(v)*Math.round((Math.abs(v)+Number.EPSILON)*100);
const amount = (v:number) => cents(v)/100;
/** Integer scales match Laravel BCMath: quantity 3 decimals, price 2, half-up per component. */
export function lineAmount(q:number,p:number):number {
  if(!Number.isFinite(q)||!Number.isFinite(p)||Math.abs(q*1000)>Number.MAX_SAFE_INTEGER||Math.abs(p*100)>Number.MAX_SAFE_INTEGER)return NaN;
  const product=BigInt(Math.round(q*1000))*BigInt(Math.round(p*100)),sign=product<0n?-1n:1n;
  return Number(sign*((product*sign+500n)/1000n))/100;
}
export function ratioPercent(value:number,base:number):number|null {
  if(!base||!Number.isFinite(value)||!Number.isFinite(base)||!Number.isSafeInteger(cents(value))||!Number.isSafeInteger(cents(base)))return null;
  const numerator=BigInt(cents(value))*10000n,denominator=BigInt(cents(base)),sign=numerator<0n?-1n:1n;
  return Number(sign*((numerator*sign*2n+denominator)/(denominator*2n)))/100;
}
export function draftPlanBudget(s:State,p:Project):PlanBudget {
  const previous=p.planBudget;
  const mandorByRow=new Map(previous?.mandor.map(row=>[row.rowId,row])??[]);
  const version=canReviseSubmission(p)?2:previous?.version??2;
  const b:PlanBudget={version,mandor:ownerPlanRows(s,p).map(row=>mandorByRow.get(row.rowId)??{rowId:row.rowId,qtyM:row.qm,qtyS:row.qs,priceM:0,priceS:0}),materials:previous?.materials??[],bop:previous?.bop??[],permit:previous?.permit??p.costs.permit,usage:previous?.usage??[],...(!canReviseSubmission(p)&&previous?{...(previous.bopLimit!==undefined?{bopLimit:previous.bopLimit}:{}),...(previous.bopReference!==undefined?{bopReference:previous.bopReference}:{})}:{})};
  if(version===2)b.usage=automaticBudgetUsage(budgetSummary(s,p,b));
  return b;
}
export function budgetSummary(s:State,p:Project,b=draftPlanBudget(s,p)) {
  const owners=ownerPlanRows(s,p),sum=(values:number[])=>values.reduce((n,v)=>n+cents(v),0)/100;
  const ownerM=sum(owners.map(r=>lineAmount(r.qm,r.m??0))),ownerS=sum(owners.map(r=>lineAmount(r.qs,r.s??0)));
  const mandorM=sum(b.mandor.map(r=>lineAmount(r.qtyM,r.priceM))),mandorS=sum(b.mandor.map(r=>lineAmount(r.qtyS,r.priceS)));
  const material=sum(b.materials.map(r=>lineAmount(r.qty,r.price))),bop=sum(b.bop.map(r=>r.total)),permit=b.permit;
  const mandor=amount(mandorM+mandorS),total=amount(mandor+material+bop),grossProfit=amount(ownerS-total);
  const usage={mandor:0,material:0,bop:0,permit:0};
  const usageRows=b.version===2||canReviseSubmission(p)?automaticBudgetUsage({mandorM,mandorS,material,bop,permit}):b.usage;
  for(const row of usageRows) if(Object.hasOwn(usage,row.category)) usage[row.category]=amount(usage[row.category]+row.amount);
  return {ownerM,ownerS,ownerTotal:amount(ownerM+ownerS),mandorM,mandorS,mandor,material,bop,permit,total,revenue:ownerS,cogs:total,grossProfit,margin:ratioPercent(grossProfit,ownerS),usage};
}

/** Canonical positive rows for point IV.5; no client installments or dates. */
export function automaticBudgetUsage(t:Pick<ReturnType<typeof budgetSummary>,'mandorM'|'mandorS'|'material'|'bop'|'permit'>):UsagePlanRow[] {
  return [
    {id:'usage-mandor-s',category:'mandor' as const,component:'s' as const,amount:t.mandorS},
    {id:'usage-mandor-m',category:'mandor' as const,component:'m' as const,amount:t.mandorM},
    ...(['material','bop','permit'] as const).map(category=>({id:'usage-'+category,category,amount:t[category]})),
  ].filter(row=>row.amount>0);
}
export const usageLabel=(row:UsagePlanRow)=>row.category==='mandor'&&row.component==='m'?'Material BOQ Mandor':categories[row.category];
export function validatePlanBudget(s:State,p:Project,submit:boolean) {
  const b=p.planBudget!, owners=ownerPlanRows(s,p);
  const ownersByRow=new Map(owners.map(row=>[row.rowId,row]));
  const number=(v:number,volume=false)=>{if(!Number.isFinite(v)||v<0||v>1000000000000||(volume&&(v>1000000000||Math.abs(v*1000-Math.round(v*1000))>0.000001))||(!volume&&Math.abs(v*100-Math.round(v*100))>0.0001))throw new Error('Angka anggaran harus nonnegatif; uang maksimal dua desimal, volume tiga desimal.');};
  const ids=(rows:{id:string}[])=>{if(rows.length>200||new Set(rows.map(r=>r.id)).size!==rows.length||rows.some(r=>!/^[a-zA-Z0-9-]{3,100}$/.test(r.id)))throw new Error('Baris anggaran harus memiliki ID unik, maksimal 200 baris per tabel.');};
  ids(b.materials);ids(b.bop); number(b.permit);
  if(b.mandor.length!==owners.length||new Set(b.mandor.map(r=>r.rowId)).size!==owners.length)throw new Error('Designator BOQ Mandor harus sesuai BOQ Plan.');
  for(const row of b.mandor){const owner=ownersByRow.get(row.rowId);number(row.qtyM,true);number(row.qtyS,true);number(row.priceM);number(row.priceS);if(!owner||row.qtyM>owner.qm||row.qtyS>owner.qs||row.priceM>(owner.m??0)||row.priceS>(owner.s??0))throw new Error('Harga atau volume BOQ Mandor melebihi BOQ Plan per komponen.');}
  for(const row of b.materials){number(row.qty,true);number(row.price);if(submit&&(!row.name.trim()||!row.unit.trim()||!row.qty))throw new Error('Lengkapi Material Non Stock, satuan dan volume positif.');}
  for(const row of b.bop){number(row.qty,true);number(row.total);if(submit&&(!row.description.trim()||!row.unit.trim()||!row.qty))throw new Error('Lengkapi uraian BOP, satuan dan jumlah positif.');}
  const totals=budgetSummary(s,p,b);
  if([totals.total,totals.ownerTotal].some(v=>!Number.isFinite(v)||v>1000000000000))throw new Error('Total anggaran di luar batas yang didukung.');
  if(!ppTemplate.riskLevels.includes(p.riskLevel??'')){if(submit||p.riskLevel)throw new Error('Pilih tingkat risiko sesuai checklist template.');}
  if((p.signatureRows??[]).length>20||new Set((p.signatureRows??[]).map(r=>r.id)).size!==(p.signatureRows??[]).length)throw new Error('Baris penandatangan harus unik, maksimal 20.');
  for(const r of p.signatureRows??[])if(!/^[a-zA-Z0-9-]{3,100}$/.test(r.id)||!['Dibuat Oleh','Diperiksa Oleh','Disetujui Oleh'].includes(r.label)||!printRoles.includes(r.role)||r.job.length>250)throw new Error('Baris penandatangan tidak valid.');
  return totals;
}

export function applyBudgetPlan(s:State,p:Project,submit=false,strict=false):Project {
  if(strict&&p.planBudget){const ids=new Set(ownerPlanRows(s,p).map(r=>r.rowId)),rows=p.planBudget.mandor;if(rows.length!==ids.size||new Set(rows.map(r=>r.rowId)).size!==ids.size||rows.some(r=>!ids.has(r.rowId)))throw new Error('Designator BOQ Mandor harus sesuai BOQ Plan.');}
  const b=draftPlanBudget(s,strict?{...p,status:'Draft'}:p), result={...p,planBudget:b,riskLevel:p.riskLevel??'',risk:p.riskLevel??'',communication:ppTemplate.communicationRows.map(row=>row.join(' | ')).join('\n'),quality:ppTemplate.quality.join('\n'),closing:ppTemplate.closing,signatureRows:p.signatureRows??defaultSignatureRows()};
  const totals=strict?validatePlanBudget(s,result,submit):budgetSummary(s,result,b);
  return {...result,costs:{mandor:totals.mandor,material:totals.material,bop:totals.bop,permit:totals.permit},planBudgetTotals:totals};
}

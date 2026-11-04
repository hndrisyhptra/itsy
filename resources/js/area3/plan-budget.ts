import type { State, Project, Role } from './domain';
import { ppTemplate } from './plan-template';

export type BudgetCategory = 'mandor' | 'material' | 'bop' | 'permit';
export interface MandorPlanRow { rowId:string; qtyM:number; qtyS:number; priceM:number; priceS:number }
export interface MaterialPlanRow { id:string; name:string; qty:number; unit:string; price:number }
export interface BopPlanRow { id:string; description:string; unit:string; qty:number; total:number }
export interface UsagePlanRow { id:string; category:BudgetCategory; term:string; date:string; amount:number }
export interface SignaturePlanRow { id:string; label:'Dibuat Oleh'|'Diperiksa Oleh'|'Disetujui Oleh'; role:Role; job:string }
export interface PlanBudget { version:1; mandor:MandorPlanRow[]; materials:MaterialPlanRow[]; bop:BopPlanRow[]; permit:number; bopLimit:number|null; bopReference:string; usage:UsagePlanRow[] }
export interface OwnerPlanRow { rowId:string; code:string; name:string; unit:string; m:number|null; s:number|null; qm:number; qs:number }
export const categories: Record<BudgetCategory,string> = {mandor:'Jasa Mandor',material:'Material Non Stock',bop:'BOP',permit:'Perizinan (Preliminary) Reimburse'};
export const printRoles:Role[]=['Admin Konstruksi','PM Konstruksi','Admin Area','Mgr Deployment','Mgr Bussiness Support','Head of PMO'];
export const defaultSignatureRows = ():SignaturePlanRow[] => printRoles.map((role,i)=>({id:`signature-${i}`,label:i===0?'Dibuat Oleh':i<3?'Diperiksa Oleh':'Disetujui Oleh',role,job:role}));
export function ownerPlanRows(s:State,p:Project):OwnerPlanRow[] {
  const root=p.parentId?s.projects.find(x=>x.id===p.rootId):p;
  return (root?.boq??[]).filter(row=>!p.parentId||p.allocations[row.id]).map(row=>{
    const k=s.khs.find(k=>k.id===row.khsId);
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
  return {version:1,mandor:ownerPlanRows(s,p).map(row=>previous?.mandor.find(x=>x.rowId===row.rowId)??{rowId:row.rowId,qtyM:row.qm,qtyS:row.qs,priceM:0,priceS:0}),materials:previous?.materials??[],bop:previous?.bop??[],permit:previous?.permit??p.costs.permit,bopLimit:previous?.bopLimit??null,bopReference:previous?.bopReference??'',usage:previous?.usage??[]};
}
export function budgetSummary(s:State,p:Project,b=draftPlanBudget(s,p)) {
  const owners=ownerPlanRows(s,p),sum=(values:number[])=>values.reduce((n,v)=>n+cents(v),0)/100;
  const ownerM=sum(owners.map(r=>lineAmount(r.qm,r.m??0))),ownerS=sum(owners.map(r=>lineAmount(r.qs,r.s??0)));
  const mandorM=sum(b.mandor.map(r=>lineAmount(r.qtyM,r.priceM))),mandorS=sum(b.mandor.map(r=>lineAmount(r.qtyS,r.priceS)));
  const material=sum(b.materials.map(r=>lineAmount(r.qty,r.price))),bop=sum(b.bop.map(r=>r.total)),permit=b.permit;
  const mandor=amount(mandorM+mandorS),total=amount(mandor+material+bop),grossProfit=amount(ownerS-total);
  const usage={mandor:0,material:0,bop:0,permit:0}; for(const row of b.usage) if(Object.hasOwn(usage,row.category)) usage[row.category]=amount(usage[row.category]+row.amount);
  return {ownerM,ownerS,ownerTotal:amount(ownerM+ownerS),mandorM,mandorS,mandor,material,bop,permit,total,revenue:ownerS,cogs:total,grossProfit,margin:ratioPercent(grossProfit,ownerS),usage};
}

/** Prefill only the selected row; existing installments keep their entered amounts. */
export function selectUsageCategory(s:State,p:Project,rowId:string,category:BudgetCategory):UsagePlanRow[] {
  const budget=draftPlanBudget(s,p);
  if(!Object.hasOwn(categories,category)||!budget.usage.some(row=>row.id===rowId))throw new Error('Kategori atau baris waktu penggunaan tidak valid.');
  const totals=budgetSummary(s,p,budget);
  const allocated=budget.usage.filter(row=>row.id!==rowId&&row.category===category).reduce((sum,row)=>sum+cents(row.amount),0);
  const value=category==='mandor'?0:Math.max(0,cents(totals[category])-allocated)/100;
  return budget.usage.map(row=>row.id===rowId?{...row,category,amount:value}:row);
}
const validDate=(v:string)=>/^\d{4}-\d{2}-\d{2}$/.test(v)&&Number.isFinite(Date.parse(v))&&new Date(v).toISOString().slice(0,10)===v;
export function validatePlanBudget(s:State,p:Project,submit:boolean) {
  const b=p.planBudget!, owners=ownerPlanRows(s,p);
  const number=(v:number,volume=false)=>{if(!Number.isFinite(v)||v<0||v>1000000000000||(volume&&(v>1000000000||Math.abs(v*1000-Math.round(v*1000))>0.000001))||(!volume&&Math.abs(v*100-Math.round(v*100))>0.0001))throw new Error('Angka anggaran harus nonnegatif; uang maksimal dua desimal, volume tiga desimal.');};
  const ids=(rows:{id:string}[])=>{if(rows.length>200||new Set(rows.map(r=>r.id)).size!==rows.length||rows.some(r=>!/^[a-zA-Z0-9-]{3,100}$/.test(r.id)))throw new Error('Baris anggaran harus memiliki ID unik, maksimal 200 baris per tabel.');};
  ids(b.materials);ids(b.bop);ids(b.usage); number(b.permit);
  if(b.mandor.length!==owners.length||new Set(b.mandor.map(r=>r.rowId)).size!==owners.length)throw new Error('Designator BOQ Mandor harus sesuai BOQ Plan.');
  for(const row of b.mandor){const owner=owners.find(o=>o.rowId===row.rowId);number(row.qtyM,true);number(row.qtyS,true);number(row.priceM);number(row.priceS);if(!owner||row.qtyM>owner.qm||row.qtyS>owner.qs||row.priceM>(owner.m??0)||row.priceS>(owner.s??0))throw new Error('Harga atau volume BOQ Mandor melebihi BOQ Plan per komponen.');}
  for(const row of b.materials){number(row.qty,true);number(row.price);if(submit&&(!row.name.trim()||!row.unit.trim()||!row.qty))throw new Error('Lengkapi Material Non Stock, satuan dan volume positif.');}
  for(const row of b.bop){number(row.qty,true);number(row.total);if(submit&&(!row.description.trim()||!row.unit.trim()||!row.qty))throw new Error('Lengkapi uraian BOP, satuan dan jumlah positif.');}
  if(b.bopReference.length>500)throw new Error('Referensi Cost Structure terlalu panjang.');
  const totals=budgetSummary(s,p,b);
  if(b.bopLimit!==null){number(b.bopLimit);if(cents(totals.bop)>cents(b.bopLimit))throw new Error('Total BOP melebihi batas Cost Structure yang diisi.');}
  if(submit&&totals.bop>0&&(b.bopLimit===null||!b.bopReference.trim()))throw new Error('Isi batas BOP dan referensi Cost Structure sebelum pengajuan.');
  for(const row of b.usage){if(!Object.hasOwn(categories,row.category))throw new Error('Kategori waktu penggunaan tidak valid.');number(row.amount);if(row.term.length>100||(row.date&&!validDate(row.date))||(submit&&(!row.term.trim()||!validDate(row.date)||!row.amount)))throw new Error('Lengkapi termin, tanggal dan nilai positif pada waktu penggunaan.');}
  for(const key of Object.keys(categories) as BudgetCategory[]){if(cents(totals.usage[key])>cents(totals[key])||(submit&&cents(totals.usage[key])!==cents(totals[key])))throw new Error(`Rincian waktu penggunaan ${categories[key]} harus sesuai total anggarannya.`);}
  if([totals.total,totals.ownerTotal].some(v=>!Number.isFinite(v)||v>1000000000000))throw new Error('Total anggaran di luar batas yang didukung.');
  if(!ppTemplate.riskLevels.includes(p.riskLevel??'')){if(submit||p.riskLevel)throw new Error('Pilih tingkat risiko sesuai checklist template.');}
  if((p.signatureRows??[]).length>20||new Set((p.signatureRows??[]).map(r=>r.id)).size!==(p.signatureRows??[]).length)throw new Error('Baris penandatangan harus unik, maksimal 20.');
  for(const r of p.signatureRows??[])if(!/^[a-zA-Z0-9-]{3,100}$/.test(r.id)||!['Dibuat Oleh','Diperiksa Oleh','Disetujui Oleh'].includes(r.label)||!printRoles.includes(r.role)||r.job.length>250)throw new Error('Baris penandatangan tidak valid.');
  return totals;
}

export function applyBudgetPlan(s:State,p:Project,submit=false,strict=false):Project {
  if(strict&&p.planBudget){const ids=ownerPlanRows(s,p).map(r=>r.rowId),rows=p.planBudget.mandor;if(rows.length!==ids.length||new Set(rows.map(r=>r.rowId)).size!==ids.length||rows.some(r=>!ids.includes(r.rowId)))throw new Error('Designator BOQ Mandor harus sesuai BOQ Plan.');}
  const b=draftPlanBudget(s,p), result={...p,planBudget:b,riskLevel:p.riskLevel??'',risk:p.riskLevel??'',communication:ppTemplate.communicationRows.map(row=>row.join(' | ')).join('\n'),quality:ppTemplate.quality.join('\n'),closing:ppTemplate.closing,signatureRows:p.signatureRows??defaultSignatureRows()};
  const totals=strict?validatePlanBudget(s,result,submit):budgetSummary(s,result,b);
  return {...result,costs:{mandor:totals.mandor,material:totals.material,bop:totals.bop,permit:totals.permit},planBudgetTotals:totals};
}

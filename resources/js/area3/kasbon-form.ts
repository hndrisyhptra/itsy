import { today } from './domain';
import type { KasbonData } from './kasbon-document';
import type { KasbonRow } from './kasbon-content';
import type { KasbonSettlement } from './kasbon-settlement';

export const KASBON_DEFAULTS={place:'Surabaya',evp:'I GEDE WAHYU DANYNDRA',businessSupport:"ARIZA ARBA'ATUS S"};
export type KasbonInput={settlementUserId?:string;costCenter:string;wbs:string;activity:string;place:string;date:string;evp:string;businessSupport:string;rows:KasbonRow[]};
/** Frozen legacy input remains available to historical callers; the submission form passes a canonical quote. */
export function kasbonInput(value:number|KasbonSettlement,previous?:KasbonData):KasbonInput {
  const amount=typeof value==='number'?value:value.net;
  const rows:KasbonRow[]=typeof value==='number'
    ?previous?.rows.reduce((n,r)=>n+Number(r.amount),0)===amount?previous.rows.map(r=>({...r,amount:String(r.amount)})):[{accountCode:'',accountName:'',text:'',amount:String(amount)}]
    :kasbonComponentRows(value,previous);
  return {settlementUserId:previous?.settlementUserId??'',costCenter:previous?.costCenter??'',wbs:previous?.wbs??'',activity:previous?.activity??'',place:previous?.place??KASBON_DEFAULTS.place,date:today(),
    evp:previous?.evp??KASBON_DEFAULTS.evp,businessSupport:previous?.businessSupport??KASBON_DEFAULTS.businessSupport,
    rows};
}

/** Jasa is net of the existing frozen quote tax; Material stays intact. No per-row tax rounding. */
export function kasbonComponentRows(quote:KasbonSettlement,previous?:KasbonData):KasbonRow[]{
  const serviceCents=BigInt(Math.round(quote.net*100))-BigInt(Math.round(quote.material*100));
  const parts=[{component:'service' as const,amount:Number(serviceCents)/100},{component:'material' as const,amount:quote.material}].filter(r=>r.amount>0);
  return parts.map(part=>{
    const matches=previous?.rows.filter(r=>r.component===part.component)??[];
    // A historical untyped row is reusable only when the old/new bill have one identical component.
    const old=matches.length===1?matches[0]:previous?.rows.length===1&&!previous.rows[0].component&&parts.length===1&&previous.settlement&&
      (part.component==='service'?previous.settlement.material===0:previous.settlement.service===0)?previous.rows[0]:undefined;
    return {component:part.component,accountCode:old?.accountCode??'',accountName:old?.accountName??'',text:old?.text??'',amount:String(part.amount)};
  });
}

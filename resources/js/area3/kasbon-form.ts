import { today } from './domain';
import type { KasbonData } from './kasbon-document';

export const KASBON_DEFAULTS={place:'Surabaya',evp:'I GEDE WAHYU DANYNDRA',businessSupport:"ARIZA ARBA'ATUS S"};
export type KasbonInput={costCenter:string;wbs:string;activity:string;place:string;date:string;evp:string;businessSupport:string;rows:{accountCode:string;accountName:string;text:string;amount:string}[]};
export function kasbonInput(amount:number,previous?:KasbonData):KasbonInput {
  return {costCenter:previous?.costCenter??'',wbs:previous?.wbs??'',activity:previous?.activity??'',place:previous?.place??KASBON_DEFAULTS.place,date:today(),
    evp:previous?.evp??KASBON_DEFAULTS.evp,businessSupport:previous?.businessSupport??KASBON_DEFAULTS.businessSupport,
    rows:previous?.rows.map(r=>({...r,amount:String(r.amount)}))??[{accountCode:'',accountName:'',text:'',amount:String(amount)}]};
}

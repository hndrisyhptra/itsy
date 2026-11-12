export const WHOLE_RUPIAH = 'idr-trunc-v1' as const;
export const HALF_RUPIAH = 'idr-whole-v1' as const;
export interface MoneyPolicy { moneyPolicy?:typeof WHOLE_RUPIAH|typeof HALF_RUPIAH }
const currency=new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0,minimumFractionDigits:0});
const plain=new Intl.NumberFormat('id-ID',{maximumFractionDigits:0});
export const rupiahNumber={format:(value:number)=>plain.format((Object.is(Math.trunc(value),-0)?0:Math.trunc(value)))};
export const money=(value:number)=>currency.format((Object.is(Math.trunc(value),-0)?0:Math.trunc(value)));
export const wholeRupiah=(value:number)=>Object.is(Math.trunc(value),-0)?0:Math.trunc(value);
export const monetaryRound=(value:number,document:MoneyPolicy)=>document.moneyPolicy===WHOLE_RUPIAH?wholeRupiah(value):document.moneyPolicy===HALF_RUPIAH?Math.sign(value)*Math.round(Math.abs(value)+Number.EPSILON):Math.sign(value)*Math.round((Math.abs(value)+Number.EPSILON)*100)/100;

/** Truncation policy cuts each independent component from exact scaled quantities. Legacy rows retain combined cent rounding. */
export function moneyLine(qm:number,pm:number,qs:number,ps:number,document:MoneyPolicy){
  if(![qm,pm,qs,ps].every(Number.isFinite))return NaN;
  if(document.moneyPolicy!==WHOLE_RUPIAH&&document.moneyPolicy!==HALF_RUPIAH)return monetaryRound(qm*pm+qs*ps,document);
  const component=(q:number,p:number)=>document.moneyPolicy===HALF_RUPIAH?Number((BigInt(Math.round(q*1000))*BigInt(Math.round(p*100))+50000n)/100000n):Number((BigInt(Math.round(q*1000))*BigInt(Math.trunc(p)))/1000n);
  return component(qm,pm)+component(qs,ps);
}

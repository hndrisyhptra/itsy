import type { Deal, Term } from './domain';
import { moneyLine, monetaryRound } from './currency';

export const KASBON_SETTLEMENT_POLICY='mandor-service-pph25-v1' as const;
export interface KasbonSettlement {policy:typeof KASBON_SETTLEMENT_POLICY;gross:number;material:number;service:number;withheld:number;net:number}
/** Saved agreement × this term's actuals. Excluded Material/Jasa remain independent. */
export function kasbonQuote(deal:Deal,term:Term):KasbonSettlement {
  let material=0,gross=0;
  for(const r of deal.rows){
    const a=term.actuals[r.rowId]??{m:0,s:0};
    material+=moneyLine(r.m?a.m:0,r.pm,0,0,term);
    gross+=moneyLine(r.m?a.m:0,r.pm,r.s?a.s:0,r.ps,term);
  }
  material=monetaryRound(material,term);gross=monetaryRound(gross,term);
  const service=monetaryRound(gross-material,term);
  const net=Number((BigInt(Math.round(material*100))*1000n+BigInt(Math.round(service*100))*975n)/100000n);
  return {policy:KASBON_SETTLEMENT_POLICY,gross,material,service,withheld:monetaryRound(gross-net,term),net};
}
export function paymentDue(term:Pick<Term,'kasbon'>,gross:number){return term.kasbon?.settlement?.policy===KASBON_SETTLEMENT_POLICY?term.kasbon.amount:gross;}
export function kasbonQuoteMatches(deal:Deal,term:Term){
  const saved=term.kasbon?.settlement;if(!saved)return true;
  const expected=kasbonQuote(deal,term);
  return saved.policy===expected.policy&&(['gross','material','service','withheld','net'] as const).every(k=>saved[k]===expected[k])&&term.kasbon?.amount===expected.net;
}

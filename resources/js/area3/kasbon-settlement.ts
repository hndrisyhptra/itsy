import type { Deal, Term } from './domain';
import { moneyLine, monetaryRound } from './currency';

export const LEGACY_KASBON_SETTLEMENT_POLICY='mandor-service-pph25-v1' as const;
export const KASBON_SETTLEMENT_POLICY='mandor-service-pph25-round-v2' as const;
export type KasbonSettlementPolicy=typeof KASBON_SETTLEMENT_POLICY|typeof LEGACY_KASBON_SETTLEMENT_POLICY;
export function isKasbonSettlementPolicy(policy:unknown):policy is KasbonSettlementPolicy{return policy===KASBON_SETTLEMENT_POLICY||policy===LEGACY_KASBON_SETTLEMENT_POLICY;}
export interface KasbonSettlement {policy:KasbonSettlementPolicy;gross:number;material:number;service:number;withheld:number;net:number}
/** Saved agreement × this term's actuals. Excluded Material/Jasa remain independent. */
export function kasbonQuote(deal:Deal,term:Term,policy:KasbonSettlementPolicy=KASBON_SETTLEMENT_POLICY):KasbonSettlement {
  if(!isKasbonSettlementPolicy(policy))throw new Error('Kebijakan perhitungan kasbon tidak dikenali.');
  let material=0,gross=0;
  for(const r of deal.rows){
    const a=term.actuals[r.rowId]??{m:0,s:0};
    material+=moneyLine(r.m?a.m:0,r.pm,0,0,term);
    gross+=moneyLine(r.m?a.m:0,r.pm,r.s?a.s:0,r.ps,term);
  }
  material=monetaryRound(material,term);gross=monetaryRound(gross,term);
  const service=monetaryRound(gross-material,term);
  // ROUND the final payable once (half-up), not the tax first. Exact integer arithmetic.
  const net=Number((BigInt(Math.round(material*100))*1000n+BigInt(Math.round(service*100))*975n+(policy===KASBON_SETTLEMENT_POLICY?50000n:0n))/100000n);
  return {policy,gross,material,service,withheld:monetaryRound(gross-net,term),net};
}
export function paymentDue(term:Pick<Term,'kasbon'>,gross:number){return isKasbonSettlementPolicy(term.kasbon?.settlement?.policy)?term.kasbon!.amount:gross;}
export function kasbonQuoteMatches(deal:Deal,term:Term){
  const saved=term.kasbon?.settlement;if(!saved)return true;
  if(!isKasbonSettlementPolicy(saved.policy))return false;
  const expected=kasbonQuote(deal,term,saved.policy);
  return saved.policy===expected.policy&&(['gross','material','service','withheld','net'] as const).every(k=>saved[k]===expected[k])&&term.kasbon?.amount===expected.net;
}

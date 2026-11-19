import type {State,Term,Bapp} from './domain';
import {termTotalForDeal} from './domain';
import {settlementRecorded} from './payment-settlement';
import {barpCurrent,requiresBarp,bappCurrent,earlyBapp,requiresBapp} from './bapp-workflow';

/** Only the authorized loaded graph; never match by Project code or a sibling document. */
export function workDocumentRows(s:State){
  const projects=new Map(s.projects.map(p=>[p.id,p])),deals=new Map(s.deals.map(d=>[d.id,d])),spks=new Map(s.spks.map(v=>[v.id,v])),bapps=new Map(s.bapps.map(b=>[b.termId,b]));
  const rows=s.terms.flatMap(t=>{const v=spks.get(t.spkId),d=deals.get(v?.dealId??''),p=projects.get(d?.projectId??'');return p&&d&&v?[{t,v,d,p,b:bapps.get(t.id),amount:termTotalForDeal(d,t),cumulative:0}]:[];});
  const groups=new Map<string,typeof rows>();for(const row of rows){const group=groups.get(row.v.id)??[];group.push(row);groups.set(row.v.id,group);}
  for(const group of groups.values()){let approved=0;for(const row of group.sort((a,b)=>a.t.number-b.t.number)){row.cumulative=approved+(row.t.status==='Disetujui'||earlyBapp(row.b)?row.amount:0);if(row.t.status==='Disetujui')approved+=row.amount;}}
  return rows;
}
export function workPaymentReady(t:Term,b:Bapp|undefined){return !requiresBapp(t)||bappCurrent(b,t);}
export const workBappReady=(t:Term,b:Bapp|undefined)=>bappCurrent(b,t)||!requiresBapp(t)&&b?.status==='Sah';

/** Mirrors the existing explicit prepareBapp guard; does not unlock the saved BARP. */
export function savedBappPreparationAllowed(t:Term,amount:number){return (t.workflowVersion??1)>=3&&['Draft','Diajukan','Disetujui'].includes(t.status)&&amount>0&&!!t.barp&&!settlementRecorded(t)&&t.paid===0&&(!requiresBarp(t)||barpCurrent(t));}

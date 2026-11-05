import type { State, Project, Deal, Component } from './domain';

export function mandorSphThreshold(p:Project,rowId:string,component:Component):number|null {
  if(!p.planBudget)return null;
  const plan=p.planBudget.mandor.find(row=>row.rowId===rowId),price=component==='m'?plan?.priceM:plan?.priceS;
  return typeof price==='number'&&Number.isFinite(price)&&price>=0?price:null;
}

/** Only the saved issued snapshot can retain the historical price policy. */
export function sphThresholdApplies(s:State,d:Deal):boolean {
  const project=s.projects.find(p=>p.id===d.projectId);
  return !!project?.planBudget&&!s.deals.some(old=>old.id===d.id&&old.projectId===d.projectId&&['Terbit','Sah'].includes(old.status));
}

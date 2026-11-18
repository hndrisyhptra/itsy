import { settlementRecorded } from './payment-settlement';
import { financeTaskPending } from './partial-payment';
import { canReviewLegacySettlement } from './legacy-settlement-review';
import { paymentDue } from './kasbon-settlement';
import { type State, boqTotal, canApproveProject, canApproveTerm, dealTotal, round, termTotalForDeal } from './domain';

/** Include Branches without transactions; Laravel already scopes the master list to the account. */
export function dashboardBranches(s: State): string[] {
  return [...new Set([...(s.branches ?? []).map(b => b.name), ...s.projects.map(p => p.branch)].filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'id'));
}

/** Read-only summary of Laravel's authorized saved state. No workflow or account mutations. */
export function dashboardSummary(s: State, branch = '') {
  const projects = s.projects.filter(p => !branch || p.branch === branch);
  const projectIds = new Set(projects.map(p => p.id));
  const deals = new Map(s.deals.filter(d => projectIds.has(d.projectId)).map(d => [d.id, d]));
  const spks = s.spks.filter(v => v.status === 'Sah' && deals.has(v.dealId));
  const spkProjects = new Map(spks.map(v => [v.id, deals.get(v.dealId)!.projectId]));
  const terms = s.terms.filter(t => spkProjects.has(t.spkId));
  const termProjects = new Map(terms.map(t => [t.id, spkProjects.get(t.spkId)!]));
  const completed = new Set(s.bapps.filter(b => b.status === 'Sah' && (b.completionPolicy!=='barp-before-approval-v1'||terms.some(t=>t.id===b.termId&&settlementRecorded(t)))).map(b => termProjects.get(b.termId)));
  const sum = (values: number[]) => round(values.reduce((a, b) => a + b, 0));
  const pendingTerms = terms.filter(t => canApproveTerm(s,t)||canReviewLegacySettlement(s,t)||(s.role==='Finance'&&financeTaskPending(t)));
  const orderDeals=new Map(spks.map(v=>[v.id,deals.get(v.dealId)!]));
  let requested=0,billed=0,paid=0,outstanding=0;
  for(const t of terms){
    if(!['Diajukan','Disetujui'].includes(t.status))continue;
    const gross=termTotalForDeal(orderDeals.get(t.spkId)!,t);requested+=gross;
    if(t.status==='Disetujui'){billed+=gross;paid+=t.paid;outstanding+=Math.max(0,round(paymentDue(t,gross)-t.paid));}
  }
  return {
    projects, spks,
    activeProjects: projects.filter(p => p.status === 'Sah' && !completed.has(p.id)).length,
    continuations: projects.filter(p => p.parentId).length,
    activeMandors: new Set(spks.map(v => deals.get(v.dealId)!.mandor)).size,
    contractValue: sum(spks.map(v => dealTotal(deals.get(v.dealId)!))),
    totalBoq: sum(projects.filter(p => !p.parentId && p.boqStatus === 'Final').map(boqTotal)),
    requested:round(requested),billed:round(billed),paid:round(paid),outstanding:round(outstanding),
    pendingApprovals: pendingTerms.length + projects.filter(p => canApproveProject(s, p)).length,
  };
}

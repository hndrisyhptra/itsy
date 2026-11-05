import { type State, boqTotal, canApproveProject, canApproveTerm, dealTotal, round, termTotal } from './domain';

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
  const completed = new Set(s.bapps.filter(b => b.status === 'Sah').map(b => termProjects.get(b.termId)));
  const approved = terms.filter(t => t.status === 'Disetujui');
  const sum = (values: number[]) => round(values.reduce((a, b) => a + b, 0));
  const pendingTerms = terms.filter(t => canApproveTerm(s, t) || (s.role === 'Finance' && t.apmStatus !== 'Valid' && ['Menunggu Verifikasi', 'Valid'].includes(t.scan?.status ?? '')));
  return {
    projects, spks,
    activeProjects: projects.filter(p => p.status === 'Sah' && !completed.has(p.id)).length,
    continuations: projects.filter(p => p.parentId).length,
    activeMandors: new Set(spks.map(v => deals.get(v.dealId)!.mandor)).size,
    contractValue: sum(spks.map(v => dealTotal(deals.get(v.dealId)!))),
    totalBoq: sum(projects.filter(p => !p.parentId && p.boqStatus === 'Final').map(boqTotal)),
    billed: sum(approved.map(t => termTotal(s, t))),
    paid: sum(approved.map(t => t.paid)),
    outstanding: sum(approved.map(t => Math.max(0, round(termTotal(s, t) - t.paid)))),
    pendingApprovals: pendingTerms.length + projects.filter(p => canApproveProject(s, p)).length,
  };
}

import type {Project} from './domain';

export const MANDOR_BUDGET_POLICY='pid-budget-mandor-v2' as const;
export const OWNER_BUDGET_POLICY='pid-budget-v1' as const;
export const tracksPidBudget=(p:Project)=>p.budgetPidPolicy===MANDOR_BUDGET_POLICY||p.budgetPidPolicy===OWNER_BUDGET_POLICY;
/** Editor previews the next explicit save; issued v1 keeps its original Owner basis. */
export function pidBudgetCosts(p:Project,editing=false){
  const mandor=editing||p.budgetPidPolicy!==OWNER_BUDGET_POLICY,t=p.planBudgetTotals;
  const material=(mandor?t?.mandorM:t?.ownerM)??0,service=(mandor?t?.mandorS:t?.ownerS)??0;
  return {mandor,ready:!mandor||material+service>0,amounts:{TOTAL:material+service,Material:material,'Non Material':service,Operasional:t?.bop??p.costs.bop}};
}

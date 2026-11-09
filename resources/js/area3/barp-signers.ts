import type { Project,State,Term,Role } from './domain';
export interface BarpSigner { userId:string; name:string; nik:string }
/** Match the frozen term assignee. Historical reads never fall back to a current account. */
export function paymentSigner(state:Pick<State,'users'>,project:Project,term:Term,role:Role,allowAccount=false):BarpSigner {
  const id=(term.workflowVersion??1)>=3?term.approverUserIds?.[role]??'':'';
  const decision=id?[...term.approvals,...(project.approvals??[])].find(a=>a.role===role&&a.userId===id&&a.decision==='Setujui'):undefined;
  const snapshot=id&&project.signatureUsers?.[role]?.userId===id?project.signatureUsers[role]:undefined;
  const user=allowAccount&&id?state.users.find(u=>u.id===id&&u.role===role):undefined;
  const first=(...values:(string|undefined)[])=>values.find(s=>s?.trim())?.trim()??'';
  return {userId:id,name:first(decision?.actorName,snapshot?.name,user?.name),nik:first(decision?.actorNik,snapshot?.nik,user?.nik)};
}

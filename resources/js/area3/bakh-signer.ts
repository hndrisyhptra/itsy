import type { Deal, State } from './domain';

/** Selected PP identities only; never infer an arbitrary Head of PMO account. */
export function syncBakhSigner(s:Pick<State,'projects'|'deals'|'users'>,deal:Deal):Deal {
  const {signerUserId:ignoredId,signerNik:ignoredNik,signerRole:ignoredRole,signerSource:ignoredSource,...clean}=deal;
  const saved=s.deals.find(d=>d.id===deal.id&&d.projectId===deal.projectId);
  if(saved&&['Terbit','Sah'].includes(saved.status))return {...clean,signer:saved.signer,signerUserId:saved.signerUserId,signerNik:saved.signerNik,signerRole:saved.signerRole,signerSource:saved.signerSource};
  const project=s.projects.find(p=>p.id===deal.projectId);
  if((project?.workflowVersion??1)<3)return clean;
  const role='Head of PMO' as const,id=project?.approverUserIds?.[role]??'';
  const decision=id?project?.approvals?.find(a=>a.role===role&&a.userId===id&&a.decision==='Setujui'):undefined;
  const snapshot=id&&project?.signatureUsers?.[role]?.userId===id?project.signatureUsers[role]:undefined;
  const user=id?s.users.find(u=>u.id===id&&u.role===role):undefined;
  const first=(...values:(string|undefined)[])=>values.find(value=>value?.trim())?.trim()??'';
  return {...clean,signer:first(decision?.actorName,snapshot?.name,user?.name),signerUserId:id,signerNik:first(decision?.actorNik,snapshot?.nik,user?.nik),signerRole:role,signerSource:'pp-head-pmo'};
}

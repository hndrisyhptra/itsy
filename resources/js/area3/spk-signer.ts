import { headRole } from './work-unit';
import type { Spk, State } from './domain';
import { projectHeadIdentity } from './bakh-signer';

export function syncSpkSigner(state: Pick<State,'spks'|'deals'|'projects'|'users'>, spk: Spk): Spk {
  const { signerUserId: ignoredId, signerNik: ignoredNik, signerRole: ignoredRole, signerSource: ignoredSource, signerJob: ignoredJob, ...clean } = spk;
  const saved = state.spks.find(s => s.id === spk.id && s.dealId === spk.dealId);
  if (saved && ['Terbit', 'Sah'].includes(saved.status)) return { ...clean, signer: saved.signer, position: saved.position, signerUserId: saved.signerUserId, signerNik: saved.signerNik, signerRole: saved.signerRole, signerSource: saved.signerSource, ...(saved.signerJob?{signerJob:saved.signerJob}:{}) };
  const deal = state.deals.find(d => d.id === spk.dealId), project = state.projects.find(p => p.id === deal?.projectId);
  if (!project || (project.workflowVersion ?? 1) < 3) return clean;
  const identity=projectHeadIdentity(state, project);
  return { ...clean, ...identity, position: identity.signerJob || (headRole(project)==='Head of PMO'?'HEAD OF PMO AREA JAWA BALI':'GENERAL MANAGER OPERATION') };
}

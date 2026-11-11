import type { State } from './domain';

export const HEAD_REOPEN_MESSAGE = 'PP sedang menunggu persetujuan ulang Head of PMO. Proses dokumen turunannya ditahan sampai PP Sah kembali.';

/** Form feedback only; Laravel repeats this against saved relations. */
export function assertHeadReopenCommand(s: State, command: string, args: unknown[]) {
  const pending = new Set(s.projects.filter(p => p.headApprovalReopen && p.status !== 'Sah').map(p => p.id));
  if (!pending.size) return;
  const deal = (id: unknown) => s.deals.find(d => d.id === id)?.projectId;
  const spk = (id: unknown) => deal(s.spks.find(v => v.id === id)?.dealId);
  const term = (id: unknown) => spk(s.terms.find(t => t.id === id)?.spkId);
  const input = args[0] as { id?: string; projectId?: string; dealId?: string } | undefined;
  let project: string | undefined;
  switch (command) {
    case 'saveDeal': project = input?.projectId; break;
    case 'saveSpk': case 'saveSpkFinance': project = deal(input?.dealId); break;
    case '_recordPayeeFile': case '_setPayeeVersion': case 'createTerm': project = spk(args[0]); break;
    case 'saveTerm': case 'submitFinanceTerm': project = term(input?.id); break;
    case 'decide': case 'adoptFinancePayment': case '_issueKasbon': case '_attachTransferProof': case 'decideFinancePayment': case 'requestPartialPayment': case 'verifyPartialPayment': case 'payPartialPayment': case 'recordApm': case 'recordPayment': case 'verifyTermScan': case '_attachTermScan': case '_attachTermWorkEvidence': case 'removeTermWorkEvidence': project = term(args[0]); break;
    case 'attestBapp': case 'releaseVolume': case 'createContinuation': project = term(s.bapps.find(b => b.id === args[0])?.termId); break;
  }
  if (project && pending.has(project)) throw new Error(HEAD_REOPEN_MESSAGE);
}

import type { Term } from './domain';

export const BARP_PLACE = 'Surabaya';
/** Presentation only: retain the saved term identity and ledger numbering. */
export function isSingleFinalBarp(term: Pick<Term, 'number' | 'type'>) {
  return term.number === 1 && term.type === 'Final';
}
export function barpStageLabel(term: Pick<Term, 'number' | 'type'>) {
  return isSingleFinalBarp(term) ? '' : `Tahap ${term.number}`;
}

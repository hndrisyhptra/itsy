import type { Term } from './domain';
import { isSinglePayment } from './payment-labels';

/** Presentation only: retain the saved term identity and ledger numbering. */
export function isSingleFinalBarp(term: Pick<Term, 'number' | 'type'>) {
  return isSinglePayment(term);
}
export function barpStageLabel(term: Pick<Term, 'number' | 'type'>) {
  return isSingleFinalBarp(term) ? '' : `Tahap ${term.number}`;
}

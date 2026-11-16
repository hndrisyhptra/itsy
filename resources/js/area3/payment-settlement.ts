import type { Term } from './domain';

/** A transfer settlement is independent of its optional APM reference. Legacy APM stays valid. */
export function settlementRecorded(t:Term):boolean {
  return t.apmStatus==='Valid'||(t.workflowVersion===3&&['kasbon-v1','kasbon-settlement-v2'].includes(t.paymentFlow??'')&&t.financePayment?.status==='Dibayar'&&!!t.financePayment.settledAt&&t.paid>0&&!!t.kasbon?.id&&!!t.transferProof?.id&&t.financePayment.kasbonId===t.kasbon.id&&t.financePayment.transferFileId===t.transferProof.id);
}

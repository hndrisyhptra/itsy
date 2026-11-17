/** Shared display labels only; saved installment identities and workflow are unchanged. */
type PaymentIdentity={number:number;type:string};
export const isSinglePayment=(t:PaymentIdentity)=>t.number===1&&t.type==='Final';
export const paymentLabel=(t:PaymentIdentity)=>isSinglePayment(t)?'Pembayaran Final':`Termin ${t.number}`;
export const paymentDescription=(t:PaymentIdentity)=>isSinglePayment(t)?'Final':`Termin ${t.number} · ${t.type}`;
export const paymentTotalLabel=(t:PaymentIdentity)=>isSinglePayment(t)?'Total Pembayaran':`Total Termin ${t.number}`;
export const paymentDocumentTitle=(kind:string,t:PaymentIdentity)=>isSinglePayment(t)&&kind==='BA Rekonsiliasi Pekerjaan – Termin'?'BA Rekonsiliasi Pekerjaan':kind;

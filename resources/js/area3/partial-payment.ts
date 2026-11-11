import { usesFinancePayment } from './finance-payment';
import { type State, type Term, type Approval, getTerm, requireAdmin, requireFinance, approvalRoles, now, log, termTotal, uid } from './domain';
import { recordCommand } from './commands';

export interface PartialPaymentRequest {
  id:string;cycle:number;status:'Diajukan'|'Dikembalikan'|'Diverifikasi'|'Dibayar';amount:number;
  requestedBy:string;requestedByName:string;requestedAt:string;documentsAtRequest:'OK'|'NOK';
  decision?:Approval;documentsAtPayment?:'OK'|'NOK';apm?:string;paidAt?:string;paidBy?:string;
}
export const usesPartialPayment=(t:Term)=>t.workflowVersion===3&&t.type==='Parsial'&&t.paymentFlow==='partial-payment-v1';
export const documentStatus=(t:Term):'OK'|'NOK'=>t.scan?'OK':'NOK';
export function financeTaskPending(t:Term) {
  if(usesFinancePayment(t))return t.status==='Disetujui'&&(t.scan?.status==='Menunggu Verifikasi'||t.apmStatus!=='Valid'&&!!t.kasbon&&t.financePayment?.status!=='Dikembalikan');
  if(t.status!=='Disetujui')return false;
  if(usesPartialPayment(t))return (t.apmStatus!=='Valid'&&['Diajukan','Diverifikasi'].includes(t.paymentRequest?.status??''))||t.scan?.status==='Menunggu Verifikasi';
  return (t.workflowVersion??1)>=2&&t.apmStatus!=='Valid'&&['Menunggu Verifikasi','Valid'].includes(t.scan?.status??'');
}
export const partialFinanceHistory=(t:Term)=>[...(t.paymentRequestHistory??[]),...(t.paymentRequest?[t.paymentRequest]:[])].flatMap(r=>r.decision?[r.decision]:[]);
export function partialPaymentLabel(t:Term) {
  const request=t.paymentRequest;
  return !request?'Belum diajukan ke Finance':request.status==='Diajukan'?'Menunggu verifikasi Payment Finance':request.status==='Diverifikasi'?'Menunggu pencatatan APM':request.status==='Dikembalikan'?'Payment dikembalikan ke Admin':'Payment terbayar';
}
function eligible(t:Term) {
  if(usesFinancePayment(t)||t.workflowVersion!==3||t.type!=='Parsial'||t.status!=='Disetujui'||t.approvals.length!==approvalRoles(t).length||t.apmStatus==='Valid'||t.paid>0)throw new Error('Payment khusus Termin Parsial membutuhkan lima approval lengkap dan tagihan belum dibayar.');
  const approvals=new Map(t.approvals.map(a=>[a.role,a]));
  for(const role of approvalRoles(t)){const a=approvals.get(role);if(a?.decision!=='Setujui'||!a.userId||a.userId!==t.approverUserIds?.[role])throw new Error('Approval Payment harus sesuai kelima pengguna terpilih, termasuk Head of PMO.');}
}
export function requestPartialPayment(s:State,id:string) {
  recordCommand('requestPartialPayment',[id]);requireAdmin(s);const t=getTerm(s,id);eligible(t);
  if(t.paymentRequest&&t.paymentRequest.status!=='Dikembalikan')throw new Error('Payment sudah diajukan. Pantau verifikasi Finance pada pengajuan yang sama.');
  const actor=s.users.find(u=>u.id===s.actorUserId);if(!actor)throw new Error('Akun Admin aktif diperlukan.');
  if(t.paymentRequest)(t.paymentRequestHistory??=[]).push(structuredClone(t.paymentRequest));
  t.paymentFlow='partial-payment-v1';t.paymentRequest={id:uid('payreq'),cycle:(t.paymentRequestHistory?.length??0)+1,status:'Diajukan',amount:termTotal(s,t),requestedBy:actor.id,requestedByName:actor.name,requestedAt:now(),documentsAtRequest:documentStatus(t)};
  log(s,'Payment Parsial diajukan ke Finance',`Termin ${t.number} · Berkas ${documentStatus(t)}`,'/termin/'+id);
}
export function verifyPartialPayment(s:State,id:string,decision:'Setujui'|'Kembalikan',note:string) {
  recordCommand('verifyPartialPayment',[id,decision,note]);requireFinance(s);const t=getTerm(s,id);eligible(t);
  if(!usesPartialPayment(t)||t.paymentRequest?.status!=='Diajukan')throw new Error('Admin harus mengajukan Payment terlebih dahulu sebelum verifikasi Finance.');
  if(!['Setujui','Kembalikan'].includes(decision)||note.length>1000||(decision==='Kembalikan'&&!note.trim()))throw new Error('Catatan pengembalian Payment wajib diisi; maksimal 1000 karakter.');
  const actor=s.users.find(u=>u.id===s.actorUserId&&u.active&&u.role==='Finance');if(!actor)throw new Error('Akun Finance aktif diperlukan.');
  t.paymentRequest.decision={role:'Finance',userId:actor.id,actorName:actor.name,decision,note:note.trim(),at:now(),paymentRequestId:t.paymentRequest.id,requestedAt:t.paymentRequest.requestedAt};t.paymentRequest.status=decision==='Setujui'?'Diverifikasi':'Dikembalikan';
  log(s,'Payment Parsial '+t.paymentRequest.status,`Termin ${t.number} · ${note}`,'/termin/'+id);
}
export function assertPartialApmReady(t:Term,total:number) {
  const r=t.paymentRequest;
  if(r?.status!=='Diverifikasi'||r.decision?.decision!=='Setujui'||!r.decision.userId||r.amount!==total)throw new Error('Payment terverifikasi sesuai tagihan wajib sebelum APM.');
}

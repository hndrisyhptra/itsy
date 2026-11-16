import { settlementRecorded } from './payment-settlement';
import { type State, type Term, type Spk, type Approval, getTerm, approvalRoles, requireFinance, requireAdmin, applyTermDecision, applyTermSave, applySpk, termTotal, now, log } from './domain';
import { paymentDue, kasbonQuoteMatches, type KasbonSettlement } from './kasbon-settlement';
import { recordCommand } from './commands';

export interface KasbonSummary {settlement?:KasbonSettlement;settlementUserId?:string;id:string;version:number;amount:number;issuedBy:string;issuedAt:string;issuedRole?:State['role']}
export interface FinancePayment {settledAt?:string;settledBy?:string;apmHistory?:{before:string;after:string;at:string;by:string}[];status:'Menunggu Approval'|'Kasbon Terbit'|'Dikembalikan'|'Diverifikasi'|'Dibayar';decision?:Approval&{kasbonId:string};documentsAtPayment?:'OK'|'NOK';kasbonId?:string;transferFileId?:string}
export const usesFinancePayment=(t:Term)=>t.workflowVersion===3&&['kasbon-v1','kasbon-settlement-v2'].includes(t.paymentFlow??'');
export const financePaymentLabel=(t:Term)=>usesSettlementPayment(t)&&['Draft','Dikembalikan','Ditolak'].includes(t.status)?'Lengkapi kasbon & ajukan ulang dari PM':t.status==='Diajukan'?'Menunggu Approval':t.financePayment?.status==='Menunggu Approval'?'Menunggu Pengajuan Kasbon Admin':t.financePayment?.status==='Kasbon Terbit'?'Kasbon Diajukan · Review Finance':t.financePayment?.status??'Menunggu Pengajuan Kasbon Admin';
export function adminKasbonPending(t:Term){
  if(t.paymentFlow==='kasbon-settlement-v2')return ['Draft','Dikembalikan','Ditolak'].includes(t.status)&&t.paid===0&&t.apmStatus!=='Valid';
  if(!usesFinancePayment(t)||t.status!=='Disetujui'||t.apmStatus==='Valid'||t.paid>0||t.kasbon&&t.financePayment?.status!=='Dikembalikan')return false;
  return approvalRoles(t).every(role=>t.approvals.some(a=>a.role===role&&a.decision==='Setujui'&&!!a.userId&&a.userId===t.approverUserIds?.[role]));
}
export const usesSettlementPayment=(t:Pick<Term,'paymentFlow'|'workflowVersion'>)=>t.workflowVersion===3&&t.paymentFlow==='kasbon-settlement-v2';
export const submissionLabel=(t:Pick<Term,'type'>)=>t.type==='Final'?'Ajukan Payment':'Ajukan Termin';
export function submitFinanceTerm(s:State,input:Term){
  requireAdmin(s);const old=getTerm(s,input.id);
  if(usesSettlementPayment(old))throw new Error('Isi dan ajukan kasbon terlebih dahulu sebelum tujuh approval.');
  if(old.workflowVersion!==3||old.paymentRequest||old.paid>0||old.kasbon)throw new Error('Pengajuan ini sudah memiliki transaksi Finance atau menggunakan alur historis.');
  recordCommand('submitFinanceTerm',[input]);applyTermSave(s,input,true);const t=getTerm(s,input.id);t.paymentFlow='kasbon-v1';t.financePayment={status:'Menunggu Approval'};
}

export function saveSpkFinance(s:State,input:Spk,action:'save'|'generate'|'attest'='save'){
  recordCommand('saveSpkFinance',[input,action]);const id=applySpk(s,input,action);s.spks.find(v=>v.id===id)!.financialFlow='kasbon-v1';return id;
}
export function financePaymentEligible(t:Term){
  if(!usesFinancePayment(t)||t.status!=='Disetujui'||t.apmStatus==='Valid'||t.paid>0)throw new Error('Payment membutuhkan seluruh approval termasuk Head dan tagihan belum dibayar.');
  for(const role of approvalRoles(t))if(!t.approvals.some(a=>a.role===role&&a.decision==='Setujui'&&a.userId&&a.userId===t.approverUserIds?.[role]))throw new Error('Seluruh approval pengguna terpilih harus lengkap.');
}
export function decideFinancePayment(s:State,id:string,decision:'Setujui'|'Kembalikan',note:string,expectedKasbonId?:string){
  const t=getTerm(s,id),expected=expectedKasbonId??t.kasbon?.id;
  recordCommand('decideFinancePayment',[id,decision,note,...(expected?[expected]:[])]);requireFinance(s);if(usesSettlementPayment(t)){if(expected!==t.kasbon?.id)throw new Error('Versi kasbon berubah. Review kembali kasbon aktif.');applyTermDecision(s,id,decision,note);return;}financePaymentEligible(t);
  if(expected!==t.kasbon?.id)throw new Error('Versi kasbon berubah. Review kembali kasbon yang aktif sebelum memberi keputusan Finance.');
  if(!t.kasbon||!['Kasbon Terbit','Diverifikasi'].includes(t.financePayment?.status??'')||!['Setujui','Kembalikan'].includes(decision)||note.length>1000||(decision==='Kembalikan'&&!note.trim())||(decision==='Setujui'&&t.financePayment?.status!=='Kasbon Terbit'))throw new Error('Terbitkan kasbon terlebih dahulu; pengembalian wajib disertai catatan.');
  if(decision==='Setujui'&&t.scan&&t.scan.status!=='Valid')throw new Error('Scan sudah tersedia; verifikasi berkas terlebih dahulu sebelum Approve Payment.');
  const actor=s.users.find(u=>u.id===s.actorUserId&&u.role==='Finance'&&u.active);if(!actor)throw new Error('Akun Finance aktif diperlukan.');
  const a:Approval&{kasbonId:string}={userId:actor.id,actorName:actor.name,role:'Finance',decision,note:note.trim(),at:now(),kasbonId:t.kasbon.id,requestedAt:t.kasbon.issuedAt};
  (t.financePaymentHistory??=[]).push(a);t.financePayment={status:decision==='Setujui'?'Diverifikasi':'Dikembalikan',decision:a};log(s,'Payment '+t.financePayment.status,`Termin ${t.number} · ${note}`,'/termin/'+id);
}
export function assertFinanceApmReady(s:State,t:Term){
  financePaymentEligible(t);
  if(t.scan&&t.scan.status!=='Valid')throw new Error('Scan sudah tersedia; verifikasi berkas sebelum APM.');
  if(t.financePayment?.status!=='Diverifikasi'||t.financePayment.decision?.decision!=='Setujui'||t.financePayment.decision.kasbonId!==t.kasbon?.id||!t.transferProof?.id||t.transferProof.kasbonId!==t.kasbon?.id||t.kasbon.amount!==paymentDue(t,termTotal(s,t))||!kasbonQuoteMatches(s.deals.find(d=>d.id===s.spks.find(v=>v.id===t.spkId)!.dealId)!,t))throw new Error('Kasbon terbit, persetujuan Finance sesuai kasbon dan bukti transfer wajib sebelum APM.');
}

export function adoptFinancePayment(s:State,id:string){
  recordCommand('adoptFinancePayment',[id]);requireAdmin(s);const t=getTerm(s,id);
  if(t.workflowVersion!==3||usesFinancePayment(t)||t.paymentRequest||t.paid>0||t.approverUserIds?.['Mgr Bussiness Support'])throw new Error('Pengajuan historis ini sudah memiliki Payment atau memerlukan penugasan approver yang sesuai.');
  t.paymentFlow='kasbon-v1';financePaymentEligible(t);t.financePayment={status:'Menunggu Approval'};log(s,'Alur kasbon diadopsi Admin','Approval pekerjaan tetap tersimpan','/termin/'+id);
}

function checkedPaymentApm(s:State,t:Term,apm:string,optional=false):string {
  apm=apm.trim().toUpperCase();
  if(optional&&!apm)return '';
  if(!/^[A-Z0-9][A-Z0-9/._-]{4,59}$/.test(apm))throw new Error('Nomor APM 5–60 karakter, huruf/angka/._-/ saja.');
  if(s.terms.some(x=>x.id!==t.id&&x.apmStatus==='Valid'&&x.apm.toUpperCase()===apm))throw new Error('Nomor APM sudah digunakan.');
  return apm;
}
export function settleFinancePayment(s:State,id:string,apm='') {
  requireFinance(s);const t=getTerm(s,id);assertFinanceApmReady(s,t);apm=checkedPaymentApm(s,t,apm,true);
  recordCommand('settleFinancePayment',[id,apm]);const amount=paymentDue(t,termTotal(s,t)),at=now();
  t.paid=amount;t.apm=apm;t.apmStatus=apm?'Valid':'Belum Terbit';
  t.paymentHistory.push({amount,ref:t.transferProof!.id,at,source:'TRANSFER_PROOF',actor:s.actorUserId});
  Object.assign(t.financePayment!,{status:'Dibayar',documentsAtPayment:t.scan?'OK':'NOK',kasbonId:t.kasbon!.id,transferFileId:t.transferProof!.id,settledAt:at,settledBy:s.actorUserId});
  log(s,'Pembayaran ditandai lunas',apm?'APM '+apm:'Bukti transfer tercatat · APM belum diisi','/termin/'+id);
}
export function updatePaymentApm(s:State,id:string,apm:string) {
  requireFinance(s);const t=getTerm(s,id);
  if(!usesFinancePayment(t)||!settlementRecorded(t)||t.status!=='Disetujui'||t.financePayment?.status!=='Dibayar'||t.paid!==paymentDue(t,termTotal(s,t)))throw new Error('Nomor APM hanya dapat diubah Finance pada pembayaran kasbon yang sudah lunas.');
  apm=checkedPaymentApm(s,t,apm);recordCommand('updatePaymentApm',[id,apm]);if(apm===t.apm)return;
  (t.financePayment.apmHistory??=[]).push({before:t.apm,after:apm,at:now(),by:s.actorUserId!});t.apm=apm;t.apmStatus='Valid';log(s,'Nomor APM diperbarui',apm,'/termin/'+id);
}

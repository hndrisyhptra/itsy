import { type State, type Term, type Spk, type Approval, getTerm, approvalRoles, requireFinance, requireAdmin, applyTermSave, applySpk, termTotal, now, log } from './domain';
import { recordCommand } from './commands';

export interface KasbonSummary {id:string;version:number;amount:number;issuedBy:string;issuedAt:string}
export interface FinancePayment {status:'Menunggu Approval'|'Kasbon Terbit'|'Dikembalikan'|'Diverifikasi'|'Dibayar';decision?:Approval&{kasbonId:string};documentsAtPayment?:'OK'|'NOK';kasbonId?:string;transferFileId?:string}
export const usesFinancePayment=(t:Term)=>t.workflowVersion===3&&t.paymentFlow==='kasbon-v1';
export const financePaymentLabel=(t:Term)=>t.status==='Diajukan'?'Menunggu Approval':t.financePayment?.status==='Menunggu Approval'?'Menunggu Kasbon Finance':t.financePayment?.status??'Menunggu Kasbon Finance';
export const submissionLabel=(t:Pick<Term,'type'>)=>t.type==='Final'?'Ajukan Payment':'Ajukan Termin';
export function submitFinanceTerm(s:State,input:Term){
  requireAdmin(s);const old=getTerm(s,input.id);
  if(old.workflowVersion!==3||old.paymentRequest||old.paid>0||old.kasbon)throw new Error('Pengajuan ini sudah memiliki transaksi Finance atau menggunakan alur historis.');
  recordCommand('submitFinanceTerm',[input]);applyTermSave(s,input,true);const t=getTerm(s,input.id);t.paymentFlow='kasbon-v1';t.financePayment={status:'Menunggu Approval'};
}

export function saveSpkFinance(s:State,input:Spk,action:'save'|'generate'|'attest'='save'){
  recordCommand('saveSpkFinance',[input,action]);const id=applySpk(s,input,action);s.spks.find(v=>v.id===id)!.financialFlow='kasbon-v1';return id;
}
export function financePaymentEligible(t:Term){
  if(!usesFinancePayment(t)||t.status!=='Disetujui'||t.apmStatus==='Valid'||t.paid>0)throw new Error('Payment membutuhkan seluruh approval termasuk Head dan tagihan belum dibayar.');
  for(const role of approvalRoles(t))if(!t.approvals.some(a=>a.role===role&&a.decision==='Setujui'&&a.userId&&a.userId===t.approverUserIds?.[role]))throw new Error('Kelima approval pengguna terpilih harus lengkap.');
}
export function decideFinancePayment(s:State,id:string,decision:'Setujui'|'Kembalikan',note:string){
  recordCommand('decideFinancePayment',[id,decision,note]);requireFinance(s);const t=getTerm(s,id);financePaymentEligible(t);
  if(!t.kasbon||!['Kasbon Terbit','Diverifikasi'].includes(t.financePayment?.status??'')||!['Setujui','Kembalikan'].includes(decision)||note.length>1000||(decision==='Kembalikan'&&!note.trim())||(decision==='Setujui'&&t.financePayment?.status!=='Kasbon Terbit'))throw new Error('Terbitkan kasbon terlebih dahulu; pengembalian wajib disertai catatan.');
  if(decision==='Setujui'&&t.scan&&t.scan.status!=='Valid')throw new Error('Scan sudah tersedia; verifikasi berkas terlebih dahulu sebelum Approve Payment.');
  const actor=s.users.find(u=>u.id===s.actorUserId&&u.role==='Finance'&&u.active);if(!actor)throw new Error('Akun Finance aktif diperlukan.');
  const a:Approval&{kasbonId:string}={userId:actor.id,actorName:actor.name,role:'Finance',decision,note:note.trim(),at:now(),kasbonId:t.kasbon.id,requestedAt:t.kasbon.issuedAt};
  (t.financePaymentHistory??=[]).push(a);t.financePayment={status:decision==='Setujui'?'Diverifikasi':'Dikembalikan',decision:a};log(s,'Payment '+t.financePayment.status,`Termin ${t.number} · ${note}`,'/termin/'+id);
}
export function assertFinanceApmReady(s:State,t:Term){
  financePaymentEligible(t);
  if(t.scan&&t.scan.status!=='Valid')throw new Error('Scan sudah tersedia; verifikasi berkas sebelum APM.');
  if(t.financePayment?.status!=='Diverifikasi'||t.financePayment.decision?.decision!=='Setujui'||t.financePayment.decision.kasbonId!==t.kasbon?.id||!t.transferProof?.id||t.transferProof.kasbonId!==t.kasbon?.id||t.kasbon.amount!==termTotal(s,t))throw new Error('Kasbon terbit, persetujuan Finance sesuai kasbon dan bukti transfer wajib sebelum APM.');
}

export function adoptFinancePayment(s:State,id:string){
  recordCommand('adoptFinancePayment',[id]);requireAdmin(s);const t=getTerm(s,id);
  if(t.workflowVersion!==3||usesFinancePayment(t)||t.paymentRequest||t.paid>0||t.approverUserIds?.['Mgr Bussiness Support'])throw new Error('Pengajuan historis ini sudah memiliki Payment atau memerlukan penugasan approver yang sesuai.');
  t.paymentFlow='kasbon-v1';financePaymentEligible(t);t.financePayment={status:'Menunggu Approval'};log(s,'Alur kasbon diadopsi Admin','Approval pekerjaan tetap tersimpan','/termin/'+id);
}

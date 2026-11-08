import { canReviseSubmission, nextTermReason, operational, type State, type Term } from './domain';

export interface PaymentLatest { id:string;number:number;type:string;status:string;version:number;apmStatus:string;scanStatus:string;financeApproved:boolean }
export interface PaymentAction { action:'create'|'open'|'blocked';label:string;termId:string;nextNumber:number|null;reason:string }

export function paymentAction(terms:PaymentLatest[],ppApproved:boolean):PaymentAction {
  const ordered=[...terms].sort((a,b)=>a.number-b.number),latest=ordered.at(-1);
  if(latest&&(latest.status!=='Disetujui'||latest.apmStatus!=='Valid')) {
    const editable=canReviseSubmission({status:latest.status,workflowVersion:latest.version} as Term);
    return {action:'open',label:editable?(latest.status==='Draft'?'Lanjutkan Draf':'Perbaiki Pengajuan'):'Buka Pengajuan',termId:latest.id,nextNumber:null,reason:''};
  }
  if(terms.length>=3||terms.some(t=>t.type==='Final')) return {action:'open',label:'Buka Pengajuan',termId:latest!.id,nextNumber:null,reason:'Termin Final sudah dibuat atau batas tiga termin tercapai.'};
  return {action:ppApproved?'create':'blocked',label:'Pilih SPK',termId:'',nextNumber:terms.length+1,reason:ppApproved?'':'PP belum Sah.'};
}
export const paymentLatest=(t:Term):PaymentLatest=>({id:t.id,number:t.number,type:t.type,status:t.status,version:t.workflowVersion??1,apmStatus:t.apmStatus,scanStatus:t.scan?.status??'',financeApproved:!!t.financeApproval});
export function paymentStage(latest:PaymentLatest|null):string {
  if(!latest)return 'Belum ada pengajuan';
  const verified=latest.version>=3?latest.financeApproved:latest.scanStatus==='Valid';
  const stage=latest.status!=='Disetujui'?latest.status==='Diajukan'?'Menunggu approval':latest.status:latest.apmStatus==='Valid'?'APM valid':latest.version>=2&&!verified?(latest.scanStatus==='Perlu Koreksi'?'Scan perlu koreksi':latest.scanStatus==='Menunggu Verifikasi'?'Menunggu Finance':'Menunggu scan TTD'):'Menunggu APM';
  return `Termin ${latest.number} · ${latest.type} · ${stage}`;
}
export function paymentStartAction(s:State,spkId:string):PaymentAction {
  if(!operational(s))throw new Error('Pengajuan pembayaran hanya untuk Admin Konstruksi atau Superadmin.');
  const v=s.spks.find(v=>v.id===spkId);if(!v||v.status!=='Sah')throw new Error('SPK Sah tidak tersedia dalam akses Anda. Muat ulang daftar.');
  const d=s.deals.find(d=>d.id===v.dealId),p=s.projects.find(p=>p.id===d?.projectId);
  if(!p)throw new Error('PP terkait tidak tersedia dalam akses Anda.');
  const action=paymentAction(s.terms.filter(t=>t.spkId===v.id).map(paymentLatest),p.status==='Sah');
  if(action.action==='open')return action;
  const reason=nextTermReason(s,spkId);if(reason)throw new Error(reason);
  return action;
}

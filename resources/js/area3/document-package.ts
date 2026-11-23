import {bappCurrent,requiresBapp} from './bapp-workflow';
import {termTotalForDeal,type State,type Term,type Deal,type Spk,type Project,type Bapp} from './domain';
import {canPrintBarp} from './barp-content';
import {settlementRecorded} from './payment-settlement';
import {paymentDue} from './kasbon-settlement';
import {approvalRoles} from './workflow';
import {currentFlowApproval} from './workflow-overview';

export interface PackageLineage {current:Term;spk:Spk;deal:Deal;project:Project}
export function documentPackageLineage(s:State,bapp:Bapp):PackageLineage|undefined{
  const current=s.terms.find(t=>t.id===bapp.termId),spk=s.spks.find(v=>v.id===current?.spkId),deal=s.deals.find(d=>d.id===spk?.dealId),project=s.projects.find(p=>p.id===deal?.projectId);
  return current&&spk&&deal&&project?{current,spk,deal,project}:undefined;
}
function approved(t:Term){return t.status==='Disetujui'&&canPrintBarp(t)&&approvalRoles(t).every(role=>!!currentFlowApproval(t,role));}
export function packagePartialPaid(t:Term,d:Deal){const due=paymentDue(t,termTotalForDeal(d,t));const proofCurrent=t.paymentFlow==='kasbon-settlement-v2'?!!t.kasbon&&t.transferProof?.kasbonId===t.kasbon.id:!t.transferProof||t.transferProof.kasbonId===t.kasbon?.id;return approved(t)&&settlementRecorded(t)&&proofCurrent&&due>0&&t.paid>=due;}
/** Cheap loaded-row gate shared by list/detail/timeline; full package validation runs on open/export. */
export function documentPackageAvailable(b:Bapp|undefined,lineage:PackageLineage|undefined){
  if(!b||b.status!=='Sah'||!lineage)return false;
  const {current:t,spk:v,deal:d,project:p}=lineage;
  return b.termId===t.id&&t.spkId===v.id&&v.dealId===d.id&&d.projectId===p.id&&p.status==='Sah'&&d.status==='Sah'&&v.status==='Sah'&&approved(t)&&(!requiresBapp(t)||bappCurrent(b,t))&&(t.type==='Final'||packagePartialPaid(t,d));
}
/** Exact saved lineage only; a shared business Project ID is never an export join key. */
export function documentPackage(s:State,bappId:string){
  const bapp=s.bapps.find(b=>b.id===bappId);
  if(!bapp||bapp.status!=='Sah')throw new Error('Paket dokumen tersedia setelah BA PP disahkan.');
  const lineage=documentPackageLineage(s,bapp);
  if(!lineage)throw new Error('Relasi dokumen belum lengkap. Muat ulang data.');
  const {current,spk,deal,project}=lineage;
  if(project.status!=='Sah'||deal.status!=='Sah'||spk.status!=='Sah'||!approved(current))throw new Error('Paket membutuhkan PP, BA KH, SPK Sah dan seluruh approval pembayaran lengkap.');
  if(current.type==='Parsial'&&!packagePartialPaid(current,deal))throw new Error('Paket dokumen Parsial tersedia setelah Finance menandai pembayaran lunas.');
  const terms=s.terms.filter(t=>t.spkId===spk.id&&t.number<=current.number&&t.status==='Disetujui').sort((a,b)=>a.number-b.number);
  if(terms.length>3||!terms.every(approved))throw new Error('Persetujuan dokumen pembayaran belum lengkap.');
  const completions=new Map(s.bapps.filter(b=>b.status==='Sah').map(b=>[b.termId,b]));
  if(terms.some(t=>requiresBapp(t)&&!bappCurrent(completions.get(t.id),t)))throw new Error('BA PP tiap pengajuan harus Sah sesuai BA RP sebelum paket diunduh.');
  return {bapp,current,spk,deal,project,terms,completions};
}
export type DocumentPackage = ReturnType<typeof documentPackage>;
export function documentPackageFilename(no:string,extension:'docx'|'pdf',term?:Pick<Term,'number'|'type'>){
  const safe=no.replace(/[\\/:*?"<>|\u0000-\u001f]/g,'-').replace(/[. ]+$/g,'').slice(0,110)||'dokumen';
  return `Paket-Dokumen_${safe}${term?.type==='Parsial'?`_Termin-${term.number}`:''}.${extension}`;
}

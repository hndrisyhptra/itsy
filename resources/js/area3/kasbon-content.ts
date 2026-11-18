import type { KasbonPrintSigner } from './finance-payment';
import type { KasbonSettlement } from './kasbon-settlement';
import type { Role, Term, Approval } from './domain';
import { bakhDocumentDate } from './bakh-content';
export interface KasbonData {printSigner?:KasbonPrintSigner;printSignerApproval?:Approval;settlement?:KasbonSettlement;templateVersion?:2|3;settlementUserId?:string;issuerRole?:Role;issuerJob?:string;costCenter:string;wbs:string;activity:string;place:string;date:string;evp:string;businessSupport:string;rows:{accountCode:string;accountName:string;text:string;amount:string}[];payee:{nik:string;accountNumber:string;accountName:string;bank:string;version?:number;ktpFileId?:string;bankFileId?:string};mandor:string;unit:string;project:string;projectCode:string;ppNo:string;spkNo:string;termNumber:number;termType:string;amount:number;signers:Partial<Record<Role,{name:string;userId:string;job?:string}>>;issuer:string}
const words=['','satu','dua','tiga','empat','lima','enam','tujuh','delapan','sembilan','sepuluh','sebelas'];
function integerWords(n:number):string {
  if(n<12)return words[n];if(n<20)return integerWords(n-10)+' belas';if(n<100)return integerWords(Math.floor(n/10))+' puluh '+integerWords(n%10);
  if(n<200)return 'seratus '+integerWords(n-100);if(n<1000)return integerWords(Math.floor(n/100))+' ratus '+integerWords(n%100);if(n<2000)return 'seribu '+integerWords(n-1000);
  for(const [unit,label] of [[1e12,'triliun'],[1e9,'miliar'],[1e6,'juta'],[1000,'ribu']] as const)if(n>=unit)return integerWords(Math.floor(n/unit))+' '+label+' '+integerWords(n%unit);
  return '';
}
export function kasbonTerbilang(amount:number){const cents=Math.round(amount*100),whole=Math.floor(cents/100),sen=cents%100;return ((whole?integerWords(whole):'nol')+' rupiah'+(sen?' '+integerWords(sen)+' sen':'')).replace(/\s+/g,' ').trim();}

export interface KasbonSignature {action:string;name:string;job:string;role?:Role;approved?:Approval;automatic?:'issuer'|'evp';span?:number}
export function kasbonSignatureColumns(d:KasbonData){return d.templateVersion===3?6:4;}
export function kasbonDate(d:KasbonData){return `${d.place}, ${bakhDocumentDate(d.date)?.full??'…'}`;}
export function kasbonTermSuffix(d:Pick<KasbonData,'termNumber'|'termType'>){return d.termNumber===1&&d.termType==='Final'?'':` · Termin ${d.termNumber}`;}
/** Presentation markers only: no synthetic EVP/maker decisions or read-time writes. */
function automaticKasbonMarks(d:KasbonData,t?:Term,kasbonId?:string):{issuer?:'issuer';evp?:'evp'} {
  if(!t||!kasbonId)return {};
  const issued=t.kasbon?.id===kasbonId?t.kasbon:t.kasbonHistory?.find(k=>k.id===kasbonId);
  if(!issued?.issuedBy?.trim()||!issued.issuedAt?.trim())return {};
  const issuer=d.issuer.trim()?'issuer' as const:undefined;
  // The EVP mark follows only the selected Manager's actual decision for the active version.
  if(t.kasbon?.id!==kasbonId||!['Diajukan','Disetujui'].includes(t.status)||t.financePayment?.status==='Dikembalikan')return {issuer};
  const role='Mgr Finance & Settlement' as const,selected=d.signers[role]?.userId;
  const manager=d.templateVersion===3&&t.workflowVersion===3&&t.paymentFlow==='kasbon-settlement-v2'&&!!selected&&t.approverUserIds?.[role]===selected
    ?t.approvals.find(a=>a.role===role&&a.userId===selected&&a.kasbonId===kasbonId&&a.decision==='Setujui'):undefined;
  const review=t.legacySettlementReview;
  const legacy=d.templateVersion!==3&&t.paymentFlow==='kasbon-v1'&&review?.kasbonId===kasbonId&&!!review.userId&&d.printSigner?.userId===review.userId&&review.decision?.role===role&&review.decision.userId===review.userId&&review.decision.kasbonId===kasbonId&&review.decision.decision==='Setujui';
  return {issuer,evp:manager||legacy?'evp':undefined};
}
export function kasbonSignatures(d:KasbonData,t?:Term,kasbonId?:string):KasbonSignature[][]{
  const automatic=automaticKasbonMarks(d,t,kasbonId);
  const row=(role:Role,job:string,action='Memeriksa'):KasbonSignature=>{
    const selected=d.signers[role],decision=t?.history.find(a=>a.role===role&&a.decision==='Setujui'&&(d.templateVersion===3?a.kasbonId===kasbonId:kasbonId===t.kasbon?.id)&&(!selected?.userId||a.userId===selected.userId));
    return {action,role,job:selected?.job?.trim()||job,name:selected?.name??decision?.actorName??'',approved:decision};
  };
  const maker={action:'Membuat',name:d.issuer,job:d.issuerJob?.trim()||(d.templateVersion===2||d.templateVersion===3?(d.issuerRole??'Admin Konstruksi')+' · Pengaju Kasbon':'Finance · Penerbit Kasbon'),automatic:automatic.issuer};
  const evp={action:'Menyetujui / Mengetahui',name:d.evp,job:'EVP Area Jawa Bali',automatic:d.evp.trim()?automatic.evp:undefined};
  if(d.templateVersion===3)return [
    [row('PM Konstruksi','Project Manager'),maker],
    [row('Mgr Project Support','Mgr Project Support Area Jawa Bali'),row('Mgr Deployment','Mgr Project Deployment Area Jawa Bali'),row('Head of PMO','Head of PMO Area Jawa Bali','Menyetujui')],
    [row('Mgr Finance & Settlement','Mgr Finance & Settlement Area Jawa Bali','Menyetujui'),evp],
  ].map(rows=>rows.map(r=>({...r,span:6/rows.length})));
  return [[row('Mgr Deployment','Mgr Project Deployment Area Jawa Bali'),row('Mgr Project Support','Mgr Project Support Area Jawa Bali'),row('PM Konstruksi','Project Manager'),maker],[{...evp,span:2},row('Head of PMO','Head of PMO Area Jawa Bali','Menyetujui / Mengetahui'),{action:'Memeriksa',name:d.printSigner?.name??d.businessSupport,job:d.printSigner?.job??'Mgr Business Support Area Jawa Bali',approved:d.printSignerApproval&&d.printSignerApproval.kasbonId===kasbonId&&d.printSignerApproval.userId===d.printSigner?.userId&&d.printSignerApproval.role==='Mgr Finance & Settlement'&&d.printSignerApproval.decision==='Setujui'?d.printSignerApproval:undefined}]];
}

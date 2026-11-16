import {type Approval,type State,type Term,getTerm,approvalRoles,now,log} from './domain';
import {usesFinancePayment,usesSettlementPayment} from './finance-payment';
import {recordCommand} from './commands';
export interface LegacySettlementReview {version:1;kasbonId:string;userId:string;name:string;assignedAt:string;assignedBy:string;decision?:Approval&{kasbonId:string}}
export const legacyReviewApproved=(t:Term)=>!t.legacySettlementReview||(t.legacySettlementReview.kasbonId===t.kasbon?.id&&t.legacySettlementReview.decision?.decision==='Setujui'&&t.legacySettlementReview.decision.role==='Mgr Finance & Settlement'&&t.legacySettlementReview.decision.userId===t.legacySettlementReview.userId&&t.legacySettlementReview.decision.kasbonId===t.kasbon?.id);
export const legacyReviewPending=(t:Term)=>!!t.legacySettlementReview&&usesFinancePayment(t)&&!usesSettlementPayment(t)&&t.status==='Disetujui'&&t.paid===0&&t.apmStatus!=='Valid'&&t.financePayment?.status==='Kasbon Terbit'&&!legacyReviewApproved(t);
export const canReviewLegacySettlement=(s:State,t:Term)=>legacyReviewPending(t)&&s.role==='Mgr Finance & Settlement'&&s.actorUserId===t.legacySettlementReview?.userId&&s.users.some(u=>u.id===s.actorUserId&&u.active&&u.role===s.role);
export const canRouteLegacyFinance=(t:Term)=>usesFinancePayment(t)&&!usesSettlementPayment(t)&&t.status==='Disetujui'&&!!t.kasbon?.id&&t.financePayment?.status==='Kasbon Terbit'&&!t.legacySettlementReview&&!t.financePayment.decision&&!t.transferProof&&!t.financePaymentHistory?.some(a=>a.decision==='Setujui')&&!t.apm&&!t.paymentHistory.length&&t.paid===0&&t.apmStatus!=='Valid';
export const legacyReviewDecisions=(t:Term)=>(t.legacySettlementReviewHistory??[]).flatMap(r=>r.decision?[r.decision]:[]);
export function routeLegacyFinance(s:State,targets:{id:string;kasbonId:string}[],userId:string){
 if(!['Finance','Superadmin'].includes(s.role))throw new Error('Hanya Finance atau Superadmin yang dapat memindahkan pengajuan.');
 if(targets.length<1||targets.length>50||new Set(targets.map(t=>t.id)).size!==targets.length)throw new Error('Pilih 1–50 pengajuan yang berbeda.');
 const terms=new Map(s.terms.map(t=>[t.id,t])),spks=new Map(s.spks.map(v=>[v.id,v])),deals=new Map(s.deals.map(d=>[d.id,d])),projects=new Map(s.projects.map(p=>[p.id,p]));
 const user=s.users.find(u=>u.id===userId&&u.active&&u.role==='Mgr Finance & Settlement');if(!user)throw new Error('Pilih akun Mgr Finance & Settlement aktif.');
 for(const target of targets){const t=terms.get(target.id);if(!t||!canRouteLegacyFinance(t)||t.kasbon?.id!==target.kasbonId)throw new Error('Pengajuan atau versi kasbon berubah; belum ada pengajuan dipindahkan.');
  const p=projects.get(deals.get(spks.get(t.spkId)!.dealId)!.projectId)!;if(user.branches&&!user.branches.includes(p.branch))throw new Error('Mgr Finance & Settlement harus memiliki seluruh Branch terpilih.');
  if(!approvalRoles(t).every(role=>t.approvals.some(a=>a.role===role&&a.decision==='Setujui'&&!!a.userId&&a.userId===t.approverUserIds?.[role])))throw new Error('Lima approval pekerjaan harus lengkap.');
 }
 recordCommand('routeLegacyFinance',[targets,userId]);
 for(const target of targets){const t=terms.get(target.id)!,r:LegacySettlementReview={version:1,kasbonId:target.kasbonId,userId:user.id,name:user.name,assignedAt:now(),assignedBy:s.actorUserId!};t.legacySettlementReview=r;(t.legacySettlementReviewHistory??=[]).push(structuredClone(r));log(s,'Payment dialihkan ke Mgr Finance & Settlement',`Termin ${t.number} · ${user.name}`,'/termin/'+t.id);}
}
export function decideLegacySettlementReview(s:State,id:string,kasbonId:string,decision:'Setujui'|'Kembalikan',note:string){
 const t=getTerm(s,id);if(!canReviewLegacySettlement(s,t))throw new Error('Review tidak menunggu akun ini.');
 if(t.kasbon?.id!==kasbonId||t.legacySettlementReview?.kasbonId!==kasbonId)throw new Error('Versi kasbon berubah.');
 if(!['Setujui','Kembalikan'].includes(decision)||note.length>1000||decision==='Kembalikan'&&!note.trim())throw new Error('Catatan pengembalian kasbon wajib diisi.');
 recordCommand('decideLegacySettlementReview',[id,kasbonId,decision,note]);
 const user=s.users.find(u=>u.id===s.actorUserId)!;
 t.legacySettlementReview!.decision={role:s.role,userId:user.id,actorName:user.name,decision,note:note.trim(),at:now(),kasbonId};
 (t.legacySettlementReviewHistory??=[]).push(structuredClone(t.legacySettlementReview!));if(decision==='Kembalikan')t.financePayment={status:'Dikembalikan'};
 log(s,'Review Mgr Finance & Settlement · '+decision,`Termin ${t.number} · ${note}`,'/termin/'+id);
}

import {type State,type Project,type Term,type Approval,type Role,approvalRoles,selectedApprover,getProject,getTerm,now,log} from './domain';
import {usesSettlementPayment} from './finance-payment';
import {usesContractBundle,bundleDocuments} from './project-bundle';
import {recordCommand} from './commands';

export type ResetMode='workflow'|'legacy-manager'|'legacy-finance'|'legacy-partial'|'legacy-scan';
export interface ResetExpected {approvedAt:string;historyLength:number;resetCount:number;bindingId:string;mode:ResetMode;financeHistoryLength:number;reviewHistoryLength:number;requestHistoryLength:number}
export interface ApprovalResetRecord {version:1;id:string;mode:ResetMode;historyOffset:number;sourceCycleNo:number;actorUserId:string;role:Approval['role'];actorName:string;at:string;reason:string;expected:ResetExpected;affectedApprovals:Approval[];carriedApprovals:Approval[];previousStatus:string;requestId?:string|null;payloadHash?:string|null;previousFinancePayment?:Term['financePayment'];previousReview?:Term['legacySettlementReview'];previousScan?:Term['scan'];previousPaymentRequest?:Term['paymentRequest']}
export function approvalCycles(document:Project|Term,project:boolean):Approval[][] {
 const history=project?(document as Project).approvalHistory??document.approvals??[]:(document as Term).history??document.approvals??[],cycles:Approval[][]=[[]];
 const reopen=project?(document as Project).headApprovalReopen:undefined;
 const resets=new Map<number,ApprovalResetRecord[]>();for(const r of document.approvalResets??[])if(r.mode==='workflow')resets.set(r.historyOffset,[...(resets.get(r.historyOffset)??[]),r]);
 for(let offset=0;offset<=history.length;offset++){
  if(reopen&&reopen.historyOffset===offset)cycles.push([...reopen.carriedApprovals]);
  for(const r of resets.get(offset)??[])cycles.push([...r.carriedApprovals]);
  if(offset===history.length)break;
  cycles.at(-1)!.push(history[offset]);if(history[offset].decision!=='Setujui')cycles.push([]);
 }
 return cycles;
}
export function approvalResetPlan(s:State,document:Project|Term,project=false) {
 const t=document as Term,ppDoc=document as Project,actor=s.actorUserId??'',role=s.role,user=s.users.find(u=>u.id===actor),roles=approvalRoles(document,project);
 let decision=document.approvals?.find(a=>roles.includes(role)&&a.userId===actor&&a.role===role&&a.decision==='Setujui'&&actor===selectedApprover(document,role)&&(project||!usesSettlementPayment(t)||a.kasbonId===t.kasbon?.id));
 let mode:ResetMode='workflow';
 if(!decision&&!project&&document.status==='Disetujui'&&!usesSettlementPayment(t)){
  if(role==='Mgr Finance & Settlement'&&t.legacySettlementReview?.userId===actor&&t.legacySettlementReview.kasbonId===t.kasbon?.id){decision=t.legacySettlementReview.decision?.kasbonId===t.kasbon?.id?t.legacySettlementReview.decision:undefined;mode='legacy-manager';}
  else if(role==='Finance'&&t.financePayment?.decision?.userId===actor&&t.financePayment.decision.kasbonId===t.kasbon?.id&&t.financePayment.status==='Diverifikasi'){decision=t.financePayment.decision;mode='legacy-finance';}
  else if(role==='Finance'&&t.paymentRequest?.decision?.userId===actor&&t.paymentRequest.decision.paymentRequestId===t.paymentRequest.id&&t.paymentRequest.status==='Diverifikasi'){decision=t.paymentRequest.decision;mode='legacy-partial';}
  else if(role==='Finance'&&t.financeApproval?.userId===actor&&t.financeApproval.scanId===t.scan?.id&&t.scan?.status==='Valid'){decision=t.financeApproval;mode='legacy-scan';}
 }
 const own=!!actor&&!!user&&user.active&&user.role===role&&decision?.userId===actor&&decision.role===role&&decision.decision==='Setujui';
 const history=project?ppDoc.approvalHistory??ppDoc.approvals??[]:t.history??t.approvals??[];
 const expected:ResetExpected={approvedAt:decision?.at??'',historyLength:history.length,resetCount:document.approvalResets?.length??0,bindingId:project?ppDoc.contractBundle?.submissions.at(-1)?.id??'':t.kasbon?.id??(mode==='legacy-partial'?t.paymentRequest?.id??'':mode==='legacy-scan'?t.scan?.id??'':''),mode,financeHistoryLength:(t.financePaymentHistory?.length??0)+(t.financeHistory?.length??0),reviewHistoryLength:t.legacySettlementReviewHistory?.length??0,requestHistoryLength:t.paymentRequestHistory?.length??0};
 const result={own,mode,decision,expected,reason:'',affected:[] as Approval[],carried:[] as Approval[]};
 if(!own){result.reason='Tidak ada persetujuan aktif atas akun Anda pada versi pengajuan ini.';return result;}
 if((document.workflowVersion??1)<2||!['Diajukan','Sah','Disetujui'].includes(document.status)){result.reason='Status pengajuan ini tidak mendukung pembatalan persetujuan.';return result;}
 const historyKey=(a:Approval)=>[a.role,a.userId??'',a.at??'',a.kasbonId??'',a.decision].join('|'),actualHistory=new Set(history.map(historyKey)),seen=new Set<Role>();
 for(const a of document.approvals??[]){if(!roles.includes(a.role)||seen.has(a.role)||a.decision!=='Setujui'||!a.userId||a.userId!==selectedApprover(document,a.role)||!a.at||!actualHistory.has(historyKey(a))||!project&&usesSettlementPayment(t)&&a.kasbonId!==t.kasbon?.id){result.reason='Riwayat persetujuan aktif tidak cocok dengan penugasan/versi tersimpan. Pembatalan ditahan.';return result;}seen.add(a.role);}
 if(mode!=='workflow'&&seen.size!==roles.length){result.reason='Approval pekerjaan historis belum lengkap. Pembatalan ditahan.';return result;}
 const deals=new Map(s.deals.map(d=>[d.id,d])),spks=new Map(s.spks.map(v=>[v.id,v]));
 const pp=project?ppDoc:s.projects.find(p=>p.id===deals.get(spks.get(t.spkId)?.dealId??'')?.projectId);
 if(!pp||pp.headApprovalReopen){result.reason='PP memakai koreksi historis khusus; pembatalan mandiri ditahan.';return result;}
 if(user?.branches&&!user.branches.includes(pp.branch)||user?.workUnits&&!user.workUnits.includes(pp.unit==='Operation'?'Operation':'Konstruksi')){result.reason='Akses Branch atau unit kerja akun telah berubah.';return result;}
 if(mode==='workflow'){
  const index=roles.indexOf(role);for(const a of document.approvals??[]){const i=roles.indexOf(a.role);(i<0||i>=index?result.affected:result.carried).push(a);}
 }else{result.affected=[decision!];result.carried=document.approvals??[];if(mode==='legacy-manager'&&t.financePayment?.decision?.decision==='Setujui')result.affected.push(t.financePayment.decision);}
 if(project){
  const children=s.deals.filter(d=>d.projectId===pp.id),ids=new Set(children.map(d=>d.id)),orders=s.spks.filter(v=>ids.has(v.dealId)),orderIds=new Set(orders.map(v=>v.id));
  if(s.terms.some(t=>orderIds.has(t.spkId))||s.projects.some(p=>p.parentId===pp.id))result.reason='PP sudah memiliki pengajuan pekerjaan/pembayaran atau PP lanjutan. Pembatalan mandiri ditahan.';
  else if(!usesContractBundle(pp)&&(children.length||orders.length))result.reason='PP historis sudah memiliki BA KH/SPK terpisah. Pembatalan mandiri ditahan.';
 }else{
  if(t.financePayment?.status==='Dikembalikan'||t.paymentRequest?.status==='Dikembalikan')result.reason='Pengajuan sudah dikembalikan untuk revisi Admin. Pembatalan persetujuan ditahan.';
  else if(pp.status!=='Sah')result.reason='PP belum Sah; proses turunannya sedang ditahan.';
  else if(t.paid>0||t.apm||t.apmStatus==='Valid'||t.paymentHistory.length||t.transferProof||t.transferProofHistory?.length||t.financePayment?.settledAt||t.financePayment?.status==='Dibayar')result.reason='Bukti transfer, APM atau realisasi pembayaran sudah tercatat. Pembatalan persetujuan ditahan.';
  else if(s.terms.some(v=>v.spkId===t.spkId&&v.number>t.number))result.reason='Sudah ada pengajuan berikutnya pada SPK ini.';
  else if(s.bapps.some(b=>b.termId===t.id&&(b.releaseHistory.length||Object.values(b.released).some(q=>q.m>0||q.s>0))))result.reason='Volume BA PP sudah dilepas; pembatalan persetujuan ditahan.';
  else if(mode==='workflow'&&!usesSettlementPayment(t)&&(t.kasbon||t.paymentRequest||t.financeApproval||t.financePayment?.decision))result.reason='Pengajuan lama sudah masuk proses kasbon/Finance. Persetujuan pekerjaan tetap mengikuti riwayat tersimpan.';
 }
 return result;
}
export function resetProjectApproval(s:State,id:string,expected:ResetExpected,note:string){applyReset(s,id,expected,note,true);}
export function resetPaymentApproval(s:State,id:string,expected:ResetExpected,note:string){applyReset(s,id,expected,note,false);}
function applyReset(s:State,id:string,expected:ResetExpected,note:string,project:boolean){
 const doc=project?getProject(s,id):getTerm(s,id),plan=approvalResetPlan(s,doc,project);
 if(!plan.own)throw new Error('Hanya pemilik persetujuan aktif yang dapat membatalkannya.');
 if(plan.reason)throw new Error(plan.reason);
 if(Object.keys(expected).length!==Object.keys(plan.expected).length||Object.entries(plan.expected).some(([key,value])=>expected[key as keyof ResetExpected]!==value))throw new Error('Persetujuan atau versi pengajuan berubah. Muat ulang sebelum membatalkan.');
 if(!note.trim()||note.length>1000)throw new Error('Alasan pembatalan persetujuan wajib diisi, maksimal 1000 karakter.');
 const t=doc as Term,p=doc as Project;
 const marker:ApprovalResetRecord={version:1,id:crypto.randomUUID(),mode:plan.mode,historyOffset:plan.expected.historyLength,sourceCycleNo:approvalCycles(doc,project).length,actorUserId:s.actorUserId!,role:s.role,actorName:s.users.find(u=>u.id===s.actorUserId)!.name,at:now(),reason:note.trim(),expected,affectedApprovals:structuredClone(plan.affected),carriedApprovals:structuredClone(plan.carried),previousStatus:doc.status};
 if(plan.mode==='workflow'&&project&&p.status==='Sah'&&usesContractBundle(p)){
  const submission=p.contractBundle!.submissions.at(-1)!,{deals,spks}=bundleDocuments(s,p);
  for(const [kind,list]of Object.entries({deals,spks})){
   if(list.length!==Object.keys(submission.documents[kind]??{}).length||list.some(v=>v.status!=='Sah'||v.approvedWithProject?.submissionId!==submission.id))throw new Error('Status dokumen paket telah berubah. Pembatalan ditahan.');
  }
 }
 recordCommand(project?'resetProjectApproval':'resetPaymentApproval',[id,expected,note]);
 if(project)p.approvalHistory??=structuredClone(p.approvals??[]);else t.history??=structuredClone(t.approvals??[]);
 if(plan.mode==='workflow'){
  if(project&&p.status==='Sah'&&usesContractBundle(p)){const {deals,spks}=bundleDocuments(s,p);for(const child of [...deals,...spks]){child.status='Draft';delete child.approvedWithProject;}}
  doc.approvals=structuredClone(plan.carried);doc.status='Diajukan';
  if(!project&&usesSettlementPayment(t)){marker.previousFinancePayment=structuredClone(t.financePayment);t.financePayment={status:'Menunggu Approval'};delete t.approverUserIds!.Finance;}
 }else if(plan.mode==='legacy-manager'){marker.previousReview=structuredClone(t.legacySettlementReview);marker.previousFinancePayment=structuredClone(t.financePayment);delete t.legacySettlementReview!.decision;t.financePayment={status:'Kasbon Terbit'};}
 else if(plan.mode==='legacy-finance'){marker.previousFinancePayment=structuredClone(t.financePayment);t.financePayment={status:'Kasbon Terbit'};}
 else if(plan.mode==='legacy-partial'){marker.previousPaymentRequest=structuredClone(t.paymentRequest);(t.paymentRequestHistory??=[]).push(structuredClone(t.paymentRequest!));delete t.paymentRequest!.decision;t.paymentRequest!.status='Diajukan';}
 else if(plan.mode==='legacy-scan'){marker.previousScan=structuredClone(t.scan);delete t.financeApproval;for(const key of ['verifiedAt','verifiedBy','verifiedRole','note'] as const)delete t.scan![key as keyof typeof t.scan];t.scan!.status='Menunggu Verifikasi';}
 (doc.approvalResets??=[]).push(marker);if(!project)t.approvalResetVersion=doc.approvalResets.length;
 log(s,'Persetujuan '+(project?'paket PP':'pembayaran')+' dibatalkan',s.role+' · '+note.trim(),(project?'/project-plan/':'/termin/')+id);
}

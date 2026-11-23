import type {State,Project,Approval} from './domain';
import {getProject,now,log} from './domain';
import {recordCommand} from './commands';
export interface CancelExpected {status:Project['status'];historyCount:number;approvals:Approval[];submissionId:string|null}
export interface ProjectCancellation {version:1;previousStatus:Project['status'];actorUserId:string;actorName:string;role:string;at:string;reason:string;sourceCycleNo?:number;requestId?:string;payloadHash?:string}
export const CANCEL_MESSAGE='Paket PP sudah Cancel. Perubahan, pengajuan ulang dan proses turunannya tidak dapat dilanjutkan.';
/** Build the loaded relationship graph once for all visible list actions. */
export function projectCancellationPlans(s:State){
 const actor=s.users.find(u=>u.id===s.actorUserId),deals=new Map(s.deals.map(d=>[d.id,d.projectId])),spks=new Map(s.spks.map(v=>[v.id,deals.get(v.dealId)])),work=new Set(s.terms.map(t=>spks.get(t.spkId))),parents=new Set(s.projects.flatMap(p=>[p.parentId,...(p.rootId!==p.id?[p.rootId]:[])].filter(Boolean)));
 return new Map(s.projects.map(p=>{
  const unit=p.unit==='Operation'?'Operation':'Konstruksi',role=unit==='Operation'?'Officer 1 Operation':'Admin Area',own=!!actor&&actor.active&&actor.role===s.role&&[role,'Superadmin'].includes(s.role)&&!!actor.branches?.includes(p.branch)&&(!actor.workUnits||actor.workUnits.includes(unit));
  const reason=p.status==='Cancel'?'Paket PP sudah Cancel.':p.headApprovalReopen&&p.status!=='Sah'?'Selesaikan koreksi khusus Head sebelum membatalkan paket.':work.has(p.id)?'Paket sudah memiliki BA RP/pengajuan pembayaran dan tidak dapat di-Cancel.':parents.has(p.id)?'Paket sudah memiliki PP lanjutan dan tidak dapat di-Cancel.':'';
  const expected:CancelExpected={status:p.status,historyCount:p.approvalHistory?.length??0,approvals:p.approvals??[],submissionId:p.contractBundle?.submissions.at(-1)?.id??null};
  return [p.id,{own,reason,expected}] as const;
 }));
}
export function cancelProject(s:State,id:string,expected:CancelExpected,reason:string){
 recordCommand('cancelProject',[id,expected,reason]);const p=getProject(s,id),plan=projectCancellationPlans(s).get(id)!;
 if(!plan.own)throw Error('Akun tidak berwenang Cancel paket unit ini.');if(plan.reason)throw Error(plan.reason);
 if(JSON.stringify(expected)!==JSON.stringify(plan.expected))throw Error('Status atau approval paket berubah. Muat ulang sebelum Cancel.');
 if(!reason.trim()||reason.length>1000)throw Error('Isi alasan Cancel maksimal 1000 karakter.');
 p.cancellation={version:1,previousStatus:p.status,actorUserId:s.actorUserId!,actorName:s.users.find(u=>u.id===s.actorUserId)!.name,role:s.role,at:now(),reason:reason.trim()};p.status='Cancel';log(s,'Paket PP Cancel',p.no+' · '+s.role+' · '+reason.trim(),'/project-plan/'+id);
}
/** Read-only feedback before an existing command is recorded. Laravel repeats the guard. */
export function assertCancellationCommand(s:State,command:string,args:unknown[]){
 if(command==='cancelProject'||!s.projects.some(p=>p.status==='Cancel'))return;
 const input=args[0] as {id?:string;projectId?:string;dealId?:string}|undefined,deal=(id:unknown)=>s.deals.find(d=>d.id===id)?.projectId,spk=(id:unknown)=>deal(s.spks.find(v=>v.id===id)?.dealId);
 let id:unknown;
 if(['saveProject','saveProjectBundle'].includes(command))id=input?.id;
 else if(['decideProject','submitProjectBundle','resetProjectApproval','assignProjectApprovers','assignPaymentApprovers','reviewBoq','finalizeBoq','importDemo'].includes(command))id=args[0];
 else if(command==='saveDeal')id=deal(input?.id)??input?.projectId;
 else if(['saveSpk','saveSpkFinance'].includes(command))id=spk(input?.id)??deal(input?.dealId);
 else if(['createTerm','createSettlementTerm','_recordPayeeFile','_setPayeeVersion'].includes(command))id=spk(args[0]);
 if(s.projects.find(p=>p.id===id)?.status==='Cancel')throw Error(CANCEL_MESSAGE);
}

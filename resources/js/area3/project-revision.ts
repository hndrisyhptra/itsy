import type {Project,State} from './domain';
import {canReviseSubmission,projectApprovalScheme} from './workflow';
import {approvalCycles} from './approval-reset';

export interface OperationApprovalRevision {version:1;firstCycleNo:number;before:Pick<Project,'workflowVersion'|'approvalScheme'|'approverUserIds'|'pmUserId'|'managerUserId'|'approvalOrderChange'>;actorUserId:string;at:string}

/** Local drafts contain editable inputs; saved unit, workflow state and history remain authoritative. */
export function projectEditorValue(raw:Project,existing?:Project):Project {
 if(existing&&!canReviseSubmission(existing))return existing;
 const p={...raw,...(existing?{
  id:existing.id,no:existing.no,rootId:existing.rootId,parentId:existing.parentId,sourceBapp:existing.sourceBapp,allocations:existing.allocations,
  unit:existing.unit==='Operation'?'Operation':'Konstruksi',status:existing.status,workflowVersion:existing.workflowVersion,
  approvals:existing.approvals,approvalHistory:existing.approvalHistory,approvalResets:existing.approvalResets,
  contractBundle:existing.contractBundle,headApprovalReopen:existing.headApprovalReopen,operationApprovalRevision:existing.operationApprovalRevision,
  preparedBy:existing.preparedBy,signatureUsers:existing.signatureUsers,
 }:{} )};
 return {...p,approvalScheme:projectApprovalScheme(existing??p)};
}

export function operationRevisionMarker(old:Project|undefined,scheme:Project['approvalScheme'],actorUserId:string,s:State):OperationApprovalRevision|undefined {
 if(old?.operationApprovalRevision)return structuredClone(old.operationApprovalRevision);
 if(!old||scheme!=='operation-v1'||old.approvalScheme==='operation-v1'||!old.approvalHistory?.length)return undefined;
 const deals=new Set(s.deals.filter(d=>d.projectId===old.id).map(d=>d.id)),orders=new Set(s.spks.filter(v=>deals.has(v.dealId)).map(v=>v.id));
 if(old.headApprovalReopen||s.terms.some(t=>orders.has(t.spkId))||s.projects.some(p=>p.parentId===old.id))throw new Error('PP Operation historis sudah memiliki proses lanjutan atau koreksi khusus. Perubahan alur approval melalui revisi ditahan.');
 const cycles=approvalCycles(old,true);
 if(old.approvals?.length||cycles.at(-1)?.length)throw new Error('Alur Operation hanya dapat dimulai setelah pengembalian/revisi menutup siklus approval sebelumnya.');
 const before:OperationApprovalRevision['before']={};for(const key of ['workflowVersion','approvalScheme','approverUserIds','pmUserId','managerUserId','approvalOrderChange'] as const)if(Object.hasOwn(old,key))Object.assign(before,{[key]:structuredClone(old[key])});
 return {version:1,firstCycleNo:cycles.length,before,actorUserId,at:new Date().toISOString()};
}

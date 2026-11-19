import { spkIssuanceError } from './document-issuance';
import { type State, type Project, type Deal, getProject, getDeal, requireAdmin, dealErrors, now } from './domain';
import { saveProjectV2, canReviseSubmission } from './workflow';
import { recordCommand } from './commands';
import { spkCalendarDate, spkPeriodError } from './spk-issuance';

export type ContractBundle={version:1;submissions:{id:string;at:string;documents:Record<string,Record<string,string>>}[]};
export const usesContractBundle=(p:Pick<Project,'contractBundle'>)=>p.contractBundle?.version===1;
export function bundleDocuments(s:State,p:Project){const deals=s.deals.filter(d=>d.projectId===p.id),ids=new Set(deals.map(d=>d.id));return {deals,spks:s.spks.filter(v=>ids.has(v.dealId))};}
export function bundleEditable(p:Project){return usesContractBundle(p)&&canReviseSubmission(p);}
export function assertBundleEditable(p:Project,action:string){if(usesContractBundle(p)&&(!canReviseSubmission(p)||action!=='save'))throw new Error('BA KH dan SPK paket hanya dapat diedit sebagai draf sebelum pengajuan bersama PP.');}
export function saveProjectBundle(s:State,input:Project,submit=false){
  recordCommand('saveProjectBundle',[input,submit]);requireAdmin(s);
  const old=s.projects.find(p=>p.id===input.id);
  if(old&&(old.workflowVersion??1)<3)throw new Error('PP historis tetap memakai alur tersimpan.');
  if(old&&!usesContractBundle(old)&&((old.approvalHistory?.length??0)>0||(old.approvals?.length??0)>0||old.status!=='Draft'))throw new Error('PP dengan riwayat approval tetap memakai alur tersimpan.');
  if(submit)throw new Error('Lengkapi BA KH dan SPK lalu ajukan paket dari SPK.');
  const id=saveProjectV2(s,input,false),p=getProject(s,id);p.contractBundle=structuredClone(old?.contractBundle??{version:1,submissions:[]});return id;
}
export function submitProjectBundle(s:State,id:string){
  recordCommand('submitProjectBundle',[id]);requireAdmin(s);const p=getProject(s,id);
  if(!bundleEditable(p))throw new Error('Paket PP belum dapat diajukan pada status ini.');
  const {deals,spks}=bundleDocuments(s,p);
  if(!deals.length||deals.length!==spks.length)throw new Error('Lengkapi BA KH dan SPK sebelum mengajukan paket PP.');
  saveProjectV2(s,p,true,true);const saved=getProject(s,id);
  for(const d of deals){const errors=dealErrors(s,d,true);if(d.status!=='Draft'||errors.length)throw new Error(errors.join('\n')||'BA KH paket harus berupa draf.');}
  for(const v of spks){if(v.status!=='Draft'||!v.payeeVersion||!v.address.trim()||!v.supervisor.trim()||!v.phone.trim()||!v.signer.trim()||!v.start||!v.end||!spkCalendarDate(v.date))throw new Error('Lengkapi draf SPK dan simpan data Mandor sebelum pengajuan paket.');const d=getDeal(s,v.dealId);const periodError=spkPeriodError(v,d);if(periodError)throw new Error(periodError);const issueError=spkIssuanceError(p,d,v,true);if(issueError)throw new Error(issueError);}
  saved.contractBundle!.submissions.push({id:crypto.randomUUID(),at:now(),documents:{}});
}
export function approveProjectBundle(s:State,p:Project){if(!usesContractBundle(p))return;const {deals,spks}=bundleDocuments(s,p),submission=p.contractBundle!.submissions.at(-1);if(!submission)throw new Error('Versi paket belum diajukan.');for(const d of deals){const errors=dealErrors(s,d,true);if(errors.length)throw new Error(errors.join('\n'));d.status='Sah';d.approvedWithProject={ppId:p.id,submissionId:submission.id};}for(const v of spks){v.status='Sah';v.approvedWithProject={ppId:p.id,submissionId:submission.id};}}

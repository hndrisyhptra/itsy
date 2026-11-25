import { type State, type Project, type Term, type Role, approvalRoles, selectedApprover, termTotalForDeal } from './domain';
import { APPROVERS_V3 } from './workflow';
import { OPERATION_APPROVERS } from './work-unit';
import { settlementRecorded } from './payment-settlement';
import { paymentDue } from './kasbon-settlement';
import { legacyReviewApproved } from './legacy-settlement-review';
import { paymentLabel } from './payment-labels';

export type ApprovalBucket='cancelled'|'draft'|'revision'|'approved'|'settlement'|'paid'|'other'|`role:${Role}`;
export type ApprovalKind='project'|'payment';
export interface ApprovalColumn {key:ApprovalBucket;label:string}
export interface ApprovalRow {id:string;projectId:string;no:string;code:string;name:string;branchId:string;branch:string;regionId:string;bucket:ApprovalBucket;position:string;assignee:string;since:string;payment?:string;route:string;timeline:string}
export interface ApprovalGroup {id:string;name:string;counts:Partial<Record<ApprovalBucket,number>>;rows:ApprovalRow[]}
export interface ApprovalRegion extends ApprovalGroup {branches:ApprovalGroup[]}
export interface ApprovalMatrix {kind:ApprovalKind;columns:ApprovalColumn[];regions:ApprovalRegion[];total:ApprovalGroup}
export interface ApprovalSelection {kind:ApprovalKind;scope:'all'|'region'|'branch';id:string;label:string;bucket?:ApprovalBucket}
const roleKey=(role:Role):ApprovalBucket=>`role:${role}`;
const normalize=(value:string)=>value.trim().toUpperCase();
const group=(id:string,name:string):ApprovalGroup=>({id,name,counts:{},rows:[]});
const date=(value?:string)=>value&&Number.isFinite(Date.parse(value))?value:'';
const latest=(values:string[])=>values.reduce((last,value)=>value&&(!last||Date.parse(value)>Date.parse(last))?value:last,'');
const isRevision=(status:string)=>['Dikembalikan','Ditolak'].includes(status);
function decisions(document:Project|Term,project:boolean){
  const term=document as Term;
  return (document.approvals??[]).filter(a=>{
    if(a.decision!=='Setujui')return false;
    const selected=selectedApprover(document,a.role);
    const financeActor=!project&&a.role==='Finance'&&term.paymentFlow==='kasbon-settlement-v2'&&!selected;
    const actorMatches=(document.workflowVersion??1)<2||!!a.userId&&(a.userId===selected||financeActor);
    const versionMatches=project||term.paymentFlow!=='kasbon-settlement-v2'||!!term.kasbon?.id&&a.kasbonId===term.kasbon.id;
    return actorMatches&&versionMatches;
  });
}
/** Read-only current-cycle resolver; historical chains retain their own role columns. */
function current(document:Project|Term,project:boolean){
  const valid=decisions(document,project),roles=approvalRoles(document,project),approved=new Set(valid.map(a=>a.role));
  return {role:roles.find(r=>!approved.has(r)),since:latest(valid.map(a=>date(a.at)))};
}
export function dashboardApprovalData(s:State,branch='',unit='Konstruksi'):{project:ApprovalMatrix;payment:ApprovalMatrix}{
  const actor=s.actorUserId?s.users.find(u=>u.id===s.actorUserId):undefined;
  // Extra account intersection; the source graph/master Branches are already SQL-authorized.
  const allowed=actor?.branches?new Set(actor.branches.map(normalize)):undefined;
  const unitAllowed=!actor?.workUnits||actor.workUnits.includes(unit);
  const users=new Map(s.users.map(u=>[u.id,u]));
  const branchMeta=new Map<string,{id:string;name:string;regionId:string;regionName:string}>();
  const visible=(name:string)=>unitAllowed&&(!allowed||allowed.has(normalize(name)))&&(!branch||normalize(name)===normalize(branch));
  for(const b of s.branches??[])if(visible(b.name))branchMeta.set(normalize(b.name),{id:b.id,name:b.name,regionId:b.regionId||'unassigned',regionName:b.regionName?.trim()||'Region belum ditetapkan'});
  const plans=new Map(s.projects.filter(p=>p.unit===unit&&visible(p.branch)).map(p=>[p.id,p]));
  for(const p of plans.values())if(!branchMeta.has(normalize(p.branch)))branchMeta.set(normalize(p.branch),{id:`legacy:${normalize(p.branch)}`,name:p.branch,regionId:'unassigned',regionName:'Region belum ditetapkan'});
  const planRoles=unit==='Operation'?OPERATION_APPROVERS:APPROVERS_V3;
  const create=(kind:ApprovalKind):ApprovalMatrix=>({kind,columns:[{key:'draft',label:'Draf'},...planRoles.map(r=>({key:roleKey(r),label:r})),...(kind==='payment'?(['Mgr Finance & Settlement','Finance'] as Role[]).map(r=>({key:roleKey(r),label:r})):[]),{key:'revision',label:'Revisi'},kind==='project'?{key:'approved',label:'Disetujui'}:{key:'settlement',label:'Menunggu Pelunasan'},...(kind==='payment'?[{key:'paid' as const,label:'Sudah Dibayar'}]:[])],regions:[],total:group('all','Semua Branch')});
  const project=create('project'),payment=create('payment');
  const branches=new Map<ApprovalMatrix,Map<string,ApprovalGroup>>(),regionIndexes=new Map<ApprovalMatrix,Map<string,ApprovalRegion>>();
  for(const matrix of [project,payment]){
    const regions=new Map<string,ApprovalRegion>(),byBranch=new Map<string,ApprovalGroup>();
    for(const b of branchMeta.values()){
      let r=regions.get(b.regionId);if(!r){r={...group(b.regionId,b.regionName),branches:[]};regions.set(r.id,r);}
      const entry=group(b.id,b.name);r.branches.push(entry);byBranch.set(b.id,entry);
    }
    matrix.regions=[...regions.values()].sort((a,b)=>a.id==='unassigned'?1:b.id==='unassigned'?-1:a.name.localeCompare(b.name,'id'));
    for(const r of matrix.regions)r.branches.sort((a,b)=>a.name.localeCompare(b.name,'id'));
    branches.set(matrix,byBranch);regionIndexes.set(matrix,regions);
  }
  const assignee=(doc:Project|Term,role:Role)=>{const id=selectedApprover(doc,role);return id?(users.get(id)?.name||'Pengguna terpilih tidak tersedia'):role==='Finance'?'Tim Finance sesuai Branch':'Belum ditetapkan';};
  const add=(matrix:ApprovalMatrix,p:Project,doc:Project|Term,bucket:ApprovalBucket,position:string,name:string,since:string)=>{
    const b=branchMeta.get(normalize(p.branch))!,r=regionIndexes.get(matrix)!.get(b.regionId)!;
    const row:ApprovalRow={id:doc.id,projectId:p.id,no:p.no,code:p.projectCode,name:p.name,branchId:b.id,branch:p.branch,regionId:r.id,bucket,position,assignee:name,since,payment:matrix.kind==='payment'?paymentLabel(doc as Term):undefined,route:matrix.kind==='project'?`/project-plan/${p.id}`:`/termin/${doc.id}`,timeline:`/project-plan/${p.id}/timeline`};
    for(const g of [matrix.total,r,branches.get(matrix)!.get(b.id)!]){g.rows.push(row);g.counts[bucket]=(g.counts[bucket]??0)+1;}
    if(!matrix.columns.some(c=>c.key===bucket))matrix.columns.splice(matrix.columns.length-(matrix.kind==='project'?2:3),0,{key:bucket,label:bucket==='cancelled'?'Cancel':bucket.startsWith('role:')?bucket.slice(5)+' · Alur lama':'Periksa Status'});
  };
  for(const p of plans.values()){
    const waiting=current(p,true);
    if(p.status==='Cancel')add(project,p,p,'cancelled','Cancel','',p.cancellation?.at??'');
    else if(p.status==='Sah')add(project,p,p,'approved','Disetujui','',waiting.since);
    else if(isRevision(p.status))add(project,p,p,'revision','Revisi','Admin',latest([...(p.approvals??[]),...(p.approvalHistory??[])].filter(a=>a.decision!=='Setujui').map(a=>date(a.at))));
    else if(p.status==='Draft')add(project,p,p,'draft','Draf','Admin',date(p.created));
    else if(waiting.role)add(project,p,p,roleKey(waiting.role),'Menunggu '+waiting.role,assignee(p,waiting.role),waiting.since);
    else add(project,p,p,'other','Periksa status pengajuan','',waiting.since);
  }
  const deals=new Map(s.deals.filter(d=>plans.has(d.projectId)).map(d=>[d.id,d]));
  const spks=new Map(s.spks.filter(v=>deals.has(v.dealId)).map(v=>[v.id,v]));
  for(const t of s.terms){
    const v=spks.get(t.spkId);if(!v)continue;const d=deals.get(v.dealId)!,p=plans.get(d.projectId)!;
    const waiting=current(t,false),since=waiting.since||date(t.submitted);
    if(t.status==='Disetujui'&&settlementRecorded(t)&&t.paid>0&&t.paid>=paymentDue(t,termTotalForDeal(d,t))){add(payment,p,t,'paid','Sudah Dibayar','',date(t.financePayment?.settledAt)||since);continue;}
    if(isRevision(t.status)||t.financePayment?.status==='Dikembalikan'||t.paymentRequest?.status==='Dikembalikan'||t.scan?.status==='Perlu Koreksi'){add(payment,p,t,'revision','Revisi','Admin',latest([...t.approvals,...t.history,...(t.financeHistory??[]),...(t.financePaymentHistory??[]),...(t.financePayment?.decision?[t.financePayment.decision]:[]),...(t.paymentRequest?.decision?[t.paymentRequest.decision]:[])].filter(a=>a.decision!=='Setujui').map(a=>date(a.at))));continue;}
    if(t.status==='Draft'){add(payment,p,t,'draft','Draf','Admin',since);continue;}
    if(t.status==='Diajukan'){if(waiting.role)add(payment,p,t,roleKey(waiting.role),'Menunggu '+waiting.role,assignee(t,waiting.role),since);else add(payment,p,t,'other','Periksa status pengajuan','',since);continue;}
    // Submitted legacy kasbon/review/scan phases remain distinct from the new seven-stage chain.
    if(t.paymentFlow==='kasbon-v1'){
      if(!t.kasbon||t.financePayment?.status==='Menunggu Approval'){add(payment,p,t,'draft','Draf · menunggu kasbon Admin','Admin',since);continue;}
      if(t.legacySettlementReview&&t.legacySettlementReview.kasbonId!==t.kasbon.id){add(payment,p,t,'other','Periksa versi kasbon','',since);continue;}
      if(!legacyReviewApproved(t)){add(payment,p,t,roleKey('Mgr Finance & Settlement'),'Menunggu Mgr Finance & Settlement',users.get(t.legacySettlementReview!.userId)?.name||t.legacySettlementReview!.name,since);continue;}
      const decision=t.financePayment?.decision;
      if(decision?.decision!=='Setujui'||decision.role!=='Finance'||!decision.userId||decision.kasbonId!==t.kasbon.id){add(payment,p,t,roleKey('Finance'),'Menunggu Finance','Tim Finance sesuai Branch',since);continue;}
    }else if(t.paymentFlow==='partial-payment-v1'){
      if(!t.paymentRequest){add(payment,p,t,'draft','Draf · menunggu pengajuan Admin','Admin',since);continue;}
      if(t.paymentRequest.status==='Diajukan'){add(payment,p,t,roleKey('Finance'),'Menunggu Finance','Tim Finance sesuai Branch',since);continue;}
    }else if(t.paymentFlow!=='kasbon-settlement-v2'&&(t.workflowVersion??1)>=2&&t.scan?.status!=='Valid'){
      const pending=t.scan?.status==='Menunggu Verifikasi';add(payment,p,t,pending?roleKey('Finance'):'draft',pending?'Menunggu verifikasi Finance':'Draf · menunggu unggahan Finance','Tim Finance sesuai Branch',since);continue;
    }
    if(t.paymentFlow==='kasbon-settlement-v2'&&waiting.role){add(payment,p,t,'other','Periksa versi/status approval','',since);continue;}
    add(payment,p,t,'settlement','Menunggu Pelunasan','Finance',since);
  }
  return {project,payment};
}
export function approvalSelectionRows(matrix:ApprovalMatrix,selection:ApprovalSelection){
  const group=selection.scope==='all'?matrix.total:selection.scope==='region'?matrix.regions.find(r=>r.id===selection.id):matrix.regions.flatMap(r=>r.branches).find(b=>b.id===selection.id);
  return (group?.rows??[]).filter(r=>!selection.bucket||r.bucket===selection.bucket);
}
export function approvalRowsPage(rows:ApprovalRow[],query='',page=1){
  const q=query.trim().toLocaleLowerCase(),filtered=q?rows.filter(r=>`${r.code} ${r.no} ${r.name} ${r.branch} ${r.position} ${r.assignee} ${r.payment??''}`.toLocaleLowerCase().includes(q)):rows;
  const pages=Math.max(1,Math.ceil(filtered.length/20)),current=Math.max(1,Math.min(Number.isSafeInteger(page)?page:1,pages));
  return {rows:filtered.slice((current-1)*20,current*20),count:filtered.length,pages,current};
}

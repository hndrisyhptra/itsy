import { settlementRecorded } from './payment-settlement';
import { usesFinancePayment, financePaymentLabel } from './finance-payment';
import { usesPartialPayment, documentStatus, partialPaymentLabel } from './partial-payment';
import { type State, type Project, type Term, nextApprovalRole } from './domain';
import {workUnitAdmin} from './work-unit';
import {projectApprovalScheme,approvalRoles} from './workflow';

/** Read-only projection of the Branch-scoped state supplied by Laravel. */
export function projectTimeline(s:State,id:string) {
  const anchor=s.projects.find(p=>p.id===id);
  if(!anchor) return undefined;
  const code=anchor.projectCode.trim().toUpperCase();
  const projects=s.projects.filter(p=>(p.unit==='Operation')===(anchor.unit==='Operation')&&(code?p.projectCode.trim().toUpperCase()===code:p.id===id));
  const projectIds=new Set(projects.map(p=>p.id));
  const deals=s.deals.filter(d=>projectIds.has(d.projectId)),dealProjects=new Map(deals.map(d=>[d.id,d.projectId]));
  const spks=s.spks.filter(v=>dealProjects.has(v.dealId)),spkProjects=new Map(spks.map(v=>[v.id,dealProjects.get(v.dealId)!]));
  const terms=s.terms.filter(t=>spkProjects.has(t.spkId)),termProjects=new Map(terms.map(t=>[t.id,spkProjects.get(t.spkId)!]));
  const bapps=s.bapps.filter(b=>termProjects.has(b.termId)),bappProjects=new Map(bapps.map(b=>[b.id,termProjects.get(b.termId)!]));
  const byProject=new Map(projects.map(p=>[p.id,{deals:[] as typeof deals,spks:[] as typeof spks,terms:[] as typeof terms,bapps:[] as typeof bapps}]));
  for(const d of deals)byProject.get(d.projectId)!.deals.push(d);
  for(const v of spks)byProject.get(spkProjects.get(v.id)!)!.spks.push(v);
  for(const t of terms)byProject.get(termProjects.get(t.id)!)!.terms.push(t);
  for(const b of bapps)byProject.get(bappProjects.get(b.id)!)!.bapps.push(b);
  const routeProject=(route:string) => {
    const parts=route.split(/[?#]/)[0].split('/').filter(Boolean);
    if((parts[0]==='project-plan'||parts[0]==='boq')&&projectIds.has(parts[1])) return parts[1];
    if(parts[0]==='ba-kh') return dealProjects.get(parts[1]);
    if(parts[0]==='spk') return parts[1]==='ba-kh'?dealProjects.get(parts[2]):spkProjects.get(parts[1]);
    if(parts[0]==='termin') return termProjects.get(parts[1]);
    if(parts[0]==='ba-rp') return termProjects.get(parts[1]);
    if(parts[0]==='ba-pp') return bappProjects.get(parts[1]);
    return undefined;
  };
  const events=s.events.flatMap(e=>{const projectId=routeProject(e.route);return projectId?[{...e,projectId}]:[];})
    .sort((a,b)=>(Date.parse(b.at)||0)-(Date.parse(a.at)||0));
  return {anchor,projects,deals,spks,terms,bapps,events,byProject};
}

export function projectPosition(p:Project):string {
  if(p.status==='Cancel') return 'Paket Cancel · proses approval dihentikan';
  if(p.status==='Sah') return (p.workflowVersion??1)<2?'PP Sah · alur historis':'PP Sah · seluruh approval selesai';
  if(p.status==='Diajukan') return 'Menunggu approval '+(nextApprovalRole(p,true)??'—');
  if(p.status==='Dikembalikan') return 'Dikembalikan · menunggu perbaikan '+workUnitAdmin(p);
  if(p.status==='Ditolak') return (p.workflowVersion??1)>=3?`Ditolak · menunggu perbaikan dan pengajuan ulang ${workUnitAdmin(p)} dari ${approvalRoles({...p,approvalScheme:projectApprovalScheme(p)},true)[0]}`:'Ditolak · proses PP berhenti';
  return 'Draft · belum diajukan';
}

export function paymentPosition(t:Term,project?:Pick<Project,'unit'>):string {
  if(t.status==='Diajukan') return 'Menunggu approval '+(nextApprovalRole(t)??'—');
  if(t.status==='Dikembalikan') return 'Dikembalikan · menunggu perbaikan '+workUnitAdmin({...t,unit:t.unit??project?.unit});
  if(t.status==='Ditolak') return (t.workflowVersion??1)>=3?`Ditolak · menunggu perbaikan dan pengajuan ulang ${workUnitAdmin({...t,unit:t.unit??project?.unit})} dari ${approvalRoles(t)[0]}`:'Ditolak · proses pembayaran berhenti';
  if(t.status==='Draft') return t.paymentFlow==='kasbon-settlement-v2'?'Draft · lengkapi aktual dan kasbon sebelum tujuh approval':'Draft · belum diajukan';
  if((t.workflowVersion??1)<2) return t.apmStatus==='Valid'?'APM valid · lihat realisasi pembayaran':'Menunggu APM';
  if(usesFinancePayment(t)&&settlementRecorded(t))return 'Tagihan lunas · '+(t.apm?'APM '+t.apm:'APM belum diisi')+' · Berkas '+documentStatus(t);
  if(t.apmStatus==='Valid') return 'APM valid · tagihan lunas'+(usesPartialPayment(t)||usesFinancePayment(t)?' · Berkas '+documentStatus(t):'');
  if(usesFinancePayment(t))return financePaymentLabel(t)+' · Berkas '+documentStatus(t);
  if(usesPartialPayment(t))return partialPaymentLabel(t)+' · Berkas '+documentStatus(t);
  if(!t.scan&&t.workflowVersion===3&&t.type==='Parsial')return 'Menunggu Admin Ajukan Payment ke Finance · Berkas NOK';
  if(!t.scan) return 'Menunggu unggah scan TTD fisik';
  if(t.scan.status==='Perlu Koreksi') return 'Scan perlu koreksi · menunggu unggah ulang';
  if(t.scan.status!=='Valid') return (t.workflowVersion??1)>=3?'Menunggu approval Finance':'Menunggu verifikasi Finance';
  if((t.workflowVersion??1)>=3 && (t.financeApproval?.decision!=='Setujui'||t.financeApproval.scanId!==t.scan.id)) return 'Menunggu approval Finance untuk scan terbaru';
  return 'Menunggu pencatatan APM oleh Finance';
}

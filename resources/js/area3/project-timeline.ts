import { type State, type Project, type Term, approvalRoles } from './domain';

/** Read-only projection of the Branch-scoped state supplied by Laravel. */
export function projectTimeline(s:State,id:string) {
  const anchor=s.projects.find(p=>p.id===id);
  if(!anchor) return undefined;
  const code=anchor.projectCode.trim().toUpperCase();
  const projects=s.projects.filter(p=>code?p.projectCode.trim().toUpperCase()===code:p.id===id);
  const projectIds=new Set(projects.map(p=>p.id));
  const deals=s.deals.filter(d=>projectIds.has(d.projectId)),dealProjects=new Map(deals.map(d=>[d.id,d.projectId]));
  const spks=s.spks.filter(v=>dealProjects.has(v.dealId)),spkProjects=new Map(spks.map(v=>[v.id,dealProjects.get(v.dealId)!]));
  const terms=s.terms.filter(t=>spkProjects.has(t.spkId)),termProjects=new Map(terms.map(t=>[t.id,spkProjects.get(t.spkId)!]));
  const bapps=s.bapps.filter(b=>termProjects.has(b.termId)),bappProjects=new Map(bapps.map(b=>[b.id,termProjects.get(b.termId)!]));
  const routeProject=(route:string) => {
    const parts=route.split(/[?#]/)[0].split('/').filter(Boolean);
    if((parts[0]==='project-plan'||parts[0]==='boq')&&projectIds.has(parts[1])) return parts[1];
    if(parts[0]==='ba-kh') return dealProjects.get(parts[1]);
    if(parts[0]==='spk') return parts[1]==='ba-kh'?dealProjects.get(parts[2]):spkProjects.get(parts[1]);
    if(parts[0]==='termin') return termProjects.get(parts[1]);
    if(parts[0]==='ba-pp') return bappProjects.get(parts[1]);
    return undefined;
  };
  const events=s.events.flatMap(e=>{const projectId=routeProject(e.route);return projectId?[{...e,projectId}]:[];})
    .sort((a,b)=>(Date.parse(b.at)||0)-(Date.parse(a.at)||0));
  return {anchor,projects,deals,spks,terms,bapps,events};
}

export function projectPosition(p:Project):string {
  if(p.status==='Sah') return (p.workflowVersion??1)<2?'PP Sah · alur historis':'PP Sah · seluruh approval selesai';
  if(p.status==='Diajukan') return 'Menunggu approval '+(approvalRoles(p,true)[p.approvals?.length??0]??'—');
  if(p.status==='Dikembalikan') return 'Dikembalikan · menunggu perbaikan Admin Konstruksi';
  if(p.status==='Ditolak') return (p.workflowVersion??1)>=3?'Ditolak · menunggu perbaikan dan pengajuan ulang Admin Konstruksi dari PM':'Ditolak · proses PP berhenti';
  return 'Draft · belum diajukan';
}

export function paymentPosition(t:Term):string {
  if(t.status==='Diajukan') return 'Menunggu approval '+(approvalRoles(t)[t.approvals.length]??'—');
  if(t.status==='Dikembalikan') return 'Dikembalikan · menunggu perbaikan Admin Konstruksi';
  if(t.status==='Ditolak') return (t.workflowVersion??1)>=3?'Ditolak · menunggu perbaikan dan pengajuan ulang Admin Konstruksi dari PM':'Ditolak · proses pembayaran berhenti';
  if(t.status==='Draft') return 'Draft · belum diajukan';
  if((t.workflowVersion??1)<2) return t.apmStatus==='Valid'?'APM valid · lihat realisasi pembayaran':'Menunggu APM';
  if(t.apmStatus==='Valid') return 'APM valid · tagihan lunas';
  if(!t.scan) return 'Menunggu unggah scan TTD fisik';
  if(t.scan.status==='Perlu Koreksi') return 'Scan perlu koreksi · menunggu unggah ulang';
  if(t.scan.status!=='Valid') return (t.workflowVersion??1)>=3?'Menunggu approval Finance':'Menunggu verifikasi Finance';
  if((t.workflowVersion??1)>=3 && (t.financeApproval?.decision!=='Setujui'||t.financeApproval.scanId!==t.scan.id)) return 'Menunggu approval Finance untuk scan terbaru';
  return 'Menunggu pencatatan APM oleh Finance';
}

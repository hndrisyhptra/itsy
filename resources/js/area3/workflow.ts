import { usesFinancePayment } from './finance-payment';
import { usesPartialPayment } from './partial-payment';
import { applyBudgetPlan } from './plan-budget';
import { AUTO_KHS_NOTE, AUTO_KHS_POLICY } from './boq-pricing';
import { applyPlanTemplate } from './plan-template';
import { recordCommand } from './commands';
import { type State, type Project, type Term, type Role, type Approval, getProject, getTerm, requireAdmin, requireFinance, validVolume, boqTotal, projectErrors, now, log } from './domain';

export const APPROVERS_V3: Role[] = ['PM Konstruksi', 'Admin Area', 'Mgr Project Support', 'Mgr Deployment', 'Head of PMO'];
export const APPROVERS_V3_PREVIOUS: Role[] = ['PM Konstruksi', 'Admin Area', 'Mgr Deployment', 'Mgr Project Support', 'Head of PMO'];
export const APPROVERS_V3_LEGACY:Role[]=['PM Konstruksi','Admin Area','Mgr Deployment','Mgr Bussiness Support','Head of PMO'];
export function usesBusinessSupport(document:AssignedDocument){return document.approvalScheme==='business-support'||(!document.approvalScheme&&Object.hasOwn(document.approverUserIds??{},'Mgr Bussiness Support')&&!Object.hasOwn(document.approverUserIds??{},'Mgr Project Support'));}
export function projectApprovalScheme(document?:Project):'project-support-first'|'project-support'|'business-support' {return document&&(!canReviseSubmission(document)||(document.approvalHistory?.length??0)>0||(document.approvals?.length??0)>0)?(usesBusinessSupport(document)?'business-support':document.approvalScheme??'project-support'):'project-support-first';}
type AssignedDocument = {approvalScheme?:'project-support-first'|'project-support'|'business-support';workflowVersion?:number; pmUserId?:string; managerUserId?:string; approverUserIds?:Partial<Record<Role,string>>};
export function canReviseSubmission(document: {status:string;workflowVersion?:number}) { return ['Draft','Dikembalikan'].includes(document.status) || ((document.workflowVersion ?? 1) >= 3 && document.status === 'Ditolak'); }
export function approvalRoles(document: AssignedDocument, project = false): Role[] { return (document.workflowVersion ?? 1) >= 3 ? (usesBusinessSupport(document)?APPROVERS_V3_LEGACY:APPROVERS_V3) : project || (document.workflowVersion ?? 1) >= 2 ? ['PM Konstruksi','Manager Konstruksi'] : ['PM Konstruksi','Manager Konstruksi','Finance']; }
export function nextApprovalRole(document:AssignedDocument & {approvals?:Approval[]},project=false):Role|undefined {const approved=new Set((document.approvals??[]).filter(a=>a.decision==='Setujui').map(a=>a.role));return approvalRoles(document,project).find(role=>!approved.has(role));}
export function selectedApprover(document: AssignedDocument, role:Role) { return (document.workflowVersion ?? 1) >= 3 ? document.approverUserIds?.[role] : role === 'PM Konstruksi' ? document.pmUserId : document.managerUserId; }
export function validateAssignments(s:State, document:AssignedDocument & {branch:string}, required=true) {
  const result:Partial<Record<Role,string>> = {};
  for (const role of approvalRoles(document,true)) { const id=selectedApprover(document,role); if (!required && !id) continue; const user = approver(s, id, role); if (user.branches && !user.branches.includes(document.branch)) throw new Error('Seluruh approver harus ditugaskan pada Branch PP.'); result[role] = user.id; }
  return result;
}

export const DEFAULT_PP_NUMBER_FORMAT = '{SEQ}/LG.000/TA-03{BRANCH}/{MM}-{YYYY}';

export function branchOptions(s: State) { return s.branches ?? [...new Set(s.projects.map(p => p.branch))].map((name, i) => ({ id: 'branch-' + (i + 1), code: 'BR' + String(i + 1).padStart(2, '0'), name, active: true })); }
export function normalizeWorkflow(input: State): State {
  const s = structuredClone(input); s.schema = Math.max(2, s.schema);
  s.settings ??= { ppNumberFormat: DEFAULT_PP_NUMBER_FORMAT, nextPpSequence: 1 };
  if (!s.settings.ppNumberFormatVersion && s.settings.ppNumberFormat === 'PP/{BRANCH}/{YYYY}/{SEQ}') s.settings.ppNumberFormat = DEFAULT_PP_NUMBER_FORMAT;
  s.settings.ppNumberFormatVersion = 2;
  s.branches ??= branchOptions(s);
  s.projects.forEach(p => p.workflowVersion ??= 1); s.terms.forEach(t => t.workflowVersion ??= 1);
  s.actorUserId ??= s.users.find(u => u.active && u.role === s.role)?.id ?? '';
  return s;
}
export function validateNumberFormat(format: string) {
  if ((format.match(/\{SEQ\}/g)?.length ?? 0) !== 1 || format.length > 100 || !/^[A-Za-z0-9/._ -]*$/.test(format.replace(/\{(SEQ|BRANCH|YYYY|MM|UNIT)\}/g, ''))) throw new Error('Format No PP wajib satu {SEQ}; token: {BRANCH}, {YYYY}, {MM}, {UNIT}.');
}
export function previewProjectNumber(s: State, p: Project) {
  const format = s.settings?.ppNumberFormat ?? DEFAULT_PP_NUMBER_FORMAT; validateNumberFormat(format);
  const date = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit' }).formatToParts(new Date());
  let sequence = s.settings?.nextPpSequence ?? 1, value = '';
  do {
    const tokens: Record<string, string> = { SEQ: String(sequence++).padStart(4, '0'), BRANCH: branchOptions(s).find(b => b.name === p.branch)?.code ?? 'BRANCH', YYYY: date.find(v => v.type === 'year')!.value, MM: date.find(v => v.type === 'month')!.value, UNIT: p.unit.toUpperCase() };
    value = format.replace(/\{(SEQ|BRANCH|YYYY|MM|UNIT)\}/g, (_, key) => tokens[key]);
  } while (s.projects.some(x => x.no.toLowerCase() === value.toLowerCase()));
  return { value, nextSequence: sequence };
}
export function rememberProjectNumber(s:State,p:Project,number:{value:string;nextSequence:number}) {
  const date=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit'}).formatToParts(new Date());
  s.settings!.issuedPpNumbers ??= {};
  s.settings!.issuedPpNumbers[number.value]={sequence:number.nextSequence-1,pattern:s.settings!.ppNumberFormat,branch:branchOptions(s).find(b=>b.name===p.branch)?.code??'',period:date.find(v=>v.type==='month')!.value+'-'+date.find(v=>v.type==='year')!.value,at:now()};
}
function approver(s: State, id: string | undefined, role: Role) { const u = s.users.find(u => u.id === id && u.active && u.role === role); if (!u) throw new Error('Pilih pengguna aktif dengan peran ' + role + '.'); return u; }
export function assertAssigned(s: State, document: AssignedDocument, role: Role) {
  const u = approver(s, selectedApprover(document, role), role);
  if (s.role !== role || s.actorUserId !== u.id) throw new Error('Hanya approver yang dipilih pada PP dapat menyetujui dokumen ini.');
}
export function projectApprovalRole(p: Project): Role | undefined { return p.status === 'Diajukan' ? nextApprovalRole(p,true) : undefined; }
export function canApproveProject(s: State, p: Project) { const role = projectApprovalRole(p); return !!role && role === s.role && s.actorUserId === selectedApprover(p,role) && s.users.some(u => u.id === s.actorUserId && u.active && u.role === role); }
export function canApproveTerm(s: State, t: Term) { const role = t.status === 'Diajukan' ? nextApprovalRole(t) : undefined; return !!role && role === s.role && ((t.workflowVersion ?? 1) < 2 || (s.actorUserId === selectedApprover(t,role) && s.users.some(u => u.id === s.actorUserId && u.active && u.role === role))); }
export function saveProjectV2(s: State, input: Project, submit: boolean) {
  requireAdmin(s); const p = structuredClone(input), old = s.projects.find(x => x.id === p.id);
  if (old && !canReviseSubmission(old)) throw new Error('PP terkunci pada status ini.');
  const version = (old?.workflowVersion ?? 1) >= 2 ? old!.workflowVersion! : s.schema >= 3 ? 3 : 2;
  if (version >= 3) Object.assign(p, applyPlanTemplate(p, true, submit));
  else { delete p.planTemplateVersion; delete p.teamText; delete p.durationDays; delete p.planBudget; delete p.planBudgetTotals; delete p.signatureRows; delete p.signatureUsers; delete p.closing; delete p.riskLevel; }
  p.approvalScheme=projectApprovalScheme(old);
  p.approvalOrderChange=old?.approvalOrderChange;
  p.headApprovalReopen=old?.headApprovalReopen;
  const chain = validateAssignments(s, { ...p, workflowVersion: version }, submit);
  if (!['Konstruksi', 'Operation'].includes(p.unit) || !branchOptions(s).some(b => b.name === p.branch && b.active)) throw new Error('Pilih Unit Kerja dan Branch aktif.');
  if (!old && (p.parentId || p.rootId !== p.id)) throw new Error('PP lanjutan harus dibuat dari pelepasan BA PP.');
  const number = previewProjectNumber(s, p); p.no = old?.no ?? number.value;
  if (old) { p.continuationNotes = old.continuationNotes; p.rootId = old.rootId; p.parentId = old.parentId; p.sourceBapp = old.sourceBapp; p.allocations = structuredClone(old.allocations); }
  if (p.parentId) { p.budget = old!.budget; p.boq = old!.boq; p.boqStatus = old!.boqStatus; }
  else {
    if ((!p.boq.length && (submit || version < 3)) || p.boq.length > 500 || new Set(p.boq.map(r => r.id)).size !== p.boq.length || new Set(p.boq.map(r => r.khsId)).size !== p.boq.length) throw new Error('Masukkan 1–500 designator unik pada BOQ sebelum pengajuan PP.');
    const catalog=new Map(s.khs.map(k=>[k.id,k]));
    p.boq = p.boq.map(r => { const k = catalog.get(r.khsId); if (!k?.active || !validVolume(r.qty) || r.qty <= 0) throw new Error('BOQ harus memakai designator aktif dan volume positif maksimal tiga desimal.'); const mismatch=!!p.boqUpload && (r.excelM!==k.m || r.excelS!==k.s); const reviewed=version>=3 || !mismatch || (r.reviewed && !!r.note.trim() && r.version===k.version && r.m===k.m && r.s===k.s); if(submit && !reviewed) throw new Error('Review selisih harga Excel dengan Master KHS dan isi alasan sebelum pengajuan.'); return { ...r, m: k.m, s: k.s, excelM: p.boqUpload?r.excelM:k.m, excelS: p.boqUpload?r.excelS:k.s, version: k.version, reviewed, note: version>=3?AUTO_KHS_NOTE:p.boqUpload?r.note:'Input manual dari Master KHS' }; });
    if(version>=3){p.budget=boqTotal(p);p.boqPricingPolicy=AUTO_KHS_POLICY;}else delete p.boqPricingPolicy;
    p.boqStatus = submit ? 'Final' : p.boq.length ? 'Review' : 'Belum diimpor';
  }
  if (version >= 3) {
    p.planBudget ??= old?.planBudget; p.riskLevel ??= old?.riskLevel; p.signatureRows ??= old?.signatureRows;
    const actor=s.users.find(u=>u.id===s.actorUserId);
    Object.assign(p,applyBudgetPlan(s,p,submit,true));
    p.preparedBy=old?.preparedBy??{userId:actor?.id??'',name:actor?.name??'',nik:actor?.nik??'',role:s.role,at:now()};
    p.previousPlanning=old?.previousPlanning??(old&&!old.planBudget?{costs:structuredClone(old.costs),risk:old.risk,communication:old.communication,quality:old.quality}:undefined);
    p.signatureUsers=Object.fromEntries(Object.entries(chain).map(([role,id])=>{const u=s.users.find(u=>u.id===id)!;return [role,{userId:u.id,name:u.name,nik:u.nik??''}];}));
  }
  const errors = projectErrors(s, p,version>=3&&!p.parentId&&!submit); if (errors.length) throw new Error(errors.join('\n'));
  if (submit && (!p.intro.trim() || (!p.planTemplateVersion && (!p.team.length || p.team.some(t => !t.name.trim() || !t.job.trim()))) || (!p.planBudget && !p.risk.trim()) || !p.communication.trim() || !p.quality.trim() || p.schedule.some(v => (!p.planTemplateVersion || v.days > 0) && (!v.start || !v.end)))) throw new Error('Lengkapi seluruh rencana PP sebelum diajukan.');
  p.workflowVersion = version; p.pmUserId = chain['PM Konstruksi']; if (version >= 3) p.approverUserIds = chain; else p.managerUserId = chain['Manager Konstruksi']; p.status = submit ? 'Diajukan' : 'Draft'; p.approvals = []; p.approvalHistory = old?.approvalHistory ?? [];
  if (!old) { s.settings ??= { ppNumberFormat: DEFAULT_PP_NUMBER_FORMAT, nextPpSequence: 1, ppNumberFormatVersion: 2 }; rememberProjectNumber(s,p,number); s.settings.nextPpSequence = number.nextSequence; s.projects.unshift(p); } else s.projects[s.projects.indexOf(old)] = p;
  log(s, submit ? 'PP diajukan ke PM terpilih' : 'Draft PP dan BOQ disimpan', p.no, '/project-plan/' + p.id); return p.id;
}
export function decideProject(s: State, id: string, decision: Approval['decision'], note: string) {
  recordCommand('decideProject', [id, decision, note]); const p = getProject(s, id), role = projectApprovalRole(p);
  if (!role) throw new Error('PP belum diajukan untuk approval.'); assertAssigned(s, p, role);
  if (decision !== 'Setujui' && !note.trim()) throw new Error('Alasan pengembalian/penolakan wajib.');
  const a: Approval = { role, userId: s.actorUserId, actorName: s.users.find(u => u.id === s.actorUserId)?.name, actorNik:s.users.find(u=>u.id===s.actorUserId)?.nik, decision, note: note.trim(), at: now() };
  (p.approvalHistory ??= []).push(a); p.approvals ??= [];
  if (decision === 'Setujui') { p.approvals.push(a); if (p.approvals.length === approvalRoles(p,true).length) p.status = 'Sah'; } else { p.status = decision === 'Kembalikan' ? 'Dikembalikan' : 'Ditolak'; p.approvals = []; }
  log(s, 'PP ' + decision, p.no, '/project-plan/' + id);
}
export function setNumberFormat(s: State, format: string) { recordCommand('setNumberFormat', [format]); requireAdmin(s); validateNumberFormat(format); s.settings ??= { ppNumberFormat: format, nextPpSequence: 1 }; s.settings.ppNumberFormat = format.trim(); log(s, 'Format No PP diperbarui', format, '/pengaturan'); }
export function selectActor(s: State, id: string) { recordCommand('selectActor', [id]); approver(s, id, s.role); s.actorUserId = id; }
export function assignProjectApprovers(s: State, id: string, pm: string, manager: string) {
  recordCommand('assignProjectApprovers', [id, pm, manager]); requireAdmin(s); const p = getProject(s, id);
  if ((p.workflowVersion ?? 1) >= 2 || s.terms.some(t => (t.workflowVersion ?? 1) >= 2 && s.spks.some(v => v.id === t.spkId && s.deals.some(d => d.id === v.dealId && d.projectId === id)))) throw new Error('Penugasan hanya untuk PP lama sebelum pembayaran alur baru dibuat.');
  p.pmUserId = approver(s, pm, 'PM Konstruksi').id; p.managerUserId = approver(s, manager, 'Manager Konstruksi').id;
  for (const id of [pm, manager]) { const user = s.users.find(u => u.id === id)!; if (user.branches && !user.branches.includes(p.branch)) throw new Error('PM dan Manager harus ditugaskan pada Branch PP.'); }
}
export function verifyTermScan(s: State, id: string, decision: 'Valid' | 'Perlu Koreksi', note: string) {
  recordCommand('verifyTermScan', [id, decision, note]); requireFinance(s); const t = getTerm(s, id);
  if ((t.workflowVersion ?? 1) < 2 || t.status !== 'Disetujui' || t.approvals.length !== approvalRoles(t).length || !t.scan || t.scan.status !== 'Menunggu Verifikasi' || (t.apmStatus === 'Valid'&&!usesPartialPayment(t)&&!usesFinancePayment(t))) throw new Error('Scan menunggu verifikasi dengan seluruh approval diperlukan.');
  if (decision === 'Perlu Koreksi' && !note.trim()) throw new Error('Catatan koreksi scan wajib diisi.');
  t.scan = { ...t.scan, status: decision, note, verifiedBy: s.actorUserId, verifiedAt: now() };
  if ((t.workflowVersion ?? 1) >= 3) { const user = approver(s, s.actorUserId, 'Finance'); const approval:Approval = {role:'Finance', userId:user.id, actorName:user.name, decision: decision === 'Valid' ? 'Setujui' : 'Kembalikan', note, at:now(), scanId:t.scan.id}; (t.financeHistory ??= []).push(approval); if (decision === 'Valid') t.financeApproval = approval; else delete t.financeApproval; }
}

export function assignPaymentApprovers(s:State, id:string, assignments:Partial<Record<Role,string>>) {
  recordCommand('assignPaymentApprovers',[id,assignments]); requireAdmin(s); const p = getProject(s,id);
  if (((p.workflowVersion ?? 1) >= 3&&!usesBusinessSupport(p)) || p.status !== 'Sah') throw new Error('Penugasan pembayaran berikutnya hanya untuk PP historis yang sudah Sah.');
  p.paymentApproverUserIds = validateAssignments(s,{...p,workflowVersion:3,approvalScheme:'project-support-first',approverUserIds:assignments});
  log(s,'Lima approver pembayaran berikutnya ditetapkan',p.no,'/project-plan/'+id);
}

export function adoptApprovalOrder(s:State,kind:'projects'|'terms',id:string,supportUserId:string) {
  recordCommand('adoptApprovalOrder',[kind,id,supportUserId]);requireAdmin(s);
  const document=kind==='projects'?getProject(s,id):getTerm(s,id),history=kind==='projects'?(document as Project).approvalHistory??[]:(document as Term).history;
  if(document.workflowVersion!==3||document.status!=='Diajukan'||!usesBusinessSupport(document))throw new Error('Penyesuaian ini hanya untuk pengajuan berjalan yang masih memakai Business Support.');
  if(history.length!==(document.approvals?.length??0)||history.some(a=>a.role==='Mgr Bussiness Support'))throw new Error('Pengajuan memiliki siklus/keputusan Business Support terdahulu; penyesuaian otomatis ditolak agar riwayat tetap utuh.');
  const project=kind==='projects'?document as Project:getProject(s,s.deals.find(d=>d.id===s.spks.find(v=>v.id===(document as Term).spkId)!.dealId)!.projectId);
  const assignments={...document.approverUserIds},previous=assignments['Mgr Bussiness Support'];delete assignments['Mgr Bussiness Support'];assignments['Mgr Project Support']=supportUserId;
  const selected=validateAssignments(s,{workflowVersion:3,approvalScheme:'project-support-first',approverUserIds:assignments,branch:project.branch});
  document.approverUserIds=selected;document.approvalScheme='project-support';document.approvalOrderChange={previousBusinessSupportUserId:previous,projectSupportUserId:supportUserId,actorUserId:s.actorUserId,at:now()};
  if(kind==='projects'){const p=document as Project,u=s.users.find(u=>u.id===supportUserId)!;p.signatureUsers={...p.signatureUsers,'Mgr Project Support':{userId:u.id,name:u.name,nik:u.nik??''}};}
  log(s,'Urutan approval diperbarui · Project Support sebelum Deployment',kind==='projects'?(document as Project).no:'Termin '+(document as Term).number,kind==='projects'?'/project-plan/'+id:'/termin/'+id);
}

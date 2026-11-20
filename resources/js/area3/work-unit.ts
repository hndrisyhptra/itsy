import type {Role,State,Project} from './domain';
export const OPERATION_APPROVERS:Role[]=['Officer 1 Operation','Manager Branch Operation','Officer 1 SS','Manager SS','General Manager Operation'];
export function isOperation(document:{approvalScheme?:string}){return document.approvalScheme==='operation-v1';}
export function headRole(document:{approvalScheme?:string}): 'Head of PMO'|'General Manager Operation'{return isOperation(document)?'General Manager Operation':'Head of PMO';}
export function workUnitAdmin(document:{unit?:string;approvalScheme?:string}){return document.unit==='Operation'||isOperation(document)?'Admin Operation':'Admin Konstruksi';}
export function unitOptions(s:Pick<State,'role'|'users'|'actorUserId'>):string[]{
  const units=s.users.find(u=>u.id===s.actorUserId)?.workUnits;
  if(units)return units;
  return ['Superadmin','Finance','Mgr Finance & Settlement'].includes(s.role)?['Konstruksi','Operation']:[s.role.includes('Operation')||['Officer 1 SS','Manager SS'].includes(s.role)?'Operation':'Konstruksi'];
}
export function eligibleApprover(user:State['users'][number],p:Pick<Project,'branch'|'unit'>,role:Role){
  return user.active&&user.role===role&&(!user.branches||user.branches.includes(p.branch))&&(!user.workUnits||user.workUnits.includes(p.unit))&&(!['Manager SS','General Manager Operation'].includes(role)||user.accessScope==='REGION');
}
/** Dashboard unit filter reuses the already authorized graph; no API or monetary recalculation. */
export function stateForUnit(s:State,unit:string):State{
  const projects=s.projects.filter(p=>(p.unit==='Operation'?'Operation':'Konstruksi')===unit),pids=new Set(projects.map(p=>p.id));
  const deals=s.deals.filter(d=>pids.has(d.projectId)),dids=new Set(deals.map(d=>d.id));
  const spks=s.spks.filter(v=>dids.has(v.dealId)),sids=new Set(spks.map(v=>v.id));
  const terms=s.terms.filter(t=>sids.has(t.spkId)),tids=new Set(terms.map(t=>t.id));
  return {...s,projects,deals,spks,terms,bapps:s.bapps.filter(b=>tids.has(b.termId))};
}

import {type State,type Term,round,termTotalForDeal} from './domain';
import {dashboardMatrix} from './dashboard-matrix';
import {dashboardApprovalData} from './dashboard-approval';
import {projectPlanHeaderTotal} from './plan-budget';
import {paymentDue} from './kasbon-settlement';
import {settlementRecorded} from './payment-settlement';
import {paymentLabel} from './payment-labels';

export type ExecutiveMetric='projects'|'plans'|'budget'|'paid'|'outstanding'|'approval'|'settlement'|'documents'|'requested';
export interface ExecutiveRow {id:string;code:string;no:string;name:string;branch:string;status:string;amount?:number;stage?:string;payment?:string;assignee?:string;since?:string;route:string;timeline:string;plans?:number}
export interface MonthlyPayment {key:string;termId:string;pid:string;amount:number;row:ExecutiveRow}
export const EXECUTIVE_LABELS:Record<ExecutiveMetric,string>={projects:'Total ID Project',plans:'Paket PP Berjalan',budget:'Total Anggaran PP',paid:'Total Sudah Dibayar',outstanding:'Outstanding Pembayaran',approval:'Menunggu Approval',settlement:'Menunggu Pelunasan',documents:'Berkas Belum Lengkap',requested:'Nilai Pengajuan Pembayaran'};
const amount=(n:number)=>Number.isFinite(n)?Math.max(0,round(n)):0;
const norm=(v:string)=>v.trim().toUpperCase();
const monthFormat=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit'});
const monthLabel=new Intl.DateTimeFormat('id-ID',{month:'short',timeZone:'Asia/Jakarta'});
/** WIB calendar month; never substitute submission/upload/today for an unknown payment date. */
export function paidMonth(value:string|undefined):string|null{
 if(!value)return null;
 const canonical=/^\d{4}-\d{2}-\d{2}$/.test(value)?value+'T00:00:00Z':/[zZ]|[+-]\d{2}:?\d{2}$/.test(value)?value:value.replace(' ','T')+'Z';
 const date=new Date(canonical);if(!Number.isFinite(date.getTime()))return null;
 const parts=monthFormat.formatToParts(date);
 return parts.find(p=>p.type==='year')!.value+'-'+parts.find(p=>p.type==='month')!.value;
}
function paymentSlices(t:Term,paid:number):{key:string|null;amount:number}[]{
 const history=(t.paymentHistory??[]).filter(h=>Number.isFinite(h.amount)&&h.amount>0);
 const total=round(history.reduce((sum,h)=>sum+h.amount,0));
 // Current single settlements have an authoritative settlement timestamp even when old history is incomplete.
 if(total!==paid){const fallback=paidMonth(t.financePayment?.settledAt??t.paymentRequest?.paidAt);if(fallback)return [{key:fallback,amount:paid}];}
 let left=paid;const rows:{key:string|null;amount:number}[]=[];
 for(const h of history){const value=Math.min(left,amount(h.amount));if(value>0)rows.push({key:paidMonth(h.at),amount:value});left=round(left-value);}
 if(left>0)rows.push({key:null,amount:left});return rows;
}
/** Pure read model over the authorized, single-unit graph. No repricing, writes or private reads. */
export function executiveDashboard(s:State,branch='',unit='Konstruksi'){
 const matrix=dashboardMatrix(s,branch),approval=dashboardApprovalData(s,branch,unit);
 const plans=new Map(s.projects.filter(p=>p.status!=='Cancel').map(p=>[p.id,p]));
 const visible=new Set(matrix.plans.map(p=>p.id));
 const deals=new Map(s.deals.filter(d=>plans.has(d.projectId)).map(d=>[d.id,d]));
 const orders=new Map(s.spks.filter(v=>deals.has(v.dealId)).map(v=>[v.id,{spk:v,terms:[] as Term[]}]));
 const projectOrders=new Map<string,{spk:State['spks'][number];terms:Term[]}[]>();
 for(const order of orders.values()){const id=deals.get(order.spk.dealId)!.projectId;const entries=projectOrders.get(id)??[];entries.push(order);projectOrders.set(id,entries);}
 const rows:Record<ExecutiveMetric,ExecutiveRow[]>={projects:[],plans:[],budget:[],paid:[],outstanding:[],approval:[],settlement:[],documents:[],requested:[]};
 const payments:MonthlyPayment[]=[];let undated=0;
 const requestedPayments=new Map(matrix.payments.filter(t=>t.requested>0).map(t=>[t.id,t.requested]));
 const paymentPositions=new Map(approval.payment.total.rows.map(r=>[r.id,r]));
 const base=(id:string):ExecutiveRow=>{const p=plans.get(id)!;return {id,code:p.projectCode,no:p.no,name:p.name,branch:p.branch,status:p.status,route:'/project-plan/'+p.id,timeline:'/project-plan/'+p.id+'/timeline'};};
 const closedTerms=new Set<string>(),orderedDealIds=new Set([...orders.values()].map(o=>o.spk.dealId)),unorderedProjects=new Set([...deals.values()].filter(d=>!orderedDealIds.has(d.id)).map(d=>d.projectId));
 for(const t of s.terms){
  const order=orders.get(t.spkId);if(!order)continue;order.terms.push(t);
  const deal=deals.get(order.spk.dealId)!,gross=termTotalForDeal(deal,t),paid=t.status==='Disetujui'&&settlementRecorded(t)?amount(t.paid):0,due=amount(paymentDue(t,gross));
  if(t.status==='Disetujui'&&paid>0&&paid>=due)closedTerms.add(t.id);
  if(!visible.has(deal.projectId)||order.spk.status!=='Sah')continue;
  const position=paymentPositions.get(t.id),r:ExecutiveRow={...base(deal.projectId),id:t.id,status:t.status,stage:position?.position??paymentLabel(t),payment:paymentLabel(t),assignee:position?.assignee,since:position?.since,route:'/termin/'+t.id};
  if(requestedPayments.has(t.id))rows.requested.push({...r,amount:requestedPayments.get(t.id)!});
  if(paid>0){
   rows.paid.push({...r,amount:paid});
   for(const slice of paymentSlices(t,paid))if(slice.key)payments.push({key:slice.key,termId:t.id,pid:norm(r.code),amount:slice.amount,row:{...r,amount:slice.amount}});else undated+=slice.amount;
  }
  const active=['Diajukan','Disetujui'].includes(t.status)&&position?.bucket!=='revision';
  if(active&&due>paid){rows.outstanding.push({...r,amount:round(due-paid)});if(position?.bucket==='settlement')rows.settlement.push({...r,amount:round(due-paid)});}
  if(active&&!t.scan?.id)rows.documents.push({...r,amount:paid,stage:paid>0?'Sudah Dibayar · Berkas NOK':'Berkas NOK'});
 }
 for(const p of matrix.plans){
  const ordersFor=projectOrders.get(p.id)??[],saved=plans.get(p.id)!;
  const hasUnorderedDeal=unorderedProjects.has(p.id);
  const closed=saved.status==='Sah'&&!hasUnorderedDeal&&ordersFor.length>0&&ordersFor.every(o=>o.spk.status==='Sah'&&deals.get(o.spk.dealId)!.status==='Sah'&&o.terms.length>0&&o.terms.reduce((a,b)=>a.number>b.number?a:b).type==='Final'&&o.terms.every(t=>closedTerms.has(t.id)));
  if(!closed)rows.plans.push(base(p.id));
  if(saved.status==='Sah'&&!saved.parentId)rows.budget.push({...base(p.id),amount:amount(projectPlanHeaderTotal(saved))});
 }
 const pidGroups=new Map<string,ExecutiveRow>(),pidBranches=new Map<string,Set<string>>();
 for(const p of matrix.plans){if(!p.code)continue;let group=pidGroups.get(p.code);if(!group){group={...base(p.id),id:p.code,status:p.closed?'CLOSE':'OPEN',plans:0,no:''};pidGroups.set(p.code,group);pidBranches.set(p.code,new Set());}pidBranches.get(p.code)!.add(p.branch);group.branch=[...pidBranches.get(p.code)!].join(', ');group.plans!++;}
 rows.projects=[...pidGroups.values()];
 for(const group of [approval.project,approval.payment])for(const r of group.total.rows){if(!visible.has(r.projectId)||!r.bucket.startsWith('role:'))continue;rows.approval.push({id:group.kind+':'+r.id,code:r.code,no:r.no,name:r.name,branch:r.branch,status:r.position,stage:group.kind==='project'?'Paket PP':'Pembayaran',assignee:r.assignee,since:r.since,route:r.route,timeline:r.timeline});}
 const values=Object.fromEntries((Object.keys(rows) as ExecutiveMetric[]).map(key=>[key,['budget','paid','outstanding','settlement','requested'].includes(key)?round(rows[key].reduce((sum,r)=>sum+(r.amount??0),0)):rows[key].length])) as Record<ExecutiveMetric,number>;
 const pendingStages=new Map<string,number>();for(const r of rows.approval)pendingStages.set(r.status,(pendingStages.get(r.status)??0)+1);
 const bottleneck=[...pendingStages].sort((a,b)=>b[1]-a[1])[0];
 return {matrix,rows,values,payments,requestedPids:new Set(rows.requested.map(r=>norm(r.code)).filter(Boolean)).size,undated:round(undated),open:matrix.totals.open,closed:matrix.totals.closed,bottleneck,paidMissing:rows.documents.filter(r=>(r.amount??0)>0).length,settlementCount:rows.settlement.length,pendingOutstanding:round(values.outstanding-values.settlement)};
}
export function monthlyPayments(payments:MonthlyPayment[],year:number){
 const grouped=new Map<string,MonthlyPayment[]>();for(const p of payments){const rows=grouped.get(p.key)??[];rows.push(p);grouped.set(p.key,rows);}
 return Array.from({length:12},(_,i)=>{const key=`${year}-${String(i+1).padStart(2,'0')}`,rows=grouped.get(key)??[];return {key,label:monthLabel.format(new Date(Date.UTC(year,i,1))),amount:round(rows.reduce((sum,r)=>sum+r.amount,0)),count:new Set(rows.map(r=>r.termId)).size,pids:new Set(rows.map(r=>r.pid).filter(Boolean)).size,rows};});
}

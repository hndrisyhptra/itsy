import { unitOptions, stateForUnit, OPERATION_APPROVERS } from './work-unit';
import { DashboardMatrix } from './dashboard-matrix-table';
import { DashboardApproval } from './dashboard-approval-table';
import { useMemo, useState } from 'react';
import { ClipboardCheck, Download, Plus, ReceiptText, SlidersHorizontal, MapPin, Building2 } from 'lucide-react';
import { type State, operational } from './domain';
import { dashboardBranches } from './dashboard-summary';
import { Link, PageTitle } from './ui';
import {ExecutiveDashboard} from './dashboard-executive-view';

export function Dashboard({ s:source }: { s: State }) {
  const units=unitOptions(source),[unit,setUnit]=useState(units[0]??'Konstruksi');
  const s=useMemo(()=>stateForUnit(source,unit),[source,unit]);
  const [branch, setBranch] = useState(''),[tab,setTab]=useState<'summary'|'approval'>('summary');
  const finance=['Finance','Mgr Finance & Settlement'].includes(s.role);
  const approver=['PM Konstruksi','Manager Konstruksi','Admin Area','Mgr Project Support','Mgr Deployment','Mgr Bussiness Support','Head of PMO',...OPERATION_APPROVERS].includes(s.role);
  const section=finance?'Ringkasan pembayaran':approver?'Ringkasan persetujuan':'Ringkasan operasional';
  return <div className="dashboard-overview">
    <PageTitle title="Dashboard" description={`${s.role} · Ringkasan proyek dan pembayaran sesuai akses Branch akun.`}>
      <Link to="/laporan" className="btn"><Download size={15}/> Lihat Laporan</Link>
      {finance&&<Link to="/termin/list-payment" className="btn primary"><ReceiptText size={16}/> List Payment</Link>}
      {approver&&<Link to="/inbox" className="btn primary"><ClipboardCheck size={16}/> Buka Approval Saya</Link>}
      {operational(s) && <Link to="/project-plan/new" className="btn primary"><Plus size={16}/> Project Plan Baru</Link>}
    </PageTitle>
    <section className="dashboard-filters" aria-labelledby="dashboard-filters-title">
      <div className="dashboard-filters-heading"><div className="dashboard-filters-title"><span className="dashboard-filter-icon"><SlidersHorizontal size={16}/></span><div><h2 id="dashboard-filters-title">Filter Dashboard</h2><p>{section}</p></div></div></div>
      <div className="dashboard-filter-fields">
        <label><span><Building2 size={15}/> Unit Kerja</span><select aria-label="Filter Unit Kerja dashboard" value={unit} onChange={e=>{setUnit(e.target.value);setBranch('');}}>{units.map(u=><option key={u}>{u}</option>)}</select></label>
        <label><span><MapPin size={15}/> Branch</span><select aria-label="Filter Branch dashboard" value={branch} onChange={e => setBranch(e.target.value)}>
          <option value="">Semua Branch</option>
          {dashboardBranches(s).map(name => <option key={name} value={name}>{name}</option>)}
        </select></label>
      </div>
      <p className="sr-only" aria-live="polite">Menampilkan: <strong>{unit}</strong><span aria-hidden="true"> · </span><strong>{branch||'Semua Branch'}</strong>. Branch sesuai akses akun.</p>{branch&&<button type="button" className="dashboard-filter-reset" onClick={()=>setBranch('')}>Reset Branch</button>}
    </section>
    <ExecutiveDashboard key={`${unit}:${branch}:${s.actorUserId}:${s.role}`} s={s} unit={unit} branch={branch}/>
    <div className="view-tabs dashboard-monitor-tabs" role="tablist" aria-label="Tabel monitoring dashboard"><button id="dashboard-summary-tab" type="button" role="tab" aria-selected={tab==='summary'} aria-controls="dashboard-summary-panel" className={tab==='summary'?'active':''} onClick={()=>setTab('summary')}>Summary</button><button id="dashboard-approval-tab" type="button" role="tab" aria-selected={tab==='approval'} aria-controls="dashboard-approval-panel" className={tab==='approval'?'active':''} onClick={()=>setTab('approval')}>Status Approval</button></div>
    {tab==='summary'?<section id="dashboard-summary-panel" role="tabpanel" aria-labelledby="dashboard-summary-tab"><DashboardMatrix key={`${unit}:${branch}`} s={s} branch={branch}/></section>:<section id="dashboard-approval-panel" role="tabpanel" aria-labelledby="dashboard-approval-tab"><DashboardApproval key={`${unit}:${branch}`} s={s} branch={branch} unit={unit}/></section>}
  </div>;
}

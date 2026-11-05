import { useState } from 'react';
import { ArrowUpRight, Download, FileSignature, FolderKanban, Plus, ReceiptText } from 'lucide-react';
import { type State, money, operational } from './domain';
import { dashboardBranches, dashboardSummary } from './dashboard-summary';
import { Link, PageTitle } from './ui';

export function Dashboard({ s }: { s: State }) {
  const [branch, setBranch] = useState('');
  const data = dashboardSummary(s, branch);
  return <div className="dashboard-overview">
    <PageTitle title="Dashboard" description="Ringkasan proyek, kontrak mandor, dan realisasi pembayaran.">
      <Link to="/laporan" className="btn"><Download size={15}/> Lihat Laporan</Link>
      {operational(s) && <Link to="/project-plan/new" className="btn primary"><Plus size={16}/> Project Plan Baru</Link>}
    </PageTitle>
    <div className="section-toolbar">
      <div className="view-tabs"><span className="active">Ringkasan operasional</span><span className="period-label">Ringkasan workspace</span></div>
      <select aria-label="Filter Branch dashboard" value={branch} onChange={e => setBranch(e.target.value)}>
        <option value="">Semua Branch</option>
        {dashboardBranches(s).map(name => <option key={name} value={name}>{name}</option>)}
      </select>
    </div>
    <div className="metrics-grid">{[
      { label: 'Proyek Aktif', value: String(data.activeProjects), sub: `${data.projects.length} Project Plan terdaftar`, icon: FolderKanban, route: '/project-plan' },
      { label: 'Total Nilai SPK', value: money(data.contractValue), sub: `${data.spks.length} SPK telah disahkan`, icon: FileSignature, route: '/spk' },
      { label: 'Outstanding Pembayaran', value: money(data.outstanding), sub: 'Tagihan approved − realisasi', icon: ReceiptText, route: '/laporan?tab=Outstanding+Payment' },
    ].map(m => <Link to={m.route} className="metric-main" key={m.label}><div className="metric-label">{m.label}<m.icon size={17}/></div><strong>{m.value}</strong><div className="metric-bottom"><span>{m.sub}</span><ArrowUpRight size={15}/></div></Link>)}</div>
    <div className="mini-metrics">{[
      ['PP Lanjutan', data.continuations, 'Subalokasi budget induk'],
      ['Mandor Aktif', data.activeMandors, 'Pada SPK sah'],
      ['Total Nilai BOQ', money(data.totalBoq), 'BOQ root final'],
      ['Tagihan Disetujui', money(data.billed), 'Approval lengkap'],
      ['Pembayaran Terealisasi', money(data.paid), 'Pembayaran tercatat'],
      ['Approval Menunggu', data.pendingApprovals, 'Sesuai peran Anda'],
    ].map(([label, value, sub]) => <div key={label}><span>{label}</span><strong>{value}</strong><small>{sub}</small></div>)}</div>
  </div>;
}

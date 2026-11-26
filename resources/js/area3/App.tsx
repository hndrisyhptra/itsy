import {workspaceInboxCount,workspaceSearch} from './shell-read-model';
import { ChangePasswordForm } from './change-password';
import { ThemeToggle, readTheme, saveTheme, type Theme } from './theme-toggle';
import { useMemo, useState, useEffect, lazy, Suspense } from 'react';
import { Users, LayoutDashboard, FolderKanban, FileSignature, ReceiptText, ClipboardCheck, Inbox, Database, ChartNoAxesCombined, Settings, PanelLeftClose, PanelLeftOpen, Search, Bell, ChevronDown, ChevronRight, ArrowUpRight, FlaskConical, Menu, KeyRound } from 'lucide-react';
import { useStore, mutate, reset, clearAllDrafts, persistenceError, persistencePending, waitForPersistence, confirmedRevision, inboxRevision, refreshInboxState } from './store';
import { type State, ROLES, dateLabel, operational } from './domain';
import { SCENARIOS } from './seed';
import { boot, relativePath } from './laravel';
import { createInboxSync, inboxSyncRoute } from './inbox-sync';
import { Link, usePath, navigate, Button, Badge, Empty, Notice, Modal, Toasts, attempt, toast, confirmAction } from './ui';
const TelegramProfile=lazy(()=>import('./telegram').then(m=>({default:m.TelegramProfile})));
const Dashboard=lazy(()=>import('./dashboard').then(m=>({default:m.Dashboard})));
const ProjectTimeline=lazy(()=>import('./project-timeline-page').then(m=>({default:m.ProjectTimeline})));
const Projects=lazy(()=>import('./projects').then(m=>({default:m.Projects})));
const ProjectEditor=lazy(()=>import('./projects').then(m=>({default:m.ProjectEditor})));
const ProjectDetail=lazy(()=>import('./projects').then(m=>({default:m.ProjectDetail})));
const BoqPage=lazy(()=>import('./projects').then(m=>({default:m.BoqPage})));
const ContractHub=lazy(()=>import('./contracts').then(m=>({default:m.ContractHub})));
const Contracts=lazy(()=>import('./contracts').then(m=>({default:m.Contracts})));
const DealEditor=lazy(()=>import('./contracts').then(m=>({default:m.DealEditor})));
const SpkEditor=lazy(()=>import('./contracts').then(m=>({default:m.SpkEditor})));
const FinancePaymentList=lazy(()=>import('./finance-payment-list').then(m=>({default:m.FinancePaymentList})));
const Terms=lazy(()=>import('./payments').then(m=>({default:m.Terms})));
const TermEditor=lazy(()=>import('./payments').then(m=>({default:m.TermEditor})));
const ApprovalInbox=lazy(()=>import('./payments').then(m=>({default:m.ApprovalInbox})));
const WorkDocuments=lazy(()=>import('./work-documents').then(m=>({default:m.WorkDocuments})));
const WorkDocumentEditor=lazy(()=>import('./work-documents').then(m=>({default:m.WorkDocumentEditor})));
const NewWorkDocument=lazy(()=>import('./work-documents').then(m=>({default:m.NewWorkDocument})));
const BappDetail=lazy(()=>import('./payments').then(m=>({default:m.BappDetail})));
import { selectActor } from './domain';
const MasterKhs=lazy(()=>import('./management').then(m=>({default:m.MasterKhs})));
const Reports=lazy(()=>import('./management').then(m=>({default:m.Reports})));
const SettingsPage=lazy(()=>import('./management').then(m=>({default:m.SettingsPage})));
const MasterDesignator=lazy(()=>import('./master-designator').then(m=>({default:m.MasterDesignator})));
const MasterMandor=lazy(()=>import('./master-mandor').then(m=>({default:m.MasterMandor})));
const MANDOR_ROLES=['Admin Konstruksi','Admin Operation','Admin Area','Officer 1 Operation','Superadmin'];
const BudgetingPid=lazy(()=>import('./budgeting-pid').then(m=>({default:m.BudgetingPid})));
import { MASTER_TABS } from './master-data';
const NAV = [
  { path: '/dashboard', label: 'Dashboard', icon: LayoutDashboard }, { path: '/project-plan', label: 'Project Plan', icon: FolderKanban },
  { path: '/spk', label: 'SPK', icon: FileSignature }, { path: '/ba-pp', label: 'BA RP & BA PP', icon: ClipboardCheck },
  { path: '/termin', label: 'Pembayaran', icon: ReceiptText }, { path: '/inbox', label: 'Inbox Approval', icon: Inbox },
  { path: '/master-designator', label: 'Master Designator', icon: Database }, { path: '/master-mandor', label: 'Master Mandor', icon: Users }, { path: '/budgeting-pid', label: 'Master Budgeting PID', icon: Database }, { path: '/laporan', label: 'Laporan', icon: ChartNoAxesCombined },
  { path: '/pengaturan', label: 'Pengaturan', icon: Settings },
];
export default function App() {
  const s = useStore(), path = usePath(), clean = path.split('?')[0], parts = clean.split('/').filter(Boolean), module = parts[0] ?? 'dashboard', id = parts[1];
  const navModule = module === 'ba-rp' ? 'ba-pp' : module === 'boq' ? 'project-plan' : module === 'ba-kh' ? 'spk' : module === 'master-khs' ? 'master-designator' : module;
  const [collapsed, collapse] = useState(false), [mobile, setMobile] = useState(false), [query, setQuery] = useState(''), [panel, setPanel] = useState(''), [theme, setTheme] = useState<Theme>(readTheme);
  const [scenario, setScenario] = useState(''), [mastersOpen,setMastersOpen]=useState(navModule==='master-designator'),[workOpen,setWorkOpen]=useState(navModule==='ba-pp');
  const [inboxSyncStatus,setInboxSyncStatus]=useState('Inbox diperbarui otomatis setiap 15 detik.');
  useEffect(()=>{
    if(!boot?.account)return;
    const eligible=()=>inboxSyncRoute(relativePath(location.pathname))&&!persistencePending&&!document.querySelector('[role="dialog"], [role="alertdialog"], dialog[open]')
      &&!Array.from(document.querySelectorAll<HTMLInputElement|HTMLTextAreaElement>('.approval-layout > .stack input:not([readonly]):not([disabled]), .approval-layout > .stack textarea:not([readonly]):not([disabled])')).some(input=>input.value.trim()!=='');
    const sync=createInboxSync({visible:()=>document.visibilityState!=='hidden',eligible,revision:confirmedRevision,probe:inboxRevision,refresh:signal=>refreshInboxState(signal,eligible),status:setInboxSyncStatus});
    const wake=()=>sync.wake();
    document.addEventListener('visibilitychange',wake);window.addEventListener('focus',wake);window.addEventListener('popstate',wake);
    return()=>{sync.stop();document.removeEventListener('visibilitychange',wake);window.removeEventListener('focus',wake);window.removeEventListener('popstate',wake);};
  },[]);
  useEffect(()=>{if(navModule==='master-designator')setMastersOpen(true);if(navModule==='ba-pp')setWorkOpen(true);},[navModule]);
  useEffect(() => { const listener=(event:Event) => { const message=(event as CustomEvent<string>).detail; toast(message,true); navigate('/dashboard'); }; window.addEventListener('area3:server-error',listener); return () => window.removeEventListener('area3:server-error',listener); },[]);
  useEffect(() => { document.documentElement.dataset.theme = theme; saveTheme(theme); }, [theme]);
  useEffect(() => { setMobile(false); setPanel(''); setQuery(''); document.title = `${NAV.find(n => n.path === `/${navModule}`)?.label ?? 'Dashboard'} · it's Area 3`; }, [path]);
  const inboxCount = useMemo(()=>workspaceInboxCount(s),[s]), active = NAV.find(n => n.path === `/${navModule}`);
  const searchResults = useMemo(()=>workspaceSearch(s,query),[s,query]);
  let content;
  if (module === 'dashboard' || !parts.length) content = <Dashboard s={s}/>;
  else if (module === 'project-plan') content = !id ? <Projects s={s}/> : id === 'new' || parts[2] === 'edit' ? <ProjectEditor key={`${s.scenario}-${id}-edit`} s={s} id={id}/> : parts[2] === 'timeline' ? <ProjectTimeline key={id} s={s} id={id}/> : <ProjectDetail s={s} id={id}/>;
  else if (module === 'boq') content = boot?.account ? (id && s.projects.some(p=>p.id===id) ? <ProjectDetail s={s} id={id}/> : <Projects s={s}/>) : <BoqPage key={`${s.scenario}-${id}`} s={s} id={id}/>;
  else if (module === 'ba-kh') content = !id ? <Contracts s={s} kind="deal"/> : <DealEditor key={`${s.scenario}-${id}`} s={s} id={id}/>;
  else if (module === 'spk') content = id === 'ba-kh' ? <DealEditor key={`${s.scenario}-${parts[2]}`} s={s} id={parts[2] ?? 'new'}/> : !id ? <ContractHub s={s}/> : <SpkEditor key={`${s.scenario}-${id}`} s={s} id={id}/>;
  else if (module === 'ba-rp') content = id==='new'?<NewWorkDocument s={s}/>:id?<WorkDocumentEditor key={id} s={s} id={id}/>:<WorkDocuments s={s}/>;
  else if (module === 'termin') content = id==='list-payment'?<FinancePaymentList s={s}/>:id==='new'?<NewWorkDocument s={s}/>:!id?<Terms s={s}/> : <TermEditor key={`${s.scenario}-${id}`} s={s} id={id}/>;
  else if (module === 'inbox') content = <ApprovalInbox s={s}/>;
  else if (module === 'ba-pp') content = id ? <BappDetail key={`${s.scenario}-${id}`} s={s} id={id}/> : <WorkDocuments s={s} tab="bapp"/>;
  else if (module === 'master-designator' || module === 'master-khs') content = boot?.account ? <MasterDesignator key={id ?? (module==='master-khs'?'khs':'designator')} s={s} entity={MASTER_TABS.find(t=>t.id===id)?.id ?? (module==='master-khs'?'khs':'designator')}/> : <MasterKhs s={s}/>;
  else if (module === 'laporan') content = <Reports s={s}/>;
  else if (module === 'master-mandor') content = <MasterMandor s={s}/>;
  else if (module === 'budgeting-pid') content = <BudgetingPid s={s}/>;
  else if (module === 'pengaturan') content = <SettingsPage s={s}/>;
  else content = <Empty title="Halaman tidak ditemukan"><Link className="btn primary" to="/dashboard">Kembali ke Dashboard</Link></Empty>;
  return <div className={`app ${collapsed ? 'collapsed' : ''}`}>
    {mobile && <div className="mobile-scrim" onClick={() => setMobile(false)}/>}
    <aside className={`sidebar ${mobile ? 'mobile-open' : ''}`}>
      <Link to="/dashboard" className="brand"><span className="brand-mark">i<span>t</span></span><span className="brand-text">it's <strong>Area 3</strong><small>WORKSPACE</small></span></Link>
      <div className="workspace-label">WORKSPACE <span>AREA 3</span></div>
      <nav aria-label="Menu utama">{NAV.filter(n=>(n.path!=="/master-mandor"||!!boot?.account&&MANDOR_ROLES.includes(s.role))&&(n.path!=="/budgeting-pid"||!!boot?.account&&["Finance","Superadmin"].includes(s.role))).map((n, i) => <div key={n.path} className={i === 6 ? 'nav-section' : ''}>{i === 6 && <div className="nav-caption">ADMINISTRASI</div>}{n.path==='/ba-pp'?<><button type="button" className={`nav-link master-nav-parent ${navModule==='ba-pp'?'active':''}`} title={n.label} aria-label={n.label} aria-expanded={workOpen} aria-controls="work-document-submenu" onClick={()=>{if(collapsed){collapse(false);setWorkOpen(true);}else setWorkOpen(v=>!v);}}><n.icon size={18}/><span>{n.label}</span><ChevronDown className={workOpen?'master-chevron open':'master-chevron'} size={14}/></button>{workOpen&&<div id="work-document-submenu" className="master-submenu">{[['/ba-rp','BA RP'],['/ba-pp','BA PP']].map(([route,label])=><Link key={route} to={route} title={label} className={`nav-link ${module===route.slice(1)?'active':''}`}><ChevronRight size={13}/><span>{label}</span></Link>)}</div>}</>:n.path==='/master-designator'?<><button type="button" className={`nav-link master-nav-parent ${navModule==='master-designator'?'active':''}`} title={n.label} aria-label={n.label} aria-expanded={mastersOpen} aria-controls="master-submenu" onClick={()=>{if(collapsed){collapse(false);setMastersOpen(true);}else setMastersOpen(v=>!v);}}><n.icon size={18}/><span>{n.label}</span><ChevronDown className={mastersOpen?'master-chevron open':'master-chevron'} size={14}/></button>{mastersOpen&&<div id="master-submenu" className="master-submenu">{MASTER_TABS.map(t=><Link key={t.id} title={t.label} aria-label={t.label} className={`nav-link ${navModule==='master-designator'&&(id===t.id||(!id&&(module==='master-khs'?'khs':'designator')===t.id))?'active':''}`} to={'/master-designator/'+t.id}><ChevronRight size={13}/><span>{t.label}</span></Link>)}</div>}</>:<Link className={`nav-link ${navModule === n.path.slice(1) ? 'active' : ''}`} title={n.label} to={n.path}><n.icon size={18}/><span>{n.label}</span>{n.path === '/inbox' && inboxCount > 0 && <b>{inboxCount}</b>}</Link>}{n.path==='/termin'&&['Finance','Mgr Finance & Settlement'].includes(s.role)&&<div className="master-submenu"><Link className={`nav-link ${id==='list-payment'?'active':''}`} title="List Payment" to="/termin/list-payment"><ChevronRight size={13}/><span>List Payment</span></Link></div>}</div>)}</nav>
      <div className="sidebar-bottom"><div className="prototype-note"><FlaskConical size={15}/><div><strong>{boot?.account ? "Workspace Area 3" : "Workspace"}</strong></div></div><Button onClick={() => collapse(!collapsed)} aria-label="Ubah lebar menu" className="collapse-button">{collapsed ? <PanelLeftOpen size={17}/> : <><PanelLeftClose size={17}/><span>Ringkas menu</span></>}</Button></div>
    </aside>
    <div className="main-shell"><header className="topbar"><Button className="mobile-toggle" aria-label="Buka menu" onClick={() => setMobile(!mobile)}><Menu size={19}/></Button><div className="breadcrumbs"><Link to="/dashboard">Workspace</Link><ChevronRight size={13}/><span>{active?.label ?? 'Dashboard'}</span>{id && <><ChevronRight size={13}/><span>{navModule==='master-designator' ? MASTER_TABS.find(t=>t.id===id)?.label??'Designator' : id === 'new' ? 'Baru' : module==='termin'&&id==='list-payment'?'List Payment':module === 'project-plan' && parts[2] === 'timeline' ? 'Timeline' : 'Detail'}</span></>}</div><div className="top-actions"><Button className="mobile-search" aria-label="Buka pencarian" onClick={() => setPanel('search')}><Search size={18}/></Button><div className="global-search"><Search size={15}/><input aria-label="Pencarian global" placeholder="Cari PP, SPK, proyek…" value={query} onChange={e => setQuery(e.target.value)}/><kbd>⌕</kbd>{query && <div className="search-results">{searchResults.length ? searchResults.map(r => <Link to={r.route} key={r.route}><strong>{r.title}</strong><small>{r.detail}</small><ArrowUpRight size={14}/></Link>) : <p>Tidak ada hasil untuk “{query}”.</p>}</div>}</div><Button className="icon-button" aria-label="Notifikasi" onClick={() => setPanel(panel === 'notifications' ? '' : 'notifications')}><Bell size={18}/>{inboxCount > 0 && <i className="notification-dot"/>}</Button><ThemeToggle theme={theme} onChange={setTheme}/><button className="profile-button" aria-label="Buka profil" onClick={() => setPanel(panel === 'profile' ? '' : 'profile')}><span className="avatar">{s.profile.name.trim().split(/\s+/).slice(0,2).map(v => v[0]).join('').toUpperCase()}</span><span>{s.profile.name}<small>{s.role}</small></span><ChevronDown size={14}/></button></div></header>
      {boot?.account ? <div className="demo-bar"><span><span className="demo-label">WORKSPACE</span> Akses mengikuti akun dan Branch penugasan. <small role="status">{inboxSyncStatus}</small></span><div><strong>{s.role}</strong><span>{boot.account.branches.length} Branch</span><Button confirm="Muat ulang halaman dan data terbaru? Isian yang belum disimpan ke server perlu diperiksa kembali." disabled={persistencePending} onClick={() => window.location.reload()}>Muat ulang data</Button></div></div> : <> <div className="demo-bar"><span><span className="demo-label">PROTOTIPE</span> Simulasikan alur kerja dengan berganti peran.</span><div><label htmlFor="role">Peran demo</label><select disabled={persistencePending} id="role" aria-label="Peran demo" value={s.role} onChange={e => mutate(d => { d.role = e.target.value as State['role']; })}>{ROLES.map(r => <option key={r}>{r}</option>)}</select><select disabled={persistencePending} aria-label="Pengguna demo aktif" value={s.actorUserId ?? ''} onChange={e => mutate(d => selectActor(d, e.target.value))}>{s.users.filter(u => u.active && u.role === s.role).map(u => <option key={u.id} value={u.id}>{u.name}</option>)}</select><Button onClick={() => setPanel('scenario')}><FlaskConical size={14}/> Skenario demo <ChevronDown size={13}/></Button></div></div> </>}
      <main key={s.generation} className={`page-content${navModule==='spk'?' spk-workspace':''}`}>{persistenceError && <Notice tone="error">{persistenceError}</Notice>}<Suspense fallback={<div className="empty" role="status">Memuat halaman…</div>}>{content}</Suspense></main><footer className="app-footer"><span>it's Area 3 <span>·</span> Administrasi Proyek Konstruksi</span><span><i/> {persistencePending ? 'Menyimpan data ke server' : 'Tersimpan di server'} · {new Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit' }).format(new Date(s.lastSaved))} WIB</span></footer>
    </div>
    {panel === 'search' && <Modal title="Pencarian Global" onClose={() => setPanel('')}><input className="full-width" aria-label="Cari PP SPK atau proyek" placeholder="Cari PP, SPK, proyek…" value={query} onChange={e => setQuery(e.target.value)}/><div className="mobile-search-results">{query ? searchResults.length ? searchResults.map(r => <Link to={r.route} key={r.route}><strong>{r.title}</strong><small>{r.detail}</small></Link>) : <Empty title="Tidak ada hasil"/> : <p className="muted">Masukkan nomor PP, SPK, atau nama proyek.</p>}</div></Modal>}
    {panel === 'notifications' && <Modal title="Notifikasi & Aktivitas" onClose={() => setPanel('')}><Notice>{inboxCount} tugas menunggu peran {s.role}.</Notice><div className="activity-list">{s.events.slice(0, 8).map(e => <Link to={e.route} key={e.id}><span className="activity-dot"/><div><strong>{e.title}</strong><p>{e.detail}</p><small>{e.actor} · {dateLabel(e.at)}</small></div><ChevronRight size={14}/></Link>)}</div></Modal>}
    {panel === 'profile' && <Modal title="Profil & Preferensi" onClose={() => setPanel('')}><div className="profile-summary"><span className="avatar large">{s.profile.name.trim().split(/\s+/).slice(0,2).map(v => v[0]).join('').toUpperCase()}</span><h3>{s.profile.name}</h3><p>{s.profile.email}</p><Badge>{s.role}</Badge></div><div className="stack"><Button onClick={() => { setPanel(''); navigate('/pengaturan'); }}><Settings size={16}/> Pengaturan profil</Button>{boot?.account&&<Button onClick={()=>setPanel('password')}><KeyRound size={16}/> Ganti Kata Sandi</Button>}{boot?.account&&<Button onClick={()=>setPanel('telegram')}>Telegram Approval</Button>}{boot?.account ? <>{s.role === 'Superadmin' && <a className="btn" href={boot.accountUrls?.manage}>Kelola akun pengguna</a>}<form method="post" action={boot.accountUrls?.logout} onSubmit={async e=>{e.preventDefault();const form=e.currentTarget;if(await confirmAction('Keluar dari akun ini?')&&form.isConnected&&!persistencePending){clearAllDrafts();form.submit();}}}><input type="hidden" name="_token" value={boot.csrf}/><button className="btn danger" type="submit" disabled={persistencePending}>Keluar dari akun</button></form></> : <Button danger onClick={() => { setScenario('overview'); setPanel('scenario'); }}>Reset data demo</Button>}</div></Modal>}
    {!boot?.account && panel === 'scenario' && <Modal title="Jelajahi skenario demo" onClose={() => setPanel('')}><p className="muted">Muat checkpoint untuk mencoba alur. Tindakan ini mengganti seluruh data workspace demo dan draft sesi browser ini.</p><div className="scenario-options">{SCENARIOS.map(v => <button className={scenario === v.id ? 'selected' : ''} onClick={() => setScenario(v.id)} key={v.id}><span className="scenario-letter">{v.id === 'overview' ? <LayoutDashboard size={16}/> : v.id}</span><div><strong>{v.label}</strong><p>{v.description}</p></div>{scenario === v.id && <ClipboardCheck size={16}/>}</button>)}</div><div className="modal-actions"><Button onClick={() => setPanel('')}>Batal</Button><Button confirm="Ganti seluruh data dan draf demo dengan skenario yang dipilih?" primary disabled={!scenario} onClick={() => attempt(() => { reset(scenario); void waitForPersistence().then(clearAllDrafts).catch(() => {}); setPanel(''); navigate(scenario === 'A' ? '/project-plan/pp-1/edit' : scenario === 'B' ? '/spk/ba-kh/new?project=pp-1' : scenario === 'C' ? '/spk/new' : scenario === 'D' ? '/termin/new' : scenario === 'E' ? '/ba-pp' : '/dashboard'); }, 'Skenario berhasil dimuat.')}>Ganti data & muat skenario</Button></div></Modal>}
    {panel==='telegram'&&boot?.account&&<Modal title="Telegram Approval" onClose={()=>setPanel('profile')}><Suspense fallback={<p role="status">Memuat Telegram…</p>}><TelegramProfile/></Suspense></Modal>}
    {panel==='password'&&boot?.account&&<Modal title="Ganti Kata Sandi" onClose={()=>setPanel('profile')}><ChangePasswordForm onBack={()=>setPanel('profile')}/></Modal>}
    {persistencePending && <div className="server-saving-overlay" role="status" aria-label="Menyimpan data ke server"><span>Menyimpan data ke server…</span></div>}
    <Toasts/>
  </div>;
}

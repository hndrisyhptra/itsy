import { moneyLine } from './currency';
import { BoqMandorPreviewButton } from './boq-mandor-preview';
import { useEffect, useRef, useState } from 'react';
import { UploadTasks } from './upload-tasks';
import { applyAutomaticBoqPricing, usesAutomaticBoqPricing } from './boq-pricing';
import { startingUpload, type UploadProgress } from './upload-progress';
import { UploadProgressView } from './upload-progress-view';
import { uploadTask, type UploadTask } from './upload-queue';
import { Upload } from 'lucide-react';
import { DEFAULT_PP_NUMBER_FORMAT } from './workflow';
import { type State, type Project, type Approval, type Role, projectApprovalScheme, usesBusinessSupport, APPROVERS_V3, approvalRoles, selectedApprover, assignPaymentApprovers, uid, money, num, boqTotal, operational, branchOptions, decideProject, canApproveProject, assignProjectApprovers, setNumberFormat } from './domain';
import { mutate, uploadBoq, waitForPersistence, persistencePending } from './store';
import { boot, appUrl } from './laravel';
import { Link, Card, Field, Input, Button, Table, Notice, Badge, attempt, confirmAction, ActionLink, toast } from './ui';

export function BoqDraftEditor({ s, p:input, onChange, disabled, onBusy }: { s:State; p:Project; onChange:(p:Project)=>void; disabled:boolean; onBusy?:(value:boolean)=>void }) {
  const automatic=usesAutomaticBoqPricing(s,input),p=applyAutomaticBoqPricing(s,input);
  const [loading,setLoading]=useState(false),[error,setError]=useState(''),[progress,setProgress]=useState<UploadProgress|null>(null),[uploadName,setUploadName]=useState('');
  const latest=useRef(p),controller=useRef<AbortController|null>(null);latest.current=p;
  useEffect(()=>()=>{controller.current?.abort();onBusy?.(false);},[]);
  const apply=(imported:{rows:Project['boq'];source:NonNullable<Project['boqUpload']>})=>{const current=latest.current,next:Project={...current,boq:imported.rows,boqUpload:imported.source,boqStatus:'Review'};onChange(automatic?applyAutomaticBoqPricing(s,next):{...next,budget:current.budget>0?current.budget:boqTotal(next)});};
  async function useResult(task:UploadTask){setError('');setUploadName(task.name);setProgress({phase:'processing',percent:100,message:'Mengambil hasil BOQ yang sudah diproses.'});try{apply((await uploadTask(task.id,true)).result);setProgress({phase:'completed',percent:100});}catch(e){setProgress({phase:'error',percent:100});setError(e instanceof Error?e.message:'Hasil upload belum dapat digunakan.');}}
  if(p.parentId) return <Notice>BOQ lanjutan menggunakan alokasi BA PP. BOQ Owner otomatis {money(p.budget)}; volume root dipertahankan.</Notice>;
  async function selectFile(file?:File) {
    if(!file) return;
    if(!await confirmAction(`Unggah BOQ ${file.name}? Isi BOQ draf akan diganti setelah file berhasil divalidasi.`))return;
    setError('');setProgress(null);setUploadName(file.name);
    if(!file.name.toLowerCase().endsWith('.xlsx') || file.size>10*1024*1024) { setError('Gunakan file .xlsx maksimal 10 MB.'); return; }
    setLoading(true); onBusy?.(true);
    try {
      controller.current=new AbortController();setProgress(startingUpload());
      const imported=await uploadBoq(file,p.id,()=>{onBusy?.(false);},controller.current.signal,setProgress);
      apply(imported);
    } catch(e) { if(!(e instanceof DOMException&&e.name==='AbortError')){setError(e instanceof Error?e.message:'File BOQ gagal dibaca. Isi draft sebelumnya tetap tersedia.');setProgress(previous=>previous?.phase==='failed'?previous:{phase:'error',percent:previous?.percent??null});} }
    finally { setLoading(false); onBusy?.(false); }
  }
  const changeRows=(boq:Project['boq'])=>onChange(applyAutomaticBoqPricing(s,{...p,boq}));
  const update=(id:string,values:Partial<Project['boq'][number]>)=>changeRows(p.boq.map(r=>r.id===id?{...r,...values}:r));
  const catalog=new Map(s.khs.map(k=>[k.id,k]));
  const unresolved=automatic?0:p.boq.filter(r=>{const k=catalog.get(r.khsId);return p.boqUpload && k && (r.excelM!==k.m || r.excelS!==k.s) && (!r.reviewed || !r.note.trim());}).length;
  const price=(value:number|null|undefined)=>value===null?'':money(value??0);
  return <>
    <Notice>Upload template BOQ dengan sheet BoQ, kolom Designator, Satuan, Material, Jasa dan VOL. Hanya volume positif diimpor; harga Master KHS digunakan untuk BOQ Owner.</Notice>
    <div className="upload-box"><Upload size={27}/><h3>Upload BOQ Plan</h3><p>Excel .xlsx maksimal 10 MB. Memilih file baru mengganti isi BOQ draft setelah file berhasil divalidasi. Harga komponen yang kosong diterima. Jika memakai rumus, hitung ulang dan simpan file sebelum upload.</p>
      <input disabled={disabled||loading||!boot} aria-label="Pilih file template BOQ Excel" type="file" accept=".xlsx" onChange={e=>{const file=e.target.files?.[0];e.target.value='';void selectFile(file);}}/>
      {progress && <UploadProgressView name={uploadName} progress={progress}/>}
      {error && <Notice tone="error"><span role="alert">{error}</span></Notice>}
      {p.boqUpload && <Notice><b>{p.boqUpload.name}</b><p>{p.boqUpload.package} · {p.boqUpload.sourceRows} baris sumber → {p.boq.length} komponen BOQ. Total sumber {money(p.boqUpload.sourceTotal)}.</p><small>File asli tersimpan privat. Volume dapat disesuaikan pada draft; harga sumber tetap menjadi pembanding.</small>{s.projects.some(x=>x.id===p.id && x.boqUpload?.id===p.boqUpload?.id) && <p><ActionLink confirm="Unduh file ini?" href={appUrl('/api/prototype/boq/'+p.id+'/source')}>Unduh file BOQ asli</ActionLink></p>}</Notice>}
      {!boot && <Notice tone="warning">Upload Excel memerlukan sesi login Laravel.</Notice>}
      {!boot?.account && !p.boq.length && <Button confirm="Gunakan contoh BOQ simulasi pada draf ini?" disabled={disabled||loading} onClick={()=>onChange({...p,boqUpload:undefined,boq:s.khs.filter(k=>k.active).slice(0,6).map((k,i)=>({id:uid('row'),khsId:k.id,qty:[1200,800,12,4,8,6][i],m:k.m,s:k.s,excelM:k.m,excelS:k.s,version:k.version,reviewed:true,note:'Contoh sintetis; bukan hasil parsing file'}))})}>Muat Contoh BOQ (Simulasi)</Button>}
    </div>
    {boot?.account&&<UploadTasks kind="boq" documentId={p.id} onUse={!disabled&&!loading?task=>void useResult(task):undefined}/>}
    {!!unresolved && <Notice tone="warning">{unresolved} komponen memiliki selisih harga. Isi alasan dan konfirmasikan penggunaan harga KHS sebelum mengajukan PP. Draf tetap dapat disimpan.</Notice>}
    {automatic && <Notice>Harga BOQ otomatis mengikuti Master KHS di database. Selisih harga Excel disimpan sebagai pembanding dan tidak memerlukan alasan atau konfirmasi. Total Nilai pada Header PP mengikuti BOQ ini.</Notice>}
    <Table headers={['Designator / Paket','Satuan','Volume','Material Excel / KHS','Jasa Excel / KHS','Jumlah KHS',automatic?'Harga / Aksi':'Review / Aksi']}>{p.boq.map(r=>{
      const k=catalog.get(r.khsId),mismatch=!automatic && !!p.boqUpload && !!k && (r.excelM!==k.m || r.excelS!==k.s);
      return <tr key={r.id}><td><b>{k?.code}</b><small>{k?.name} · {k?.package}</small>{r.sourceRow && <small>Baris Excel {r.sourceRow} · {r.sourceCode}</small>}</td><td>{k?.unit}</td><td><input aria-label={'Volume '+k?.code} type="number" min="0.001" step="0.001" disabled={disabled||loading} value={r.qty} onChange={e=>update(r.id,{qty:Number(e.target.value)})}/></td><td className="numeric">{price(p.boqUpload?r.excelM:k?.m)}{k?.m != null && <small>KHS {price(k?.m)}</small>}</td><td className="numeric">{price(p.boqUpload?r.excelS:k?.s)}{k?.s != null && <small>KHS {price(k?.s)}</small>}</td><td className="numeric">{money(moneyLine(r.qty,k?.m??0,r.qty,k?.s??0,p))}</td><td>{mismatch && <><Input aria-label={'Alasan selisih '+k?.code} disabled={disabled||loading} value={r.note} onChange={note=>update(r.id,{note,reviewed:false})}/><label><input type="checkbox" disabled={disabled||loading||!r.note.trim()} checked={r.reviewed&&!!r.note.trim()} onChange={e=>update(r.id,{reviewed:e.target.checked,version:k?.version??r.version,m:k?.m??null,s:k?.s??null})}/> Gunakan harga KHS</label></>}<Button confirm="Hapus komponen BOQ ini dari draf?" disabled={disabled||loading} danger onClick={()=>changeRows(p.boq.filter(x=>x.id!==r.id))}>Hapus</Button></td></tr>;
    })}</Table>
    <div className="total-row">BOQ Owner ({num(p.boq.length)} komponen)<strong>{money(boqTotal(p))}</strong></div>
    {!!p.boq.length && <Notice>BOQ sudah terisi. Total Nilai pada Header PP dan BOQ Owner pada Anggaran &amp; Margin otomatis mengikuti harga KHS.</Notice>}
  </>;
}

export function ApproverSelect({ s, label, role, value, onChange, disabled, branch }: { s: State; label: string; role: Role; value?: string; onChange: (v: string) => void; disabled: boolean; branch?: string }) {
  const users=s.users.filter(u=>u.active&&u.role===role&&(!u.branches||(!!branch&&u.branches.includes(branch))));
  return <Field label={label} hint={!users.length?(branch?'Belum ada akun aktif dengan peran ini pada Branch terpilih. Superadmin dapat menambahkannya melalui Kelola akun.':'Pilih Branch terlebih dahulu.') : undefined}><select disabled={disabled} value={value ?? ''} onChange={e => onChange(e.target.value)}><option value="">Pilih {role}</option>{users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>;
}

export function ProjectApproverFields({s,p,onChange,disabled}:{s:State;p:Project;onChange:(p:Project)=>void;disabled:boolean}) {
  const version = (p.workflowVersion ?? 1) >= 2 ? p.workflowVersion! : s.schema >= 3 ? 3 : 2;
  const document = {...p,workflowVersion:version,approvalScheme:projectApprovalScheme(p)};
  return <>{approvalRoles(document,true).map((role,i) => <ApproverSelect key={role} s={s} branch={p.branch} label={`Approval ${i+1} · ${role} (wajib saat pengajuan)`} role={role} value={selectedApprover(document,role)} disabled={disabled} onChange={id => onChange(version >= 3 ? {...p,approverUserIds:{...p.approverUserIds,[role]:id},...(role === 'PM Konstruksi' ? {pmUserId:id} : {})} : {...p,...(role === 'PM Konstruksi' ? {pmUserId:id} : {managerUserId:id})})}/>)}</>;
}
function FuturePaymentApprovers({s,p}:{s:State;p:Project}) {
  const [chain,setChain] = useState<Partial<Record<Role,string>>>(p.paymentApproverUserIds ?? {'PM Konstruksi':p.pmUserId});
  return <><h3>Approver untuk pengajuan termin berikutnya</h3><Notice>Riwayat PP lama tetap dipertahankan. Lima penanggung jawab ini digunakan untuk termin baru; pengajuan yang sudah tersimpan tetap mengikuti penugasan saat dibuat.</Notice><div className="form-grid">{APPROVERS_V3.map((role,i) => <ApproverSelect key={role} s={s} branch={p.branch} role={role} label={`Approval ${i+1} · ${role}`} value={chain[role]} onChange={id => setChain({...chain,[role]:id})} disabled={!operational(s)}/>)}</div>{operational(s) && <Button confirm="Simpan penugasan lima approver untuk pembayaran berikutnya?" onClick={() => attempt(() => mutate(d => assignPaymentApprovers(d,p.id,chain)), 'Penanggung jawab pembayaran berikutnya tersimpan.')}>Simpan lima approver</Button>}</>;
}
export function ProjectApprovalPanel({ s, p }: { s: State; p: Project }) {
  const [note, setNote] = useState(''), [pm, setPm] = useState(p.pmUserId ?? ''), [manager, setManager] = useState(p.managerUserId ?? '');
  const legacy = (p.workflowVersion ?? 1) < 2, roles = approvalRoles(p,true);
  async function confirmProjectDecision(decision:Approval['decision']){if(persistencePending)return;try{mutate(d=>decideProject(d,p.id,decision,note));await waitForPersistence();setNote('');toast('Keputusan PP tersimpan.');}catch(e){toast(e instanceof Error?e.message:String(e),true);}}
  return <Card title={legacy ? 'Riwayat Project Plan' : 'Approval Project Plan'} subtitle={legacy ? 'Dokumen historis tetap dipertahankan.' : roles.join(' → ')}>
    <SubmissionRejectionNotice document={p} history={p.approvalHistory}/><div className="actions"><BoqMandorPreviewButton s={s} p={p}/></div>
    {p.headApprovalReopen && <Notice tone="warning">Approval Head dibuka ulang: {p.headApprovalReopen.reason} Keputusan Head terdahulu tetap menjadi riwayat dan tidak berlaku sebagai persetujuan pada siklus koreksi.</Notice>}
    {!legacy && <><div className="form-grid">{roles.map(role => <ApproverSelect key={role} s={s} branch={p.branch} role={role} label={role} value={selectedApprover(p,role)} onChange={() => {}} disabled/>)}</div><Notice>Status PP: {p.status} · {p.approvals?.length ?? 0}/{roles.length} disetujui. SPK tersedia setelah seluruh approval lengkap.</Notice>{canApproveProject(s, p) && <><Field label="Catatan / alasan pengembalian atau penolakan"><textarea value={note} onChange={e => setNote(e.target.value)}/></Field><div className="actions">{(['Tolak', 'Kembalikan', 'Setujui'] as Approval['decision'][]).map(decision => <Button confirm={`${decision} Project Plan ${p.no}? Keputusan akan dicatat atas akun Anda.`} key={decision} primary={decision === 'Setujui'} disabled={persistencePending} onClick={()=>void confirmProjectDecision(decision)}>{decision} PP</Button>)}</div></>}</>}
    {legacy && s.schema < 3 && <><div className="form-grid"><ApproverSelect s={s} branch={p.branch} role="PM Konstruksi" label="PM Konstruksi" value={pm} onChange={setPm} disabled={!operational(s)}/><ApproverSelect s={s} branch={p.branch} role="Manager Konstruksi" label="Manager Konstruksi" value={manager} onChange={setManager} disabled={!operational(s)}/></div>{operational(s) && <Button confirm="Simpan penanggung jawab Project Plan ini?" onClick={() => attempt(() => mutate(d => assignProjectApprovers(d,p.id,pm,manager)), 'Penanggung jawab tersimpan.')}>Simpan Penanggung Jawab</Button>}</>}
    {!!p.approvalHistory?.length && <Table headers={['Approver', 'Keputusan', 'Catatan', 'Waktu']}>{p.approvalHistory.map((a, i) => <tr key={i}><td>{a.actorName ?? a.userId}<small>{a.role}</small></td><td>{a.decision}</td><td>{a.note}</td><td>{new Date(a.at).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' })}</td></tr>)}</Table>}
    {s.schema >= 3 && ((p.workflowVersion ?? 1) < 3||usesBusinessSupport(p)) && p.status === 'Sah' && <FuturePaymentApprovers s={s} p={p}/>}
  </Card>;
}

export function SubmissionRejectionNotice({document,history=[]}:{document:{status:string;workflowVersion?:number};history?:Approval[]}) {
  const last=history.at(-1);
  if((document.workflowVersion??1)<3 || !['Ditolak','Draft'].includes(document.status) || last?.decision!=='Tolak')return null;
  return <Notice tone="warning"><strong>Pengajuan ditolak. Perbaiki dan ajukan ulang dari awal.</strong><p>Catatan {last.actorName??last.role}: {last.note}</p><p>Admin Konstruksi perlu menyimpan perbaikan dan mengajukan kembali. Seluruh approval dimulai dari PM Konstruksi; keputusan sebelumnya tetap ada di riwayat.</p></Notice>;
}

export function ProjectInbox({ s }: { s: State }) {
  const [page,setPage]=useState(1);
  const waiting = s.projects.filter(p => canApproveProject(s, p));
  const pages=Math.max(1,Math.ceil(waiting.length/20)),current=Math.min(page,pages);
  return <Card title="Approval Project Plan" subtitle="Hanya PP yang ditugaskan ke akun pengguna aktif muncul sebagai tugas."><Table headers={['No PP / Project', 'Branch', 'BOQ Owner', 'Approval', 'Aksi']}>{waiting.slice((current-1)*20,current*20).map(p => <tr key={p.id}><td><b>{p.no}</b><small>{p.projectCode} · {p.name}</small></td><td>{p.branch}</td><td>{money(p.parentId ? p.budget : boqTotal(p))}</td><td><Badge>{p.status}</Badge> {p.approvals?.length ?? 0}/{approvalRoles(p,true).length}</td><td><div className="actions"><Link className="btn primary" to={'/project-plan/' + p.id}>Tinjau PP</Link><BoqMandorPreviewButton s={s} p={p}/></div></td></tr>)}</Table>{!waiting.length && <p className="muted">Tidak ada PP menunggu persetujuan pengguna ini.</p>}<div className="table-foot"><span>{waiting.length} PP · {current}/{pages}</span><div className="actions"><Button disabled={current===1} onClick={()=>setPage(current-1)}>Sebelumnya</Button><Button disabled={current===pages} onClick={()=>setPage(current+1)}>Berikutnya</Button></div></div></Card>;
}

export function NumberingSettings({ s }: { s: State }) {
  const [format, setFormat] = useState(s.settings?.ppNumberFormat ?? DEFAULT_PP_NUMBER_FORMAT);
  return <Card title="Format Nomor Project Plan"><Field label="Format No PP" hint="Token: {SEQ} (minimal 4 digit), {BRANCH}, {YYYY}, {MM}, {UNIT}. Nomor diberikan server saat simpan pertama."><Input disabled={!operational(s)} value={format} onChange={setFormat}/></Field><Button confirm="Simpan format penomoran PP untuk dokumen baru?" primary disabled={!operational(s)} onClick={() => attempt(() => mutate(d => setNumberFormat(d, format)), 'Format No PP tersimpan.')}>Simpan Format</Button><Notice>Format bawaan: {DEFAULT_PP_NUMBER_FORMAT}. Contoh dengan kode branch R424 pada November 2026: 0001/LG.000/TA-03R424/11-2026. Urutan terus bertambah tanpa reset otomatis; tanggal mengikuti server Asia/Jakarta. Perubahan berlaku untuk nomor baru. Kode Branch: {branchOptions(s).map(b => b.code + ' = ' + b.name).join(', ')}.</Notice></Card>;
}

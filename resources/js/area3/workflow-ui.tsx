import { useState } from 'react';
import { Upload } from 'lucide-react';
import { DEFAULT_PP_NUMBER_FORMAT } from './workflow';
import { type State, type Project, type Approval, type Role, APPROVERS_V3, approvalRoles, selectedApprover, assignPaymentApprovers, uid, money, num, boqTotal, operational, branchOptions, decideProject, canApproveProject, assignProjectApprovers, setNumberFormat } from './domain';
import { mutate, uploadBoq } from './store';
import { boot, appUrl } from './laravel';
import { Link, Card, Field, Input, Button, Table, Notice, Badge, attempt } from './ui';

export function BoqDraftEditor({ s, p, onChange, disabled, onBusy }: { s:State; p:Project; onChange:(p:Project)=>void; disabled:boolean; onBusy?:(value:boolean)=>void }) {
  const [loading,setLoading]=useState(false),[error,setError]=useState('');
  if(p.parentId) return <Notice>BOQ lanjutan menggunakan alokasi BA PP. BOQ Owner otomatis {money(p.budget)}; volume root dipertahankan.</Notice>;
  async function selectFile(file?:File) {
    if(!file) return;
    setError('');
    if(!file.name.toLowerCase().endsWith('.xlsx') || file.size>10*1024*1024) { setError('Gunakan file .xlsx maksimal 10 MB.'); return; }
    setLoading(true); onBusy?.(true);
    try {
      const imported=await uploadBoq(file);
      const total=imported.rows.reduce((sum,r)=>sum+r.qty*((r.m??0)+(r.s??0)),0);
      onChange({...p,boq:imported.rows,boqUpload:imported.source,boqStatus:'Review',budget:p.budget>0?p.budget:Math.round(total*100)/100});
    } catch(e) { setError(e instanceof Error?e.message:'File BOQ gagal dibaca. Isi draft sebelumnya tetap tersedia.'); }
    finally { setLoading(false); onBusy?.(false); }
  }
  const update=(id:string,values:Partial<Project['boq'][number]>)=>onChange({...p,boq:p.boq.map(r=>r.id===id?{...r,...values}:r)});
  const unresolved=p.boq.filter(r=>{const k=s.khs.find(k=>k.id===r.khsId);return p.boqUpload && k && (r.excelM!==k.m || r.excelS!==k.s) && (!r.reviewed || !r.note.trim());}).length;
  const price=(value:number|null|undefined)=>value===null?'N/A':money(value??0);
  return <>
    <Notice>Upload template BOQ dengan sheet BoQ, kolom Designator, Satuan, Material, Jasa dan VOL. Hanya volume positif diimpor; harga Master KHS digunakan untuk BOQ Owner.</Notice>
    <div className="upload-box"><Upload size={27}/><h3>Upload BOQ Plan</h3><p>Excel .xlsx maksimal 10 MB. Memilih file baru mengganti isi BOQ draft setelah file berhasil divalidasi.</p>
      <input disabled={disabled||loading||!boot} aria-label="Pilih file template BOQ Excel" type="file" accept=".xlsx" onChange={e=>{const file=e.target.files?.[0];e.target.value='';void selectFile(file);}}/>
      {loading && <p role="status">Membaca file dan mencocokkan designator dengan Master KHS…</p>}
      {error && <Notice tone="error"><span role="alert">{error}</span></Notice>}
      {p.boqUpload && <Notice><b>{p.boqUpload.name}</b><p>{p.boqUpload.package} · {p.boqUpload.sourceRows} baris sumber → {p.boq.length} komponen BOQ. Total sumber {money(p.boqUpload.sourceTotal)}.</p><small>File asli tersimpan privat. Volume dapat disesuaikan pada draft; harga sumber tetap menjadi pembanding.</small>{s.projects.some(x=>x.id===p.id && x.boqUpload?.id===p.boqUpload?.id) && <p><a href={appUrl('/api/prototype/boq/'+p.id+'/source')}>Unduh file BOQ asli</a></p>}</Notice>}
      {!boot && <Notice tone="warning">Upload Excel memerlukan sesi login Laravel.</Notice>}
      {!boot?.account && !p.boq.length && <Button disabled={disabled||loading} onClick={()=>onChange({...p,boqUpload:undefined,boq:s.khs.filter(k=>k.active).slice(0,6).map((k,i)=>({id:uid('row'),khsId:k.id,qty:[1200,800,12,4,8,6][i],m:k.m,s:k.s,excelM:k.m,excelS:k.s,version:k.version,reviewed:true,note:'Contoh sintetis; bukan hasil parsing file'}))})}>Muat Contoh BOQ (Simulasi)</Button>}
    </div>
    {!!unresolved && <Notice tone="warning">{unresolved} komponen memiliki selisih harga. Isi alasan dan konfirmasikan penggunaan harga KHS sebelum mengajukan PP. Draf tetap dapat disimpan.</Notice>}
    <Table headers={['Designator / Paket','Satuan','Volume','Material Excel / KHS','Jasa Excel / KHS','Jumlah KHS','Review / Aksi']}>{p.boq.map(r=>{
      const k=s.khs.find(k=>k.id===r.khsId),mismatch=!!p.boqUpload && !!k && (r.excelM!==k.m || r.excelS!==k.s);
      return <tr key={r.id}><td><b>{k?.code}</b><small>{k?.name} · {k?.package}</small>{r.sourceRow && <small>Baris Excel {r.sourceRow} · {r.sourceCode}</small>}</td><td>{k?.unit}</td><td><input aria-label={'Volume '+k?.code} type="number" min="0.001" step="0.001" disabled={disabled||loading} value={r.qty} onChange={e=>update(r.id,{qty:Number(e.target.value)})}/></td><td className="numeric">{price(p.boqUpload?r.excelM:k?.m)}<small>KHS {price(k?.m)}</small></td><td className="numeric">{price(p.boqUpload?r.excelS:k?.s)}<small>KHS {price(k?.s)}</small></td><td className="numeric">{money(r.qty*((k?.m??0)+(k?.s??0)))}</td><td>{mismatch && <><Input aria-label={'Alasan selisih '+k?.code} disabled={disabled||loading} value={r.note} onChange={note=>update(r.id,{note,reviewed:false})}/><label><input type="checkbox" disabled={disabled||loading||!r.note.trim()} checked={r.reviewed&&!!r.note.trim()} onChange={e=>update(r.id,{reviewed:e.target.checked,version:k?.version??r.version,m:k?.m??null,s:k?.s??null})}/> Gunakan harga KHS</label></>}<Button disabled={disabled||loading} danger onClick={()=>onChange({...p,boq:p.boq.filter(x=>x.id!==r.id)})}>Hapus</Button></td></tr>;
    })}</Table>
    <div className="total-row">BOQ Owner ({num(p.boq.length)} komponen)<strong>{money(boqTotal(p))}</strong></div>
    {!!p.boq.length && <Notice>BOQ sudah terisi. Lanjutkan ke Header PP; BOQ Owner otomatis tampil pada Anggaran &amp; Margin.</Notice>}
  </>;
}

export function ApproverSelect({ s, label, role, value, onChange, disabled, branch }: { s: State; label: string; role: Role; value?: string; onChange: (v: string) => void; disabled: boolean; branch?: string }) {
  const users=s.users.filter(u=>u.active&&u.role===role&&(!u.branches||(!!branch&&u.branches.includes(branch))));
  return <Field label={label} hint={!users.length?(branch?'Belum ada akun aktif dengan peran ini pada Branch terpilih. Superadmin dapat menambahkannya melalui Kelola akun.':'Pilih Branch terlebih dahulu.') : undefined}><select disabled={disabled} value={value ?? ''} onChange={e => onChange(e.target.value)}><option value="">Pilih {role}</option>{users.map(u => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>;
}

export function ProjectApproverFields({s,p,onChange,disabled}:{s:State;p:Project;onChange:(p:Project)=>void;disabled:boolean}) {
  const version = (p.workflowVersion ?? 1) >= 2 ? p.workflowVersion! : s.schema >= 3 ? 3 : 2;
  const document = {...p,workflowVersion:version};
  return <>{approvalRoles(document,true).map((role,i) => <ApproverSelect key={role} s={s} branch={p.branch} label={`Approval ${i+1} · ${role} (wajib saat pengajuan)`} role={role} value={selectedApprover(document,role)} disabled={disabled} onChange={id => onChange(version >= 3 ? {...p,approverUserIds:{...p.approverUserIds,[role]:id},...(role === 'PM Konstruksi' ? {pmUserId:id} : {})} : {...p,...(role === 'PM Konstruksi' ? {pmUserId:id} : {managerUserId:id})})}/>)}</>;
}
function FuturePaymentApprovers({s,p}:{s:State;p:Project}) {
  const [chain,setChain] = useState<Partial<Record<Role,string>>>(p.paymentApproverUserIds ?? {'PM Konstruksi':p.pmUserId});
  return <><h3>Approver untuk pengajuan termin berikutnya</h3><Notice>Riwayat PP lama tetap dipertahankan. Lima penanggung jawab ini digunakan untuk termin baru; pengajuan yang sudah tersimpan tetap mengikuti penugasan saat dibuat.</Notice><div className="form-grid">{APPROVERS_V3.map((role,i) => <ApproverSelect key={role} s={s} branch={p.branch} role={role} label={`Approval ${i+1} · ${role}`} value={chain[role]} onChange={id => setChain({...chain,[role]:id})} disabled={!operational(s)}/>)}</div>{operational(s) && <Button onClick={() => attempt(() => mutate(d => assignPaymentApprovers(d,p.id,chain)), 'Penanggung jawab pembayaran berikutnya tersimpan.')}>Simpan lima approver</Button>}</>;
}
export function ProjectApprovalPanel({ s, p }: { s: State; p: Project }) {
  const [note, setNote] = useState(''), [pm, setPm] = useState(p.pmUserId ?? ''), [manager, setManager] = useState(p.managerUserId ?? '');
  const legacy = (p.workflowVersion ?? 1) < 2, roles = approvalRoles(p,true);
  return <Card title={legacy ? 'Riwayat Project Plan' : 'Approval Project Plan'} subtitle={legacy ? 'Dokumen historis tetap dipertahankan.' : roles.join(' → ')}>
    <SubmissionRejectionNotice document={p} history={p.approvalHistory}/>
    {!legacy && <><div className="form-grid">{roles.map(role => <ApproverSelect key={role} s={s} branch={p.branch} role={role} label={role} value={selectedApprover(p,role)} onChange={() => {}} disabled/>)}</div><Notice>Status PP: {p.status} · {p.approvals?.length ?? 0}/{roles.length} disetujui. SPK tersedia setelah seluruh approval lengkap.</Notice>{canApproveProject(s, p) && <><Field label="Catatan / alasan pengembalian atau penolakan"><textarea value={note} onChange={e => setNote(e.target.value)}/></Field><div className="actions">{(['Tolak', 'Kembalikan', 'Setujui'] as Approval['decision'][]).map(decision => <Button key={decision} primary={decision === 'Setujui'} onClick={() => attempt(() => mutate(d => decideProject(d, p.id, decision, note)), 'Keputusan PP tersimpan.')}>{decision} PP</Button>)}</div></>}</>}
    {legacy && s.schema < 3 && <><div className="form-grid"><ApproverSelect s={s} branch={p.branch} role="PM Konstruksi" label="PM Konstruksi" value={pm} onChange={setPm} disabled={!operational(s)}/><ApproverSelect s={s} branch={p.branch} role="Manager Konstruksi" label="Manager Konstruksi" value={manager} onChange={setManager} disabled={!operational(s)}/></div>{operational(s) && <Button onClick={() => attempt(() => mutate(d => assignProjectApprovers(d,p.id,pm,manager)), 'Penanggung jawab tersimpan.')}>Simpan Penanggung Jawab</Button>}</>}
    {!!p.approvalHistory?.length && <Table headers={['Approver', 'Keputusan', 'Catatan', 'Waktu']}>{p.approvalHistory.map((a, i) => <tr key={i}><td>{a.actorName ?? a.userId}<small>{a.role}</small></td><td>{a.decision}</td><td>{a.note}</td><td>{new Date(a.at).toLocaleString('id-ID', { timeZone: 'Asia/Jakarta' })}</td></tr>)}</Table>}
    {s.schema >= 3 && (p.workflowVersion ?? 1) < 3 && p.status === 'Sah' && <FuturePaymentApprovers s={s} p={p}/>}
  </Card>;
}

export function SubmissionRejectionNotice({document,history=[]}:{document:{status:string;workflowVersion?:number};history?:Approval[]}) {
  const last=history.at(-1);
  if((document.workflowVersion??1)<3 || !['Ditolak','Draft'].includes(document.status) || last?.decision!=='Tolak')return null;
  return <Notice tone="warning"><strong>Pengajuan ditolak. Perbaiki dan ajukan ulang dari awal.</strong><p>Catatan {last.actorName??last.role}: {last.note}</p><p>Admin Konstruksi perlu menyimpan perbaikan dan mengajukan kembali. Seluruh approval dimulai dari PM Konstruksi; keputusan sebelumnya tetap ada di riwayat.</p></Notice>;
}

export function ProjectInbox({ s }: { s: State }) {
  const waiting = s.projects.filter(p => canApproveProject(s, p));
  return <Card title="Approval Project Plan" subtitle="Hanya PP yang ditugaskan ke akun pengguna aktif muncul sebagai tugas."><Table headers={['No PP / Project', 'Branch', 'BOQ Owner', 'Approval', 'Aksi']}>{waiting.map(p => <tr key={p.id}><td><b>{p.no}</b><small>{p.projectCode} · {p.name}</small></td><td>{p.branch}</td><td>{money(p.parentId ? p.budget : boqTotal(p))}</td><td><Badge>{p.status}</Badge> {p.approvals?.length ?? 0}/{approvalRoles(p,true).length}</td><td><Link className="btn primary" to={'/project-plan/' + p.id}>Tinjau PP</Link></td></tr>)}</Table>{!waiting.length && <p className="muted">Tidak ada PP menunggu persetujuan pengguna ini.</p>}</Card>;
}

export function NumberingSettings({ s }: { s: State }) {
  const [format, setFormat] = useState(s.settings?.ppNumberFormat ?? DEFAULT_PP_NUMBER_FORMAT);
  return <Card title="Format Nomor Project Plan"><Field label="Format No PP" hint="Token: {SEQ} (minimal 4 digit), {BRANCH}, {YYYY}, {MM}, {UNIT}. Nomor diberikan server saat simpan pertama."><Input disabled={!operational(s)} value={format} onChange={setFormat}/></Field><Button primary disabled={!operational(s)} onClick={() => attempt(() => mutate(d => setNumberFormat(d, format)), 'Format No PP tersimpan.')}>Simpan Format</Button><Notice>Format bawaan: {DEFAULT_PP_NUMBER_FORMAT}. Contoh dengan kode branch R424 pada November 2026: 0001/LG.000/TA-03R424/11-2026. Urutan terus bertambah tanpa reset otomatis; tanggal mengikuti server Asia/Jakarta. Perubahan berlaku untuk nomor baru. Kode Branch: {branchOptions(s).map(b => b.code + ' = ' + b.name).join(', ')}.</Notice></Card>;
}

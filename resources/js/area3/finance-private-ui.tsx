import { bappCurrent,requiresBapp } from './bapp-workflow';
import { approvalRoles } from './workflow';
import { paymentLabel,paymentDescription } from './payment-labels';
import { KasbonAmountNotice } from './kasbon-amount-notice';
import {legacyReviewApproved} from './legacy-settlement-review';
import { settlementRecorded } from './payment-settlement';
import { settleFinancePayment, updatePaymentApm, setKasbonPrintSigner, canEditKasbonPrintSigner } from './finance-payment';
import { paymentDue, kasbonQuote } from './kasbon-settlement';
import { ApproverSelect } from './workflow-ui';
import { usesSettlementPayment } from './finance-payment';
import { KasbonPreview } from './kasbon-preview';
import { useEffect, useMemo, useRef, useState } from 'react';
import { type State, type Spk, type Term, money, operational, getSpk, projectForSpk, termTotal, termTotalForDeal } from './domain';
import { usesFinancePayment, financePaymentLabel, decideFinancePayment, adminKasbonPending } from './finance-payment';
import { appUrl, boot } from './laravel';
import { mutate, persistencePending, refreshState, savePrivateDocument, uploadPrivateDocument, waitForPersistence } from './store';
import { startingUpload, type UploadProgress } from './upload-progress';
import { UploadProgressView } from './upload-progress-view';
import { UploadTasks } from './upload-tasks';
import { type UploadKind } from './upload-queue';
import { Card, Field, Input, Button, Notice, Badge, Modal, Table, toast, ActionLink, Link } from './ui';
import { KasbonDocument, type KasbonData } from './kasbon-document';
import { kasbonInput, type KasbonInput } from './kasbon-form';
import { kasbonComponentLabel } from './kasbon-content';
import { FinanceReviewButton } from './finance-review';

type PrivateFile={id:string;kind:string;uploadedAt:string};
type Payee={nik:string;accountNumber:string;accountName:string;bank:string;ktpFileId:string;bankFileId:string};
const blankPayee:Payee={nik:'',accountNumber:'',accountName:'',bank:'',ktpFileId:'',bankFileId:''};
async function privateRead<T>(path:string,signal?:AbortSignal):Promise<T>{
  const r=await fetch(appUrl(path),{credentials:'same-origin',headers:{Accept:'application/json'},signal:signal?AbortSignal.any([signal,AbortSignal.timeout(15000)]):AbortSignal.timeout(15000)});
  if(!r.ok)throw new Error('Data pribadi belum dapat dimuat. Periksa akses akun dan muat ulang.');return r.json();
}
function PrivateFileLink({id,label}:{id:string;label:string}){
  const [open,setOpen]=useState(false);
  return <><Button onClick={()=>setOpen(true)}>{label}</Button>{open&&<Modal title={label} onClose={()=>setOpen(false)} wide><iframe sandbox="allow-same-origin" title={label} className="private-file-preview" src={appUrl('/api/finance/files/'+id)}/><ActionLink className="btn" confirm="Unduh dokumen pribadi ini?" href={appUrl('/api/finance/files/'+id+'?download=1')}>Unduh File</ActionLink></Modal>}</>;
}
function PrivateUpload({kind,documentId,label,onReady}:{kind:UploadKind;documentId:string;label:string;onReady:()=>void}){
  const [file,setFile]=useState<File|null>(null),[busy,setBusy]=useState(false),[progress,setProgress]=useState<UploadProgress|null>(null);
  const seen=useRef(new Set<string>());
  return <div className="stack"><Field label={label} hint="Maksimal 10 MB. File pribadi; tersimpan melalui antrean."><input type="file" accept={kind==='transfer-proof'?'application/pdf,image/jpeg,image/png,image/webp':'image/jpeg,image/png,image/webp'} disabled={busy||persistencePending} onChange={e=>{setFile(e.target.files?.[0]??null);setProgress(null);}}/></Field><Button confirm={'Unggah '+label+'?'} disabled={!file||busy||persistencePending} onClick={()=>{if(!file)return;setBusy(true);setProgress(startingUpload());void uploadPrivateDocument(kind,documentId,file,setProgress).then(()=>{setFile(null);onReady();toast('File pribadi tersimpan.');}).catch(e=>{setProgress(p=>({phase:'error',percent:p?.percent??null}));toast(e.message,true);}).finally(()=>setBusy(false));}}>Unggah {label}</Button>{progress&&<UploadProgressView name={label} progress={progress}/>}<UploadTasks kind={kind} documentId={documentId} onReady={task=>{if(!seen.current.has(task.id)){seen.current.add(task.id);onReady();void refreshState();}}}/></div>;
}
export function MandorPayeePanel({s,spk}:{s:State;spk?:Spk}){
  const allowed=!!boot?.account&&(operational(s)||s.role==='Finance'||s.role==='Mgr Finance & Settlement');
  const [data,setData]=useState<Payee>(blankPayee),[files,setFiles]=useState<PrivateFile[]>([]),[error,setError]=useState(''),[reload,setReload]=useState(0),[loaded,setLoaded]=useState(false),[busy,setBusy]=useState(false),[version,setVersion]=useState(0);
  const loadedSpk=useRef('');
  useEffect(()=>{if(!allowed||!spk)return;const a=new AbortController();setLoaded(false);void privateRead<{profile:Payee|null;files:PrivateFile[];version:number}>('/api/finance/spk/'+spk.id+'/payee',a.signal).then(r=>{if(loadedSpk.current!==spk.id){setData(r.profile??blankPayee);loadedSpk.current=spk.id;}setFiles(r.files);setVersion(r.version);setLoaded(true);setError('');}).catch(e=>{if(!a.signal.aborted)setError(e.message);});return()=>a.abort();},[allowed,spk?.id,reload]);
  if(!allowed)return null;
  if(!spk)return <Card title="Identitas & Rekening Mandor"><Notice>Simpan Draft SPK terlebih dahulu untuk mengisi data Mandor dan mengunggah foto. Kelengkapan diperiksa sebelum Sahkan SPK.</Notice></Card>;
  const editable=operational(s)&&projectForSpk(s,spk).status!=='Cancel',change=(key:keyof Payee,value:string)=>setData(d=>({...d,[key]:value}));
  return <Card title="Identitas & Rekening Mandor" subtitle={`Data pribadi untuk pengajuan kasbon · versi ${version}. Perubahan tidak mengganti isi SPK yang sudah terbit.`}>{error&&<Notice tone="error">{error}</Notice>}{!editable&&loaded&&(!data.nik||!data.accountNumber||!data.accountName||!data.bank||!data.ktpFileId||!data.bankFileId)&&<Notice tone="warning">Data Mandor belum lengkap. Admin Konstruksi perlu melengkapi dan menyimpannya pada SPK.</Notice>}<div className="form-grid">{([['nik','NIK KTP (16 digit)'],['accountNumber','Nomor Rekening'],['accountName','Nama Pemilik Rekening'],['bank','Nama Bank']] as const).map(([key,label])=><Field key={key} label={label}><input autoComplete="off" inputMode={key==='nik'||key==='accountNumber'?'numeric':'text'} maxLength={key==='nik'?16:key==='accountNumber'?40:key==='bank'?100:150} disabled={!editable||!loaded||busy} value={data[key]} onChange={e=>change(key,e.target.value)}/></Field>)}</div>{(['ktpFileId','bankFileId'] as const).map(key=><div className="stack" key={key}><Field label={key==='ktpFileId'?'Foto KTP terpilih':'Foto Buku Tabungan terpilih'}><select disabled={!editable||!loaded||busy} value={data[key]} onChange={e=>change(key,e.target.value)}><option value="">Pilih hasil upload</option>{data[key]&&!files.some(f=>f.id===data[key])&&<option value={data[key]}>Dokumen tersimpan pada profil v{version}</option>}{files.filter(f=>f.kind===(key==='ktpFileId'?'mandor-ktp':'mandor-bank')).map(f=><option key={f.id} value={f.id}>{new Date(f.uploadedAt).toLocaleString('id-ID')} · {f.id.slice(0,8)}</option>)}</select></Field>{data[key]&&<PrivateFileLink id={data[key]} label={key==='ktpFileId'?'Preview Foto KTP':'Preview Buku Tabungan'}/>}</div>)}{editable&&<><div className="form-grid"><PrivateUpload kind="mandor-ktp" documentId={spk.id} label="Foto KTP" onReady={()=>setReload(v=>v+1)}/><PrivateUpload kind="mandor-bank" documentId={spk.id} label="Foto Buku Tabungan" onReady={()=>setReload(v=>v+1)}/></div><Button primary confirm="Simpan versi baru data pribadi Mandor ini?" disabled={!loaded||busy||persistencePending} onClick={()=>{setBusy(true);void savePrivateDocument('/api/finance/spk/'+spk.id+'/payee',data).then(()=>{toast('Data Mandor tersimpan.');setReload(v=>v+1);}).catch(e=>toast(e.message,true)).finally(()=>setBusy(false));}}>Simpan Data Mandor</Button></>}</Card>;
}

export type KasbonVersion={id:string;version:number;data:KasbonData};

export function KasbonPayeeReview({s,t,kasbon}:{s:State;t:Term;kasbon?:KasbonData}) {
  const [current,setCurrent]=useState(false),spk=s.spks.find(v=>v.id===t.spkId);
  if(!operational(s)&&s.role!=='Finance'&&s.role!=='Mgr Finance & Settlement')return null;
  if(!kasbon)return <MandorPayeePanel s={s} spk={spk}/>;
  return <Card title="Penerima pada Kasbon Tersimpan" subtitle={'Versi data Mandor: '+(kasbon.payee.version??'historis')}><Notice>Rekening dan foto berikut adalah snapshot yang dipakai kasbon ini. Perubahan data SPK berikutnya tidak mengganti tujuan transfer kasbon yang sudah diajukan.</Notice><div className="form-grid">{([['nik','NIK KTP'],['accountName','Nama Penerima'],['accountNumber','Nomor Rekening'],['bank','Nama Bank']] as const).map(([key,label])=><Field key={key} label={label}><input readOnly value={kasbon.payee[key]}/></Field>)}</div><div className="actions">{kasbon.payee.ktpFileId&&<PrivateFileLink id={kasbon.payee.ktpFileId} label="Foto KTP pada Kasbon"/>}{kasbon.payee.bankFileId&&<PrivateFileLink id={kasbon.payee.bankFileId} label="Buku Tabungan pada Kasbon"/>}<Button onClick={()=>setCurrent(v=>!v)}>{current?'Tutup':'Lihat'} Data SPK Terkini</Button></div>{current&&<MandorPayeePanel s={s} spk={spk}/>}</Card>;
}

export function KasbonRowsEditor({rows,busy,total,onChange}:{rows:KasbonInput['rows'];busy:boolean;total:number;onChange:(rows:KasbonInput['rows'])=>void}){
  return <Table className="kasbon-input-rows" headers={['Komponen','No Akun','Nama Akun','Teks / Keperluan','Nilai (Rp)']}>{rows.map((r,i)=>{
    const label=r.component?kasbonComponentLabel(r.component):`Baris ${i+1}`;
    return <tr key={r.component??i}><td><b>{label}</b>{r.component==='service'&&<small>Setelah potongan PPh</small>}</td>{(['accountCode','accountName','text'] as const).map(key=><td key={key}><input aria-label={`${key==='accountCode'?'No Akun':key==='accountName'?'Nama Akun':'Teks / Keperluan'} ${label}`} type="text" maxLength={key==='accountCode'?50:key==='accountName'?150:300} value={r[key]} disabled={busy} onChange={e=>onChange(rows.map((v,j)=>j===i?{...v,[key]:e.target.value}:v))}/></td>)}<td className="numeric"><input aria-label={`Nilai ${label}`} readOnly value={money(Number(r.amount))}/></td></tr>;
  })}<tr className="kasbon-input-total"><th colSpan={4}>Total Pengajuan Kasbon</th><td className="numeric"><b>{money(total)}</b></td></tr></Table>;
}

function KasbonSubmissionForm({s,t,previous,onClose,onSubmitted}:{s:State;t:Term;previous?:KasbonData;onClose:()=>void;onSubmitted:()=>void}) {
  const quote=kasbonQuote(s.deals.find(d=>d.id===s.spks.find(v=>v.id===t.spkId)!.dealId)!,t);
  const [form,setForm]=useState<KasbonInput>(()=>kasbonInput(quote,previous)),[payee,setPayee]=useState<Payee|null>(null),[loaded,setLoaded]=useState(false),[payeeVersion,setPayeeVersion]=useState(0),[payeeReload,setPayeeReload]=useState(0),[error,setError]=useState(''),[busy,setBusy]=useState(false);
  useEffect(()=>{const a=new AbortController();setLoaded(false);void privateRead<{profile:Payee|null;version:number}>('/api/finance/spk/'+t.spkId+'/payee?profile_only=1',a.signal).then(r=>{setPayee(r.profile);setPayeeVersion(r.version);setLoaded(true);setError('');}).catch(e=>{if(!a.signal.aborted)setError(e.message);});return()=>a.abort();},[t.spkId,payeeReload]);
  const change=(key:Exclude<keyof KasbonInput,'rows'>,value:string)=>setForm(f=>({...f,[key]:value}));
  const complete=loaded&&payeeVersion>0&&!!payee?.nik&&!!payee.accountNumber&&!!payee.accountName&&!!payee.bank&&!!payee.ktpFileId&&!!payee.bankFileId;
  async function submit(){setBusy(true);setError('');try{await savePrivateDocument('/api/finance/terms/'+t.id+'/kasbon',{...form,payeeVersion});onSubmitted();toast(usesSettlementPayment(t)?`Kasbon diajukan. Menunggu approval ${approvalRoles(t)[0]}.`:'Kasbon diajukan. Pengajuan masuk ke inbox Finance.');}catch(e){setError(e instanceof Error?e.message:String(e));}finally{setBusy(false);}}
  return <Modal title={'Ajukan Kasbon · '+paymentDescription(t)} onClose={()=>{if(!busy)onClose();}} wide>
    <Notice>{usesSettlementPayment(t)?`Lengkapi kasbon sebelum pengajuan. Approval berjalan ${approvalRoles(t).join(' → ')}.`:'Admin mengajukan kasbon setelah lima approval sampai Head of PMO.'} Nominal dan penerima mengikuti data tersimpan.</Notice>
    <KasbonAmountNotice gross={quote.gross} amount={quote.net} settlement={quote} estimate/>
    {error&&<Notice tone="error">{error}</Notice>}
    {requiresBapp(t)&&!bappCurrent(s.bapps.find(b=>b.termId===t.id),t)&&<Notice tone="warning">Sahkan BA PP sesuai BA RP tersimpan sebelum mengajukan kasbon pembayaran.</Notice>}
    {!loaded&&!error&&<Notice>Memuat identitas dan rekening Mandor…</Notice>}
    {loaded&&!complete&&<Notice tone="warning">Lengkapi dan simpan NIK KTP, rekening, bank serta foto Mandor pada SPK sebelum mengajukan kasbon. <Link to={'/spk/'+t.spkId}>Buka SPK</Link></Notice>}
    <div className="actions"><Button disabled={busy} onClick={()=>setPayeeReload(v=>v+1)}>Muat ulang data penerima</Button><span>Versi penerima: {payeeVersion||'belum tersimpan'}</span></div><div className="form-grid"><Field label="Nilai setelah potongan PPh"><input readOnly value={money(quote.net)}/></Field>{([['accountName','Nama Penerima'],['accountNumber','Nomor Rekening'],['bank','Nama Bank']] as const).map(([key,label])=><Field key={key} label={label}><input readOnly value={payee?.[key]??''}/></Field>)}</div>
    {usesSettlementPayment(t)&&<ApproverSelect s={s} branch={s.projects.find(p=>p.id===s.deals.find(d=>d.id===s.spks.find(v=>v.id===t.spkId)?.dealId)?.projectId)?.branch} role="Mgr Finance & Settlement" label="Approval 6 · Mgr Finance & Settlement *" value={form.settlementUserId} onChange={id=>setForm(f=>({...f,settlementUserId:id}))} disabled={busy}/>}
    <Notice>Cost Center, WBS dan nomor akun diisi sesuai referensi resmi. {usesSettlementPayment(t)?'EVP merupakan penandatangan fisik. Mgr Finance & Settlement mengikuti akun approval yang dipilih.':'EVP dan Mgr Business Support mengikuti template historis. Penandatangan fisik Mgr Finance & Settlement dapat dipilih pada preview kasbon aktif.'}</Notice>
    <div className="form-grid">{([['costCenter','Cost Center'],['wbs','WBS'],['activity','Jenis Kegiatan'],['place','Lokasi di atas TTD Mandor'],['date','Tanggal Kasbon'],['evp','Nama EVP Area Jawa Bali'],['businessSupport','Nama Mgr Business Support']] as const).filter(([key])=>!usesSettlementPayment(t)||key!=='businessSupport').map(([key,label])=><Field key={key} label={(key==='place'?'Tempat pengajuan':label)+' *'}><input type={key==='date'?'date':'text'} maxLength={key==='costCenter'||key==='wbs'||key==='place'?100:150} disabled={busy} value={form[key]} onChange={e=>change(key,e.target.value)}/></Field>)}</div>
    <Notice>Rincian Jasa dan Material dipisahkan otomatis sesuai tagihan. Isi nomor akun, nama akun dan teks/keperluan masing-masing. Nominal Jasa sudah setelah potongan PPh; Material tidak dipotong.</Notice>
    <KasbonRowsEditor rows={form.rows} busy={busy} total={quote.net} onChange={rows=>setForm(f=>({...f,rows}))}/>
    <div className="actions"><Button onClick={onClose} disabled={busy}>Batal</Button><Button primary confirm={usesSettlementPayment(t)?`Ajukan pembayaran beserta kasbon ini untuk tujuh approval, dimulai dari ${approvalRoles(t)[0]}?`:"Ajukan kasbon kepada Finance untuk direview?"} disabled={busy||persistencePending||(requiresBapp(t)&&!bappCurrent(s.bapps.find(b=>b.termId===t.id),t))||!complete||!adminKasbonPending(t)||usesSettlementPayment(t)&&!form.settlementUserId} onClick={()=>void submit()}>Ajukan Kasbon</Button></div>
  </Modal>;
}

export function KasbonSubmissionButton({s,t,previous,onSubmitted}:{s:State;t:Term;previous?:KasbonData;onSubmitted?:()=>void}) {
  const [open,setOpen]=useState(false);
  useEffect(()=>{const open=(event:Event)=>{if((event as CustomEvent).detail===t.id)setOpen(true);};window.addEventListener('area3:open-kasbon',open);return()=>window.removeEventListener('area3:open-kasbon',open);},[t.id]);
  if(!boot?.account||!operational(s)||!adminKasbonPending(t))return null;
  return <><Button primary disabled={!!t.kasbon&&!previous||requiresBapp(t)&&!bappCurrent(s.bapps.find(b=>b.termId===t.id),t)} title="Lengkapi dan ajukan kasbon setelah BA PP Sah" onClick={()=>setOpen(true)}>{t.kasbon?'Perbaiki & Ajukan Kasbon':'Isi Kasbon & Ajukan Pembayaran'}</Button>{open&&<KasbonSubmissionForm s={s} t={t} previous={previous} onClose={()=>setOpen(false)} onSubmitted={()=>{setOpen(false);onSubmitted?.();}}/>}</>;
}

export function AdminKasbonInbox({s}:{s:State}) {
  const [page,setPage]=useState(1);
  const rows=useMemo(()=>{const spks=new Map(s.spks.map(v=>[v.id,v])),deals=new Map(s.deals.map(d=>[d.id,d])),projects=new Map(s.projects.map(p=>[p.id,p])),bapps=new Map(s.bapps.map(b=>[b.termId,b]));return s.terms.filter(adminKasbonPending).flatMap(t=>{const spk=spks.get(t.spkId),deal=deals.get(spk?.dealId??''),p=projects.get(deal?.projectId??'');return spk&&deal&&p?.status==='Sah'?[{t,spk,deal,p,ready:!requiresBapp(t)||bappCurrent(bapps.get(t.id),t)}]:[];});},[s]);
  if(!boot?.account||!operational(s))return null;
  const pages=Math.max(1,Math.ceil(rows.length/20)),current=Math.min(page,pages);
  return <Card title="Pengajuan Kasbon · Admin Konstruksi" subtitle="Lengkapi pembayaran dan kasbon sebelum pengajuan. Pengajuan baru yang dikembalikan mengulang approval dari PM; dokumen historis mengikuti alur tersimpan."><Table headers={['Project Plan / SPK','Pembayaran','Nilai Tagihan','Status','Aksi']}>{rows.slice((current-1)*20,current*20).map(({t,p,spk,deal,ready})=><tr key={t.id}><td>{p.no}<small>{spk.no} · {p.projectCode}</small></td><td>{paymentDescription(t)}</td><td className="numeric">{money(termTotalForDeal(deal,t))}</td><td>{!ready?'Menunggu BA PP Sah':t.financePayment?.status==='Dikembalikan'?'Dikembalikan Finance':'Siap Ajukan Kasbon'}</td><td><Link className="btn" to={ready?'/termin/'+t.id:'/ba-rp/'+t.id+'?step=bapp'}>{ready?'Buka & Ajukan Kasbon':'Lengkapi BA RP & BA PP'}</Link></td></tr>)}</Table>{!rows.length&&<p className="muted">Tidak ada pengajuan kasbon yang menunggu Admin.</p>}{rows.length>20&&<div className="table-foot"><span>{rows.length} pengajuan · {current}/{pages}</span><div className="actions"><Button disabled={current===1} onClick={()=>setPage(current-1)}>Sebelumnya</Button><Button disabled={current===pages} onClick={()=>setPage(current+1)}>Berikutnya</Button></div></div>}</Card>;
}

export function FinanceKasbonPanel({s,t}:{s:State;t:Term}) {
  const [documents,setDocuments]=useState<KasbonVersion[]>([]),[error,setError]=useState(''),[reload,setReload]=useState(0),[busy,setBusy]=useState(false),[preview,setPreview]=useState(''),[note,setNote]=useState(''),[apm,setApm]=useState(t.apm),[printSigner,setPrintSigner]=useState('');
  const allowed=!!boot?.account&&(operational(s)||s.role==='Finance'||s.role==='Mgr Finance & Settlement'&&t.legacySettlementReview?.userId===s.actorUserId||usesSettlementPayment(t)&&['Diajukan','Disetujui'].includes(t.status)&&t.approverUserIds?.[s.role]===s.actorUserId),finance=s.role==='Finance',ready=t.status==='Disetujui'&&!settlementRecorded(t)&&legacyReviewApproved(t),paid=settlementRecorded(t);
  useEffect(()=>{if(!allowed||!usesFinancePayment(t))return;const a=new AbortController();void privateRead<{documents:KasbonVersion[]}>('/api/finance/terms/'+t.id+'/kasbon?documents_only=1',a.signal).then(r=>{setDocuments(r.documents);setError('');}).catch(e=>{if(!a.signal.aborted)setError(e.message);});return()=>a.abort();},[allowed,t.id,t.kasbon?.id,reload,t.legacySettlementReview]);
  useEffect(()=>setApm(t.apm),[t.id,t.apm]);
  if(!usesFinancePayment(t)||!allowed)return null;
  async function command(fn:(s:State)=>void,message:string){setBusy(true);try{mutate(fn);await waitForPersistence();setNote('');toast(message);}catch(e){toast(e instanceof Error?e.message:String(e),true);}finally{setBusy(false);}}
  const latest=documents.find(d=>d.id===t.kasbon?.id),selected=documents.find(d=>d.id===preview);
  async function savePrintSigner(){
    if(!selected)return;setBusy(true);
    try{mutate(d=>setKasbonPrintSigner(d,t.id,selected.id,printSigner));await waitForPersistence();
      const response=await privateRead<{documents:KasbonVersion[]}>('/api/finance/terms/'+t.id+'/kasbon?documents_only=1');setDocuments(response.documents);toast('Penandatangan fisik kasbon tersimpan.');
    }catch(e){setError(e instanceof Error?e.message:String(e));toast(e instanceof Error?e.message:String(e),true);setPreview('');}finally{setBusy(false);}
  }
  const gross=termTotal(s,t);
  return <Card title="Kasbon Admin & Review Pembayaran Finance" subtitle="Kasbon baru diisi sebelum tujuh approval. Finance mereview berkas lalu memproses transfer, bukti transfer dan tandai lunas. Nomor APM opsional. Dokumen historis mengikuti alur tersimpan.">
    {error&&<Notice tone="error">{error}</Notice>}
    {!t.kasbon&&<Notice>Kasbon baru dan nilai transfer menggunakan nilai setelah potongan PPh 2,5% khusus Jasa Mandor. Material tidak dipotong. Rincian nominal ditampilkan saat Admin mengisi kasbon.</Notice>}
    {requiresBapp(t)&&<Notice tone={bappCurrent(s.bapps.find(b=>b.termId===t.id),t)?'success':'warning'}>BA RP → Sahkan BA PP → Pengajuan Kasbon &amp; Pembayaran. {s.bapps.find(b=>b.termId===t.id)&&<Link to={'/ba-pp/'+s.bapps.find(b=>b.termId===t.id)!.id}>Buka BA PP</Link>}</Notice>}
    {t.kasbon&&<KasbonAmountNotice gross={gross} amount={t.kasbon.amount} settlement={t.kasbon.settlement}/>}
    <div className="summary-strip"><span>Pengajuan <Badge>{financePaymentLabel(t)}</Badge></span><span>{t.kasbon?'Nilai transfer':'Tagihan bruto'} <b>{money(paymentDue(t,gross))}</b></span><span>Status Berkas <Badge>{t.scan?'OK':'NOK'}</Badge></span></div>
    {operational(s)&&<div className="actions"><KasbonSubmissionButton s={s} t={t} previous={latest?.data} onSubmitted={()=>setReload(v=>v+1)}/><Link className="text-link" to={'/spk/'+t.spkId}>Lihat / lengkapi data Mandor pada SPK</Link></div>}
    {t.status==='Diajukan'&&<Notice>{usesSettlementPayment(t)?'Kasbon diajukan bersama pembayaran. Menunggu tujuh approval berurutan.':'Menunggu seluruh approval sampai Head of PMO sebelum kasbon.'}</Notice>}
    {(finance||s.role==='Mgr Finance & Settlement')&&<div className="actions"><FinanceReviewButton s={s} t={t} kasbon={latest?.data} identity={<KasbonPayeeReview s={s} t={t} kasbon={latest?.data}/>}/></div>}
    {finance&&ready&&!t.kasbon&&<Notice>Menunggu Admin Konstruksi mengisi dan mengajukan form kasbon.</Notice>}
    {!t.scan&&<Notice tone="warning">Berkas belum lengkap (NOK). Finance dapat mengunggah dan memverifikasi scan TTD saat review atau melengkapinya setelah pembayaran.</Notice>}
    {t.legacySettlementReview&&<Notice tone={legacyReviewApproved(t)?'success':'warning'}>{legacyReviewApproved(t)?'Mgr Finance & Settlement telah menyetujui kasbon aktif.':'Menunggu review Mgr Finance & Settlement.'} {t.legacySettlementReview.name}{t.legacySettlementReview.decision?.note&&' · '+t.legacySettlementReview.decision.note}</Notice>}
    {t.financePayment?.decision?.note&&<Notice tone="warning">Catatan Finance: {t.financePayment.decision.note}</Notice>}
    {documents.length>0&&<div className="actions">{documents.map(d=><Button key={d.id} onClick={()=>{setPreview(d.id);setPrintSigner(d.data.printSigner?.userId??'');}}>Preview Kasbon v{d.version}{d.id!==t.kasbon?.id?' · Arsip':''}</Button>)}</div>}
    {finance&&ready&&t.kasbon&&<>
      {t.scan&&t.scan.status!=='Valid'&&<Notice tone="warning">Scan tersedia. Verifikasi pada bagian berkas sebelum Approve Payment/APM.</Notice>}
      {t.financePayment?.status==='Diverifikasi'&&<PrivateUpload kind="transfer-proof" documentId={t.id} label="Bukti Transfer" onReady={()=>setReload(v=>v+1)}/>}
      {t.transferProof?.kasbonId===t.kasbon.id&&t.financePayment?.status==='Diverifikasi'&&<>
        <Field label="Nomor APM (opsional)" hint="Boleh dikosongkan. Nomor APM dapat ditambah atau diperbaiki setelah lunas."><Input disabled={busy||persistencePending} value={apm} onChange={setApm}/></Field>
        <Button primary confirm={`Tandai lunas ${money(paymentDue(t,gross))}? Pastikan transfer sudah dilakukan dan bukti transfer sesuai.`} disabled={busy||persistencePending} onClick={()=>void command(d=>settleFinancePayment(d,t.id,apm),'Pembayaran ditandai lunas.')}>Tandai Lunas</Button>
      </>}

    </>}
    {t.transferProof&&<PrivateFileLink id={t.transferProof.id} label="Preview Bukti Transfer"/>}
    {paid&&<Notice tone="success">Lunas {money(t.paid)} · {paymentLabel(t)} · {t.apm?`APM ${t.apm}`:'APM belum diisi'}. Transfer bank dilakukan di luar aplikasi.</Notice>}
    {finance&&paid&&<div className="stack"><Field label="Nomor APM" hint="Pembaruan nomor tidak mengulang pelunasan atau mengubah nilai transfer."><Input disabled={busy||persistencePending} value={apm} onChange={setApm}/></Field><Button confirm="Simpan perubahan nomor APM pada pembayaran yang sudah lunas?" disabled={busy||persistencePending||!apm.trim()||apm.trim().toUpperCase()===t.apm} onClick={()=>void command(d=>updatePaymentApm(d,t.id,apm),'Nomor APM tersimpan.')}>Simpan Nomor APM</Button></div>}
    {selected&&<Modal title={`Form Pengajuan Kasbon Project · v${selected.version}`} onClose={()=>setPreview('')} wide>{canEditKasbonPrintSigner(s,t,selected.id)&&selected.data.templateVersion!==3&&<div className="preview-toolbar stack"><Notice>Kasbon historis. Pilih Mgr Finance &amp; Settlement sebagai penandatangan fisik; pembayaran dan approval yang sudah tercatat tetap berlaku.</Notice><ApproverSelect s={s} branch={projectForSpk(s,getSpk(s,t.spkId)).branch} label="Penandatangan fisik Mgr Finance & Settlement" role="Mgr Finance & Settlement" value={printSigner} onChange={setPrintSigner} disabled={busy||persistencePending}/><Button confirm="Ganti penandatangan fisik kasbon ini dengan akun Mgr Finance & Settlement yang dipilih? Riwayat approval dan nilai pembayaran tetap." disabled={busy||persistencePending||!printSigner} onClick={()=>void savePrintSigner()}>Simpan Penandatangan</Button></div>}{busy?<Notice>Memuat dokumen terkonfirmasi…</Notice>:<KasbonPreview data={selected.data} term={t} kasbonId={selected.id}/>}</Modal>}
  </Card>;
}

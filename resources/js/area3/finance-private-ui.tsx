import { useEffect, useMemo, useRef, useState } from 'react';
import { type State, type Spk, type Term, money, operational, termTotal, termTotalForDeal, recordApm } from './domain';
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
  const allowed=!!boot?.account&&(operational(s)||s.role==='Finance');
  const [data,setData]=useState<Payee>(blankPayee),[files,setFiles]=useState<PrivateFile[]>([]),[error,setError]=useState(''),[reload,setReload]=useState(0),[loaded,setLoaded]=useState(false),[busy,setBusy]=useState(false),[version,setVersion]=useState(0);
  const loadedSpk=useRef('');
  useEffect(()=>{if(!allowed||!spk)return;const a=new AbortController();setLoaded(false);void privateRead<{profile:Payee|null;files:PrivateFile[];version:number}>('/api/finance/spk/'+spk.id+'/payee',a.signal).then(r=>{if(loadedSpk.current!==spk.id){setData(r.profile??blankPayee);loadedSpk.current=spk.id;}setFiles(r.files);setVersion(r.version);setLoaded(true);setError('');}).catch(e=>{if(!a.signal.aborted)setError(e.message);});return()=>a.abort();},[allowed,spk?.id,reload]);
  if(!allowed)return null;
  if(!spk)return <Card title="Identitas & Rekening Mandor"><Notice>Simpan Draft SPK terlebih dahulu untuk mengisi data Mandor dan mengunggah foto. Kelengkapan diperiksa sebelum Sahkan SPK.</Notice></Card>;
  const editable=operational(s),change=(key:keyof Payee,value:string)=>setData(d=>({...d,[key]:value}));
  return <Card title="Identitas & Rekening Mandor" subtitle={`Data pribadi untuk pengajuan kasbon · versi ${version}. Perubahan tidak mengganti isi SPK yang sudah terbit.`}>{error&&<Notice tone="error">{error}</Notice>}{!editable&&loaded&&(!data.nik||!data.accountNumber||!data.accountName||!data.bank||!data.ktpFileId||!data.bankFileId)&&<Notice tone="warning">Data Mandor belum lengkap. Admin Konstruksi perlu melengkapi dan menyimpannya pada SPK.</Notice>}<div className="form-grid">{([['nik','NIK KTP (16 digit)'],['accountNumber','Nomor Rekening'],['accountName','Nama Pemilik Rekening'],['bank','Nama Bank']] as const).map(([key,label])=><Field key={key} label={label}><input autoComplete="off" inputMode={key==='nik'||key==='accountNumber'?'numeric':'text'} maxLength={key==='nik'?16:key==='accountNumber'?40:key==='bank'?100:150} disabled={!editable||!loaded||busy} value={data[key]} onChange={e=>change(key,e.target.value)}/></Field>)}</div>{(['ktpFileId','bankFileId'] as const).map(key=><div className="stack" key={key}><Field label={key==='ktpFileId'?'Foto KTP terpilih':'Foto Buku Tabungan terpilih'}><select disabled={!editable||!loaded||busy} value={data[key]} onChange={e=>change(key,e.target.value)}><option value="">Pilih hasil upload</option>{data[key]&&!files.some(f=>f.id===data[key])&&<option value={data[key]}>Dokumen tersimpan pada profil v{version}</option>}{files.filter(f=>f.kind===(key==='ktpFileId'?'mandor-ktp':'mandor-bank')).map(f=><option key={f.id} value={f.id}>{new Date(f.uploadedAt).toLocaleString('id-ID')} · {f.id.slice(0,8)}</option>)}</select></Field>{data[key]&&<PrivateFileLink id={data[key]} label={key==='ktpFileId'?'Preview Foto KTP':'Preview Buku Tabungan'}/>}</div>)}{editable&&<><div className="form-grid"><PrivateUpload kind="mandor-ktp" documentId={spk.id} label="Foto KTP" onReady={()=>setReload(v=>v+1)}/><PrivateUpload kind="mandor-bank" documentId={spk.id} label="Foto Buku Tabungan" onReady={()=>setReload(v=>v+1)}/></div><Button primary confirm="Simpan versi baru data pribadi Mandor ini?" disabled={!loaded||busy||persistencePending} onClick={()=>{setBusy(true);void savePrivateDocument('/api/finance/spk/'+spk.id+'/payee',data).then(()=>{toast('Data Mandor tersimpan.');setReload(v=>v+1);}).catch(e=>toast(e.message,true)).finally(()=>setBusy(false));}}>Simpan Data Mandor</Button></>}</Card>;
}

export type KasbonVersion={id:string;version:number;data:KasbonData};

export function KasbonPayeeReview({s,t,kasbon}:{s:State;t:Term;kasbon?:KasbonData}) {
  const [current,setCurrent]=useState(false),spk=s.spks.find(v=>v.id===t.spkId);
  if(!operational(s)&&s.role!=='Finance')return null;
  if(!kasbon)return <MandorPayeePanel s={s} spk={spk}/>;
  return <Card title="Penerima pada Kasbon Tersimpan" subtitle={'Versi data Mandor: '+(kasbon.payee.version??'historis')}><Notice>Rekening dan foto berikut adalah snapshot yang dipakai kasbon ini. Perubahan data SPK berikutnya tidak mengganti tujuan transfer kasbon yang sudah diajukan.</Notice><div className="form-grid">{([['nik','NIK KTP'],['accountName','Nama Penerima'],['accountNumber','Nomor Rekening'],['bank','Nama Bank']] as const).map(([key,label])=><Field key={key} label={label}><input readOnly value={kasbon.payee[key]}/></Field>)}</div><div className="actions">{kasbon.payee.ktpFileId&&<PrivateFileLink id={kasbon.payee.ktpFileId} label="Foto KTP pada Kasbon"/>}{kasbon.payee.bankFileId&&<PrivateFileLink id={kasbon.payee.bankFileId} label="Buku Tabungan pada Kasbon"/>}<Button onClick={()=>setCurrent(v=>!v)}>{current?'Tutup':'Lihat'} Data SPK Terkini</Button></div>{current&&<MandorPayeePanel s={s} spk={spk}/>}</Card>;
}

function KasbonSubmissionForm({s,t,previous,onClose,onSubmitted}:{s:State;t:Term;previous?:KasbonData;onClose:()=>void;onSubmitted:()=>void}) {
  const [form,setForm]=useState<KasbonInput>(()=>kasbonInput(termTotal(s,t),previous)),[payee,setPayee]=useState<Payee|null>(null),[loaded,setLoaded]=useState(false),[payeeVersion,setPayeeVersion]=useState(0),[payeeReload,setPayeeReload]=useState(0),[error,setError]=useState(''),[busy,setBusy]=useState(false);
  useEffect(()=>{const a=new AbortController();setLoaded(false);void privateRead<{profile:Payee|null;version:number}>('/api/finance/spk/'+t.spkId+'/payee?profile_only=1',a.signal).then(r=>{setPayee(r.profile);setPayeeVersion(r.version);setLoaded(true);setError('');}).catch(e=>{if(!a.signal.aborted)setError(e.message);});return()=>a.abort();},[t.spkId,payeeReload]);
  const change=(key:Exclude<keyof KasbonInput,'rows'>,value:string)=>setForm(f=>({...f,[key]:value}));
  const complete=loaded&&payeeVersion>0&&!!payee?.nik&&!!payee.accountNumber&&!!payee.accountName&&!!payee.bank&&!!payee.ktpFileId&&!!payee.bankFileId;
  async function submit(){setBusy(true);setError('');try{await savePrivateDocument('/api/finance/terms/'+t.id+'/kasbon',{...form,payeeVersion});onSubmitted();toast('Kasbon diajukan. Pengajuan masuk ke inbox Finance.');}catch(e){setError(e instanceof Error?e.message:String(e));}finally{setBusy(false);}}
  return <Modal title={'Ajukan Kasbon · Termin '+t.number+' ('+t.type+')'} onClose={()=>{if(!busy)onClose();}} wide>
    <Notice>Admin Konstruksi mengajukan kasbon setelah lima approval termasuk Head of PMO. Nominal mengikuti tagihan termin, penerima mengikuti data Mandor SPK tersimpan. Finance mereview berkas sebelum memproses pembayaran.</Notice>
    {error&&<Notice tone="error">{error}</Notice>}
    {!loaded&&!error&&<Notice>Memuat identitas dan rekening Mandor…</Notice>}
    {loaded&&!complete&&<Notice tone="warning">Lengkapi dan simpan NIK KTP, rekening, bank serta foto Mandor pada SPK sebelum mengajukan kasbon. <Link to={'/spk/'+t.spkId}>Buka SPK</Link></Notice>}
    <div className="actions"><Button disabled={busy} onClick={()=>setPayeeReload(v=>v+1)}>Muat ulang data penerima</Button><span>Versi penerima: {payeeVersion||'belum tersimpan'}</span></div><div className="form-grid"><Field label="Nilai Pengajuan"><input readOnly value={money(termTotal(s,t))}/></Field>{([['accountName','Nama Penerima'],['accountNumber','Nomor Rekening'],['bank','Nama Bank']] as const).map(([key,label])=><Field key={key} label={label}><input readOnly value={payee?.[key]??''}/></Field>)}</div>
    <Notice>Cost Center, WBS dan nomor akun diisi sesuai referensi resmi. Nama EVP dan Mgr Business Support merupakan penandatangan fisik, bukan tambahan approver aplikasi.</Notice>
    <div className="form-grid">{([['costCenter','Cost Center'],['wbs','WBS'],['activity','Jenis Kegiatan'],['place','Lokasi di atas TTD Mandor'],['date','Tanggal Kasbon'],['evp','Nama EVP Area Jawa Bali'],['businessSupport','Nama Mgr Business Support']] as const).map(([key,label])=><Field key={key} label={label+' *'}><input type={key==='date'?'date':'text'} maxLength={key==='costCenter'||key==='wbs'||key==='place'?100:150} disabled={busy} value={form[key]} onChange={e=>change(key,e.target.value)}/></Field>)}</div>
    <Table headers={['No Akun','Nama Akun','Teks / Keperluan','Amount (Rp)','Aksi']}>{form.rows.map((r,i)=><tr key={i}>{(['accountCode','accountName','text','amount'] as const).map(key=><td key={key}><input aria-label={`${key} baris ${i+1}`} type={key==='amount'?'number':'text'} min={key==='amount'?'1':undefined} step={key==='amount'?'1':undefined} maxLength={key==='accountCode'?50:key==='accountName'?150:300} value={r[key]} disabled={busy} onChange={e=>setForm(f=>({...f,rows:f.rows.map((v,j)=>j===i?{...v,[key]:e.target.value}:v)}))}/></td>)}<td><Button confirm="Hapus baris rincian kasbon ini?" disabled={busy||form.rows.length===1} onClick={()=>setForm(f=>({...f,rows:f.rows.filter((_,j)=>j!==i)}))}>Hapus</Button></td></tr>)}</Table>
    <div className="actions"><Button confirm="Tambahkan baris rincian kasbon?" disabled={busy||form.rows.length>=20} onClick={()=>setForm(f=>({...f,rows:[...f.rows,{accountCode:'',accountName:'',text:'',amount:''}]}))}>+ Baris</Button><Button onClick={onClose} disabled={busy}>Batal</Button><Button primary confirm="Ajukan kasbon dari data ini kepada Finance untuk direview? Dokumen akan disimpan sebagai versi baru." disabled={busy||persistencePending||!complete||!adminKasbonPending(t)} onClick={()=>void submit()}>Ajukan Kasbon</Button></div>
  </Modal>;
}

export function KasbonSubmissionButton({s,t,previous,onSubmitted}:{s:State;t:Term;previous?:KasbonData;onSubmitted?:()=>void}) {
  const [open,setOpen]=useState(false);
  if(!boot?.account||!operational(s)||!adminKasbonPending(t))return null;
  return <><Button primary disabled={!!t.kasbon&&!previous} title="Isi form pengajuan kasbon untuk review Finance" onClick={()=>setOpen(true)}>{t.kasbon?'Perbaiki & Ajukan Kasbon':'Ajukan Kasbon'}</Button>{open&&<KasbonSubmissionForm s={s} t={t} previous={previous} onClose={()=>setOpen(false)} onSubmitted={()=>{setOpen(false);onSubmitted?.();}}/>}</>;
}

export function AdminKasbonInbox({s}:{s:State}) {
  const [page,setPage]=useState(1);
  const rows=useMemo(()=>{const spks=new Map(s.spks.map(v=>[v.id,v])),deals=new Map(s.deals.map(d=>[d.id,d])),projects=new Map(s.projects.map(p=>[p.id,p]));return s.terms.filter(adminKasbonPending).flatMap(t=>{const spk=spks.get(t.spkId),deal=deals.get(spk?.dealId??''),p=projects.get(deal?.projectId??'');return spk&&deal&&p?.status==='Sah'?[{t,spk,deal,p}]:[];});},[s]);
  if(!boot?.account||!operational(s))return null;
  const pages=Math.max(1,Math.ceil(rows.length/20)),current=Math.min(page,pages);
  return <Card title="Pengajuan Kasbon · Admin Konstruksi" subtitle="Pengajuan yang sudah mendapat lima approval termasuk Head of PMO. Kasbon yang dikembalikan Finance diajukan ulang tanpa mengulang approval pekerjaan."><Table headers={['Project Plan / SPK','Termin','Nilai Tagihan','Status','Aksi']}>{rows.slice((current-1)*20,current*20).map(({t,p,spk,deal})=><tr key={t.id}><td>{p.no}<small>{spk.no} · {p.projectCode}</small></td><td>{t.number} · {t.type}</td><td className="numeric">{money(termTotalForDeal(deal,t))}</td><td>{t.financePayment?.status==='Dikembalikan'?'Dikembalikan Finance':'Siap Ajukan Kasbon'}</td><td><Link className="btn" to={'/termin/'+t.id}>Buka & Ajukan Kasbon</Link></td></tr>)}</Table>{!rows.length&&<p className="muted">Tidak ada pengajuan kasbon yang menunggu Admin.</p>}{rows.length>20&&<div className="table-foot"><span>{rows.length} pengajuan · {current}/{pages}</span><div className="actions"><Button disabled={current===1} onClick={()=>setPage(current-1)}>Sebelumnya</Button><Button disabled={current===pages} onClick={()=>setPage(current+1)}>Berikutnya</Button></div></div>}</Card>;
}

export function FinanceKasbonPanel({s,t}:{s:State;t:Term}) {
  const [documents,setDocuments]=useState<KasbonVersion[]>([]),[error,setError]=useState(''),[reload,setReload]=useState(0),[busy,setBusy]=useState(false),[preview,setPreview]=useState(''),[note,setNote]=useState(''),[apm,setApm]=useState(t.apm);
  const allowed=!!boot?.account&&(operational(s)||s.role==='Finance'),finance=s.role==='Finance',ready=t.status==='Disetujui'&&t.apmStatus!=='Valid';
  useEffect(()=>{if(!allowed||!usesFinancePayment(t))return;const a=new AbortController();void privateRead<{documents:KasbonVersion[]}>('/api/finance/terms/'+t.id+'/kasbon?documents_only=1',a.signal).then(r=>{setDocuments(r.documents);setError('');}).catch(e=>{if(!a.signal.aborted)setError(e.message);});return()=>a.abort();},[allowed,t.id,t.kasbon?.id,reload]);
  if(!usesFinancePayment(t)||!allowed)return null;
  async function command(fn:(s:State)=>void,message:string){setBusy(true);try{mutate(fn);await waitForPersistence();setNote('');toast(message);}catch(e){toast(e instanceof Error?e.message:String(e),true);}finally{setBusy(false);}}
  const latest=documents.find(d=>d.id===t.kasbon?.id),selected=documents.find(d=>d.id===preview);
  return <Card title="Kasbon Admin & Review Pembayaran Finance" subtitle="Admin mengajukan kasbon setelah Head of PMO. Finance mereview PP sampai kasbon, kemudian memproses transfer, bukti transfer dan APM.">
    {error&&<Notice tone="error">{error}</Notice>}
    <div className="summary-strip"><span>Pengajuan <Badge>{financePaymentLabel(t)}</Badge></span><span>Tagihan <b>{money(termTotal(s,t))}</b></span><span>Status Berkas <Badge>{t.scan?'OK':'NOK'}</Badge></span></div>
    {operational(s)&&<div className="actions"><KasbonSubmissionButton s={s} t={t} previous={latest?.data} onSubmitted={()=>setReload(v=>v+1)}/><Link className="text-link" to={'/spk/'+t.spkId}>Lihat / lengkapi data Mandor pada SPK</Link></div>}
    {t.status==='Diajukan'&&<Notice>Menunggu seluruh approval pekerjaan termasuk Head of PMO. Admin belum dapat mengajukan kasbon.</Notice>}
    {finance&&<div className="actions"><FinanceReviewButton s={s} t={t} kasbon={latest?.data} identity={<KasbonPayeeReview s={s} t={t} kasbon={latest?.data}/>}/></div>}
    {finance&&ready&&!t.kasbon&&<Notice>Menunggu Admin Konstruksi mengisi dan mengajukan form kasbon.</Notice>}
    {!t.scan&&<Notice tone="warning">Berkas belum lengkap (NOK). Scan TTD dapat dilengkapi dan diverifikasi setelah pembayaran.</Notice>}
    {t.financePayment?.decision?.note&&<Notice tone="warning">Catatan Finance: {t.financePayment.decision.note}</Notice>}
    {documents.length>0&&<div className="actions">{documents.map(d=><Button key={d.id} onClick={()=>setPreview(d.id)}>Preview Kasbon v{d.version}{d.id!==t.kasbon?.id?' · Arsip':''}</Button>)}</div>}
    {finance&&ready&&t.kasbon&&<>
      {t.scan&&t.scan.status!=='Valid'&&<Notice tone="warning">Scan tersedia. Verifikasi pada bagian berkas sebelum Approve Payment/APM.</Notice>}
      <Field label="Catatan Finance / alasan pengembalian"><textarea maxLength={1000} value={note} disabled={busy} onChange={e=>setNote(e.target.value)}/></Field>
      <div className="actions"><Button confirm="Kembalikan kasbon/payment kepada Admin untuk diperbaiki dengan catatan ini? Lima approval pekerjaan tetap tersimpan." disabled={busy||!note.trim()||t.financePayment?.status==='Dikembalikan'} onClick={()=>void command(d=>decideFinancePayment(d,t.id,'Kembalikan',note,t.kasbon!.id),'Kasbon dikembalikan kepada Admin untuk koreksi.')}>Kembalikan Kasbon</Button><Button primary confirm="Dokumen PP sampai kasbon sudah direview dan sesuai? Setujui pembayaran untuk diproses transfer oleh Finance." disabled={busy||!latest||t.financePayment?.status!=='Kasbon Terbit'||!!t.scan&&t.scan.status!=='Valid'} onClick={()=>void command(d=>decideFinancePayment(d,t.id,'Setujui',note,t.kasbon!.id),'Pembayaran disetujui Finance.')}>Approve Payment</Button></div>
      {t.financePayment?.status==='Diverifikasi'&&<PrivateUpload kind="transfer-proof" documentId={t.id} label="Bukti Transfer" onReady={()=>setReload(v=>v+1)}/>}
      <Field label="Nomor APM *"><Input disabled={busy||t.financePayment?.status!=='Diverifikasi'} value={apm} onChange={setApm}/></Field><Button primary confirm={`Catat pelunasan ${money(termTotal(s,t))} dengan APM ${apm}? Pastikan transfer sudah dilakukan dan bukti transfer sudah diunggah.`} disabled={busy||persistencePending||!t.transferProof||t.financePayment?.status!=='Diverifikasi'||!apm.trim()} onClick={()=>void command(d=>recordApm(d,t.id,apm),'APM dan pelunasan tersimpan.')}>Catat APM & Tandai Lunas</Button>
    </>}
    {t.transferProof&&<PrivateFileLink id={t.transferProof.id} label="Preview Bukti Transfer"/>}
    {t.apmStatus==='Valid'&&<Notice tone="success">Lunas {money(t.paid)} · Termin {t.number} ({t.type}) · APM {t.apm}. Transfer bank dilakukan di luar aplikasi.</Notice>}
    {selected&&<Modal title={`Form Pengajuan Kasbon Project · v${selected.version}`} onClose={()=>setPreview('')} wide><div className="preview-toolbar"><span>{selected.id===t.kasbon?.id?'Versi saat ini':'Arsip kasbon · tidak berlaku untuk pembayaran baru'}</span><Button confirm="Cetak form kasbon ini dengan ruang tanda tangan fisik kosong?" onClick={()=>window.print()}>Cetak / Simpan PDF</Button></div><KasbonDocument data={selected.data}/></Modal>}
  </Card>;
}

import { useState } from 'react';
import { startingUpload, type UploadProgress } from './upload-progress';
import { UploadProgressView } from './upload-progress-view';
import { type State, type Term, money, operational, termTotal, getSpk, approvalCount, recordApm, verifyTermScan } from './domain';
import { mutate, uploadTermScan, persistencePending } from './store';
import { appUrl } from './laravel';
import { Card, Field, Input, Button, Notice, Badge, Modal, DocumentPreview, attempt, toast } from './ui';

export function PaymentEvidencePanel({ s, t }: { s: State; t: Term }) {
  const [file, setFile] = useState<File | null>(null), [note, setNote] = useState(''), [apm, setApm] = useState(t.apm), [confirm, setConfirm] = useState(false), [preview, setPreview] = useState(false), [progress,setProgress]=useState<UploadProgress|null>(null);
  const canUpload = operational(s) && t.status === 'Disetujui' && t.approvals.length === approvalCount(t) && t.scan?.status !== 'Valid' && t.apmStatus !== 'Valid';
  const canVerify = s.role === 'Finance' && t.scan?.status === 'Menunggu Verifikasi' && t.apmStatus !== 'Valid';
  const canPay = s.role === 'Finance' && t.scan?.status === 'Valid' && t.apmStatus !== 'Valid' && ((t.workflowVersion ?? 1) < 3 || (t.financeApproval?.decision === 'Setujui' && t.financeApproval.scanId === t.scan.id));
  return <Card title="TTD Basah, Scan & Verifikasi Finance" subtitle="Seluruh approval → cetak → TTD fisik → unggah scan → approval/verifikasi Finance → APM dan lunas.">
    <Notice tone="success">Berkas siap dicetak setelah seluruh persetujuan dokumen lengkap. Tanda tangan dikumpulkan di luar aplikasi.</Notice>
    <Button onClick={() => setPreview(true)}>Buka Berkas Siap Cetak</Button>
    <div className="form-grid"><Field label="Scan dokumen TTD basah (PDF/JPG/PNG, maks. 10 MB)"><input key={t.scan?.id ?? 'new-scan'} type="file" disabled={!canUpload || persistencePending} accept="application/pdf,image/jpeg,image/png" onChange={e => {setFile(e.target.files?.[0] ?? null);setProgress(null);}}/></Field><Field label="Status scan"><div className="input-like"><Badge>{t.scan?.status ?? 'Belum diunggah'}</Badge>{t.scan && <small>{t.scan.name} · {Math.ceil(t.scan.size / 1024)} KB</small>}</div></Field></div>
    <div className="actions"><Button disabled={!canUpload || !file || persistencePending} onClick={() => { if (!file) return; if (file.size > 10 * 1024 * 1024) return toast('Ukuran scan maksimal 10 MB.', true); setProgress(startingUpload()); void uploadTermScan(t.id, file,setProgress).then(() => { setFile(null); toast('Scan tersimpan di Laravel.'); }).catch(error => {setProgress(previous=>({phase:'error',percent:previous?.percent??null}));toast(error.message, true);}); }}>Unggah Scan</Button>{t.scan && <a className="btn" href={appUrl('/api/prototype/payments/' + t.id + '/scan')}>Unduh Scan</a>}</div>
    {progress && <UploadProgressView name={file?.name??t.scan?.name} progress={progress}/>}
    {t.scan?.note && <Notice tone="warning">Catatan Finance: {t.scan.note}</Notice>}
    {canVerify && <><Field label="Catatan verifikasi / alasan koreksi"><textarea value={note} onChange={e => setNote(e.target.value)}/></Field><div className="actions"><Button onClick={() => attempt(() => mutate(d => verifyTermScan(d, t.id, 'Perlu Koreksi', note)), 'Scan dikembalikan untuk koreksi.')}>Kembalikan Scan</Button><Button primary onClick={() => attempt(() => mutate(d => verifyTermScan(d, t.id, 'Valid', note)), 'Scan diverifikasi Finance.')}>Setujui & Verifikasi Scan</Button></div></>}
    {(t.workflowVersion ?? 1) >= 3 && <><Notice tone={t.financeApproval ? 'success' : 'warning'}>Approval Finance: {t.financeApproval ? `${t.financeApproval.actorName} · disetujui setelah pemeriksaan scan` : t.scan?.status === 'Perlu Koreksi' ? 'Scan perlu koreksi; unggah ulang untuk diperiksa Finance.' : 'Menunggu scan TTD fisik dan verifikasi Finance.'}</Notice>{!!t.financeHistory?.length && <div className="stack">{t.financeHistory.map((a,i) => <small key={i}>{a.actorName} · {a.decision} · {new Date(a.at).toLocaleString('id-ID',{timeZone:'Asia/Jakarta'})} · {a.note}</small>)}</div>}</>}
    <div className="summary-strip"><span>Tagihan <b>{money(termTotal(s, t))}</b></span><span>Terbayar <b>{money(t.paid)}</b></span><span>Outstanding <b>{money(termTotal(s, t) - t.paid)}</b></span></div>
    <Field label="Nomor APM" hint="Nomor APM unik menandai seluruh tagihan termin telah dibayar."><Input disabled={!canPay} value={apm} onChange={setApm}/></Field>
    <Button primary disabled={!canPay || !apm.trim()} onClick={() => setConfirm(true)}>Catat APM & Tandai Lunas</Button>
    {t.apmStatus === 'Valid' && <Notice tone="success">Lunas · APM {t.apm}. Pencatatan ini merupakan simulasi; tidak mengirim transfer bank.</Notice>}
    {confirm && <Modal title="Catat APM dan pelunasan?" onClose={() => setConfirm(false)}><p>APM {apm} akan mencatat pelunasan {money(termTotal(s, t) - t.paid)}. Pastikan scan telah diperiksa dan pembayaran telah dilakukan.</p><div className="modal-actions"><Button onClick={() => setConfirm(false)}>Batal</Button><Button primary onClick={() => { const ok = attempt(() => { mutate(d => recordApm(d, t.id, apm)); return true; }, 'APM tersimpan dan tagihan lunas.'); if (ok) setConfirm(false); }}>Konfirmasi APM & Lunas</Button></div></Modal>}
    {preview && <DocumentPreview state={s} kind={t.type === 'Parsial' ? 'BA Rekonsiliasi Pekerjaan – Termin' : 'Persiapan BA Penyelesaian Pekerjaan'} spk={getSpk(s, t.spkId)} term={t} onClose={() => setPreview(false)}/>}
  </Card>;
}

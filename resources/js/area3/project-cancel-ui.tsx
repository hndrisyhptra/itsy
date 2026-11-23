import {useMemo,useState} from 'react';
import {Ban} from 'lucide-react';
import type {State,Project} from './domain';
import {cancelProject,projectCancellationPlans,type CancelExpected} from './project-cancellation';
import {mutate,waitForPersistence,persistencePending} from './store';
import {Button,Field,Modal,Notice,toast} from './ui';
export function ProjectCancelButton({s,p,plan:prepared}:{s:State;p:Project;plan?:ReturnType<typeof projectCancellationPlans> extends Map<string,infer P>?P:never}){
 const plan=useMemo(()=>prepared??projectCancellationPlans(s).get(p.id)!,[prepared,s,p.id]);
 const [expected,setExpected]=useState<CancelExpected|null>(null),[reason,setReason]=useState(''),[busy,setBusy]=useState(false),[error,setError]=useState('');
 if(!plan.own||p.status==='Cancel'&&!busy)return null;
 const changed=!!expected&&JSON.stringify(expected)!==JSON.stringify(plan.expected);
 async function submit(){if(!expected||busy||persistencePending)return;setBusy(true);setError('');try{mutate(d=>cancelProject(d,p.id,expected,reason));await waitForPersistence();setExpected(null);setReason('');toast('Paket PP Cancel. Proses approval dihentikan.');}catch(e){setError(e instanceof Error?e.message:String(e));}finally{setBusy(false);}}
 return <><Button className="icon-action" aria-label={'Cancel paket PP '+p.no} title={plan.reason||'Cancel paket PP'} disabled={!!plan.reason||persistencePending} onClick={()=>{setExpected(structuredClone(plan.expected));setError('');}}><Ban size={15}/></Button>{expected&&<Modal title="Cancel Paket PP" onClose={()=>{if(!busy)setExpected(null);}}><div className="project-cancel-form"><Notice tone="warning">{p.no} · {p.name}. Paket akan berstatus Cancel dan tidak dapat diedit, diajukan ulang atau melanjutkan approval. Data dan riwayat tetap tersimpan.</Notice><Field label="Alasan Cancel *"><textarea maxLength={1000} value={reason} disabled={busy} onChange={e=>setReason(e.target.value)}/></Field>{busy&&<Notice>Menyimpan pembatalan paket…</Notice>}{!busy&&(error||changed||plan.reason)&&<Notice tone="error">{error||plan.reason||'Status paket berubah. Tutup dan periksa data terbaru sebelum Cancel.'}</Notice>}<div className="actions"><Button disabled={busy} onClick={()=>setExpected(null)}>Tutup</Button><Button danger confirm={'YA untuk Cancel paket '+p.no+' secara permanen?'} disabled={busy||persistencePending||changed||!!plan.reason||!reason.trim()} onClick={()=>void submit()}>Cancel Paket PP</Button></div></div></Modal>}</>;
}

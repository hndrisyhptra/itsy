import { paymentLabel } from './payment-labels';
import {useMemo,useState} from 'react';
import {type State,type Term,money} from './domain';
import {canRouteLegacyFinance,canReviewLegacySettlement,routeLegacyFinance,decideLegacySettlementReview} from './legacy-settlement-review';
import {mutate,waitForPersistence,persistencePending} from './store';
import {Button,Modal,Field,Notice,Table,toast} from './ui';

export function legacyRoutingRows(s:State){
 const spks=new Map(s.spks.map(v=>[v.id,v])),deals=new Map(s.deals.map(v=>[v.id,v])),projects=new Map(s.projects.map(v=>[v.id,v]));
 return s.terms.filter(canRouteLegacyFinance).flatMap(t=>{const spk=spks.get(t.spkId),deal=deals.get(spk?.dealId??''),p=projects.get(deal?.projectId??'');return p?[{t,p}]:[];});
}
export function LegacyFinanceRouting({s}:{s:State}){
 const [open,setOpen]=useState(false),[q,setQ]=useState(''),[page,setPage]=useState(1),[ids,setIds]=useState<string[]>([]),[manager,setManager]=useState(''),[busy,setBusy]=useState(false);
 const rows=useMemo(()=>legacyRoutingRows(s),[s]);
 if(!['Finance','Superadmin'].includes(s.role)||!rows.length)return null;
 const matches=rows.filter(({p})=>`${p.no} ${p.name} ${p.projectCode} ${p.branch}`.toLowerCase().includes(q.toLowerCase())),pages=Math.max(1,Math.ceil(matches.length/20)),current=Math.min(page,pages),selected=rows.filter(({t})=>ids.includes(t.id));
 const branches=new Set(selected.map(({p})=>p.branch)),users=s.users.filter(u=>u.active&&u.role==='Mgr Finance & Settlement'&&(!u.branches||[...branches].every(b=>u.branches!.includes(b))));
 async function submit(){
  setBusy(true);try{mutate(d=>routeLegacyFinance(d,selected.map(({t})=>({id:t.id,kasbonId:t.kasbon!.id})),manager));await waitForPersistence();setOpen(false);setIds([]);setManager('');toast('Pengajuan dipindahkan ke inbox Mgr Finance & Settlement.');}catch(e){toast(e instanceof Error?e.message:String(e),true);}finally{setBusy(false);}
 }
 return <div className="actions"><Button onClick={()=>{setOpen(true);setIds([]);setManager('');}}>Alihkan Payment Lama ke Mgr Finance ({rows.length})</Button>{open&&<Modal title="Alihkan pengajuan yang belum disetujui Finance" onClose={()=>{if(!busy)setOpen(false);}} wide>
  <Notice>Pilih maksimal 50 pengajuan. Lima approval pekerjaan tetap. Setelah Mgr Finance menyetujui kasbon aktif, pengajuan kembali ke inbox Finance.</Notice>
  <Field label="Cari ID Project / LOP / PP / Branch"><input value={q} maxLength={100} disabled={busy} onChange={e=>{setQ(e.target.value);setPage(1);}}/></Field>
  <Table headers={['Pilih','PP / ID Project','LOP / Branch','Nilai Kasbon']}>{matches.slice((current-1)*20,current*20).map(({t,p})=><tr key={t.id}><td><input type="checkbox" aria-label={'Pilih '+p.no+' '+paymentLabel(t)} checked={ids.includes(t.id)} disabled={busy||!ids.includes(t.id)&&ids.length>=50} onChange={e=>setIds(old=>e.target.checked?[...old,t.id]:old.filter(id=>id!==t.id))}/></td><td>{p.no}<small>{p.projectCode} · {paymentLabel(t)}</small></td><td>{p.name}<small>{p.branch}</small></td><td className="numeric">{money(t.kasbon!.amount)}</td></tr>)}</Table>
  <div className="table-foot"><span>{selected.length}/50 dipilih · Halaman {current}/{pages}</span><div className="actions"><Button disabled={busy||current===1} onClick={()=>setPage(current-1)}>Sebelumnya</Button><Button disabled={busy||current===pages} onClick={()=>setPage(current+1)}>Berikutnya</Button></div></div>
  <Field label="Mgr Finance & Settlement tujuan" hint="Hanya akun aktif yang memiliki seluruh Branch pengajuan terpilih."><select value={manager} disabled={busy||!selected.length} onChange={e=>setManager(e.target.value)}><option value="">Pilih akun Mgr Finance</option>{users.map(u=><option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
  {!users.length&&<Notice tone="warning">Belum ada akun Mgr Finance aktif untuk seluruh Branch yang dipilih.</Notice>}
  <Button primary confirm={`Pindahkan ${selected.length} pengajuan terpilih ke Mgr Finance? Approval pekerjaan dan nominal tetap.`} disabled={busy||persistencePending||!selected.length||selected.length!==ids.length||!users.some(u=>u.id===manager)} onClick={()=>void submit()}>Alihkan Pengajuan Terpilih</Button>
 </Modal>}</div>;
}
export function LegacySettlementReviewActions({s,t,onDone}:{s:State;t:Term;onDone:()=>void}){
 const [note,setNote]=useState(''),[busy,setBusy]=useState(false);
 if(!canReviewLegacySettlement(s,t))return null;
 async function decide(decision:'Setujui'|'Kembalikan'){
  setBusy(true);try{mutate(d=>decideLegacySettlementReview(d,t.id,t.kasbon!.id,decision,note));await waitForPersistence();setNote('');onDone();toast(decision==='Setujui'?'Disetujui. Pengajuan masuk kembali ke Finance.':'Kasbon dikembalikan ke Admin.');}catch(e){toast(e instanceof Error?e.message:String(e),true);}finally{setBusy(false);}
 }
 return <div className="stack"><Notice>Pengajuan lama dialihkan dari Finance. Review PP sampai kasbon sebelum menyetujui. Lima approval pekerjaan sebelumnya tetap tercatat.</Notice><Field label="Catatan Mgr Finance / alasan pengembalian"><textarea maxLength={1000} value={note} disabled={busy} onChange={e=>setNote(e.target.value)}/></Field><div className="actions"><Button confirm="Kembalikan kasbon ke Admin untuk diperbaiki? Kasbon versi baru harus direview Mgr Finance lagi; lima approval pekerjaan tetap." disabled={busy||persistencePending||!note.trim()} onClick={()=>void decide('Kembalikan')}>Kembalikan Kasbon</Button><Button primary confirm="Setujui kasbon aktif dan teruskan pengajuan ke Finance untuk proses pembayaran?" disabled={busy||persistencePending} onClick={()=>void decide('Setujui')}>Setujui & Teruskan ke Finance</Button></div></div>;
}

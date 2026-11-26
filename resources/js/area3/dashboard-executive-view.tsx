import {useMemo,useState} from 'react';
import {ArrowUpRight,FolderKanban,ClipboardList,Wallet,ReceiptText,Clock3,ClipboardCheck,BadgeCheck,FileWarning} from 'lucide-react';
import {type State,money} from './domain';
import {executiveDashboard,EXECUTIVE_LABELS,type ExecutiveMetric,type ExecutiveRow} from './dashboard-executive';
import {Button,Modal,Table,Empty,Notice,navigate} from './ui';

function RowModal({title,rows,onClose,projects,note}:{note?:string;title:string;rows:ExecutiveRow[];onClose:()=>void;projects?:ExecutiveRow[]}){
 const [q,setQ]=useState(''),[page,setPage]=useState(1),[pid,setPid]=useState('');
 const source=pid?projects!.filter(r=>r.code.trim().toUpperCase()===pid):rows;
 const query=q.trim().toLocaleLowerCase('id-ID'),matches=useMemo(()=>query?source.filter(r=>[r.code,r.no,r.name,r.branch,r.status,r.stage,r.payment,r.assignee].join(' ').toLocaleLowerCase('id-ID').includes(query)):source,[source,query]);
 const pages=Math.max(1,Math.ceil(matches.length/20)),current=Math.min(page,pages),visible=matches.slice((current-1)*20,current*20);
 return <Modal title={title+(pid?' · '+pid:'')} wide onClose={onClose}><div className="executive-detail">
 {note&&<Notice>{note}</Notice>}
 {pid&&<Button onClick={()=>{setPid('');setPage(1);setQ('');}}>Kembali ke ID Project</Button>}
 <div className="search-field"><input aria-label="Cari rincian card dashboard" placeholder="Cari PID, nomor PP, kegiatan, Branch atau status…" value={q} onChange={e=>{setQ(e.target.value);setPage(1);}}/></div>
 {visible.length?<Table headers={['ID Project / PP','Nama Kegiatan','Branch','Status / Posisi','Nilai / Jumlah','Aksi']} className="executive-detail-table">{visible.map((r,i)=><tr key={r.id+':'+i}><td><b>{r.code||'Belum diisi'}</b><small>{r.no}</small></td><td>{r.name}</td><td>{r.branch}</td><td>{r.status}{r.payment&&<small>{r.payment}</small>}{r.stage&&<small>{r.stage}</small>}{r.assignee&&<small>{r.assignee}</small>}{r.since&&Number.isFinite(Date.parse(r.since))&&<small>Sejak {new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeZone:'Asia/Jakarta'}).format(new Date(r.since))}</small>}</td><td className="numeric">{r.amount!==undefined?money(r.amount):r.plans!==undefined?`${r.plans} PP`:'—'}</td><td><div className="actions">{projects&&!pid?<Button onClick={()=>{setPid(r.code.trim().toUpperCase());setPage(1);setQ('');}}>Lihat PP</Button>:<><Button onClick={()=>{onClose();navigate(r.route);}}>Detail</Button><Button onClick={()=>{onClose();navigate(r.timeline);}}>Timeline</Button></>}</div></td></tr>)}</Table>:<Empty title="Tidak ada data" description="Belum ada data yang sesuai dengan pilihan dan pencarian Anda."/>}
 <div className="table-foot activity-pagination"><span>Total {matches.length} data</span><nav className="actions" aria-label="Halaman rincian card"><Button disabled={current===1} onClick={()=>setPage(current-1)}>Sebelumnya</Button><span>Halaman {current}/{pages}</span><Button disabled={current===pages} onClick={()=>setPage(current+1)}>Berikutnya</Button></nav></div></div></Modal>;
}
export function ExecutiveDashboard({s,unit,branch}:{s:State;unit:string;branch:string}){
 const data=useMemo(()=>executiveDashboard(s,branch,unit),[s,branch,unit]);
 const [selection,setSelection]=useState<ExecutiveMetric|null>(null);
 const cards:{key:ExecutiveMetric;icon:typeof FolderKanban;sub:string;money?:boolean;attention?:boolean}[]=[
  {key:'projects',icon:FolderKanban,sub:`${data.open} Open · ${data.closed} Close`},
  {key:'plans',icon:ClipboardList,sub:'Draf, Revisi, Approval dan Sah yang belum selesai'},
  {key:'budget',icon:Wallet,sub:'PP induk Sah · Total Anggaran poin 5',money:true},
  {key:'paid',icon:BadgeCheck,sub:`${data.rows.paid.length} pembayaran · nominal tercatat Finance`,money:true},
  {key:'outstanding',icon:ReceiptText,sub:`Masih diproses ${money(data.pendingOutstanding)} · siap lunas ${money(data.values.settlement)}`,money:true,attention:true},
 ];
 const waitingDates=data.rows.approval.map(r=>r.since?Date.parse(r.since):NaN).filter(Number.isFinite),oldest=waitingDates.length?Math.max(0,Math.floor((Date.now()-waitingDates.reduce((a,b)=>Math.min(a,b),Infinity))/86400000)):null;
 const oversight=[{key:'approval' as const,icon:ClipboardCheck,sub:data.bottleneck?`${data.bottleneck[0]} · ${data.bottleneck[1]} pengajuan${oldest===null?'':' · tertua '+oldest+' hari'}`:'Paket PP dan pembayaran dalam akses Anda'},
 {key:'settlement' as const,icon:Clock3,sub:`${data.settlementCount} pengajuan · bagian dari Outstanding`,money:true},
 {key:'requested' as const,icon:ReceiptText,sub:`${data.rows.requested.length} pengajuan · ${data.requestedPids} ID Project · Bruto sebelum PPh`,money:true},
 {key:'documents' as const,icon:FileWarning,sub:`${data.paidMissing} sudah dibayar · scan bertanda tangan belum ada`}];
 const open=(key:ExecutiveMetric)=>setSelection(key);
 const card=(c:typeof cards[number])=><button type="button" className={`executive-card ${c.attention?'executive-attention':''}`} key={c.key} aria-label={`Lihat ${EXECUTIVE_LABELS[c.key]}`} onClick={()=>open(c.key)}><span className="executive-card-label">{EXECUTIVE_LABELS[c.key]}<c.icon size={17}/></span><strong>{c.money?money(data.values[c.key]):data.values[c.key]}</strong><span className="executive-card-bottom"><span>{c.sub}</span><ArrowUpRight size={15}/></span></button>;
 return <>
 <section className="executive-primary" aria-label="Ringkasan utama dashboard">{cards.map(card)}</section>
 <section className="executive-oversight" aria-label="Pengawasan dashboard">{oversight.map(card)}</section>
 <p className="executive-note">Outstanding mencakup pengajuan aktif yang masih diproses dan menunggu pelunasan; Draf, Revisi dan Cancel dikecualikan. Nominal pembayaran mengikuti kasbon tersimpan, termasuk kebijakan PPh historis. Nilai Pengajuan Pembayaran adalah tagihan bruto sebelum potongan PPh, termasuk pengajuan aktif dan yang sudah dibayar.</p>
 {selection&&<RowModal note={selection==='requested'?'Tagihan bruto sebelum potongan PPh dari pengajuan aktif dan yang sudah dibayar. Draf, Revisi, Ditolak dan paket Cancel dikecualikan. Nilai ini berbeda dari nominal transfer bersih.':undefined} key={selection} title={EXECUTIVE_LABELS[selection]} rows={data.rows[selection]} projects={selection==='projects'?data.matrix.plans.map(p=>({id:p.id,code:p.code,no:p.no,name:p.name,branch:p.branch,status:p.status,route:'/project-plan/'+p.id,timeline:'/project-plan/'+p.id+'/timeline'})):undefined} onClose={()=>setSelection(null)}/>}

 </>;
}

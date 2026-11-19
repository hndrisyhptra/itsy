import {useMemo,useState} from 'react';
import {Plus} from 'lucide-react';
import {type State,money,operational} from './domain';
import {Bapps,BappDetail,TermEditor} from './payments';
import {PaymentProjectPicker} from './payment-project-picker';
import {barpCurrent,requiresBarp} from './bapp-workflow';
import {WorkNextAction} from './work-next-action';
import {workDocumentRows} from './work-document-flow';
import {Badge,Button,Card,Empty,Link,Notice,PageTitle,Table,usePath} from './ui';

export function WorkDocuments({s,tab='barp'}:{s:State;tab?:'barp'|'bapp'}){
 const [query,setQuery]=useState(''),[page,setPage]=useState(1),indexed=useMemo(()=>tab==='barp'?workDocumentRows(s):[],[s,tab]);
 const rows=indexed.filter(({p,v,d})=>`${p.projectCode} ${p.no} ${p.name} ${v.no} ${d.mandor}`.toLowerCase().includes(query.toLowerCase())),pages=Math.max(1,Math.ceil(rows.length/20)),current=Math.min(page,pages);
 return <><PageTitle title="BA RP & BA PP" description="SPK Sah → Sahkan BA RP → Buat dan Sahkan BA PP → Kasbon & Pembayaran.">{operational(s)&&<Link to="/ba-rp/new" className="btn primary"><Plus size={15}/>Buat BA RP</Link>}</PageTitle><nav className="view-tabs" aria-label="Dokumen pekerjaan"><Link className={tab==='barp'?'active':''} to="/ba-rp">BA RP</Link><Link className={tab==='bapp'?'active':''} to="/ba-pp">BA PP</Link></nav>{tab==='bapp'?<Bapps s={s} shell={false}/>:<Card className="no-padding"><div className="filters"><input aria-label="Cari BA RP" placeholder="Cari ID Project, PP, SPK atau Mandor…" value={query} onChange={e=>{setQuery(e.target.value);setPage(1);}}/></div>{rows.length?<Table headers={['ID Project / PP / SPK','Mandor','Pengajuan','Nilai Rekonsiliasi','BA RP','BA PP','Langkah Berikutnya']}>{rows.slice((current-1)*20,current*20).map(({t,v,d,p,b,amount})=><tr key={t.id}><td><b>{p.projectCode}</b><small>{p.no}</small><small>{v.no}</small></td><td>{d.mandor}</td><td>{t.number===1&&t.type==='Final'?'Final':`Termin ${t.number} · ${t.type}`}</td><td className="numeric">{money(amount)}</td><td><Badge>{barpCurrent(t)?'Sah':requiresBarp(t)?'Draf':amount>0?'Tersimpan':'Draf'}</Badge></td><td><Badge>{b?.status??'Belum dibuat'}</Badge></td><td className="work-document-next-cell"><WorkNextAction s={s} t={t} b={b} amount={amount}/><Link className="text-link" to={'/ba-rp/'+t.id}>Buka BA RP</Link></td></tr>)}</Table>:<Empty title="BA RP belum tersedia" description="Pilih SPK Sah untuk mencatat volume aktual pekerjaan dan membuat BA RP."/>}<div className="table-foot"><span>{rows.length} BA RP · {current}/{pages}</span><div className="actions"><Button disabled={current===1} onClick={()=>setPage(current-1)}>Sebelumnya</Button><Button disabled={current===pages} onClick={()=>setPage(current+1)}>Berikutnya</Button></div></div></Card>}</>;
}
export function WorkDocumentEditor({s,id}:{s:State;id:string}){
 const path=usePath(),t=s.terms.find(t=>t.id===id),b=s.bapps.find(b=>b.termId===id),step=new URLSearchParams(path.split('?')[1]??'').get('step');
 if(!t)return <Empty title="BA RP tidak ditemukan"/>;
 if(step==='bapp'&&requiresBarp(t)&&!barpCurrent(t))return <><PageTitle title="Sahkan BA RP terlebih dahulu"/><Notice>BA PP dibuat setelah BA RP dan lampirannya Sah.</Notice><Link className="btn primary" to={'/ba-rp/'+id}>Buka BA RP</Link></>;
 if(step==='bapp')return b?<BappDetail s={s} id={b.id}/>:<><PageTitle title="Buat BA PP dari BA RP Tersimpan" back={'/ba-rp/'+id}/><Notice>BA PP memakai BA RP yang sudah tersimpan. Form BA RP yang sedang atau sudah melalui approval tetap terkunci. Pembuatan BA PP tidak mengubah isi BA RP, nilai maupun keputusan approval.</Notice><WorkNextAction s={s} t={t}/><Link className="text-link" to={'/termin/'+id}>Buka pembayaran tersimpan</Link></>;

 return <TermEditor s={s} id={id} stage="work"/>;
}
export function NewWorkDocument({s}:{s:State}){return <PaymentProjectPicker s={s} workDocuments/>;}

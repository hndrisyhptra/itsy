import { Fragment, useId, useMemo, useState } from 'react';
import { ChevronDown, ChevronRight, Eye, History, ArrowUpRight } from 'lucide-react';
import { type State, dateLabel } from './domain';
import { dashboardApprovalData, approvalSelectionRows, approvalRowsPage, type ApprovalMatrix, type ApprovalGroup, type ApprovalSelection, type ApprovalRow } from './dashboard-approval';
import { Card, Modal, Table, Button, Empty, Notice, navigate, DocumentPreview } from './ui';
import { ProjectBundleReview } from './project-bundle-review';
import { usesContractBundle } from './project-bundle';

function Counts({group,matrix,scope,onSelect}:{group:ApprovalGroup;matrix:ApprovalMatrix;scope:ApprovalSelection['scope'];onSelect:(s:ApprovalSelection)=>void}){
  return <>{[{key:undefined,label:matrix.kind==='project'?'Total PP':'Total Pengajuan'},...matrix.columns].map(c=><td className="numeric" key={c.key??'total'}><button type="button" className={`matrix-value approval-count ${c.key==='revision'?'is-revision':c.key==='approved'||c.key==='paid'?'is-complete':''}`} title={`Lihat ${c.label} · ${group.name}`} aria-label={`Lihat ${c.label} · ${group.name} · ${matrix.kind==='project'?'Paket PP':'Pembayaran'}`} onClick={()=>onSelect({kind:matrix.kind,scope,id:group.id,label:group.name,bucket:c.key})}>{c.key?group.counts[c.key]??0:group.rows.length}</button></td>)}</>;
}
export function ApprovalMatrixTable({matrix,onSelect}:{matrix:ApprovalMatrix;onSelect:(s:ApprovalSelection)=>void}){
  const [expanded,setExpanded]=useState<Record<string,boolean>>({}),id=useId();
  const title=matrix.kind==='project'?'Approval Paket PP':'Approval Pembayaran';
  return <Card title={title} subtitle="Angka menunjukkan posisi terkini. Klik Region untuk membuka Branch, klik angka untuk melihat daftar." className="no-padding dashboard-matrix dashboard-approval-matrix">
    <div className="table-scroll"><table><caption className="sr-only">{title} per Region dan Branch sesuai akses akun</caption><thead><tr><th scope="col">Region / Branch</th><th scope="col" className="numeric">{matrix.kind==='project'?'Total PP':'Total Pengajuan'}</th>{matrix.columns.map(c=><th scope="col" key={c.key} className="numeric">{c.label}</th>)}</tr></thead>
      {matrix.regions.map(region=>{const open=expanded[region.id]??true,bodyId=`${id}-${region.id}`;return <Fragment key={region.id}><tbody><tr className="matrix-region"><th scope="row"><button type="button" aria-expanded={open} aria-controls={bodyId} onClick={()=>setExpanded(old=>({...old,[region.id]:!open}))}>{open?<ChevronDown size={15}/>:<ChevronRight size={15}/>}<span>{region.name}<small>{region.branches.length} Branch</small></span></button></th><Counts group={region} matrix={matrix} scope="region" onSelect={onSelect}/></tr></tbody><tbody id={bodyId} hidden={!open}>{region.branches.map(b=><tr key={b.id}><th scope="row" className="matrix-branch">{b.name}</th><Counts group={b} matrix={matrix} scope="branch" onSelect={onSelect}/></tr>)}</tbody></Fragment>;})}
      {!matrix.regions.length&&<tbody><tr><td colSpan={matrix.columns.length+2}>Belum ada Branch dalam cakupan filter dan akses Anda.</td></tr></tbody>}
      <tfoot><tr><th scope="row">TOTAL</th><Counts group={matrix.total} matrix={matrix} scope="all" onSelect={onSelect}/></tr></tfoot></table></div>
    <div className="matrix-explanation"><p>{matrix.kind==='project'?'Satu PP dihitung sekali pada posisi aktif paket PP, BA KH dan SPK. Disetujui berarti paket sudah Sah pada alur paket; PP historis mengikuti status PP tersimpan.':'Satu pengajuan pembayaran dihitung sekali. Menunggu Pelunasan berarti proses approval/review selesai tetapi belum lunas. Sudah Dibayar mengikuti pembayaran yang sudah dicatat, termasuk pelunasan tanpa nomor APM.'} Revisi mencakup pengajuan ditolak atau dikembalikan.</p><p>Kolom approver menghitung dokumen yang sedang menunggu peran tersebut. Alur historis tetap mengikuti penugasan tersimpan dan dapat memiliki kolom tambahan.</p></div>
  </Card>;
}
export function ApprovalDetailModal({s,matrix,selection,onClose}:{s:State;matrix:ApprovalMatrix;selection:ApprovalSelection;onClose:()=>void}){
  const [query,setQuery]=useState(''),[page,setPage]=useState(1),[preview,setPreview]=useState('');
  const rows=useMemo(()=>approvalSelectionRows(matrix,selection),[matrix,selection]);
  const result=useMemo(()=>approvalRowsPage(rows,query,page),[rows,query,page]);
  const selected=preview?rows.find(r=>r.id===preview):undefined;
  const project=selected?s.projects.find(p=>p.id===selected.projectId):undefined;
  const term=selected&&matrix.kind==='payment'?s.terms.find(t=>t.id===selected.id):undefined;
  const spk=term?s.spks.find(v=>v.id===term.spkId):undefined,deal=spk?s.deals.find(d=>d.id===spk.dealId&&d.projectId===project?.id):undefined;
  if(preview){
    if(selected&&project&&matrix.kind==='project')return usesContractBundle(project)?<ProjectBundleReview key={project.id} s={s} p={project} modal onClose={()=>setPreview('')}/>:<DocumentPreview state={s} project={project} kind="Project Plan" onClose={()=>setPreview('')}/>;
    if(selected&&project&&term&&spk&&deal)return <DocumentPreview key={term.id} state={s} project={project} deal={deal} spk={spk} term={term} kind="BA Rekonsiliasi Pekerjaan – Termin" onClose={()=>setPreview('')}/>;
    return <Modal title="Dokumen tidak tersedia" onClose={()=>setPreview('')}><Notice>Data atau akses berubah. Kembali ke daftar untuk memeriksa posisi terbaru.</Notice><Button onClick={()=>setPreview('')}>Kembali ke daftar</Button></Modal>;
  }
  const label=selection.bucket?matrix.columns.find(c=>c.key===selection.bucket)?.label??'Status':matrix.kind==='project'?'Total PP':'Total Pengajuan';
  const go=(row:ApprovalRow,timeline=false)=>{onClose();navigate(timeline?row.timeline:row.route);};
  return <Modal title={`${matrix.kind==='project'?'Paket PP':'Pembayaran'} · ${label} · ${selection.label}`} onClose={onClose} wide><div className="dashboard-detail">
    <Notice>Daftar mengikuti akses akun dan posisi siklus pengajuan saat ini. Membuka detail atau preview tidak memberikan persetujuan.</Notice>
    <div className="search-field"><input aria-label="Cari daftar status approval" placeholder="Cari ID Project, nomor PP, LOP, Branch atau approver…" value={query} onChange={e=>{setQuery(e.target.value);setPage(1);}}/></div>
    {result.rows.length?<Table className="dashboard-approval-detail" headers={['ID Project / No. PP','LOP','Branch',...(matrix.kind==='payment'?['Pembayaran']:[]),'Posisi Saat Ini','Penanggung Jawab','Sejak','Aksi']}>
      {result.rows.map(row=><tr key={row.id}><td><b>{row.code||'Belum diisi'}</b><small>{row.no}</small></td><td>{row.name}</td><td>{row.branch}</td>{matrix.kind==='payment'&&<td>{row.payment}</td>}<td>{row.position}</td><td>{row.assignee||'—'}</td><td>{row.since?dateLabel(row.since):'Belum tercatat'}</td><td><div className="actions"><Button title="Buka detail dokumen" aria-label={`Detail ${row.no}${row.payment?' · '+row.payment:''}`} onClick={()=>go(row)}><ArrowUpRight size={14}/></Button><Button title="Lihat timeline PP dan pembayaran" aria-label={`Timeline ${row.no}${row.payment?' · '+row.payment:''}`} onClick={()=>go(row,true)}><History size={14}/></Button><Button title={matrix.kind==='project'?'Preview paket dokumen':'Preview BA RP'} aria-label={`Preview ${row.no}${row.payment?' · '+row.payment:''}`} onClick={()=>setPreview(row.id)}><Eye size={14}/></Button></div></td></tr>)}
    </Table>:<Empty title="Tidak ada data" description={query?'Tidak ada pengajuan yang sesuai pencarian.':'Belum ada dokumen pada posisi ini dalam cakupan yang dipilih.'}/>}
    <div className="table-foot"><span>{result.count} data · Halaman {result.current}/{result.pages}</span><div className="actions"><Button disabled={result.current===1} onClick={()=>setPage(result.current-1)}>Sebelumnya</Button><Button disabled={result.current===result.pages} onClick={()=>setPage(result.current+1)}>Berikutnya</Button></div></div>
  </div></Modal>;
}
export function DashboardApproval({s,branch='',unit='Konstruksi'}:{s:State;branch?:string;unit?:string}){
  const data=useMemo(()=>dashboardApprovalData(s,branch,unit),[s,branch,unit]);
  const [selection,setSelection]=useState<ApprovalSelection|null>(null);
  return <div className="dashboard-approval-stack"><ApprovalMatrixTable matrix={data.project} onSelect={setSelection}/><ApprovalMatrixTable matrix={data.payment} onSelect={setSelection}/>{selection&&<ApprovalDetailModal key={`${unit}:${branch}:${selection.kind}:${selection.scope}:${selection.id}:${selection.bucket??'all'}`} s={s} matrix={data[selection.kind]} selection={selection} onClose={()=>setSelection(null)}/>}</div>;
}

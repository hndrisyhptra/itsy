import { useMemo, useState } from 'react';
import { type DashboardMatrixData } from './dashboard-matrix';
import { MATRIX_LABELS, matrixDetailRows, matrixDetailPage, type MatrixSelection } from './dashboard-detail';
import { money } from './domain';
import { Modal, Table, Button, navigate, Badge, Empty, Notice } from './ui';

export function DashboardDetailModal({data,selection,onClose}:{data:DashboardMatrixData;selection:MatrixSelection;onClose:()=>void}){
  const [code,setCode]=useState(''),[query,setQuery]=useState(''),[page,setPage]=useState(1);
  const active=useMemo(()=>code?{...selection,metric:'plans' as const,code}:selection,[selection,code]);
  const rows=useMemo(()=>matrixDetailRows(data,active),[data,active]);
  const result=useMemo(()=>matrixDetailPage(rows,query,page),[rows,query,page]);
  const payment=active.metric==='realized',group=['projects','open','closed'].includes(active.metric);
  return <Modal title={`${MATRIX_LABELS[active.metric]} · ${selection.label}${code?' · '+code:''}`} onClose={onClose} wide>
    <div className="dashboard-detail">
      {code&&<Button onClick={()=>{setCode('');setQuery('');setPage(1);}}>Kembali ke ID Project</Button>}
      <Notice>{payment?'Pembayaran terealisasi yang membentuk angka pada tabel.':active.metric==='total'?'Sumber BOQ Plan tersimpan pada PP induk. PP lanjutan tidak dihitung ulang.':group?'Satu baris per ID Project unik. OPEN/CLOSE mengikuti seluruh PP dalam akses akun sebelum filter Branch.':'Seluruh PP tersimpan dalam cakupan yang dipilih, termasuk draf dan PP lanjutan.'}</Notice>
      <div className="search-field"><input aria-label="Cari rincian dashboard" placeholder="Cari ID Project, nomor PP/SPK, nama proyek, Branch atau APM…" value={query} onChange={e=>{setQuery(e.target.value);setPage(1);}}/></div>
      {result.rows.length?<Table className="dashboard-detail-table" headers={group?['ID Project','Nama Proyek','Branch','Status','Jumlah PP','Nilai Realisasi','Total Nilai','Aksi']:payment?['ID Project / PP','Nama Proyek','Branch','SPK','Termin','Nomor APM','Nilai Realisasi','Aksi']:['ID Project / PP','Nama Proyek','Branch','Status PP','Nilai Realisasi','Total Nilai BOQ','Aksi']}>
        {result.rows.map(r=><tr key={r.id}><td><b>{r.code||'Belum diisi'}</b>{!group&&<small>{r.no}</small>}</td><td>{r.name}</td><td>{r.branch}</td>{payment?<><td>{r.spkNo}</td><td>{r.term}</td><td>{r.apm||'Belum tercatat'}</td></>:<td><Badge>{r.status}</Badge></td>}{group&&<td className="numeric">{r.plans}</td>}<td className="numeric">{money(r.realized)}</td>{!payment&&<td className="numeric">{active.metric==='plans'&&r.parentId?<span title="Subalokasi PP induk; tidak menambah Total Nilai">Subalokasi</span>:money(r.total)}</td>}<td>{group?<Button onClick={()=>{setCode(r.code);setQuery('');setPage(1);}}>Lihat PP</Button>:<Button onClick={()=>{onClose();navigate(r.route!);}}>{payment?'Detail pembayaran':'Detail PP'}</Button>}</td></tr>)}
      </Table>:<Empty title="Tidak ada data" description={query?'Tidak ada rincian yang sesuai pencarian.':'Belum ada record yang membentuk angka ini dalam cakupan yang dipilih.'}/>}
      <div className="table-foot"><span>{result.count} dari {result.sourceCount} data · Halaman {result.current}/{result.pages}</span><div className="actions"><Button disabled={result.current===1} onClick={()=>setPage(result.current-1)}>Sebelumnya</Button><Button disabled={result.current===result.pages} onClick={()=>setPage(result.current+1)}>Berikutnya</Button></div></div>
    </div>
  </Modal>;
}

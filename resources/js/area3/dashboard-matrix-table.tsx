import { Fragment, useId, useMemo, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { type State,money } from './domain';
import { dashboardMatrix,type MatrixTotals } from './dashboard-matrix';
import { Card } from './ui';
import { DashboardDetailModal } from './dashboard-detail-modal';
import { MATRIX_LABELS, type MatrixMetric, type MatrixScope, type MatrixSelection } from './dashboard-detail';
function Values({value,scope,label,onSelect}:{value:MatrixTotals;scope:MatrixScope;label:string;onSelect:(s:MatrixSelection)=>void}){
  return <>{(['projects','open','closed','plans','realized','total'] as MatrixMetric[]).map(metric=><td key={metric} className="numeric"><button type="button" className="matrix-value" aria-label={`Lihat ${MATRIX_LABELS[metric]} · ${label}`} title={`Lihat rincian ${MATRIX_LABELS[metric]}`} onClick={()=>onSelect({metric,scope,label})}>{metric==='realized'||metric==='total'?money(value[metric]):value[metric]}</button></td>)}</>;
}
export function DashboardMatrix({s,branch=''}:{s:State;branch?:string}){
  const data=useMemo(()=>dashboardMatrix(s,branch),[s,branch]),id=useId();
  const [expanded,setExpanded]=useState<Record<string,boolean>>({}),[selection,setSelection]=useState<MatrixSelection|null>(null);
  return <Card title="Matriks Project per Region" subtitle="Klik Region untuk membuka Branch; klik angka untuk melihat rincian datanya." className="no-padding dashboard-matrix">
    <div className="table-scroll"><table><caption className="sr-only">Jumlah ID Project, status pembayaran final, jumlah PP, realisasi dan nilai BOQ Plan per Region dan Branch.</caption><thead><tr><th scope="col" rowSpan={2}>Branch</th><th scope="colgroup" colSpan={3}>ID Project</th><th className="numeric" scope="col" rowSpan={2}>Jumlah PP</th><th className="numeric" scope="col" rowSpan={2}>Nilai Realisasi</th><th className="numeric" scope="col" rowSpan={2}>Total Nilai</th></tr><tr>{['JMLH','OPEN','CLOSE'].map(label=><th scope="col" className="numeric" key={label}>{label}</th>)}</tr></thead>
    {data.regions.map(region=>{const open=expanded[region.id]??true,bodyId=`${id}-${region.id}`;return <Fragment key={region.id}><tbody><tr className="matrix-region"><th scope="row"><button aria-expanded={open} aria-controls={bodyId} onClick={()=>setExpanded(old=>({...old,[region.id]:!open}))}>{open?<ChevronDown size={15}/>:<ChevronRight size={15}/>}<span>{region.name}<small>{region.branches.length} Branch</small></span></button></th><Values value={region.totals} scope={{kind:'region',id:region.id}} label={region.name} onSelect={setSelection}/></tr></tbody><tbody id={bodyId} hidden={!open}>{region.branches.map(branch=><tr key={branch.id}><th scope="row" className="matrix-branch">{branch.name}</th><Values value={branch.totals} scope={{kind:'branch',id:branch.id}} label={branch.name} onSelect={setSelection}/></tr>)}</tbody></Fragment>;})}
    {!data.regions.length&&<tbody><tr><td colSpan={7}>Belum ada Branch dalam cakupan filter dan akses Anda.</td></tr></tbody>}
    <tfoot><tr><th scope="row">TOTAL · ID Project unik</th><Values value={data.totals} scope={{kind:'all'}} label={branch||'Semua Branch'} onSelect={setSelection}/></tr></tfoot></table></div>
    <div className="matrix-explanation"><p><b>CLOSE</b>: seluruh PP pada ID Project yang dapat diakses telah selesai pembayaran final. Filter Branch tidak mengabaikan PP lain yang masih terbuka dalam akses akun.</p><p>Realisasi memakai pembayaran tercatat. Total Nilai memakai BOQ Plan tersimpan; PP lanjutan tidak dijumlah ulang. ID Project yang sama di beberapa Branch dihitung sekali pada subtotal Region dan total keseluruhan.</p>{data.totals.missingCodes>0&&<p>{data.totals.missingCodes} PP belum memiliki ID Project dan belum dihitung dalam JMLH.</p>}</div>
    {selection&&<DashboardDetailModal key={`${branch}:${selection.metric}:${selection.label}`} data={data} selection={selection} onClose={()=>setSelection(null)}/> }
  </Card>;
}

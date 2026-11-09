import { Fragment, useId, useMemo, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { type State,money } from './domain';
import { dashboardMatrix,type MatrixTotals } from './dashboard-matrix';
import { Card } from './ui';
function Values({value}:{value:MatrixTotals}){return <><td className="numeric">{value.projects}</td><td className="numeric">{value.open}</td><td className="numeric">{value.closed}</td><td className="numeric">{value.plans}</td><td className="numeric">{money(value.realized)}</td><td className="numeric">{money(value.total)}</td></>;}
export function DashboardMatrix({s,branch=''}:{s:State;branch?:string}){
  const data=useMemo(()=>dashboardMatrix(s,branch),[s,branch]),id=useId();
  const [expanded,setExpanded]=useState<Record<string,boolean>>({});
  return <Card title="Matriks Project per Region" subtitle="Klik Region untuk membuka atau menutup daftar Branch." className="no-padding dashboard-matrix">
    <div className="table-scroll"><table><caption className="sr-only">Jumlah ID Project, status pembayaran final, jumlah PP, realisasi dan nilai BOQ Plan per Region dan Branch.</caption><thead><tr><th scope="col" rowSpan={2}>Branch</th><th scope="colgroup" colSpan={3}>ID Project</th><th className="numeric" scope="col" rowSpan={2}>Jumlah PP</th><th className="numeric" scope="col" rowSpan={2}>Nilai Realisasi</th><th className="numeric" scope="col" rowSpan={2}>Total Nilai</th></tr><tr>{['JMLH','OPEN','CLOSE'].map(label=><th scope="col" className="numeric" key={label}>{label}</th>)}</tr></thead>
    {data.regions.map(region=>{const open=expanded[region.id]??true,bodyId=`${id}-${region.id}`;return <Fragment key={region.id}><tbody><tr className="matrix-region"><th scope="row"><button aria-expanded={open} aria-controls={bodyId} onClick={()=>setExpanded(old=>({...old,[region.id]:!open}))}>{open?<ChevronDown size={15}/>:<ChevronRight size={15}/>}<span>{region.name}<small>{region.branches.length} Branch</small></span></button></th><Values value={region.totals}/></tr></tbody><tbody id={bodyId} hidden={!open}>{region.branches.map(branch=><tr key={branch.id}><th scope="row" className="matrix-branch">{branch.name}</th><Values value={branch.totals}/></tr>)}</tbody></Fragment>;})}
    {!data.regions.length&&<tbody><tr><td colSpan={7}>Belum ada Branch dalam cakupan filter dan akses Anda.</td></tr></tbody>}
    <tfoot><tr><th scope="row">TOTAL · ID Project unik</th><Values value={data.totals}/></tr></tfoot></table></div>
    <div className="matrix-explanation"><p><b>CLOSE</b>: seluruh PP pada ID Project yang dapat diakses telah selesai pembayaran final. Filter Branch tidak mengabaikan PP lain yang masih terbuka dalam akses akun.</p><p>Realisasi memakai pembayaran tercatat. Total Nilai memakai BOQ Plan tersimpan; PP lanjutan tidak dijumlah ulang. ID Project yang sama di beberapa Branch dihitung sekali pada subtotal Region dan total keseluruhan.</p>{data.totals.missingCodes>0&&<p>{data.totals.missingCodes} PP belum memiliki ID Project dan belum dihitung dalam JMLH.</p>}</div>
  </Card>;
}

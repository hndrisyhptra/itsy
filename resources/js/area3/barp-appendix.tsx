import type { CSSProperties } from 'react';
import type { State,Project,Deal,Spk,Term } from './domain';
import { barpAppendixData,BARP_APPENDIX_LEFT,BARP_APPENDIX_RIGHT } from './barp-appendix-content';
export function BarpAppendix({state,project,deal,spk,term}:{state:State;project:Project;deal:Deal;spk:Spk;term:Term}){
  const data=barpAppendixData(state,project,deal,spk,term);
  return <ReconciliationAppendix data={data}/>;
}
export function ReconciliationAppendix({data,bapp=false}:{data:ReturnType<typeof barpAppendixData>;bapp?:boolean}){
  return <article className={`document barp-appendix${bapp?' bapp-appendix':''}`}><header><h2>{data.title}</h2><p>PEKERJAAN: {data.job}{data.jobSuffix}</p><p>WITEL: {data.witel}</p></header>{data.status&&<p className="barp-review">{data.status}</p>}
    <div className="barp-appendix-scroll"><table><caption>Harga satuan dan nilai dalam Rupiah (Rp)</caption><colgroup>{data.widths.map((width,i)=><col key={i} style={{width:`${width}%`}}/>)}</colgroup><thead><tr>{BARP_APPENDIX_LEFT.map(h=><th key={h} rowSpan={2} scope="col">{h}</th>)}<th colSpan={2} scope="colgroup">HARGA SATUAN</th>{data.projectHeaders.map((label,i)=><th key={i} scope="colgroup">{label}</th>)}{BARP_APPENDIX_RIGHT.map(h=><th key={h} rowSpan={2} scope="col">{h}</th>)}</tr><tr><th scope="col">Material</th><th scope="col">Jasa</th>{data.projectHeaders.map((_,i)=><th key={i} scope="col">VOL</th>)}</tr></thead><tbody>{data.rows.map(row=><tr key={row.id}>{row.cells.map((cell,i)=><td key={i} className={i>=4?'numeric':i===0||i===3?'center':''}>{cell}{i===6&&row.component&&<small className="barp-appendix-component">{row.component}</small>}</td>)}</tr>)}{!data.rows.length&&<tr><td colSpan={data.widths.length}>Belum ada komponen pekerjaan.</td></tr>}</tbody><tfoot>{data.summaryRows.map(row=><tr key={row.label}><th colSpan={4} scope="row">{row.label}</th>{row.values.map((value,i)=><td key={i} className="numeric">{value}</td>)}</tr>)}{data.recap.map(row=><tr key={row.label}><th colSpan={data.widths.length-1} scope="row">{row.label}</th><td className="numeric">{row.value}</td></tr>)}</tfoot></table></div>
    <p className="barp-appendix-note">{data.note}{data.splitVolumes&&' Komponen Material dan Jasa dengan volume berbeda ditampilkan pada baris terpisah.'}</p>
    <section className="barp-appendix-signatures" style={{'--barp-signature-columns':data.signatures.length} as CSSProperties} aria-label={bapp?'Penandatangan lampiran BA PP':'Penandatangan lampiran rekonsiliasi'}>{data.signatures.map(signer=><div key={signer.job}><h3>{signer.job}</h3><div className="barp-appendix-wet-signature" aria-label="Area TTD basah dokumen asli"/><strong>{signer.name}</strong>{signer.nik&&<p>NIK: {signer.nik}</p>}</div>)}</section>
  </article>;
}

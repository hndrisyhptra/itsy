import type { State,Project,Deal,Spk,Term } from './domain';
import { barpAppendixData,BARP_APPENDIX_LEFT,BARP_APPENDIX_RIGHT } from './barp-appendix-content';
export function BarpAppendix({state,project,deal,spk,term}:{state:State;project:Project;deal:Deal;spk:Spk;term:Term}){
  const data=barpAppendixData(state,project,deal,spk,term);
  return <article className="document barp-appendix"><header><h2>{data.title}</h2><p>PEKERJAAN: {data.job}{data.jobSuffix}</p><p>WITEL: {data.witel}</p></header>{data.status&&<p className="barp-review">{data.status}</p>}
    <div className="barp-appendix-scroll"><table><colgroup>{data.widths.map((width,i)=><col key={i} style={{width:`${width}%`}}/>)}</colgroup><thead><tr>{BARP_APPENDIX_LEFT.map(h=><th key={h} rowSpan={2} scope="col">{h}</th>)}<th colSpan={data.actualHeaders.length} scope="colgroup">{data.actualHeading}</th>{BARP_APPENDIX_RIGHT.map(h=><th key={h} rowSpan={2} scope="col">{h}</th>)}</tr><tr>{data.actualHeaders.map(label=><th key={label} scope="col">{label}</th>)}</tr></thead><tbody>{data.rows.map(row=><tr key={row.id}>{row.cells.map((cell,i)=><td key={i} className={i>=4?'numeric':i===0||i===3?'center':''}>{cell}</td>)}</tr>)}{!data.rows.length&&<tr><td colSpan={data.widths.length}>Belum ada komponen pekerjaan.</td></tr>}</tbody><tfoot>{data.recap.map(row=><tr key={row.label}><th colSpan={data.widths.length-1} scope="row">{row.label}</th><td className="numeric">{row.value}</td></tr>)}</tfoot></table></div>
    <p className="barp-appendix-note">{data.note}</p>
    <section className="barp-appendix-signatures" aria-label="Penandatangan lampiran rekonsiliasi">{data.signatures.map(signer=><div key={signer.job}><h3>{signer.job}</h3><div className="barp-appendix-wet-signature" aria-label="Area TTD basah dokumen asli"/><strong>{signer.name}</strong>{signer.nik&&<p>NIK: {signer.nik}</p>}</div>)}</section>
  </article>;
}

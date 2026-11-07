import type { Project, Deal, Spk } from './domain';
import { SPK_TEXT, spkDocumentData } from './spk-content';
import { SPK_LOGO_URL } from './spk-assets';
export { SPK_LOGO_URL } from './spk-assets';

export function SpkDocument({project,deal,spk}:{project:Project;deal:Deal;spk:Spk}){
  const data=spkDocumentData(project,deal,spk);
  return <article className={`document spk-document${data.compact?' spk-letter-compact':''}`}>
    <header className="spk-letter-brand"><img src={SPK_LOGO_URL} alt="Telkom Akses"/></header>
    <div className="spk-letter-body">
      <div className="spk-letter-title"><h2>{SPK_TEXT.title}</h2><p>Nomor : {data.number}</p></div>
      <div className="spk-letter-addressee"><p>{data.placeDate}</p><p>Kepada Yth :<br/>{data.mandor}<br/>Mandor Project<br/><span className="spk-multiline">{data.address}</span><br/>Di Tempat</p></div>
      <p className="spk-letter-subject">Perihal : Perintah Mulai Kerja Pekerjaan <strong>{data.job}</strong> Area <strong>{data.branch}</strong>.</p>
      <p className="spk-letter-opening">{data.opening}</p>
      <div className="spk-letter-table-wrap"><table className="spk-letter-table"><colgroup><col style={{width:'8.37%'}}/><col style={{width:'52.9%'}}/><col style={{width:'12.62%'}}/><col style={{width:'12.91%'}}/><col style={{width:'13.2%'}}/></colgroup><thead><tr><th rowSpan={2} scope="col">NO</th><th rowSpan={2} scope="col">NAMA CLUSTER</th><th colSpan={3} scope="colgroup">NILAI</th></tr><tr><th scope="col">MATERIAL</th><th scope="col">JASA</th><th scope="col">TOTAL</th></tr></thead><tbody><tr><td>1</td><td>{data.job}</td><td className="numeric">{data.materialText}</td><td className="numeric">{data.serviceText}</td><td className="numeric">{data.totalText}</td></tr></tbody></table></div>
      <div className="spk-letter-lower"><p>{SPK_TEXT.coordination}<strong>{data.supervisor}</strong> Nomor Handphone <strong>{data.phone}</strong></p><p>{SPK_TEXT.safety}</p></div>
      <section className="spk-letter-signature" aria-label="Penandatangan Telkom Akses sesuai SPK"><strong>PT TELKOM AKSES</strong><div className="spk-letter-signature-space" aria-label="Area tanda tangan basah dokumen asli"/><strong className="spk-signer-name">{data.signer}</strong><strong>{data.position}</strong><strong>NIK: {data.nik}</strong></section>
    </div>
  </article>;
}

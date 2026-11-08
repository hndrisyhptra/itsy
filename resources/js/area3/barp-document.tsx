import type { Project,Deal,Spk,Term } from './domain';
import { BARP_TEXT,barpDocumentData } from './barp-content';
export function BarpDocument({project,deal,spk,term}:{project:Project;deal:Deal;spk:Spk;term:Term}){
  const v=barpDocumentData(project,deal,spk,term);
  const categories=[['1',v.stageScope,v.materialText,v.serviceText,v.totalText],['2','Pekerjaan Tambahan :',v.zeroMaterial,v.zeroService,'0'],['3','Pekerjaan Kurangan :',v.zeroMaterial,v.zeroService,'0'],['4','Harga Berdasarkan Rekonsiliasi',v.materialText,v.serviceText,v.totalText]];
  return <article className="document barp-document">
    <h2>{v.title}</h2>{v.status&&<p className="barp-review">{v.status}</p>}
    <dl className="barp-meta"><dt>Nama Proyek</dt><dd>{v.job}</dd><dt>Jenis Pekerjaan</dt><dd>SWAKELOLA MANDORAN</dd><dt>Nama</dt><dd>{v.mandor}</dd></dl>
    <p>{v.opening}</p><h3>1. Obyek Rekonsiliasi</h3><p>Lingkup Rekonsiliasi Meliputi :</p>
    <table><thead><tr><th>NO</th><th>LOKASI</th><th>STO</th><th>HOMEPASS</th></tr></thead><tbody><tr><td>1</td><td>{v.location}</td><td>{v.sto}</td><td className="numeric">{v.homepass}</td></tr></tbody></table>
    <h3>2. Hasil Rekonsiliasi</h3><h4>a. Lingkup Pekerjaan (SOW)</h4><p>{BARP_TEXT.sow}</p><h4>b. Harga Borongan</h4><p>{v.price}</p>
    <table className="barp-price"><colgroup><col style={{width:'7%'}}/><col style={{width:'68%'}}/><col style={{width:'25%'}}/></colgroup><thead><tr><th>NO</th><th>DESKRIPSI</th><th>Rp</th></tr></thead><tbody>{categories.flatMap(([no,label,m,j,total])=>[
      <tr key={no}><td>{no}</td><td colSpan={2}><strong>{label}</strong></td></tr>,
      ...[['Material',m],['Jasa',j],['Total Nilai',total]].map(([label,value])=><tr key={`${no}-${label}`}><td/><td>{label}</td><td className="numeric">{value}</td></tr>)
    ])}</tbody></table>
    <h3>3. {v.reconHeading}</h3><p>{v.reconText}</p>
    <table className="barp-recap"><thead><tr><th rowSpan={2}>NO</th><th rowSpan={2}>LOKASI</th><th rowSpan={2}>STO</th><th colSpan={3}>HARGA</th></tr><tr><th>MATERIAL</th><th>JASA</th><th>JUMLAH</th></tr></thead><tbody><tr><td>1</td><td>{v.location}</td><td>{v.sto}</td><td className="numeric">{v.materialText}</td><td className="numeric">{v.serviceText}</td><td className="numeric">{v.totalText}</td></tr><tr><td/><td colSpan={2}><strong>TOTAL</strong></td><td className="numeric">{v.materialText}</td><td className="numeric">{v.serviceText}</td><td className="numeric"><strong>{v.totalText}</strong></td></tr></tbody></table>
    <h3>4. Keterangan Lainnya</h3><p>{BARP_TEXT.note}</p><p>{BARP_TEXT.closing}</p>
    <section className="barp-signatures"><p>{v.placeDate}</p><table className="barp-signature-pair"><tbody><tr><th>PT TELKOM AKSES</th><th>MANDOR</th></tr><tr><td><div className="barp-signature-space" aria-label="Area TTD basah dokumen asli"/></td><td><div className="barp-signature-space" aria-label="Area TTD basah dokumen asli"/></td></tr><tr><td><strong>{v.waspang}</strong></td><td><strong>{v.mandor}</strong></td></tr><tr><td><strong>WASPANG PROJECT</strong></td><td>KETUA MANDOR</td></tr></tbody></table><div className="barp-head"><strong>Mengetahui / Menyetujui<br/>PT TELKOM AKSES</strong><div className="barp-signature-space" aria-label="Area TTD basah dokumen asli"/><strong>{v.head}</strong><br/>HEAD OF PMO AREA JAWA BALI{v.headNik&&<><br/>{v.headNik}</>}</div></section>
  </article>;
}

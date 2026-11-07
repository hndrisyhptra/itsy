import React from 'react';
import type { State, Project, Deal, Spk } from './domain';
import { spkAppendixData } from './spk-appendix-content';

export function SpkAppendixTable({ state, project, deal }: { state: State; project: Project; deal: Deal }) {
  const data = spkAppendixData(state, project, deal);
  return <div className="spk-appendix-grid">
    <div className="table-scroll spk-appendix-scroll" role="region" aria-label="Tabel lampiran SPK" tabIndex={0}>
      <table className="spk-appendix-table">
        <caption>Harga satuan dan nilai dalam Rupiah (Rp)</caption>
        <colgroup>{['no', 'code', 'description', 'unit', 'price', 'price', 'project', 'volume', 'amount', 'amount', 'total'].map((col, i) => <col key={i} className={`spk-appendix-col-${col}`}/>)}</colgroup>
        <thead>
          <tr><th rowSpan={2} scope="col">NO</th><th rowSpan={2} scope="col">DESIGNATOR</th><th rowSpan={2} scope="col">URAIAN</th><th rowSpan={2} scope="col">SATUAN</th><th colSpan={2} scope="colgroup">HARGA SATUAN</th><th scope="colgroup"><span className="spk-appendix-project">{project.name || '…'}</span></th><th rowSpan={2} scope="col">TOTAL VOL</th><th rowSpan={2} scope="col">MATERIAL</th><th rowSpan={2} scope="col">JASA</th><th rowSpan={2} scope="col">JUMLAH</th></tr>
          <tr><th scope="col">Material</th><th scope="col">Jasa</th><th scope="col">VOL</th></tr>
        </thead>
        <tbody>{data.rows.map(row => <tr key={row.id}>{row.cells.map((cell, i) => <td key={i} className={i >= 4 ? 'numeric' : i === 0 || i === 3 ? 'spk-appendix-center' : undefined}>{cell}{i === 6 && row.component && <small className="spk-appendix-component">{row.component}</small>}</td>)}</tr>)}{!data.rows.length && <tr><td colSpan={11}>Belum ada komponen pekerjaan yang dipilih.</td></tr>}</tbody>
        <tfoot>
          <tr><th colSpan={4} scope="row">JUMLAH MATERIAL</th><td/><td/><td className="numeric">{data.materialText}</td><td className="numeric">{data.materialText}</td><td className="numeric">{data.materialText}</td><td/><td/></tr>
          <tr><th colSpan={4} scope="row">JUMLAH JASA</th><td/><td/><td className="numeric">{data.serviceText}</td><td className="numeric">{data.serviceText}</td><td/><td className="numeric">{data.serviceText}</td><td/></tr>
          <tr><th colSpan={4} scope="row">JUMLAH HARGA</th><td/><td/><td className="numeric">{data.totalText}</td><td className="numeric">{data.totalText}</td><td/><td/><td className="numeric">{data.totalText}</td></tr>
        </tfoot>
      </table>
    </div>
    {data.splitVolumes && <p className="spk-appendix-note">Komponen Material dan Jasa dengan volume berbeda ditampilkan pada baris terpisah.</p>}
  </div>;
}

export function SpkAppendix({ state, project, deal, spk }: { state: State; project: Project; deal: Deal; spk: Spk }) {
  return <article className="document spk-appendix">
    <header className="spk-appendix-heading"><h2>LAMPIRAN SURAT PERINTAH KERJA</h2><dl><dt>PEKERJAAN</dt><dd>{project.name || '…'}</dd><dt>WITEL</dt><dd>{deal.witel || '…'}</dd></dl></header>
    <SpkAppendixTable state={state} project={project} deal={deal}/>
    <section className="spk-appendix-signature" aria-label="Penandatangan Telkom Akses sesuai SPK"><h3>PT TELKOM AKSES</h3><div className="spk-appendix-wet-signature" aria-label="Ruang tanda tangan basah penandatangan SPK"/><strong>{spk.signer || '…'}</strong><p>{spk.position?.toLocaleUpperCase('id-ID') || '…'}</p><p>NIK: {spk.signerNik || 'Belum tersedia'}</p></section>
  </article>;
}

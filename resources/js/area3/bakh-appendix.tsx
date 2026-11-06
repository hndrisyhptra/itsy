import React from 'react';
import { type State, type Project, type Deal } from './domain';
import { bakhAppendixRows, bakhSignatures, BAKH_TEXT } from './bakh-content';

/** Read-only attachment: negotiation and agreement unit prices, never volume totals. */
export function BakhAppendix({ state, project, deal }: { state: State; project: Project; deal: Deal }) {
  const rows = bakhAppendixRows(state, project, deal);

  return <article className="document bakh-appendix">
    <header className="bakh-appendix-heading">
      <h2>{BAKH_TEXT.appendixTitle}</h2>
      <p>PEKERJAAN: {project.name}</p>
    </header>
    <div className="table-scroll bakh-appendix-scroll" role="region" aria-label="Tabel lampiran BA KH" tabIndex={0}>
      <table className="bakh-appendix-table">
        <caption>Harga satuan (Rp)</caption>
        <colgroup><col className="bakh-col-no"/><col className="bakh-col-code"/><col className="bakh-col-description"/><col className="bakh-col-unit"/>{Array.from({ length: 6 }, (_, i) => <col key={i} className="bakh-col-price"/>)}</colgroup>
        <thead>
          <tr><th rowSpan={3} scope="col">NO</th><th rowSpan={3} scope="col">DESIGNATOR</th><th rowSpan={3} scope="col">URAIAN</th><th rowSpan={3} scope="col">SATUAN</th><th colSpan={4} scope="colgroup">NEGOSIASI</th><th colSpan={2} rowSpan={2} scope="colgroup">KESEPAKATAN</th></tr>
          <tr><th colSpan={2} scope="colgroup">MANDOR</th><th colSpan={2} scope="colgroup">TELKOM AKSES</th></tr>
          <tr>{['Mandor', 'Telkom Akses', 'Kesepakatan'].flatMap(group => ['Material', 'Jasa'].map(component => <th key={`${group}-${component}`} scope="col" aria-label={`${group} ${component}`}>{component}</th>))}</tr>
        </thead>
        <tbody>{rows.map(row => <tr key={row.id}>{row.cells.map((cell, i) => <td key={i} className={i >= 4 ? 'numeric' : i === 0 || i === 3 ? 'bakh-cell-center' : undefined}>{cell}</td>)}</tr>)}{!rows.length && <tr><td colSpan={10}>Belum ada komponen pekerjaan yang dipilih.</td></tr>}</tbody>
      </table>
    </div>
    <div className="bakh-appendix-signatures">
      {bakhSignatures(deal, true).map((signer, i) => <section key={i} aria-label={i === 0 ? 'Penandatangan Telkom Akses' : 'Penandatangan Mandor'}><h3>{signer.title}</h3><div className="bakh-wet-signature" aria-label={i === 0 ? 'Ruang tanda tangan basah Head of PMO' : 'Ruang tanda tangan basah Mandor'}/><strong>{signer.name}</strong><p>{signer.job}</p>{signer.nik && <p>{signer.nik}</p>}</section>)}
    </div>
  </article>;
}

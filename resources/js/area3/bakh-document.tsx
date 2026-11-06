import React from 'react';
import { type Project, type Deal } from './domain';

import { bakhDocumentData, BAKH_TEXT, type BakhRun } from './bakh-content';
export { bakhDocumentDate } from './bakh-content';

function rich(runs: BakhRun[]) { return runs.map((run, i) => run.bold ? <strong key={i}>{run.text}</strong> : <React.Fragment key={i}>{run.text}</React.Fragment>); }

export function BakhDocument({ project, deal }: { project: Project; deal: Deal }) {
  const data = bakhDocumentData(project, deal);
  const head = deal.signerSource === 'pp-head-pmo';
  return <article className="document bakh-document">
    <header className="bakh-document-heading"><h2>{BAKH_TEXT.title}<br/><span>{BAKH_TEXT.subtitle}</span></h2><p>NOMOR : {data.number}</p></header>
    <dl className="bakh-document-meta">{data.metadata.map(([label, value]) => <React.Fragment key={label}><dt>{label}</dt><dd>{value}</dd></React.Fragment>)}</dl>
    <p>{rich(data.opening)}</p>
    <ol className="bakh-document-clauses">
      <li><p>{BAKH_TEXT.result}</p><dl className="bakh-document-prices"><dt>Harga Penawaran</dt><dd>{BAKH_TEXT.price} <em>{BAKH_TEXT.tax}</em></dd><dt>Harga Kesepakatan</dt><dd>{BAKH_TEXT.price} <em>{BAKH_TEXT.tax}</em></dd></dl></li>
      <li>{BAKH_TEXT.payment}</li>
      <li>{rich(data.team)}</li>
    </ol>
    <p>{BAKH_TEXT.closing}</p>
    <p className="bakh-document-place">{data.place}</p>
    <div className="bakh-document-signatures">
      {data.signatures.map((signer, i) => <section key={i} aria-label={i === 0 ? head ? 'Head of PMO · Telkom Akses' : 'Penandatangan Telkom Akses' : 'Penandatangan Mandor'}><h3>{signer.title}</h3><div className="bakh-document-signature-space" aria-label="Area tanda tangan dokumen asli"/><strong>{signer.name}</strong><p>{signer.job}</p>{signer.nik && <p>{signer.nik}</p>}</section>)}
    </div>
  </article>;
}

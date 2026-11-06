import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { BakhDocument, bakhDocumentDate } from '../../resources/js/area3/bakh-document';
import { DocumentPreview } from '../../resources/js/area3/ui';
import { seed } from '../../resources/js/area3/seed';

function fixture() {
  const s = seed('C'), p = s.projects[0], d = structuredClone(s.deals[0]);
  p.name = 'Pekerjaan Preview QA';
  Object.assign(d, { no: '0088-01/LG.000/TA-03R546/11-2026', date: '2026-11-06', location: 'Lokasi dari form QA', witel: 'Witel dari form QA', place: 'Tempat dari form QA', fo: '2 tim (8 orang)', jointer: '1 tim (2 orang)', start: '2026-11-30', end: '2026-12-13', signer: 'Head Tersimpan QA', signerRole: 'Head of PMO', signerSource: 'pp-head-pmo', signerNik: 'QA-009', mandor: 'Mandor dari form QA' });
  return { s, p, d };
}

test('calendar date prose is derived from the form date with correct words and no timezone rollover', () => {
  assert.deepEqual(bakhDocumentDate('2026-11-06'), { weekday: 'Jumat', dayWords: 'Enam', month: 'November', yearWords: 'Dua Ribu Dua Puluh Enam', full: '6 November 2026' });
  assert.equal(bakhDocumentDate('2024-02-29')?.dayWords, 'Dua Puluh Sembilan');
  assert.equal(bakhDocumentDate('2000-01-01')?.yearWords, 'Dua Ribu');
  assert.equal(bakhDocumentDate('1999-12-31')?.yearWords, 'Seribu Sembilan Ratus Sembilan Puluh Sembilan');
  for (const value of [undefined, '', '2026-02-29', '2026-04-31', '2026-13-01', '0000-01-01', '06/11/2026', '2026-11-06T00:00:00Z']) assert.equal(bakhDocumentDate(value), null);
});

test('Word narrative preview uses every document-form value, three clauses and private saved Head identity without sample data or writes', () => {
  const { s, p, d } = fixture(), before = JSON.stringify({ s, p, d });
  const html = renderToStaticMarkup(createElement(BakhDocument, { project: p, deal: d }));
  for (const text of [p.name, d.no, d.location, d.witel, d.place, d.fo, d.jointer, d.signer, d.signerNik, d.mandor, '30 November 2026', '13 Desember 2026', 'Jumat', 'Enam', 'Dua Ribu Dua Puluh Enam']) assert.ok(typeof text === 'string' && html.includes(text), String(text));
  assert.match(html, /BERITA ACARA<br\/><span>NEGOSIASI KESEPAKATAN HARGA/);
  assert.equal((html.match(/<li>/g) ?? []).length, 3);
  assert.equal((html.match(/Belum Termasuk Pajak Penghasilan/g) ?? []).length, 2);
  assert.match(html, /Waspang TA dan Pelaksana Pekerjaan/); assert.match(html, /POW terlampir/);
  assert.equal((html.match(/class="bakh-document-signature-space"[^>]*><\/div>/g) ?? []).length, 2);
  assert.doesNotMatch(html, /HENDRA|HILARIUS|PROJECT JPP|FLORES|September 2024|<img|<svg|APPROVED|<table|Jumlah Kesepakatan/);
  assert.equal(JSON.stringify({ s, p, d }), before);
});

test('blank or invalid form data stays unfilled without inventing dates, people or Head roles for historical documents', () => {
  const { p, d } = fixture(); Object.assign(d, { date: '2026-02-30', start: '', end: '', fo: '', jointer: '', signerNik: '', signer: 'Penandatangan historis QA', signerSource: undefined, signerRole: undefined });
  const html = renderToStaticMarkup(createElement(BakhDocument, { project: p, deal: d }));
  assert.match(html, /tanggal <strong>…<\/strong>/); assert.match(html, /jumlah tim FO <strong>…<\/strong> dan jointer <strong>…<\/strong>/);
  assert.match(html, /Penandatangan historis QA/); assert.match(html, /Penandatangan sesuai BA KH/);
  assert.doesNotMatch(html, /HEAD OF PMO|QA-009|Invalid Date|undefined|NaN/);
});

test('BA KH action opens the portrait narrative while separate attachment retains its own layout', () => {
  const { s, p, d } = fixture();
  const html = renderToStaticMarkup(createElement(DocumentPreview, { state: s, project: p, deal: d, kind: 'BA Kesepakatan Harga', onClose: () => {} }));
  assert.match(html, /BA KH sesuai template · A4 vertikal/); assert.match(html, /class="document bakh-document"/);
  assert.doesNotMatch(html, /<table|Status bukti dokumen|Jumlah Kesepakatan/);
  const formOnly = renderToStaticMarkup(createElement(DocumentPreview, { state: { ...s, projects: [], khs: [] }, project: { ...p, rootId: 'unloaded-root' }, deal: d, kind: 'BA Kesepakatan Harga', onClose: () => {} }));
  assert.ok(formOnly.includes(p.name), 'main BA KH uses form data without looking up root BOQ or KHS');
  const appendix = renderToStaticMarkup(createElement(DocumentPreview, { state: s, project: p, deal: d, kind: 'Lampiran Negosiasi Harga', onClose: () => {} }));
  assert.match(appendix, /A4 horizontal/); assert.match(appendix, /bakh-appendix-table/);
});

test('form values are escaped as text in preview rather than interpreted as markup', () => {
  const { p, d } = fixture(); p.name = '<script>QA</script>'; d.fo = '<img src=x onerror=QA>'; d.signer = 'Head & QA';
  const html = renderToStaticMarkup(createElement(BakhDocument, { project: p, deal: d }));
  assert.match(html, /&lt;script&gt;QA&lt;\/script&gt;/); assert.match(html, /&lt;img src=x onerror=QA&gt;/); assert.match(html, /Head &amp; QA/);
  assert.doesNotMatch(html, /<script|<img/);
});

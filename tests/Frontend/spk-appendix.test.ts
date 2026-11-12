import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { dealTotal } from '../../resources/js/area3/domain';
import { spkDocumentData } from '../../resources/js/area3/spk-content';
import { spkAppendixData } from '../../resources/js/area3/spk-appendix-content';
import { SpkAppendix } from '../../resources/js/area3/spk-appendix';
import { DocumentPreview } from '../../resources/js/area3/ui';
import { SpkEditor } from '../../resources/js/area3/contracts';

function fixture() {
  const s = seed('D'), p = s.projects[0], d = s.deals[0], spk = s.spks[0];
  const source = p.boq[0], item = s.khs.find(k => k.id === source.khsId)!;
  p.name = 'Proyek Lampiran <QA> & Area';
  item.code = 'SPK-QA'; item.name = 'Uraian pekerjaan QA'; item.unit = 'Meter';
  item.m = 999999; item.s = 888888;
  d.rows = [{ ...d.rows[0], rowId: source.id, m: true, s: true, qm: 12.125, qs: 12.125, pm: 1000, ps: 2000 }];
  d.witel = 'Witel tersimpan'; d.signer = 'Head BA KH berbeda';
  spk.signer = 'Penandatangan SPK tersimpan'; spk.position = 'Jabatan SPK tersimpan';
  return { s, p, d, spk };
}

test('SPK appendix follows the reference grid with project/Vol, separate designator, unit prices, amounts and three totals', () => {
  const { s, p, d, spk } = fixture(), before = JSON.stringify(s);
  const data = spkAppendixData(s, p, d);
  assert.deepEqual(data.rows[0].cells, ['1', 'SPK-QA', 'Uraian pekerjaan QA', 'Meter', '1.000', '2.000', '12,125', '12,125', '12.125', '24.250', '36.375']);
  const html = renderToStaticMarkup(createElement(SpkAppendix, { state: s, project: p, deal: d, spk }));
  assert.match(html, /LAMPIRAN SURAT PERINTAH KERJA/);
  assert.match(html, /<th scope="colgroup"><span class="spk-appendix-project">Proyek Lampiran &lt;QA&gt; &amp; Area/);
  assert.match(html, />VOL<\/th>/); assert.match(html, />TOTAL VOL<\/th>/);
  assert.match(html, /colSpan="2" scope="colgroup">HARGA SATUAN/);
  assert.equal((html.match(/<col class=/g) ?? []).length, 11);
  assert.equal((html.match(/<tfoot>[\s\S]*?<\/tfoot>/g) ?? []).length, 1);
  assert.match(html, /colSpan="4" scope="row">JUMLAH MATERIAL<\/th><td><\/td><td><\/td><td class="numeric">12\.125<\/td><td class="numeric">12\.125<\/td><td class="numeric">12\.125<\/td>/);
  for (const label of ['JUMLAH MATERIAL', 'JUMLAH JASA', 'JUMLAH HARGA', 'Witel tersimpan']) assert.ok(html.includes(label));
  assert.doesNotMatch(html, /10761965|RISWANDI|HENDRA|999\.999|888\.888|N\/A/);
  assert.equal(JSON.stringify(s), before);
});

test('different Material/Jasa volumes produce separate rows without summing quantities or charging the wrong component', () => {
  const { s, p, d } = fixture(); d.rows[0].qm = 5; d.rows[0].qs = 8;
  const data = spkAppendixData(s, p, d);
  assert.equal(data.splitVolumes, true);
  assert.deepEqual(data.rows.map(r => r.cells.slice(4)), [
    ['1.000', '', '5', '5', '5.000', '', '5.000'],
    ['', '2.000', '8', '8', '', '16.000', '16.000'],
  ]);
  assert.deepEqual(data.rows.map(r => r.component), ['Material', 'Jasa']);
  assert.equal(data.total, dealTotal(d));
});

test('zero stays visible, deselected components stay blank and the canonical cent rounding matches the main SPK', () => {
  const { s, p, d, spk } = fixture();
  d.rows[0].qm = 1; d.rows[0].qs = 2; d.rows[0].pm = .005; d.rows[0].ps = .0025;
  let data = spkAppendixData(s, p, d), letter = spkDocumentData(p, d, spk);
  assert.equal(data.total, dealTotal(d));
  assert.deepEqual([data.material, data.service, data.total], [letter.material, letter.service, letter.total]);
  assert.deepEqual(data.rows.map(r => r.cells[10]), ['0', '0']);
  d.rows[0].m = false; d.rows[0].ps = 0;
  d.rows.push({ ...d.rows[0], rowId: 'excluded', m: false, s: false });
  data = spkAppendixData(s, p, d);
  assert.equal(data.rows.length, 1);
  assert.deepEqual(data.rows[0].cells.slice(4), ['', '0', '2', '2', '', '0', '0']);
  assert.equal(data.materialText, ''); assert.equal(data.serviceText, '0');
});

test('continuation attachment uses the selected agreement quantities and saved SPK signer while only labels come from root', () => {
  const { s, p, d, spk } = fixture();
  const child = { ...structuredClone(p), id: 'child', rootId: p.id, parentId: p.id, name: 'Proyek lanjutan', boq: [], allocations: {} };
  s.projects.push(child); d.projectId = child.id; d.rows[0].qm = 3; d.rows[0].qs = 3;
  p.boq[0].qty = 9999;
  const data = spkAppendixData(s, child, d);
  assert.equal(data.rows[0].cells[1], 'SPK-QA'); assert.equal(data.rows[0].cells[6], '3');
  const html = renderToStaticMarkup(createElement(SpkAppendix, { state: s, project: child, deal: d, spk }));
  assert.match(html, /Proyek lanjutan/); assert.match(html, /Penandatangan SPK tersimpan/);
  assert.match(html, /JABATAN SPK TERSIMPAN/);
  assert.doesNotMatch(html, /Head BA KH berbeda|APPROVED|<img|<svg|9999/);
  assert.match(html, /aria-label="Ruang tanda tangan basah penandatangan SPK"><\/div>/);
});

test('form and Lihat Lampiran share one template grid, while the main SPK stays portrait with its Word action', () => {
  const { s, p, d, spk } = fixture();
  const props = { state: s, project: p, deal: d, spk, onClose: () => {} };
  const html = renderToStaticMarkup(createElement(DocumentPreview, { ...props, kind: 'Lampiran SPK' }));
  assert.match(html, /A4 horizontal/); assert.match(html, /Cetak \/ Simpan PDF browser/);
  assert.match(html, /class="document spk-appendix"/);
  assert.equal((html.match(/<table /g) ?? []).length, 1);
  assert.doesNotMatch(html, /doc-brand|Jumlah Kesepakatan|spk-document/);
  const form = renderToStaticMarkup(createElement(SpkEditor, { s, id: spk.id }));
  assert.match(form, /class="spk-appendix-table"/); assert.doesNotMatch(form, /NAMA PROYEK/);
  const letter = renderToStaticMarkup(createElement(DocumentPreview, { ...props, kind: 'Surat Perintah Kerja' }));
  assert.match(letter, /A4 vertikal/); assert.match(letter, /Unduh Word/);
  assert.doesNotMatch(letter, /spk-appendix-table/);
});

test('1000-row attachment indexes references once without per-row find, and missing/empty references do not invent data', () => {
  const { s, p, d, spk } = fixture();
  d.rows = Array.from({ length: 1000 }, (_, i) => ({ ...d.rows[0], rowId: `row-${i}` }));
  p.boq = d.rows.map(row => ({ ...p.boq[0], id: row.rowId }));
  s.khs.find = () => { throw new Error('per-row KHS find'); };
  p.boq.find = () => { throw new Error('per-row BOQ find'); };
  const data = spkAppendixData(s, p, d);
  assert.equal(data.rows.length, 1000); assert.equal(data.rows[999].cells[1], 'SPK-QA');
  assert.equal(data.total, dealTotal(d));
  d.rows = [{ ...d.rows[0], rowId: 'missing' }];
  assert.equal(spkAppendixData(s, p, d).rows[0].cells[1], 'Referensi tidak tersedia');
  d.rows = [];
  const html = renderToStaticMarkup(createElement(SpkAppendix, { state: s, project: p, deal: d, spk }));
  assert.match(html, /colSpan="11">Belum ada komponen/);
});

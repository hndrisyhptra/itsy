import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { BakhAppendix } from '../../resources/js/area3/bakh-appendix';
import { DocumentPreview } from '../../resources/js/area3/ui';

function fixture() {
  const s = seed('C'), p = s.projects[0], d = structuredClone(s.deals[0]);
  const source = p.boq[0];
  const item = s.khs.find(k => k.id === source.khsId)!;
  item.code = 'APPENDIX-QA'; item.name = 'Uraian lampiran QA'; item.unit = 'Meter';
  d.rows = [{ ...d.rows[0], rowId: source.id, m: true, s: true, qm: 100, qs: 200, offerM: 1100, offerS: 2200, sphM: 3300, sphS: 4400, pm: 5500, ps: 6600 }];
  d.signer = 'Saved Head QA'; d.signerNik = 'QA-123'; d.signerRole = 'Head of PMO'; d.signerSource = 'pp-head-pmo'; d.mandor = 'Mandor QA';
  return { s, p, d };
}

test('attachment matches the ten-column three-tier template and maps unit prices to the correct party', () => {
  const { s, p, d } = fixture(), before = JSON.stringify(s);
  const html = renderToStaticMarkup(createElement(BakhAppendix, { state: s, project: p, deal: d }));
  assert.match(html, /<header class="bakh-appendix-heading"><h2>LAMPIRAN BERITA ACARA KESEPEKATAN HARGA<\/h2><p>PEKERJAAN: /);
  assert.ok(html.includes(`PEKERJAAN: ${p.name}`));
  assert.ok(!html.includes(p.no));
  assert.ok(!html.includes(d.no));
  assert.doesNotMatch(html, /class="doc-number"|bakh-appendix-project/);
  assert.match(html, /colSpan="4" scope="colgroup">NEGOSIASI/);
  assert.match(html, /colSpan="2" rowSpan="2" scope="colgroup">KESEPAKATAN/);
  assert.equal((html.match(/rowSpan="3"/g) ?? []).length, 4);
  const cells = [...html.matchAll(/<tbody><tr>(.*?)<\/tr><\/tbody>/gs)][0][1].match(/<td[^>]*>.*?<\/td>/g)!;
  assert.equal(cells.length, 10);
  assert.deepEqual(cells.map(cell => cell.replace(/<[^>]+>/g, '')), ['1', 'APPENDIX-QA', 'Uraian lampiran QA', 'Meter', '1.100', '2.200', '3.300', '4.400', '5.500', '6.600']);
  assert.doesNotMatch(html, /Jumlah Kesepakatan|Volume|550\.000/);
  assert.equal(JSON.stringify(s), before);
});

test('zero prices remain zero, excluded components are empty and deselected rows stay absent', () => {
  const { s, p, d } = fixture(); d.rows[0].m = false; d.rows[0].ps = 0;
  d.rows.push({ ...d.rows[0], rowId: 'not-selected', m: false, s: false });
  const html = renderToStaticMarkup(createElement(BakhAppendix, { state: s, project: p, deal: d }));
  assert.equal((html.match(/<td class="numeric"><\/td>/g) ?? []).length, 3);
  assert.doesNotMatch(html, /N\/A/);
  assert.match(html, /<td class="numeric">0<\/td>/);
  assert.equal((html.match(/<tbody><tr>/g) ?? []).length, 1);
  assert.doesNotMatch(html, /not-selected/);
});

test('continuation uses root row references and retains the saved Head identity without inventing signatures', () => {
  const { s, p, d } = fixture(), child = { ...structuredClone(p), id: 'child-appendix', parentId: p.id, rootId: p.id, boq: [] };
  s.projects.push(child); d.projectId = child.id; d.status = 'Sah';
  child.approverUserIds = { 'Head of PMO': 'changed-head' };
  const html = renderToStaticMarkup(createElement(BakhAppendix, { state: s, project: child, deal: d }));
  assert.match(html, /APPENDIX-QA/); assert.match(html, /Saved Head QA/); assert.match(html, /HEAD OF PMO AREA JAWA BALI/); assert.match(html, /NIK: QA-123/); assert.match(html, /Mandor QA/);
  assert.equal((html.match(/class="bakh-wet-signature"[^>]*><\/div>/g) ?? []).length, 2);
  assert.doesNotMatch(html, /APPROVED|<img|<svg|changed-head/);
});

test('existing attachment action opens the dedicated preview without duplicate generic tables', () => {
  const { s, p, d } = fixture();
  const html = renderToStaticMarkup(createElement(DocumentPreview, { state: s, project: p, deal: d, kind: 'Lampiran Negosiasi Harga', onClose: () => {} }));
  assert.match(html, /Preview · Lampiran BA KH/); assert.match(html, /Cetak \/ Simpan PDF browser/);
  assert.match(html, /A4 horizontal/);
  assert.equal((html.match(/<table /g) ?? []).length, 1);
  assert.doesNotMatch(html, /Perbandingan Harga per Komponen|Status bukti dokumen/);
});

test('empty rows and missing reference/identity are explicit without copying names from the template', () => {
  const { s, p, d } = fixture(); d.rows[0].rowId = 'missing-reference'; d.signer = ''; d.signerNik = '';
  let html = renderToStaticMarkup(createElement(BakhAppendix, { state: s, project: p, deal: d }));
  assert.match(html, /Referensi tidak tersedia/); assert.match(html, /Belum ditetapkan/); assert.match(html, /NIK: Belum tersedia/);
  assert.doesNotMatch(html, /HENDRA|HILARIUS/);
  d.rows = []; html = renderToStaticMarkup(createElement(BakhAppendix, { state: s, project: p, deal: d }));
  assert.match(html, /colSpan="10">Belum ada komponen/);
});

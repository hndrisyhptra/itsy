import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createBakhDocx, bakhWordFilename, DOCX_MIME } from '../../resources/js/area3/bakh-docx';
import { bakhAppendixRows, bakhDocumentData, BAKH_TEXT } from '../../resources/js/area3/bakh-content';
import { DocumentPreview } from '../../resources/js/area3/ui';
import { seed } from '../../resources/js/area3/seed';

// Read via ZIP central directory (not the writer's local-header iteration).
export function docxParts(bytes: Uint8Array): Record<string, string> {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength), decoder = new TextDecoder();
  assert.equal(view.getUint32(bytes.length - 22, true), 0x06054b50);
  const count = view.getUint16(bytes.length - 12, true), result: Record<string, string> = {};
  let cursor = view.getUint32(bytes.length - 6, true);
  for (let i = 0; i < count; i++) {
    assert.equal(view.getUint32(cursor, true), 0x02014b50);
    const size = view.getUint32(cursor + 24, true), nameLength = view.getUint16(cursor + 28, true), extra = view.getUint16(cursor + 30, true), comment = view.getUint16(cursor + 32, true), local = view.getUint32(cursor + 42, true);
    const name = decoder.decode(bytes.subarray(cursor + 46, cursor + 46 + nameLength));
    assert.equal(view.getUint32(local, true), 0x04034b50); assert.equal(view.getUint16(local + 8, true), 0);
    assert.equal(view.getUint32(local + 18, true), size); assert.equal(view.getUint32(local + 14, true), view.getUint32(cursor + 16, true));
    const start = local + 30 + view.getUint16(local + 26, true) + view.getUint16(local + 28, true);
    result[name] = decoder.decode(bytes.subarray(start, start + size));
    cursor += 46 + nameLength + extra + comment;
  }
  return result;
}

function fixture() {
  const s = seed('C'), p = s.projects[0], d = structuredClone(s.deals[0]);
  p.name = 'Pekerjaan Word QA';
  Object.assign(d, { no: '0099-01/LG.000/TA-03R546/11-2026', date: '2026-11-06', location: 'Lokasi Word QA', witel: 'Witel Word QA', place: 'Tempat Word QA', fo: '2 tim (8 orang)', jointer: '1 tim (2 orang)', start: '2026-11-30', end: '2026-12-13', signer: 'Head Word QA', signerRole: 'Head of PMO', signerSource: 'pp-head-pmo', signerNik: 'QA-WORD-01', mandor: 'Mandor Word QA' });
  return { s, p, d };
}

test('Word download is a real native DOCX package with no HTML, macros or signature images', () => {
  const { s, p, d } = fixture();
  for (const kind of ['bakh', 'appendix'] as const) {
    const bytes = createBakhDocx(s, p, d, kind), parts = docxParts(bytes);
    assert.equal(new DataView(bytes.buffer).getUint32(0, true), 0x04034b50);
    assert.equal(Object.keys(parts).length, 6);
    assert.match(parts['[Content_Types].xml'], /wordprocessingml.document.main\+xml/);
    assert.match(parts['_rels/.rels'], /Target="word\/document.xml"/);
    assert.match(parts['word/document.xml'], /<w:document .*<w:body>/);
    assert.doesNotMatch(Object.values(parts).join(''), /altChunk|<html|vbaProject|TargetMode="External"|<w:drawing|APPROVED|<w:pict/);
  }
  assert.equal(DOCX_MIME, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
});

test('portrait Word uses the exact preview model, editable narrative and existing saved identity without state changes', () => {
  const { s, p, d } = fixture(), before = JSON.stringify({ s, p, d });
  const doc = docxParts(createBakhDocx(s, p, d, 'bakh'))['word/document.xml'], model = bakhDocumentData(p, d);
  for (const text of [model.number, p.name, d.location, d.witel, model.place, d.fo, d.jointer, 'Head Word QA', 'NIK: QA-WORD-01', ...model.opening.map(r => r.text), ...model.team.map(r => r.text)]) assert.ok(doc.includes(text), text);
  assert.match(doc, /w:orient="portrait"/); assert.match(doc, /w:w="11906" w:h="16838"/);
  assert.equal((doc.match(/<w:numPr>/g) ?? []).length, 3);
  assert.equal((doc.match(/w:line="1300" w:lineRule="exact"/g) ?? []).length, 2);
  assert.doesNotMatch(doc, /NEGOSIASI<\/w:t>|Jumlah Kesepakatan|Harga satuan \(Rp\)/);
  assert.equal(JSON.stringify({ s, p, d }), before);
  const formOnly = { ...s, projects: [], khs: [] };
  assert.ok(createBakhDocx(formOnly, { ...p, rootId: 'unloaded' }, d, 'bakh').length > 0);
});

test('landscape Word retains ten columns, three repeated header rows, independent prices, zero and empty components', () => {
  const { s, p, d } = fixture();
  d.rows = [{ ...d.rows[0], rowId: p.boq[0].id, m: false, s: true, offerS: 1100, sphS: 2200, ps: 0 }];
  const rows = bakhAppendixRows(s, p, d), doc = docxParts(createBakhDocx(s, p, d, 'appendix'))['word/document.xml'];
  assert.match(doc, /w:orient="landscape"/); assert.match(doc, /w:w="16838" w:h="11906"/);
  assert.equal((doc.match(/<w:tblHeader\/>/g) ?? []).length, 3);
  assert.equal((doc.match(/<w:vMerge w:val="restart"\/>/g) ?? []).length, 5);
  const row = doc.match(/<w:tr><w:trPr><w:cantSplit\/><\/w:trPr>.*?<\/w:tr>/)?.[0] ?? '';
  assert.equal((row.match(/<w:tc>/g) ?? []).length, 10);
  assert.equal((row.match(/<w:t xml:space="preserve"><\/w:t>/g) ?? []).length, 3);
  for (const cell of rows[0].cells.filter(Boolean)) assert.ok(row.includes(cell), cell);
  assert.match(doc, /<w:t xml:space="preserve">0<\/w:t>/);
  assert.ok(doc.includes(BAKH_TEXT.appendixTitle)); assert.ok(!doc.includes(p.no)); assert.ok(!doc.includes(d.no));
  assert.doesNotMatch(doc, /N\/A|Jumlah Kesepakatan/);
});

test('DOCX escapes hostile markup and control characters while retaining Unicode and safe download names', () => {
  const { s, p, d } = fixture(); p.name = '<script>é & 中文 😀</script>\u0000'; d.signer = 'Head "QA"'; d.fo = 'FO\nTim\tQA'; d.no = '../../Nomor:Word?<QA>\\1';
  const parts = docxParts(createBakhDocx(s, p, d, 'bakh')), doc = parts['word/document.xml'];
  assert.match(doc, /&lt;script&gt;é &amp; 中文 😀&lt;\/script&gt;/); assert.match(doc, /Head &quot;QA&quot;/); assert.match(doc, /FO<\/w:t><w:br\/><w:t xml:space="preserve">Tim<\/w:t><w:tab\/>/);
  assert.doesNotMatch(doc, /\u0000|<script/);
  for (const kind of ['bakh', 'appendix'] as const) { const name = bakhWordFilename(kind, d); assert.match(name, /\.docx$/); assert.doesNotMatch(name, /[\\/:*?"<>|\u0000-\u001f]/); }
});

test('historical signer, missing values and continuation references follow the same preview policy', () => {
  const { s, p, d } = fixture(), child = { ...p, id: 'child-word', rootId: p.id, boq: [] };
  d.projectId = child.id; d.signer = 'Historical Head QA'; delete d.signerRole; delete d.signerSource; delete d.signerNik; d.date = '2026-02-30'; d.fo = '';
  let doc = docxParts(createBakhDocx(s, child, d, 'bakh'))['word/document.xml'];
  assert.match(doc, /Historical Head QA/); assert.match(doc, /Penandatangan sesuai BA KH/); assert.match(doc, /<w:t xml:space="preserve">…<\/w:t>/);
  assert.doesNotMatch(doc, /HEAD OF PMO|NIK:|Invalid Date|NaN|HENDRA|HILARIUS/);
  doc = docxParts(createBakhDocx(s, child, d, 'appendix'))['word/document.xml'];
  assert.ok(doc.includes(s.khs.find(k => k.id === p.boq[0].khsId)!.code));
});

test('Word buttons appear only in the two BA KH previews and retain normal print actions', () => {
  const { s, p, d } = fixture();
  for (const [kind, label] of [['BA Kesepakatan Harga', 'Unduh BA KH sebagai Word DOCX'], ['Lampiran Negosiasi Harga', 'Unduh Lampiran BA KH sebagai Word DOCX']]) {
    const html = renderToStaticMarkup(createElement(DocumentPreview, { state: s, project: p, deal: d, kind, onClose: () => {} }));
    assert.ok(html.includes(label)); assert.match(html, /Unduh Word \(\.docx\)/); assert.match(html, /Cetak \/ Simpan PDF browser/);
    assert.match(html, /aria-busy="false"/);
  }
  const other = renderToStaticMarkup(createElement(DocumentPreview, { state: s, project: p, kind: 'Project Plan', onClose: () => {} }));
  assert.doesNotMatch(other, /Unduh Word/);
});

test('1000-row attachment indexes references once without per-row catalog finds or truncating records', () => {
  const { s, p, d } = fixture();
  s.khs = new Proxy(s.khs, { get(target, key, receiver) { if (key === 'find') throw new Error('Per-row KHS find forbidden'); return Reflect.get(target, key, receiver); } });
  d.rows = Array.from({ length: 1000 }, (_, i) => ({ ...d.rows[0], rowId: `word-row-${i}`, m: false, s: true, offerS: i, sphS: i, ps: i }));
  p.boq = d.rows.map((row, i) => ({ ...p.boq[0], id: row.rowId, khsId: s.khs[0].id, qty: i + 1 }));
  const bytes = createBakhDocx(s, p, d, 'appendix'), doc = docxParts(bytes)['word/document.xml'];
  assert.equal((doc.match(/<w:tr>/g) ?? []).length, 1004); // three headers + 1000 items + signature block
  assert.ok(bytes.length < 8 * 1024 * 1024); assert.match(doc, /<w:t xml:space="preserve">1\.000<\/w:t>|<w:t xml:space="preserve">1000<\/w:t>/);
});

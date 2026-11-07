import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { money } from '../../resources/js/area3/domain';
import { BoqDraftEditor } from '../../resources/js/area3/workflow-ui';
import { ProjectPlanAppendix } from '../../resources/js/area3/plan-document';
import { MasterKhs } from '../../resources/js/area3/management';
import { BoqPage } from '../../resources/js/area3/projects';
import { DealEditor, SpkEditor } from '../../resources/js/area3/contracts';
import { DocumentPreview } from '../../resources/js/area3/ui';

function fixture() {
  const s = seed('D'), p = s.projects[0], row = p.boq[0], k = s.khs.find(k => k.id === row.khsId)!;
  row.m = null; row.excelM = null; row.s = 0; row.excelS = 0;
  k.m = null; k.s = 0;
  const d = s.deals[0], agreed = d.rows.find(r => r.rowId === row.id)!;
  agreed.m = false; agreed.capM = null; agreed.pm = 0; agreed.ps = 0;
  return { s, p, d, spk: s.spks[0], term: s.terms[0] };
}

function check(html: string, label: string, zeroPrice = true) {
  assert.doesNotMatch(html, /N\/A/i, label);
  assert.match(html, /<td class="numeric"><\/td>/, label + ': missing component is an empty cell');
  if (zeroPrice) assert.ok(html.includes(money(0)), label + ': a real zero price remains visible');
}

test('BOQ forms, legacy/current PP previews and KHS show empty unavailable cells while preserving zero and source state', () => {
  const { s, p } = fixture(), before = JSON.stringify(s);
  for (const [label, element] of [
    ['BOQ upload/review', createElement(BoqDraftEditor, { s, p, onChange: () => {}, disabled: false })],
    ['BOQ list', createElement(BoqPage, { s, id: p.id })],
    ['PP appendix', createElement(ProjectPlanAppendix, { s, p })],
    ['PP generic preview', createElement(DocumentPreview, { state: s, project: p, kind: 'Project Plan', onClose: () => {} })],
    ['KHS list', createElement(MasterKhs, { s })],
  ] as const) check(renderToStaticMarkup(element), label);
  assert.equal(JSON.stringify(s), before);
});

test('BA KH allocation, SPK form and contract/payment previews leave unavailable components blank without changing totals or state', () => {
  const { s, p, d, spk, term } = fixture(), before = JSON.stringify(s);
  check(renderToStaticMarkup(createElement(DealEditor, { s, id: d.id })), 'BA KH allocation', false);
  const attachment = renderToStaticMarkup(createElement(SpkEditor, { s, id: spk.id }));
  check(attachment, 'SPK attachment', false);
  assert.match(attachment, /<td class="numeric">0<\/td>/, 'SPK template uses plain Rupiah amounts and preserves real zero');
  for (const kind of ['BA Kesepakatan Harga', 'Surat Perintah Kerja', 'BA Rekonsiliasi Pekerjaan – Termin']) {
    const html = renderToStaticMarkup(createElement(DocumentPreview, { state: s, project: p, deal: d, ...(kind !== 'BA Kesepakatan Harga' ? { spk } : {}), ...(kind.includes('Termin') ? { term } : {}), kind, onClose: () => {} }));
    if (kind === 'BA Kesepakatan Harga') {
      assert.doesNotMatch(html, /N\/A|<table/i, kind + ': narrative template refers to separate price attachment');
      assert.match(html, /Sesuai dengan lampiran Harga Satuan/);
    } else if (kind === 'Surat Perintah Kerja') {
      assert.match(html, /spk-document|NAMA CLUSTER/);
      assert.doesNotMatch(html, /N\/A/i);
    } else check(html, kind);
  }
  assert.equal(JSON.stringify(s), before);
});

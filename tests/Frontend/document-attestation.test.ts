import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { saveDeal, saveSpk, attestBapp } from '../../resources/js/area3/domain';
import { SpkEditor } from '../../resources/js/area3/contracts';
import { BappDetail } from '../../resources/js/area3/payments';
import { ProjectTimeline } from '../../resources/js/area3/project-timeline-page';
import { DocumentPreview } from '../../resources/js/area3/ui';

test('new BA KH/SPK save, issue and attest without references; stale client claims are ignored', () => {
  for (const action of ['save', 'generate', 'attest'] as const) {
    for (const value of [undefined, '', null, { obsolete: 'ignored' }]) {
      const s = seed('C'), d = structuredClone(s.deals[0]);
      s.deals = []; d.status = 'Draft';
      Object.assign(d, { evidence: value });
      saveDeal(s, d, action);
      assert.equal(s.deals[0].evidence, '');
      assert.equal(s.deals[0].status, action === 'save' ? 'Draft' : action === 'generate' ? 'Terbit' : 'Sah');
      const ss = seed('D'), spk = structuredClone(ss.spks[0]);
      ss.spks = []; spk.status = 'Draft';
      Object.assign(spk, { evidence: value });
      saveSpk(ss, spk, action);
      assert.equal(ss.spks[0].evidence, '');
      assert.equal(ss.spks[0].status, action === 'save' ? 'Draft' : action === 'generate' ? 'Terbit' : 'Sah');
    }
  }
});

test('BA PP keeps Admin, Final and complete approval guards while preserving archived references', () => {
  for (const invalid of ['role', 'number', 'type', 'status', 'approvals']) {
    const s = seed('E'), b = s.bapps[0], t = s.terms.find(t => t.id === b.termId)!;
    if (invalid === 'role') s.role = 'Finance';
    if (invalid === 'type') t.type = 'Parsial';
    if (invalid === 'status') t.status = 'Diajukan';
    if (invalid === 'approvals') t.approvals = [];
    const before = JSON.stringify(s);
    assert.throws(() => attestBapp(s, b.id, invalid === 'number' ? '' : b.no));
    assert.equal(JSON.stringify(s), before);
  }
  const s = seed('E'), b = s.bapps[0]; b.evidence = 'Historical archive';
  attestBapp(s, b.id, b.no, 'Stale client replacement');
  assert.equal(b.evidence, 'Historical archive');
  assert.equal(b.status, 'Sah');
  assert.throws(() => attestBapp(s, b.id, b.no), /sudah disahkan/);
});

test('SPK/BA PP forms and generic previews omit text-reference inputs and historical reference claims', () => {
  const s = seed('E'), p = s.projects[0], d = s.deals[0], spk = s.spks[0], b = s.bapps[0];
  d.evidence = spk.evidence = b.evidence = 'PRIVATE-HISTORICAL-REFERENCE';
  const before = JSON.stringify(s);
  const elements = [
    createElement(SpkEditor, { s, id: spk.id }),
    createElement(BappDetail, { s, id: b.id }),
    createElement(ProjectTimeline, { s, id: p.id }),
    createElement(DocumentPreview, { state: s, project: p, deal: d, spk, kind: 'Surat Perintah Kerja', onClose: () => {} }),
    createElement(DocumentPreview, { state: s, project: p, deal: d, spk, term: s.terms.find(t => t.id === b.termId), kind: 'BA Penyelesaian Pekerjaan', onClose: () => {} }),
  ];
  for (const el of elements) assert.doesNotMatch(renderToStaticMarkup(el), /Referensi bukti|Bukti dokumen|PRIVATE-HISTORICAL-REFERENCE/i);
  assert.equal(JSON.stringify(s), before);
});

test('BA PP reconciliation indexes loaded BOQ/KHS once instead of searching catalogs per component', () => {
  const s = seed('E'), b = s.bapps[0];
  const expectedCode = s.khs.find(k => k.id === s.projects[0].boq[0].khsId)!.code;
  s.khs.find = () => { throw new Error('Per-component KHS search'); };
  s.projects[0].boq.find = () => { throw new Error('Per-component BOQ search'); };
  const html = renderToStaticMarkup(createElement(BappDetail, { s, id: b.id }));
  assert.ok(html.includes(expectedCode));
  assert.match(html, /Sahkan BA PP|Material Accepted|Jasa Accepted/);
});

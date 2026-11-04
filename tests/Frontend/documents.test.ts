import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { blankProject, getTerm, getSpk, getDeal, normalizeWorkflow, assignProjectApprovers, createTerm, saveTerm, decide, selectActor } from '../../resources/js/area3/domain';
import { DocumentPreview } from '../../resources/js/area3/ui';
test('unsaved manually entered PP can be previewed before it exists in the store', () => {
  const s = seed('B'), p = blankProject(); p.no = 'PP/NEW-PREVIEW'; p.name = 'Proyek baru belum disimpan';
  const html = renderToStaticMarkup(createElement(DocumentPreview, { state:s, kind:'Project Plan', project:p, onClose:()=>{} }));
  assert.ok(html.includes('PP/NEW-PREVIEW'));
  assert.ok(html.includes('Proyek baru belum disimpan'));
});
test('BA PP document total uses all approved terms, not only the last Final', () => {
  const s = seed('E'), bapp = s.bapps[0], term = getTerm(s,bapp.termId), spk = getSpk(s,term.spkId), deal = getDeal(s,spk.dealId);
  const html = renderToStaticMarkup(createElement(DocumentPreview, { state:s, kind:'BA Penyelesaian Pekerjaan', bapp, term, spk, deal, onClose:()=>{} }));
  const summary = html.match(/class="doc-total"[\s\S]*?<\/div>/)?.[0] ?? '';
  assert.match(summary,/85\.922\.250/);
  assert.match(html,/900/);
});
test('continuation PP preview totals its allocated quantities rather than full root BOQ', () => {
  const s = seed(), project = s.projects.find(p=>p.parentId)!;
  const html = renderToStaticMarkup(createElement(DocumentPreview, { state:s, kind:'Project Plan', project, onClose:()=>{} }));
  const summary = html.match(/class="doc-total"[\s\S]*?<\/div>/)?.[0] ?? '';
  assert.match(summary,/33\.695\.000/);
  assert.doesNotMatch(summary,/134\.780\.000/);
});
test('new payment print is gated by two approvals and provides named wet-signature spaces', () => {
  const s = normalizeWorkflow(seed('D'));
  assignProjectApprovers(s, s.projects[0].id, 'user-1', 'user-2');
  const termId = createTerm(s, s.spks[0].id), term = getTerm(s, termId), spk = getSpk(s, term.spkId), row = getDeal(s, spk.dealId).rows[0];
  term.actuals = { [row.rowId]: { m: 1, s: 1 } }; saveTerm(s, term, true);
  const render = () => renderToStaticMarkup(createElement(DocumentPreview, { state:s, kind:'BA Rekonsiliasi Pekerjaan – Termin', term:getTerm(s, termId), spk, onClose:()=>{} }));
  assert.match(render(), /<button[^>]*disabled[^>]*>[\s\S]*?Cetak \/ Simpan PDF browser/);
  for (const role of ['PM Konstruksi', 'Manager Konstruksi'] as const) {
    s.role = role; selectActor(s, role === 'PM Konstruksi' ? 'user-1' : 'user-2'); decide(s, termId, 'Setujui', 'QA');
  }
  const html = render(), signature = html.match(/class="signature"[\s\S]*?<footer>/)?.[0] ?? '';
  assert.doesNotMatch(html, /<button[^>]*disabled[^>]*>[\s\S]*?Cetak \/ Simpan PDF browser/);
  assert.match(signature, /Rizky Nugraha/); assert.match(signature, /Dian Puspita/);
  assert.equal((signature.match(/Area TTD basah dokumen asli/g) ?? []).length, 2);
  assert.doesNotMatch(signature, /<img|<svg/);
});

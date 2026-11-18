import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { normalizeWorkflow, nextApprovalRole, type Deal } from '../../resources/js/area3/domain';
import { RiskQualitySection } from '../../resources/js/area3/plan-budget-ui';
import { ppEvpSignatory } from '../../resources/js/area3/pp-evp-signatory';

function fixture() {
  const s=normalizeWorkflow(seed('D'));s.schema=3;
  const p=s.projects[0];p.workflowVersion=3;p.approvalScheme='project-support-first';
  return {s,p,deal:(date:string,projectId=p.id):Deal=>({...s.deals[0],id:`bakh-${date}-${projectId}`,projectId,date})};
}
const evpRow=(html:string)=>html.match(/<tr data-approval-kind="automatic-evp">[\s\S]*?<\/tr>/)?.[0]??'';

test('PP form and preview always append the fixed read-only EVP row; only the preview marks automatic approval',()=>{
  const {s,p,deal}=fixture();s.deals=[deal('2026-11-13')];
  for(const printing of [false,true]) {
    const html=renderToStaticMarkup(createElement(RiskQualitySection,{s,p,editable:true,onChange:()=>{},printing})),row=evpRow(html);
    for(const text of ['Disetujui Oleh:','I GEDE WAHYU DANYNDRA','730371','EVP Area Jawa Bali'])assert.ok(row.includes(text),text);
    const table=html.match(/<div class="[^"]*pp-signatures-table[^"]*"><table[\s\S]*?<\/table>/)?.[0]??'';
    const rows=[...table.matchAll(/<tr(?:\s[^>]*)?>([\s\S]*?)<\/tr>/g)].filter(r=>r[1].includes('<td'));
    assert.equal(rows.length,5);
    for(const r of rows){const cells=[...r[1].matchAll(/<td(?:\s[^>]*)?>([\s\S]*?)<\/td>/g)];assert.equal(cells[3]?.[1],'','Every account and EVP date cell stays empty.');}
    assert.ok(html.indexOf('Head of PMO Area Jawa Bali')<html.indexOf('data-approval-kind="automatic-evp"'));
    assert.doesNotMatch(row,/<input|<select|<button|<img|<svg|Persetujuan aplikasi/);
    if(printing) {
      assert.match(row,/>APPROVED</);assert.match(row,/Persetujuan otomatis/);
      assert.doesNotMatch(row,/12 Nov 2026/,'Approval marks no longer display dates; the underlying reference is retained.');
      assert.match(row,/<div class="pp-signature-print" aria-hidden="true"><\/div>/);
    } else assert.match(row,/Disetujui otomatis/);
  }
});

test('EVP reference date takes the earliest valid saved BA KH of the exact PP, including a saved draft, with no Project-ID/root fallback',()=>{
  const {s,p,deal}=fixture();
  s.deals=[deal('2026-11-20'),deal('2026-11-13'),{...deal('2026-11-10'),status:'Draft'},deal('2026-01-01','another-pp'),deal('2026-02-30'),deal('not-a-date')];
  const result=ppEvpSignatory(s,p);assert.equal(result.bakhDate,'2026-11-10');assert.equal(result.date,'2026-11-09');
  s.deals.reverse();assert.deepEqual(ppEvpSignatory(s,p),result);
  s.deals=s.deals.filter(d=>d.projectId!==p.id);
  assert.equal(ppEvpSignatory(s,p).date,undefined);
  const row=evpRow(renderToStaticMarkup(createElement(RiskQualitySection,{s,p,printing:true})));
  assert.doesNotMatch(row,/Menunggu tanggal BA KH/);assert.match(row,/>APPROVED</);
  assert.doesNotMatch(row,/Invalid Date|1970|NaN/);
});

test('one calendar day before BA KH handles month, year and leap boundaries without local timezone or current-date fallback',()=>{
  const {s,p,deal}=fixture();
  for(const [date,expected] of [['2026-01-01','2025-12-31'],['2026-03-01','2026-02-28'],['2028-03-01','2028-02-29'],['2028-03-02','2028-03-01'],['0001-01-01',undefined]] as const) {
    s.deals=[deal(date)];assert.equal(ppEvpSignatory(s,p).date,expected,date);
  }
  for(const date of ['','2026-13-01','2026-02-29','0000-01-01','2026-11-13T12:00:00Z']) {
    s.deals=[deal(date)];assert.equal(ppEvpSignatory(s,p).date,undefined,date);
  }
});

test('automatic EVP marker never creates an account decision, changes approval routing or rewrites historical state',()=>{
  const {s,p,deal}=fixture();s.deals=[deal('2026-11-13')];
  for(const status of ['Draft','Diajukan','Dikembalikan','Ditolak','Sah'] as const) {
    p.status=status;p.approvals=[];p.approvalHistory=[];
    const before=JSON.stringify(s),next=nextApprovalRole(p,true);
    const html=renderToStaticMarkup(createElement(RiskQualitySection,{s,p,printing:true}));
    assert.equal((html.match(/>APPROVED</g)??[]).length,1);
    assert.doesNotMatch(html,/aria-label="Disetujui di aplikasi oleh /);
    assert.equal(nextApprovalRole(p,true),next);assert.equal(JSON.stringify(s),before);
  }
});

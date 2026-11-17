import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { createTerm,getTerm } from '../../resources/js/area3/domain';
import { paymentLabel,paymentDescription,paymentTotalLabel,paymentDocumentTitle } from '../../resources/js/area3/payment-labels';
import { TermEditor,Terms,BappDetail } from '../../resources/js/area3/payments';
import { KasbonAmountNotice } from '../../resources/js/area3/kasbon-amount-notice';
import { kasbonWordFilename } from '../../resources/js/area3/kasbon-docx';
import { paymentStage,paymentLatest } from '../../resources/js/area3/payment-selection';

const text=(html:string)=>html.replace(/<[^>]*>/g,' ');

test('direct Final labels omit Termin across editor, actual-volume columns, list, BA PP and selection while staged labels stay numbered',()=>{
  const s=seed('D');s.role='Admin Konstruksi';const t=getTerm(s,createTerm(s,s.spks[0].id));
  for(const [number,type,single] of [[1,'Final',true],[1,'Parsial',false],[2,'Final',false],[3,'Final',false]] as const){
    Object.assign(t,{number,type});const before=JSON.stringify(s);
    const editor=renderToStaticMarkup(createElement(TermEditor,{s,id:t.id}));
    const list=renderToStaticMarkup(createElement(Terms,{s}));
    if(single){
      assert.match(editor,/Total Pembayaran/);assert.match(editor,/Pembayaran Final/);
      assert.doesNotMatch(text(editor),/\bTermin\b/i);assert.doesNotMatch(text(list),/\bTermin\b/i);
      assert.doesNotMatch(paymentStage(paymentLatest(t)),/Termin/i);
      assert.equal(paymentDocumentTitle('BA Rekonsiliasi Pekerjaan – Termin',t),'BA Rekonsiliasi Pekerjaan');
    }else{
      assert.match(editor,new RegExp('Total Termin '+number));assert.match(editor,/Termin #1 Aktual/);
      assert.match(text(list),new RegExp('Termin '+number));assert.match(paymentLabel(t),new RegExp('Termin '+number));
    }
    assert.equal(paymentTotalLabel(t),single?'Total Pembayaran':`Total Termin ${number}`);
    assert.equal(paymentDescription(t),single?'Final':`Termin ${number} · ${type}`);
    assert.equal(JSON.stringify(s),before);
  }
  t.number=1;t.type='Final';t.status='Disetujui';
  s.bapps=[{id:'b1',termId:t.id,no:'BAPP-QA',status:'Draft',evidence:'',released:{},releaseHistory:[]}];
  const bapp=renderToStaticMarkup(createElement(BappDetail,{s,id:'b1'}));assert.doesNotMatch(text(bapp),/\bTermin\b/i);assert.match(bapp,/Aktual Pekerjaan/);
});

test('a direct historical Final retains its Finance panel when the unavailable next-term card is omitted',()=>{
  const s=seed('D');s.role='Finance';const t=getTerm(s,createTerm({...s,role:'Admin Konstruksi'},s.spks[0].id));
  t.number=1;t.type='Final';t.status='Disetujui';t.workflowVersion=1;
  const html=renderToStaticMarkup(createElement(TermEditor,{s,id:t.id}));
  assert.match(html,/Nomor APM/);assert.doesNotMatch(text(html),/Termin Berikutnya/);
});

test('PPh notice explains frozen gross, service-only withholding and net without repricing historical cashbons',()=>{
  const settlement={policy:'mandor-service-pph25-v1' as const,gross:300000,material:100000,service:200000,withheld:5000,net:295000};
  const before=JSON.stringify(settlement);
  const html=renderToStaticMarkup(createElement(KasbonAmountNotice,{gross:999999,amount:295000,settlement}));
  assert.match(html,/PPh 2,5% hanya dipotong dari Jasa Mandor/);assert.match(html,/Material tidak dipotong/);
  for(const number of ['300.000','5.000','295.000'])assert.ok(html.includes(number));assert.doesNotMatch(html,/999.999/);
  assert.match(html,/Nilai setelah potongan PPh/);assert.equal(JSON.stringify(settlement),before);
  const legacy=renderToStaticMarkup(createElement(KasbonAmountNotice,{gross:300000,amount:300000}));
  assert.match(legacy,/Kasbon historis/);assert.doesNotMatch(legacy,/Potongan PPh 2,5% · Jasa Mandor|295\.000|Nilai setelah potongan PPh/);
  const material=renderToStaticMarkup(createElement(KasbonAmountNotice,{gross:100000,amount:100000,settlement:{...settlement,gross:100000,service:0,withheld:0,net:100000}}));assert.match(material,/Rp(?:&nbsp;|\s| )0/);
});

test('kasbon filenames omit Termin only for first Final and retain stage identity for installment payments',()=>{
  const data={ppNo:'PP/001',termNumber:1,termType:'Final' as const};
  assert.equal(kasbonWordFilename(data as Parameters<typeof kasbonWordFilename>[0]),'Kasbon_PP-001_Final.docx');
  for(const [number,type] of [[1,'Parsial'],[2,'Final'],[3,'Final']] as const)assert.match(kasbonWordFilename({...data,termNumber:number,termType:type} as Parameters<typeof kasbonWordFilename>[0]),new RegExp('Termin-'+number+'\\.docx$'));
});

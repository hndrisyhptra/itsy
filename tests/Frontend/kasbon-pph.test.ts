import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {createTerm,getTerm,termTotal,type Term,type Deal} from '../../resources/js/area3/domain';
import {kasbonQuote,paymentDue,kasbonQuoteMatches} from '../../resources/js/area3/kasbon-settlement';
import {KasbonDocument,type KasbonData} from '../../resources/js/area3/kasbon-document';
import {createKasbonDocx} from '../../resources/js/area3/kasbon-docx';
import {docxParts} from './helpers/docx-parts';

test('only Mandor service is reduced, net is truncated without floating-point rounding, gross and historical due stay intact',()=>{
 const s=seed('D'),d=s.deals[0],t=getTerm(s,createTerm(s,s.spks[0].id));t.moneyPolicy='idr-trunc-v1';
 for(const v of JSON.parse(readFileSync(new URL('../Fixtures/kasbon-pph.json',import.meta.url),'utf8'))){
  const deal={...d,rows:[{...d.rows[0],rowId:'r',m:true,s:true,pm:v.material,ps:v.service}]} as Deal;
  t.actuals={r:{m:1,s:1}};const before=JSON.stringify({deal,t}),quote=kasbonQuote(deal,t);
  for(const k of ['gross','material','service','withheld','net'] as const)assert.equal(quote[k],v[k],v.id+'/'+k);
  assert.equal(JSON.stringify({deal,t}),before);
  t.kasbon={id:'cash',version:1,amount:quote.net,settlement:quote,issuedAt:'2026-11-16',issuedBy:'qa'};
  assert.equal(paymentDue(t,quote.gross),v.net);assert.equal(kasbonQuoteMatches(deal,t),true);
  t.kasbon.settlement!.material++;assert.equal(kasbonQuoteMatches(deal,t),false);delete t.kasbon.settlement;
  assert.equal(paymentDue(t,quote.gross),v.gross);delete t.kasbon;
 }
 const deal={...d,rows:[{...d.rows[0],rowId:'r',m:false,s:true,pm:999999,ps:1000}]} as Deal;t.actuals={r:{m:100,s:2}};
 assert.equal(kasbonQuote(deal,t).net,1950);
});

test('kasbon HTML and Word show the frozen net and net words, never a withholding row or gross total',()=>{
 const data=JSON.parse(readFileSync(new URL('../Fixtures/kasbon-vector.json',import.meta.url),'utf8')) as KasbonData;
 data.amount=295000;data.rows=[{accountCode:'QA',accountName:'QA',text:'Pekerjaan QA',amount:'295000'}];
 data.settlement={policy:'mandor-service-pph25-v1',gross:300000,material:100000,service:200000,withheld:5000,net:295000};
 const html=renderToStaticMarkup(createElement(KasbonDocument,{data}));
 const logo=new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url)));
 const xml=docxParts(createKasbonDocx(data,logo))['word/document.xml'];
 for(const text of [html,xml]){assert.match(text,/295\.000/);assert.match(text,/dua ratus sembilan puluh lima ribu rupiah/);assert.doesNotMatch(text,/PPh|2,5|300\.000|(?<!\d)5\.000/);}
});

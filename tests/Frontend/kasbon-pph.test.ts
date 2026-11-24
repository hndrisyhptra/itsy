import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {createTerm,getTerm,termTotal,type Term,type Deal} from '../../resources/js/area3/domain';
import {kasbonQuote,paymentDue,kasbonQuoteMatches,KASBON_SETTLEMENT_POLICY,LEGACY_KASBON_SETTLEMENT_POLICY} from '../../resources/js/area3/kasbon-settlement';
import {KasbonAmountNotice} from '../../resources/js/area3/kasbon-amount-notice';
import {KasbonDocument,type KasbonData} from '../../resources/js/area3/kasbon-document';
import {createKasbonDocx} from '../../resources/js/area3/kasbon-docx';
import {docxParts} from './helpers/docx-parts';

test('only Mandor service is reduced, net is truncated without floating-point rounding, gross and historical due stay intact',()=>{
 const s=seed('D'),d=s.deals[0],t=getTerm(s,createTerm(s,s.spks[0].id));t.moneyPolicy='idr-trunc-v1';
 for(const v of JSON.parse(readFileSync(new URL('../Fixtures/kasbon-pph.json',import.meta.url),'utf8'))){
  const deal={...d,rows:[{...d.rows[0],rowId:'r',m:true,s:true,pm:v.material,ps:v.service}]} as Deal;
  t.actuals={r:{m:1,s:1}};const before=JSON.stringify({deal,t}),quote=kasbonQuote(deal,t,LEGACY_KASBON_SETTLEMENT_POLICY);
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

test('new quotes ROUND the final net once with exact half-up arithmetic; old issued amounts remain frozen',()=>{
 const s=seed('D'),d=s.deals[0],t=getTerm(s,createTerm(s,s.spks[0].id));t.moneyPolicy='idr-trunc-v1';t.actuals={r:{m:1,s:1}};
 for(const v of JSON.parse(readFileSync(new URL('../Fixtures/kasbon-pph-round.json',import.meta.url),'utf8'))){
  const deal={...d,rows:[{...d.rows[0],rowId:'r',m:true,s:true,pm:v.material,ps:v.service}]} as Deal;
  const before=JSON.stringify({deal,t}),quote=kasbonQuote(deal,t);assert.equal(quote.policy,KASBON_SETTLEMENT_POLICY);
  for(const k of ['gross','material','service','withheld','net'] as const)assert.equal(quote[k],v[k],v.id+'/'+k);
  assert.equal(JSON.stringify({deal,t}),before);t.kasbon={id:'new',version:2,amount:quote.net,settlement:quote,issuedAt:'2026-11-24',issuedBy:'qa'};
  assert.equal(paymentDue(t,quote.gross),v.net);assert(kasbonQuoteMatches(deal,t));delete t.kasbon;
 }
 const deal={...d,rows:[{...d.rows[0],rowId:'r',m:true,s:true,pm:17,ps:20}]} as Deal;
 const old=kasbonQuote(deal,t,LEGACY_KASBON_SETTLEMENT_POLICY);t.kasbon={id:'old',version:1,amount:old.net,settlement:old,issuedAt:'2026-11-16',issuedBy:'qa'};
 const before=JSON.stringify(t);assert.equal(paymentDue(t,37),36);assert(kasbonQuoteMatches(deal,t));assert.equal(JSON.stringify(t),before);assert.equal(kasbonQuote(deal,t).net,37);
 t.kasbon.settlement={...old,policy:KASBON_SETTLEMENT_POLICY};assert(!kasbonQuoteMatches(deal,t));
 const notice=renderToStaticMarkup(createElement(KasbonAmountNotice,{gross:37,amount:37,settlement:kasbonQuote(deal,t),estimate:true}));assert.match(notice,/rupiah terdekat \(ROUND\)/);
 const legacy=renderToStaticMarkup(createElement(KasbonAmountNotice,{gross:37,amount:36,settlement:old}));assert.match(legacy,/Nilai setelah potongan PPh/);assert.doesNotMatch(legacy,/rupiah terdekat/);
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

test('rounded new net is printed consistently in HTML, native Word and terbilang without exposing the deduction',()=>{
 const data=JSON.parse(readFileSync(new URL('../Fixtures/kasbon-vector.json',import.meta.url),'utf8')) as KasbonData;
 const s=seed('D'),d={...s.deals[0],rows:[{...s.deals[0].rows[0],rowId:'r',m:true,s:true,pm:100000,ps:200001}]} as Deal;
 const t=getTerm(s,createTerm(s,s.spks[0].id));t.moneyPolicy='idr-trunc-v1';t.actuals={r:{m:1,s:1}};const quote=kasbonQuote(d,t);
 data.amount=quote.net;data.rows=[{accountCode:'QA',accountName:'QA',text:'Pekerjaan QA',amount:String(quote.net)}];data.settlement=quote;
 const html=renderToStaticMarkup(createElement(KasbonDocument,{data}));
 const logo=new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url)));
 const xml=docxParts(createKasbonDocx(data,logo))['word/document.xml'];
 for(const text of [html,xml]){assert.match(text,/295\.001/);assert.match(text,/dua ratus sembilan puluh lima ribu satu rupiah/);assert.doesNotMatch(text,/PPh|2,5|300\.001|295\.000/);}
});

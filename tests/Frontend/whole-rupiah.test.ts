import test from 'node:test';
import assert from 'node:assert/strict';
import { seed } from '../../resources/js/area3/seed';
import { money, num, boqTotal, dealTotal, termTotalForDeal } from '../../resources/js/area3/domain';
import { WHOLE_RUPIAH, wholeRupiah, moneyLine } from '../../resources/js/area3/currency';
import { spkDocumentData } from '../../resources/js/area3/spk-content';
import { spkAppendixData } from '../../resources/js/area3/spk-appendix-content';
import { barpDocumentData, rupiahWords } from '../../resources/js/area3/barp-content';
import { termVolumeData } from '../../resources/js/area3/term-volume';
import { bakhAppendixRows } from '../../resources/js/area3/bakh-content';
import { lineAmount } from '../../resources/js/area3/plan-budget';

test('all money presentation and words use whole rupiah while volumes and raw historical values retain precision',()=>{
  assert.equal(money(15286.6),'Rp 15.286');assert.equal(money(0),'Rp 0');assert.equal(money(-1.5),'‑Rp 1'.replace('‑','-'));
  assert.equal(num(.125),'0,125');assert.equal(rupiahWords(15286.6),'Lima Belas Ribu Dua Ratus Delapan Puluh Enam Rupiah');
  assert.equal(wholeRupiah(-1.5),-1);assert.equal(lineAmount(.005,100,true),0);assert.equal(lineAmount(.005,100),.5);
});

test('new whole-rupiah components agree across BOQ, SPK, BARP, volume attachment and independent legacy arithmetic',()=>{
  const s=seed('D'),p=s.projects[0],d=s.deals[0],spk=s.spks[0];
  p.boq=[{...p.boq[0],qty:.015,m:100,s:100}];d.rows=[{...d.rows[0],rowId:p.boq[0].id,m:true,s:true,qm:.015,qs:.015,pm:100,ps:100}];
  const t={...s.terms[0],id:'whole-term',spkId:spk.id,number:1,type:'Parsial' as const,status:'Disetujui' as const,actuals:{[p.boq[0].id]:{m:.015,s:.015}},approvals:[],history:[],paid:0,paymentHistory:[],apm:'',apmStatus:'Belum Terbit' as const};
  assert.equal(boqTotal(p),3);assert.equal(dealTotal(d),3);assert.equal(termTotalForDeal(d,t),3);
  p.moneyPolicy=d.moneyPolicy=t.moneyPolicy=WHOLE_RUPIAH;
  assert.equal(boqTotal(p),2);assert.equal(dealTotal(d),2);assert.equal(termTotalForDeal(d,t),2);
  assert.equal(spkDocumentData(p,d,spk).total,2);assert.equal(spkAppendixData(s,p,d).total,2);
  assert.equal(barpDocumentData(p,d,spk,t).total,2);assert.equal(termVolumeData(s,p,d,spk,t).current.total,2);
  assert.equal(termVolumeData(s,p,d,spk,t).rows.reduce((n,r)=>n+r.amount,0),2);
});

test('agreement preview removes fractional prices without changing archived numbers and preserves zero/exclusion',()=>{
  const s=seed('D'),p=s.projects[0],d=s.deals[0];
  d.rows=[{...d.rows[0],m:true,s:false,pm:12.75,sphM:0,offerM:14.2}];
  const before=JSON.stringify(d),cells=bakhAppendixRows(s,p,d)[0].cells;
  assert.equal(cells[4],'14');assert.equal(cells[6],'0');assert.equal(cells[8],'12');assert.equal(cells[9],'');
  assert.equal(JSON.stringify(d),before);
});

test('whole-rupiah line products remain exact at large scaled quantities and avoid aggregate rounding drift',()=>{
  const doc={moneyPolicy:WHOLE_RUPIAH};
  for(const [q,p,expected] of [[.005,100,0],[.004,100,0],[.001,999,0],[123456.789,1000,123456789],[1000000.005,100,100000000]])assert.equal(moneyLine(q,p,0,0,doc),expected);
});

test('truncation never increments a positive fraction and historical half policy stays separately identifiable',()=>{
  const trunc={moneyPolicy:WHOLE_RUPIAH};
  for(const value of [1.01,1.49,1.5,1.99])assert.equal(wholeRupiah(value),1);
  assert.equal(moneyLine(1,10,0,0,trunc),10);
  assert.equal(moneyLine(.199,100,0,0,trunc),19);
  assert.equal(moneyLine(.199,100,0,0,{moneyPolicy:'idr-whole-v1'}),20);
  assert.equal(money(-.99),'Rp 0');
});

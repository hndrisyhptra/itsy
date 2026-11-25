import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement as h} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {kasbonInput,kasbonComponentRows} from '../../resources/js/area3/kasbon-form';
import {KASBON_SETTLEMENT_POLICY,type KasbonSettlement,kasbonQuote} from '../../resources/js/area3/kasbon-settlement';
import {KasbonRowsEditor} from '../../resources/js/area3/finance-private-ui';
import {KasbonDocument,type KasbonData} from '../../resources/js/area3/kasbon-document';
import {createKasbonDocx} from '../../resources/js/area3/kasbon-docx';
import {seed} from '../../resources/js/area3/seed';
import {createTerm,getTerm,type Deal} from '../../resources/js/area3/domain';
import {docxParts} from './helpers/docx-parts';
const quote:KasbonSettlement={policy:KASBON_SETTLEMENT_POLICY,gross:105400,service:42500,material:62900,withheld:1063,net:104337};
const previous=()=>JSON.parse(readFileSync(new URL('../Fixtures/kasbon-vector.json',import.meta.url),'utf8')) as KasbonData;

test('canonical split uses only current actuals, net Jasa and intact Material; absent components have no row',()=>{
 const s=seed('D'),t=getTerm(s,createTerm(s,s.spks[0].id));t.moneyPolicy='idr-trunc-v1';t.actuals={r:{m:1,s:1}};
 for(const [material,service,expected] of [[62900,42500,[41437,62900]],[0,20,[19]],[100000,0,[100000]],[17,20,[19,17]],[0,0,[]]] as const){
  const deal={...s.deals[0],rows:[{...s.deals[0].rows[0],rowId:'r',m:true,s:true,pm:material,ps:service}]} as Deal;
  const q=kasbonQuote(deal,t),before=JSON.stringify(q),rows=kasbonComponentRows(q);
  assert.deepEqual(rows.map(r=>Number(r.amount)),expected);assert.equal(rows.reduce((n,r)=>n+Number(r.amount),0),q.net);assert.equal(JSON.stringify(q),before);
  assert.deepEqual(rows.map(r=>r.component),service?material?['service','material']:['service']:material?['material']:[]);
 }
});

test('reissue retains only unambiguous same-component account details, canonicalizes amounts and never rewrites old rows',()=>{
 const old=previous();old.rows=[{component:'material',accountCode:'MAT',accountName:'Bahan',text:'Pembelian',amount:'1'},{component:'service',accountCode:'JAS',accountName:'Jasa',text:'Pelaksanaan',amount:'999'}];
 const before=JSON.stringify(old),f=kasbonInput(quote,old);assert.deepEqual(f.rows.map(r=>r.accountCode),['JAS','MAT']);assert.deepEqual(f.rows.map(r=>r.amount),['41437','62900']);assert.equal(JSON.stringify(old),before);
 old.rows=[{accountCode:'GABUNG',accountName:'Lama',text:'Bersama',amount:'104337'}];const split=kasbonInput(quote,old);assert(split.rows.every(r=>r.accountCode===''&&r.accountName===''&&r.text===''));
 old.rows=[{...old.rows[0],component:'service'},{...old.rows[0],component:'service'}];assert.equal(kasbonInput(quote,old).rows[0].accountCode,'');
 old.rows=[{accountCode:'ONE',accountName:'Jasa',text:'QA',amount:'1000'}];old.settlement={...quote,material:0,service:1000,gross:1000,withheld:25,net:975};assert.equal(kasbonInput({...quote,material:0,service:20,gross:20,withheld:1,net:19},old).rows[0].accountCode,'ONE');
});

test('component editor provides independent account fields and readonly amounts with exact total, without add/delete',()=>{
 const rows=kasbonInput(quote).rows,html=renderToStaticMarkup(h(KasbonRowsEditor,{rows,total:quote.net,busy:false,onChange:()=>{throw Error('SSR must not mutate')}}));
 for(const label of ['No Akun Jasa','Nama Akun Jasa','Teks / Keperluan Jasa','No Akun Material','Nama Akun Material','Teks / Keperluan Material'])assert(html.includes(`aria-label="${label}"`));
 assert.match(html,/Nilai Jasa.*?readOnly=""/);assert.match(html,/Nilai Material.*?readOnly=""/);assert.match(html,/41\.437/);assert.match(html,/62\.900/);assert.match(html,/104\.337/);assert.match(html,/Total Pengajuan Kasbon/);assert.doesNotMatch(html,/Hapus|\+ Baris|type="number"/);
});

test('saved split rows stay identical in HTML and Word, carry separate accounts/purposes and one total with no tax row',()=>{
 const d=previous();d.amount=quote.net;d.settlement=quote;d.rows=kasbonInput(quote).rows.map(r=>({...r,accountCode:r.component==='service'?'JAS-001':'MAT-001',accountName:r.component==='service'?'Akun Jasa':'Akun Material',text:r.component==='service'?'Pelaksanaan <uji>':'Pembelian bahan'}));
 const before=JSON.stringify(d),logo=new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url))),html=renderToStaticMarkup(h(KasbonDocument,{data:d})),xml=docxParts(createKasbonDocx(d,logo))['word/document.xml'];
 for(const text of [html,xml]){for(const expected of ['JAS-001','MAT-001','Jasa · Pelaksanaan','Material · Pembelian bahan','41.437','62.900','104.337','Grand Total'])assert(text.includes(expected),expected);assert.doesNotMatch(text,/PPh|1\.063|105\.400|<uji>/);assert.match(text,/&lt;uji&gt;/);}
 assert.equal(JSON.stringify(d),before);
});

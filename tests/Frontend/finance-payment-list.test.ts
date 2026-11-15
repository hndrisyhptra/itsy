import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {PaymentListView,canSeePaymentList,type PaymentListData} from '../../resources/js/area3/finance-payment-list';
import {ROLES} from '../../resources/js/area3/domain';
const fixture=JSON.parse(readFileSync(new URL('../Fixtures/payment-list.json',import.meta.url),'utf8')) as PaymentListData;
const props={filters:{q:'',branch:'',status:'all' as const,page:1},branches:['SURABAYA','DENPASAR','SEMARANG'],busy:false,error:'',onFilter:()=>{},onRefresh:()=>{}};

test('Finance-only monitoring shows LOP, saved payable, actual APM, stage and document states without private banking data',()=>{
 for(const role of ROLES)assert.equal(canSeePaymentList(role),['Finance','Mgr Finance & Settlement'].includes(role));
 const before=JSON.stringify(fixture),html=renderToStaticMarkup(createElement(PaymentListView,{...props,data:fixture}));
 for(const text of ['List Payment','Belum Terbayar','Sudah Terbayar','APM/QA/2026/1002','29.500.000','18.050.000','NOK','OK','Filter Branch Payment','Cari LOP atau Payment','Menunggu Mgr Finance &amp; Settlement'])assert.ok(html.includes(text),text);
 for(const row of fixture.items)assert.ok(html.includes('/termin/'+row.id));
 assert.doesNotMatch(html,/accountNumber|nik|storage_key|PPh|2,5%/);assert.equal(JSON.stringify(fixture),before);
});
test('monitoring distinguishes loading, empty, error and bounded pagination states',()=>{
 const loading=renderToStaticMarkup(createElement(PaymentListView,{...props,busy:true}));assert.match(loading,/Memuat daftar pembayaran/);
 const empty=renderToStaticMarkup(createElement(PaymentListView,{...props,data:{...fixture,items:[],hasMore:false}}));assert.match(empty,/Belum ada Payment yang sesuai/);assert.match(empty,/disabled=""[^>]*>Berikutnya/);
 const error=renderToStaticMarkup(createElement(PaymentListView,{...props,error:'Koneksi terputus'}));assert.match(error,/role="alert">Koneksi terputus/);assert.doesNotMatch(error,/Belum ada Payment yang sesuai/);
});

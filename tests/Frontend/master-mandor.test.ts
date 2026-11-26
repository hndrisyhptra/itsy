import {mandorImportFailure} from '../../resources/js/area3/mandor-import';
import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {canManageMandor,MANDOR_ROLES,DealMandorSelect,InheritedMandorPanel,MandorRowActions,MandorForm,MandorIdentity,type MandorDetail} from '../../resources/js/area3/master-mandor';
import type {State} from '../../resources/js/area3/domain';

test('Master Mandor management is limited to the five requested roles',()=>{
 for(const role of MANDOR_ROLES)assert(canManageMandor({role:role as State['role']}));
 for(const role of ['Finance','Mgr Finance & Settlement','PM Konstruksi','Head of PMO','Manager SS','Admin Operation Area'])assert(!canManageMandor({role:role as State['role']}));
});
test('new Mandor form contains independent identity/bank fields and no Branch/unit inputs without persistence draft',()=>{
 const html=renderToStaticMarkup(createElement(MandorForm,{initialName:'Mandor QA',onClose(){},onReady(){}}));
 for(const label of ['Nama Mandor','Alamat Mandor','NIK KTP (16 digit)','Nomor Rekening','Nama Pemilik Rekening','Nama Bank'])assert(html.includes(label),label);
 assert.match(html,/maxLength="16"/);assert.match(html,/maxLength="40"/);assert.match(html,/Simpan Identitas Mandor/);assert.doesNotMatch(html,/type="file"/);assert.doesNotMatch(html,/Unit Kerja Mandor|Branch Mandor/);assert.match(html,/berlaku untuk semua Branch dan unit kerja/);
});
test('selected Mandor detail escapes identity and only opens private photos on explicit preview',()=>{
 const data:MandorDetail={id:'qa',name:'Mandor <b>QA</b>',version:3,ready:true,profile:{address:'Alamat & QA',nik:'0000000000000001',accountNumber:'000123',accountName:'QA',bank:'QA Bank'},ktpFileId:'ktp-private',bankFileId:'bank-private'};
 const before=JSON.stringify(data),html=renderToStaticMarkup(createElement(MandorIdentity,{data}));assert.match(html,/Mandor &lt;b&gt;QA&lt;\/b&gt;/);assert.match(html,/Alamat &amp; QA/);assert.match(html,/Preview KTP Mandor/);assert.match(html,/Preview Buku Tabungan Mandor/);assert.doesNotMatch(html,/<iframe|<img|finance-private|storage_key|private_data/);assert.equal(JSON.stringify(data),before);
});

test('row actions expose Upload until both photos complete, then Preview, with Edit retained',()=>{
 for(const ready of [false,true]){
  const html=renderToStaticMarkup(createElement(MandorRowActions,{m:{id:'qa',name:'QA',version:1,ready},onEdit(){},onUpload(){},onPreview(){}}));
  assert.match(html,/aria-label="Edit QA"/);
  if(ready){assert.match(html,/aria-label="Preview foto QA"/);assert.doesNotMatch(html,/aria-label="Upload foto QA"/);}
  else{assert.match(html,/aria-label="Upload foto QA"/);assert.doesNotMatch(html,/aria-label="Preview foto QA"/);}
  assert.doesNotMatch(html,/<iframe|<img|nik|accountNumber/);
 }
});
test('initial form requires identity but labels bank fields optional',()=>{
 const html=renderToStaticMarkup(createElement(MandorForm,{onClose(){},onReady(){}}));
 for(const label of ['Nama Mandor *','Alamat Mandor *','NIK KTP (16 digit) *','Nomor Rekening (opsional)','Nama Bank (opsional)','Nama Pemilik Rekening (opsional)'])assert(html.includes(label),label);
});

test('import error recovery distinguishes missing migration or validation from uncertain commit',()=>{
 const message='Pembaruan database belum selesai';
 assert.equal(mandorImportFailure('commit',new Error(message),false),message);
 assert.equal(mandorImportFailure('preview',new Error('Server Error'),true),'Server Error');
 assert.match(mandorImportFailure('commit',new Error('Server Error'),true),/Status penyimpanan belum dapat dipastikan.*UUID yang sama/);
 assert.equal(mandorImportFailure('commit',new Error('Baris tidak valid'),false),'Baris tidak valid');
});

test('BAKH Mandor search is a bounded accessible dropdown without eager private data',()=>{
 const html=renderToStaticMarkup(createElement(DealMandorSelect,{name:'Mandor QA',value:{id:'qa',version:2},disabled:false,onChange(){}}));
 assert.match(html,/role="combobox"/);assert.match(html,/aria-autocomplete="list"/);assert.match(html,/aria-expanded="false"/);assert.match(html,/value="Mandor QA"/);assert.doesNotMatch(html,/NIK KTP|Nomor Rekening|<iframe|<img|localStorage/);
 const locked=renderToStaticMarkup(createElement(DealMandorSelect,{name:'Mandor QA',disabled:true,onChange(){}}));assert.match(locked,/disabled/);
});
test('inherited SPK source loads privately on demand without embedding NIK or account in initial markup',()=>{
 const html=renderToStaticMarkup(createElement(InheritedMandorPanel,{choice:{id:'qa',version:2},onReady(){}}));assert.match(html,/Terisi otomatis dari Mandor yang dipilih pada BA KH/);assert.match(html,/Memuat identitas Mandor terpilih/);assert.doesNotMatch(html,/<iframe|<img|accountNumber|nik/);
});

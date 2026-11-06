import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MasterImportActions, MasterBulkImport, MasterImportPreviewTable, masterImportFailure } from '../../resources/js/area3/master-import';
import { MasterRequestError } from '../../resources/js/area3/store';

test('bulk actions expose separate real template download URLs and import tooltips only to Superadmin for Designator/KHS',()=>{
  for(const entity of ['designator','khs'] as const){
    const html=renderToStaticMarkup(createElement(MasterImportActions,{entity,canManage:true,disabled:false,onOpen:()=>{}}));
    assert.match(html,new RegExp(`href="/api/masters/${entity}/template"`));assert.match(html,/title="Bulk import /);assert.match(html,/aria-label="Download template /);
    assert.equal(renderToStaticMarkup(createElement(MasterImportActions,{entity,canManage:false,disabled:false,onOpen:()=>{}})),'');
  }
  assert.equal(renderToStaticMarkup(createElement(MasterImportActions,{entity:'paket-khs',canManage:true,disabled:false,onOpen:()=>{}})),'');
});
test('import dialog starts with add-only mode, requires inspection before save and explains batch/file limits',()=>{
  const html=renderToStaticMarkup(createElement(MasterBulkImport,{entity:'khs',revision:0,onClose:()=>{},onComplete:()=>{}}));
  assert.match(html,/1000 baris dan 5 MB/);assert.match(html,/Designator dan Paket KHS harus sudah tersedia/);assert.match(html,/value="create" selected/);assert.match(html,/value="upsert"/);
  assert.match(html,/accept=".xlsx"/);assert.match(html,/disabled=""[^>]*>[\s\S]*?Simpan impor/);assert.match(html,/Periksa file/);assert.match(html,/ID dibuat otomatis/);
});
test('preview keeps source Excel row numbers, update intent, zero prices and escaped source text visible',()=>{
  const html=renderToStaticMarkup(createElement(MasterImportPreviewTable,{preview:{revision:3,sha256:'x',summary:{created:1,updated:1,unchanged:0},rows:[{row:12,code:'M-<test>',package:'Paket 5',action:'created',data:{price:0}},{row:30,code:'J-B',package:'Paket 10',action:'updated',data:{price:'4321.25'}}]}}));
  assert.match(html,/>12<\/td>/);assert.match(html,/>30<\/td>/);assert.match(html,/>0<\/td>/);assert.match(html,/M-&lt;test&gt;/);assert.match(html,/Tambah/);assert.match(html,/Perbarui/);assert.match(html,/master-table/);
});
test('a confirmed revision conflict requires another inspection while uncertain network failures preserve retry identity',()=>{
  const conflict=masterImportFailure(new MasterRequestError('Conflict',409));
  assert.equal(conflict.inspectAgain,true);assert.match(conflict.message,/Klik Periksa file kembali/);assert.match(conflict.message,/file yang dipilih tetap tersedia/);
  const network=masterImportFailure(new Error('Koneksi terputus'));
  assert.equal(network.inspectAgain,false);assert.match(network.message,/permintaan yang sama/);
  const validation=masterImportFailure(new MasterRequestError('Baris 2 salah',422));
  assert.equal(validation.inspectAgain,false);assert.match(validation.message,/Baris 2 salah/);
});

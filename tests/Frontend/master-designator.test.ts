import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { MASTER_TABS, masterId, masterRows, type MasterCatalog } from '../../resources/js/area3/master-data';
import { MasterDataTable, MasterFields } from '../../resources/js/area3/master-designator';
import { ROLES, APPROVERS_V3 } from '../../resources/js/area3/domain';
const catalog:MasterCatalog={designators:[{id_designator:1,code:'M-FO',item_name:'Kabel FO',unit:'M',designator_type_id:1},{id_designator:2,code:'J-FO',item_name:'Penarikan FO',unit:'M',designator_type_id:2}],packages:[{id_package:10,code:'P10',name:'Paket 10',description:'Keterangan'}],prices:[{id_designator_package_price:42,designator_id:1,package_id:10,price:'0.00',code:'M-FO',item_name:'Kabel FO',unit:'M',component:'Material',package:'Paket 10'}],types:[{id_designator_type:1,code:'M',name:'Material'},{id_designator_type:2,code:'J',name:'Jasa'}]};
test('master submenus use actual SQL identities, search descriptions/packages and preserve zero prices',()=>{
  assert.deepEqual(MASTER_TABS.map(t=>t.label),['Designator','KHS','Paket KHS']);
  assert.equal(masterId(catalog.designators[0]),1);assert.equal(masterId(catalog.packages[0]),10);assert.equal(masterId(catalog.prices[0]),42);
  assert.equal(masterRows(catalog,'designator',' kabel ').length,1);assert.equal(masterRows(catalog,'khs','paket 10','10').length,1);assert.equal(masterRows(catalog,'khs','','99').length,0);
  assert.ok(ROLES.includes('Mgr Project Support'));assert.ok(!(APPROVERS_V3 as readonly string[]).includes('Mgr Project Support'));
});
test('each master table supplies real edit/delete callbacks with labeled icon tooltips; viewers have no write buttons',()=>{
  for(const entity of ['designator','khs','paket-khs'] as const){
    const props={entity,catalog,rows:masterRows(catalog,entity),canManage:true,onEdit:()=>{},onDelete:()=>{}};
    const html=renderToStaticMarkup(createElement(MasterDataTable,props));
    assert.match(html,/title="Edit /);assert.match(html,/aria-label="Edit /);assert.match(html,/title="Hapus /);assert.match(html,/aria-label="Hapus /);assert.match(html,/master-table/);
    const view=renderToStaticMarkup(createElement(MasterDataTable,{...props,canManage:false}));assert.doesNotMatch(view,/title="Edit |title="Hapus /);
  }
  const price=renderToStaticMarkup(createElement(MasterDataTable,{entity:'khs',catalog,rows:catalog.prices,canManage:true,onEdit:()=>{},onDelete:()=>{}}));assert.match(price,/Rp/);assert.match(price,/0<\/td>/);assert.match(price,/<th[^>]*>Komponen<\/th>/);
});
test('master price form separates designator/package, locks saved relations and permits zero with two decimal input',()=>{
  const html=renderToStaticMarkup(createElement(MasterFields,{entity:'khs',catalog,editing:true,disabled:false,data:{designator_id:1,package_id:10,price:0},onChange:()=>{}}));
  assert.equal((html.match(/<select[^>]*disabled/g)??[]).length,2);assert.match(html,/min="0"/);assert.match(html,/step="0.01"/);assert.match(html,/value="0"/);assert.match(html,/M-FO/);assert.match(html,/Paket 10/);
});

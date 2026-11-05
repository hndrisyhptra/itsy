export type MasterEntity = 'designator' | 'khs' | 'paket-khs';
export const MASTER_TABS: {id:MasterEntity;label:string}[] = [{id:'designator',label:'Designator'},{id:'khs',label:'KHS'},{id:'paket-khs',label:'Paket KHS'}];
export interface Designator {id_designator:number;code:string;item_name:string;unit:string;designator_type_id:number|null}
export interface KhsPackage {id_package:number;code:string;name:string;description:string|null}
export interface KhsPrice {id_designator_package_price:number;designator_id:number;package_id:number;price:string|number;code:string;item_name:string;unit:string;component:string;package:string}
export interface MasterCatalog {designators:Designator[];packages:KhsPackage[];prices:KhsPrice[];types:{id_designator_type:number;code:string;name:string}[]}
export type MasterRecord = Designator | KhsPackage | KhsPrice;
export function masterId(row:MasterRecord):number {return 'id_designator' in row ? row.id_designator : 'id_package' in row ? row.id_package : row.id_designator_package_price;}
export function masterRows(catalog:MasterCatalog,entity:MasterEntity,query='',packageId=''):MasterRecord[] {
  const rows:MasterRecord[]=entity==='designator'?catalog.designators:entity==='khs'?catalog.prices:catalog.packages;
  const term=query.trim().toLocaleLowerCase('id');
  return rows.filter(row=>(!packageId||!('package_id' in row)||String(row.package_id)===packageId)&&('item_name' in row?`${row.code} ${row.item_name} ${'package' in row?row.package:''}`:`${row.code} ${row.name}`).toLocaleLowerCase('id').includes(term));
}

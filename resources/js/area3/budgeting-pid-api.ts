import {appUrl,boot} from './laravel';
import {postUpload,type UploadProgressHandler} from './upload-progress';

export type BudgetUnit='Konstruksi'|'Operation';
export type BudgetCategory='TOTAL'|'Material'|'Non Material'|'Operasional'|'Tenaga Kerja'|'Depresiasi';
export interface BudgetItem {source_title:string;source_row:number;release:number;commitment:number;actual:number;available:number}
export interface BudgetSnapshot {name:string;unit:BudgetUnit;items:Record<BudgetCategory,BudgetItem>}
export interface BudgetRow extends BudgetSnapshot {id_budget_pid:string;pid:string;warnings:string[];updated_at:string}
export interface BudgetSummary {created:number;updated:number;unchanged:number;warnings:number;pids:number}
export interface BudgetPreviewRow {pid:string;name:string;row:number;action:'created'|'updated'|'unchanged';available:Record<'TOTAL'|'Material'|'Non Material'|'Operasional',number>;warnings:string[]}
export interface BudgetPreview {version:number;preview_hash:string;sha256:string;unit:BudgetUnit;summary:BudgetSummary;rows:BudgetPreviewRow[]}
export interface BudgetImport {id_budget_import:string;unit:BudgetUnit;filename:string;created_at:string;actor_name:string;version:number;summary:BudgetSummary}
export interface BudgetChange {id_budget_change:string;pid:string;action:string;before_data:BudgetSnapshot|null;after_data:BudgetSnapshot}
export interface BudgetPage<T> {rows:T[];page:number;has_more:boolean;unit:BudgetUnit;units:BudgetUnit[]}
export class BudgetRequestError extends Error {constructor(message:string,public status:number,public uncertain=false){super(message);}}
async function read<T>(response:Response):Promise<T>{
  let data:any;
  try{data=await response.json();}catch{throw new BudgetRequestError('Respons server belum dapat dibaca.',response.status,true);}
  if(!response.ok){const details=data.errors?Object.values(data.errors).flat().join('\n'):data.message;throw new BudgetRequestError(details||'Permintaan belum berhasil.',response.status,response.status>=500);}
  return data;
}
export async function loadBudget<T>(unit:BudgetUnit,q:string,page:number,history:boolean,importId:string,signal?:AbortSignal):Promise<BudgetPage<T>>{
  const query=new URLSearchParams({unit,q,page:String(page),history:history?'1':'0'});if(importId)query.set('import_id',importId);
  return read(await fetch(appUrl('/api/budgeting-pid?')+query,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal}));
}
export async function importBudget(file:File,unit:BudgetUnit,preview:BudgetPreview|null,requestId:string,progress?:UploadProgressHandler):Promise<BudgetPreview|{summary:BudgetSummary;import_id:string;version:number;replayed?:boolean}>{
  const body=new FormData();body.set('file',file);body.set('unit',unit);body.set('stage',preview?'commit':'preview');
  if(preview){body.set('version',String(preview.version));body.set('preview_hash',preview.preview_hash);body.set('request_id',requestId);}
  try{
    const data=await read<BudgetPreview|{summary:BudgetSummary;import_id:string;version:number;replayed?:boolean}>(await postUpload(appUrl('/api/budgeting-pid/import'),body,{Accept:'application/json','X-CSRF-TOKEN':boot?.csrf??''},progress,undefined,120000));
    progress?.({phase:'completed',percent:100});return data;
  }catch(error){if(error instanceof BudgetRequestError)throw error;throw new BudgetRequestError(error instanceof Error?error.message:'Hasil belum dikonfirmasi.',0,!!preview);}
}

import { appUrl, boot } from './laravel';

export interface CatalogRole {id_role:number;code:string;name:string;system:boolean;workflow_name:string|null;version:string;users_count:number;assignments_count:number}
export interface RolePage {rows:CatalogRole[];page:number;has_more:boolean}
export type RoleChange={action:'save'|'delete';code:string;name?:string;id?:number;version?:string;request_id:string};
export class RoleCatalogError extends Error {constructor(message:string,public uncertain=false){super(message);}}

async function send(method:'GET'|'POST',path:string,input?:RoleChange,signal?:AbortSignal){
  if(!boot?.account)throw new RoleCatalogError('Masuk sebagai Superadmin untuk mengelola daftar peran.');
  let response:Response;
  try{
    response=await fetch(appUrl('/api/account/roles')+path,{method,credentials:'same-origin',cache:'no-store',signal:signal?AbortSignal.any([signal,AbortSignal.timeout(20000)]):AbortSignal.timeout(20000),headers:{Accept:'application/json',...(method==='POST'?{'Content-Type':'application/json','X-CSRF-TOKEN':boot.csrf}:{})},...(input?{body:JSON.stringify(input)}:{})});
  }catch(error){if(signal?.aborted)throw error;throw new RoleCatalogError(method==='POST'?'Koneksi terputus. Perubahan belum dapat dipastikan; tutup formulir dan muat ulang daftar sebelum mencoba kembali.':'Daftar peran tidak dapat dimuat. Periksa koneksi lalu muat ulang.',method==='POST');}
  const data=await response.json().catch(()=>null);
  if(response.status===401||response.status===419)throw new RoleCatalogError('Sesi berakhir. Silakan masuk kembali.');
  if(response.status===403)throw new RoleCatalogError('Hanya Superadmin yang dapat mengelola peran pengguna.');
  if(response.status===429)throw new RoleCatalogError('Terlalu banyak permintaan. Tunggu sebentar lalu coba kembali.');
  if(!response.ok){
    const errors=data?.errors as Record<string,string[]>|undefined;
    const message=errors?Object.values(errors).flat().join(' '):data?.message;
    throw new RoleCatalogError(response.status<500&&typeof message==='string'?message:'Perubahan belum dapat dipastikan. Tutup formulir dan muat ulang daftar.',method==='POST'&&response.status>=500);
  }
  if(!data)throw new RoleCatalogError('Respons server belum dapat dibaca. Muat ulang daftar.',method==='POST');
  return data;
}
export async function loadRoles(q:string,page:number,signal?:AbortSignal):Promise<RolePage>{
  return send('GET','?'+new URLSearchParams({q,page:String(page)}),undefined,signal);
}
export async function changeRole(input:RoleChange,signal?:AbortSignal):Promise<{role:Omit<CatalogRole,'users_count'|'assignments_count'>|null;message:string}>{
  const payload:RoleChange={action:input.action,code:input.code,request_id:input.request_id};
  if(input.action==='save')payload.name=input.name;
  if(input.id!==undefined){payload.id=input.id;payload.version=input.version;}
  return send('POST','',payload,signal);
}

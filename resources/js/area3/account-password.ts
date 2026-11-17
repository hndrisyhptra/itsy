import { appUrl, boot } from './laravel';

export type PasswordInput={current_password:string;password:string;password_confirmation:string};
export type PasswordErrors=Partial<Record<keyof PasswordInput,string>>;
export class PasswordChangeError extends Error {
  constructor(message:string,public fields:PasswordErrors={}){super(message);}
}
/** A single authenticated self-service request. Never persist or retry credentials. */
export async function changeOwnPassword(input:PasswordInput,signal?:AbortSignal):Promise<void>{
  if(!boot?.account)throw new PasswordChangeError('Masuk dengan akun pengguna untuk mengganti kata sandi.');
  let response:Response;
  try{
    response=await fetch(appUrl('/api/account/password'),{method:'PUT',credentials:'same-origin',cache:'no-store',signal,headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':boot.csrf},body:JSON.stringify({current_password:input.current_password,password:input.password,password_confirmation:input.password_confirmation})});
  }catch{
    throw new PasswordChangeError('Koneksi terputus. Status perubahan belum dapat dipastikan. Coba masuk dengan kata sandi baru sebelum mengulang.');
  }
  if(response.ok)return;
  if(response.status===401||response.status===419)throw new PasswordChangeError('Sesi Anda berakhir. Silakan masuk kembali sebelum mengganti kata sandi.');
  if(response.status===429)throw new PasswordChangeError('Terlalu banyak percobaan. Tunggu satu menit lalu coba kembali.');
  if(response.status===422){
    const body=await response.json().catch(()=>null),fields:PasswordErrors={};
    for(const key of ['current_password','password','password_confirmation'] as const){const message=body?.errors?.[key]?.[0];if(typeof message==='string')fields[key]=message;}
    throw new PasswordChangeError('Periksa kembali isian kata sandi.',fields);
  }
  throw new PasswordChangeError('Status perubahan belum dapat dipastikan. Coba masuk dengan kata sandi baru sebelum mengulang.');
}

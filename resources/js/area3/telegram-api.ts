import { appUrl, boot } from './laravel';
export class TelegramRequestError extends Error {constructor(message:string,public uncertain=false){super(message);}}
export async function telegramRequest(path:string,input?:Record<string,unknown>,signal?:AbortSignal){
  if(!boot?.account)throw new TelegramRequestError('Masuk ke akun aplikasi untuk mengatur Telegram.');
  const write=!!input;let response:Response;
  try{response=await fetch(appUrl('/api/telegram/'+path),{method:write?'POST':'GET',credentials:'same-origin',cache:'no-store',signal:signal?AbortSignal.any([signal,AbortSignal.timeout(20000)]):AbortSignal.timeout(20000),headers:{Accept:'application/json',...(write?{'Content-Type':'application/json','X-CSRF-TOKEN':boot.csrf}:{})},...(write?{body:JSON.stringify(input)}:{})});}
  catch(e){if(signal?.aborted)throw e;throw new TelegramRequestError(write?'Hasil perubahan belum pasti. Muat ulang status sebelum mencoba kembali.':'Status Telegram belum dapat dimuat.',write);}
  const data=await response.json().catch(()=>null);
  if(!response.ok){const message=data?.errors?Object.values(data.errors as Record<string,string[]>).flat().join(' '):data?.message;throw new TelegramRequestError(response.status===401||response.status===419?'Sesi berakhir. Silakan masuk kembali.':response.status===429?'Terlalu banyak permintaan. Tunggu sebentar.':response.status===403?'Akses Telegram tidak diizinkan.':typeof message==='string'&&response.status<500?message:response.status===503?'Jalankan migration Telegram pada server terlebih dahulu.':'Permintaan belum dapat dipastikan. Muat ulang status.',write&&(response.status>=500||response.status===409));}
  if(!data)throw new TelegramRequestError('Respons server tidak dapat dibaca. Muat ulang status.',write);
  return data;
}

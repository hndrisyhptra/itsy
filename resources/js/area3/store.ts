import { useSyncExternalStore } from 'react';
import { type State, type BoqRow, type BoqUpload, now, createTerm } from './domain';
import { paymentStartAction } from './payment-selection';
import { seed } from './seed';
import { boot, appUrl } from './laravel';
import { type Command, setCommandRecorder } from './commands';
import type { MasterEntity, MasterCatalog } from './master-data';
import { enqueueUpload, uploadTask, waitUpload } from './upload-queue';
const listeners = new Set<() => void>();
const DRAFT_PREFIX = 'area3-laravel-draft-' + (boot?.account ? boot.account.id + '-' : '');
let state: State = structuredClone(boot?.state ?? seed());
let confirmed = structuredClone(state);
let revision = boot?.revision ?? 0;
let csrf = boot?.csrf ?? '';
let active: Promise<void> | undefined;
export let persistenceError = '';
export let persistencePending = false;
const emit = () => listeners.forEach(fn => fn());
if (typeof window !== 'undefined') window.addEventListener('beforeunload', event => {
  if (!persistencePending) return;
  event.preventDefault();
  event.returnValue = '';
});
async function commit(command: Command, resultId?: string, scan?: { termId: string; file: File; kind?:'work-evidence' }): Promise<void> {
  if (!boot) return;
  persistencePending = true; persistenceError = ''; emit();
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 20000);
  try {
    const requestId = crypto.randomUUID();
    const form = new FormData();
    if (scan) { form.set('file', scan.file); form.set('revision', String(revision)); form.set('request_id', requestId); }
    const response = await fetch(scan ? appUrl('/api/prototype/payments/' + scan.termId + (scan.kind ? '/work-evidence' : '/scan')) : boot.urls.commands, { method:'POST', credentials:'same-origin', signal:controller.signal,
      headers: scan ? {'Accept':'application/json','X-CSRF-TOKEN':csrf} : {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
      body: scan ? form : JSON.stringify({...command,revision,request_id:requestId,result_id:resultId ?? null}) });
    const data = await response.json();
    if (response.status === 401 || response.status === 419) { if (boot.accountUrls) window.location.assign(boot.accountUrls.login); }
    if (!response.ok) {
      if (data.state) { confirmed = data.state; revision = data.revision; csrf = data.csrf ?? csrf; }
      const errors: string[] = Object.values(data.errors ?? {}).flat() as string[];
      throw new Error(errors.join('\n') || data.message || (response.status === 419 ? 'Sesi berakhir. Muat ulang halaman.' : 'Laravel menolak transaksi.'));
    }
    confirmed = data.state; state = structuredClone(confirmed); revision = data.revision; csrf = data.csrf ?? csrf;
  } catch (error) {
    try {
      const response = await fetch(boot.urls.state, {headers:{Accept:'application/json'},credentials:'same-origin',signal:AbortSignal.timeout(5000)});
      if (response.ok) { const data = await response.json(); confirmed=data.state; revision=data.revision; csrf=data.csrf ?? csrf; }
    } catch { /* Preserve the last confirmed state. Draft forms are kept locally. */ }
    state = structuredClone(confirmed);
    persistenceError = `${error instanceof Error ? error.message : 'Koneksi Laravel terputus.'} Perubahan belum dikonfirmasi; data server ditampilkan kembali. Draft formulir tetap tersedia.`;
    // Keep PP editor input mounted on a failed save; its explicit save handler shows the error.
    if(scan?.kind!=='work-evidence'&&command.command!=='saveProject')window.dispatchEvent(new CustomEvent('area3:server-error',{detail:persistenceError}));
    throw error;
  } finally { clearTimeout(timeout); persistencePending=false; emit(); }
}
function send(command: Command, resultId?: string) { active = commit(command,resultId); void active.catch(() => {}); }
export function waitForPersistence() { return active ?? Promise.resolve(); }
/** Read latest authorized state; resolve an existing term or await confirmed creation before navigation. */
export async function startPaymentFromSpk(spkId:string):Promise<string> {
  if(persistencePending)throw new Error('Tunggu konfirmasi penyimpanan sebelumnya.');
  if(boot)await refreshState();
  const action=paymentStartAction(state,spkId);
  if(action.action==='open')return action.termId;
  if(action.action!=='create')throw new Error(action.reason||'SPK belum dapat digunakan.');
  const id=mutate(s=>createTerm(s,spkId));
  await waitForPersistence();
  if(!state.terms.some(t=>t.id===id&&t.spkId===spkId))throw new Error('Draf belum dikonfirmasi. Muat ulang daftar sebelum mencoba kembali.');
  return id;
}
type MasterWriteResult={catalog:MasterCatalog;revision:number;summary?:MasterImportSummary;replayed?:boolean};
export type MasterImportSummary={created:number;updated:number;unchanged:number};
export type MasterImportPreview={summary:MasterImportSummary;rows:{row:number;code:string;package:string|null;action:'created'|'updated'|'unchanged';data:Record<string,string|number>}[];revision:number;sha256:string};
export class MasterRequestError extends Error {
  constructor(message:string,public status:number){super(message);}
}
async function masterWrite(path:string,body:BodyInit,json=false):Promise<MasterWriteResult> {
  if(!boot?.account||persistencePending)throw new Error('Gunakan sesi login aktif dan tunggu penyimpanan sebelumnya.');
  persistencePending=true; persistenceError=''; state=structuredClone(state); emit();
  const operation=(async()=>{
    try {
      const response=await fetch(appUrl(path),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf,...(json?{'Content-Type':'application/json'}:{})},body,signal:AbortSignal.timeout(60000)});
      if(response.status===401||response.status===419){if(boot.accountUrls)window.location.assign(boot.accountUrls.login);}
      const data=await response.json();
      if(!response.ok)throw new MasterRequestError((Object.values(data.errors??{}).flat() as string[]).join('\n')||data.message||'Master gagal disimpan.',response.status);
      confirmed=data.state; state=structuredClone(confirmed); revision=data.revision; csrf=data.csrf??csrf;
      return {catalog:data.catalog as MasterCatalog,revision:data.revision as number,summary:data.summary as MasterImportSummary|undefined,replayed:data.replayed as boolean|undefined};
    } finally {persistencePending=false; state=structuredClone(state); emit();}
  })();
  active=operation.then(()=>{}); void active.catch(()=>{});
  return operation;
}
export async function saveMaster(entity:MasterEntity,input:{action:'save'|'delete';id?:number;data?:Record<string,unknown>;revision:number}):Promise<MasterWriteResult> {
  return masterWrite('/api/masters/'+entity,JSON.stringify({...input,request_id:crypto.randomUUID()}),true);
}
function masterImportForm(file:File,mode:string,expectedRevision:number,stage:string):FormData {
  const form=new FormData();form.set('file',file);form.set('mode',mode);form.set('revision',String(expectedRevision));form.set('stage',stage);return form;
}
export async function previewMasterImport(entity:'designator'|'khs',file:File,mode:string,expectedRevision:number):Promise<MasterImportPreview> {
  if(!boot?.account||persistencePending)throw new Error('Tunggu penyimpanan sebelumnya selesai.');
  const response=await fetch(appUrl('/api/masters/'+entity+'/import'),{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf},body:masterImportForm(file,mode,expectedRevision,'preview'),signal:AbortSignal.timeout(60000)});
  if(response.status===401||response.status===419){if(boot.accountUrls)window.location.assign(boot.accountUrls.login);}
  const data=await response.json();
  if(!response.ok)throw new MasterRequestError((Object.values(data.errors??{}).flat() as string[]).join('\n')||data.message||'File gagal diperiksa.',response.status);
  return data as MasterImportPreview;
}
export async function commitMasterImport(entity:'designator'|'khs',file:File,mode:string,preview:MasterImportPreview,requestId:string):Promise<MasterWriteResult> {
  const form=masterImportForm(file,mode,preview.revision,'commit');form.set('sha256',preview.sha256);form.set('request_id',requestId);
  return masterWrite('/api/masters/'+entity+'/import',form);
}
export async function uploadBoq(file:File,documentId:string,onProgress?:(message:string)=>void,signal?:AbortSignal):Promise<{rows:BoqRow[];source:BoqUpload}> {
  if (!boot || persistencePending) throw new Error('Upload BOQ memerlukan sesi login aktif; tunggu transaksi sebelumnya selesai.');
  const task=await enqueueUpload('boq',documentId,file,undefined,signal);
  await waitUpload(task,onProgress,signal);
  return (await uploadTask(task.id,true,signal)).result;
}
export async function refreshState():Promise<void> {
  if(!boot)return;
  for(let attempt=0;attempt<3;attempt++){
    if(persistencePending)await waitForPersistence().catch(()=>{});
    const response=await fetch(boot.urls.state,{credentials:'same-origin',headers:{Accept:'application/json'},signal:AbortSignal.timeout(15000)});
    if(!response.ok)throw new Error('Data terbaru belum dapat dimuat. Gunakan Muat ulang data.');
    const data=await response.json();
    if(persistencePending||data.revision<revision)continue;
    confirmed=data.state;state=structuredClone(confirmed);revision=data.revision;csrf=data.csrf??csrf;emit();return;
  }
  throw new Error('Data sedang berubah. Muat ulang untuk melihat hasil upload terbaru.');
}
export function uploadTermScan(termId: string, file: File) {
  if (!boot || persistencePending) return Promise.reject(new Error('Tunggu konfirmasi transaksi Laravel sebelumnya.'));
  // useSyncExternalStore needs a new snapshot to render the upload's pending state.
  state = structuredClone(state);
  active = commit({ command: 'uploadScan', args: [] }, undefined, { termId, file });
  return active;
}
export async function queueTermWorkEvidence(termId:string,file:File) {
  // This is an append to the current server term; it never replaces client state.
  if(!boot)throw new Error('Gunakan sesi login aktif.');
  return enqueueUpload('evidence',termId,file);
}
export async function uploadTermWorkEvidence(termId:string,file:File,onProgress?:(message:string)=>void) {
  if(!boot||persistencePending)return Promise.reject(new Error('Tunggu transaksi sebelumnya dan gunakan sesi login aktif.'));
  const task=await queueTermWorkEvidence(termId,file);
  await waitUpload(task,onProgress);
  await refreshState();
}
export function mutate<T>(fn: (draft: State) => T): T {
  if (persistencePending) throw new Error('Tunggu konfirmasi transaksi Laravel sebelumnya.');
  const next = structuredClone(state);
  let command: Command | undefined;
  setCommandRecorder(value => { if (command) throw new Error('Satu transaksi hanya boleh menjalankan satu perintah.'); command=value; });
  let result: T;
  try { result=fn(next); } finally { setCommandRecorder(); }
  if (!command && next.role !== state.role) command={command:'role',args:[next.role]};
  if (!command) throw new Error('Transaksi tidak memiliki perintah layanan Laravel.');
  next.lastSaved=now(); state=next;
  if (boot) send(command,typeof result === 'string' ? result : undefined);
  else { confirmed=structuredClone(state); emit(); }
  return result;
}
export function reset(scenario='overview') {
  if (persistencePending) throw new Error('Tunggu transaksi sebelumnya sebelum reset.');
  state=structuredClone(boot?.scenarios[scenario] ?? seed(scenario));
  if (boot) send({command:'reset',args:[scenario]}); else { confirmed=structuredClone(state); emit(); }
}
export function useStore() { return useSyncExternalStore(cb => {listeners.add(cb);return () => listeners.delete(cb);},()=>state); }
export function saveDraft(key:string,value:unknown) { try {localStorage.setItem(DRAFT_PREFIX+key,JSON.stringify(value));} catch {} }
export function readDraft<T>(key:string,fallback:T):T { try {const value=localStorage.getItem(DRAFT_PREFIX+key);return value ? JSON.parse(value) : fallback;} catch {return fallback;} }
export function clearDraft(key:string) {
  const remove = () => { try {localStorage.removeItem(DRAFT_PREFIX+key);} catch {} };
  if (persistencePending) void waitForPersistence().then(remove).catch(() => {}); else remove();
}
export function clearAllDrafts() { try {Object.keys(localStorage).filter(key=>key.startsWith(DRAFT_PREFIX)).forEach(key=>localStorage.removeItem(key));} catch {} }

import type {State,Term,Bapp} from './domain';
const sorted=(value:unknown):unknown=>Array.isArray(value)?value.map(sorted):value&&typeof value==='object'?Object.fromEntries(Object.entries(value).sort(([a],[b])=>a.localeCompare(b,'en')).map(([k,v])=>[k,sorted(v)])):value;
export const bappBasis=(t:Term)=>({spkId:t.spkId,type:t.type,actuals:t.actuals,barp:t.barp??null,moneyPolicy:t.moneyPolicy??null});
export function bappCurrent(b:Bapp|undefined,t:Term){return !!b&&b.termId===t.id&&b.status==='Sah'&&b.completionPolicy==='barp-before-approval-v1'&&JSON.stringify(sorted(b.source))===JSON.stringify(sorted(bappBasis(t)));}
export function assertBappBeforePayment(s:State,t:Term){if(t.type==='Final'&&t.completionFlow==='bapp-before-payment-v1'&&!bappCurrent(s.bapps.find(b=>b.termId===t.id),t))throw new Error('Sahkan BA PP sesuai BA RP tersimpan sebelum mengajukan atau memproses pembayaran Final.');}
export function ensureBapp(s:State,t:Term,early=false){
  let b=s.bapps.find(b=>b.termId===t.id);
  if(!b){b={id:'bapp-'+crypto.randomUUID(),termId:t.id,no:`BAPP/${s.spks.find(v=>v.id===t.spkId)!.no}`,status:'Draft',evidence:'',released:{},releaseHistory:[]};s.bapps.push(b);}
  if(early&&!b.completionPolicy&&b.status!=='Sah')b.completionPolicy='barp-before-approval-v1';
  if(b.completionPolicy==='barp-before-approval-v1'&&b.status==='Sah'&&!bappCurrent(b,t)){
    (b.attestationHistory??=[]).push({source:b.source!,at:b.attestedAt!,by:b.attestedBy!,invalidatedAt:new Date().toISOString(),invalidatedBy:s.actorUserId??'',reason:'BARP berubah'});b.status='Draft';delete b.source;delete b.attestedAt;delete b.attestedBy;
  }
  return b;
}
/** Financial approval is deliberately not a prerequisite for the physical completion letter. */
export function bappPhysicalReady(b:Bapp|undefined,t:Term){return bappCurrent(b,t)&&['Draft','Diajukan','Disetujui'].includes(t.status);}

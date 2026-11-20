import {settlementRecorded} from './payment-settlement';
import type {State,Term,Bapp} from './domain';
const sorted=(value:unknown):unknown=>Array.isArray(value)?value.map(sorted):value&&typeof value==='object'?Object.fromEntries(Object.entries(value).sort(([a],[b])=>a.localeCompare(b,'en')).map(([k,v])=>[k,sorted(v)])):value;
export const bappBasis=(t:Term)=>({spkId:t.spkId,type:t.type,actuals:t.actuals,barp:t.barp??null,moneyPolicy:t.moneyPolicy??null});
export const requiresBarp=(t:Term)=>t.barpFlow==='attest-before-bapp-v1';
export function barpKasbonReturned(t:Term){const a=t.history.at(-1);return t.paymentFlow==='kasbon-settlement-v2'&&!!t.kasbon?.id&&a?.kasbonId===t.kasbon.id&&['Tolak','Kembalikan'].includes(a?.decision??'');}
export function bappStatusAllowed(t:Term){return ['Draft','Diajukan','Disetujui'].includes(t.status)||t.status==='Dikembalikan'&&t.workflowVersion===3&&barpKasbonReturned(t)&&t.approvals.length===0&&t.paid===0&&!settlementRecorded(t);}
export function barpCurrent(t:Term){const a=t.barpAttestation;return requiresBarp(t)&&a?.status==='Sah'&&!!a.by&&!!a.at&&JSON.stringify(sorted(a.source))===JSON.stringify(sorted(bappBasis(t)));}
export function invalidateBarp(s:State,t:Term){if(requiresBarp(t)&&t.barpAttestation&&!barpCurrent(t)){(t.barpAttestationHistory??=[]).push({...t.barpAttestation,invalidatedAt:new Date().toISOString(),invalidatedBy:s.actorUserId??'',reason:'BA RP berubah'});delete t.barpAttestation;}}
export const earlyBapp=(b:Bapp|undefined)=>!!b&&['barp-before-approval-v1','barp-each-payment-v2'].includes(b.completionPolicy??'');
export const requiresBapp=(t:Term)=>t.completionFlow==='bapp-each-payment-v2'||t.type==='Final'&&t.completionFlow==='bapp-before-payment-v1';
export function bappCurrent(b:Bapp|undefined,t:Term){return !!b&&b.termId===t.id&&b.status==='Sah'&&(!requiresBarp(t)||barpCurrent(t))&&earlyBapp(b)&&JSON.stringify(sorted(b.source))===JSON.stringify(sorted(bappBasis(t)));}
export function assertBappBeforePayment(s:State,t:Term){if(requiresBapp(t)&&!bappCurrent(s.bapps.find(b=>b.termId===t.id),t))throw new Error('Sahkan BA PP sesuai BA RP tersimpan sebelum mengajukan atau memproses pembayaran.');}
export function ensureBapp(s:State,t:Term,early=false){
  let b=s.bapps.find(b=>b.termId===t.id);
  if(!b){let no=`BAPP/${s.spks.find(v=>v.id===t.spkId)!.no}${t.completionFlow==='bapp-each-payment-v2'?'/T'+t.number:''}`;if([...no].length>150)no=`BAPP/${t.id}/T${t.number}`;b={id:'bapp-'+crypto.randomUUID(),termId:t.id,no,status:'Draft',...(requiresBarp(t)?{date:t.barp?.date??''}:{}),evidence:'',released:{},releaseHistory:[]};s.bapps.push(b);}
  if(early&&!b.completionPolicy&&b.status!=='Sah')b.completionPolicy='barp-before-approval-v1';
  if(earlyBapp(b)&&b.status==='Sah'&&!bappCurrent(b,t)){
    (b.attestationHistory??=[]).push({source:b.source!,at:b.attestedAt!,by:b.attestedBy!,invalidatedAt:new Date().toISOString(),invalidatedBy:s.actorUserId??'',reason:'BARP berubah'});b.status='Draft';delete b.source;delete b.attestedAt;delete b.attestedBy;
  }
  if(early&&b.status!=='Sah'&&t.completionFlow==='bapp-each-payment-v2')b.completionPolicy='barp-each-payment-v2';
  return b;
}
/** Financial approval is deliberately not a prerequisite for the physical completion letter. */
export function bappPhysicalReady(b:Bapp|undefined,t:Term){return bappCurrent(b,t)&&bappStatusAllowed(t);}

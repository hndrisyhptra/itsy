import {readFileSync} from 'node:fs';
import {seed} from '../../../resources/js/area3/seed';
import {approvalRoles,type Bapp} from '../../../resources/js/area3/domain';
import {historicalBarp} from '../../../resources/js/area3/barp-content';
import {bappBasis} from '../../../resources/js/area3/bapp-workflow';
import {kasbonQuote} from '../../../resources/js/area3/kasbon-settlement';
import type {KasbonData} from '../../../resources/js/area3/kasbon-content';
/** Synthetic saved data only, no SQL, account writes or real private recipients. */
export function paidPackageFixture(number:1|2=1,operation=false){
 const s=seed('E'),v=s.spks[0],d=s.deals.find(d=>d.id===v.dealId)!,p=s.projects.find(p=>p.id===d.projectId)!,kasbons=new Map<string,KasbonData>();
 s.schema=3;p.unit=operation?'Operation':'Konstruksi';s.bapps=[];
 for(const t of s.terms){
  t.type='Parsial';t.workflowVersion=3;t.unit=p.unit;t.approvalScheme=operation?'operation-v1':'project-support-first';t.paymentFlow='kasbon-settlement-v2';t.moneyPolicy='idr-trunc-v1';t.completionFlow='bapp-each-payment-v2';t.barpFlow='attest-before-bapp-v1';t.barp={...historicalBarp(d,v),date:'2026-11-20'};
  const quote=kasbonQuote(d,t),id='kasbon-stage-'+t.number,proof='proof-stage-'+t.number;
  t.kasbon={id,version:1,amount:quote.net,issuedAt:'2026-11-20T00:00:00Z',issuedBy:'qa-admin',settlement:quote};t.status='Disetujui';t.apm='';t.apmStatus='Belum Terbit';t.paid=quote.net;
  t.transferProof={id:proof,kasbonId:id,uploadedBy:'qa-finance',uploadedAt:'2026-11-20T00:00:00Z'};t.financePayment={status:'Dibayar',kasbonId:id,transferFileId:proof,settledAt:'2026-11-20T01:00:00Z',settledBy:'qa-finance'};
  const roles=approvalRoles(t);t.approverUserIds=Object.fromEntries(roles.map((r,i)=>[r,'qa-role-'+i]));t.approvals=roles.map((role,i)=>({role,userId:'qa-role-'+i,actorName:'QA '+role,decision:'Setujui' as const,note:'QA approved',at:'2026-11-20T00:00:00Z',kasbonId:id}));t.history=structuredClone(t.approvals);
  t.barpAttestation={status:'Sah',by:'qa-admin',at:'2026-11-20T00:00:00Z',source:structuredClone(bappBasis(t))};
  const b:Bapp={id:'paid-bapp-'+t.number,termId:t.id,no:'BAPP/QA/T'+t.number,status:'Sah',date:'2026-11-23',completionPolicy:'barp-each-payment-v2',source:structuredClone(bappBasis(t)),attestedBy:'qa-admin',attestedAt:'2026-11-20T00:00:00Z',evidence:'',released:{},releaseHistory:[]};s.bapps.push(b);
  const data=JSON.parse(readFileSync(new URL('../../Fixtures/kasbon-vector.json',import.meta.url),'utf8')) as KasbonData;
  Object.assign(data,{amount:quote.net,settlement:quote,rows:[{accountCode:'QA',accountName:'Biaya QA',text:'PAYMENT-STAGE-'+t.number,amount:String(quote.net)}],unit:p.unit,project:p.name,projectCode:p.projectCode,ppNo:p.no,spkNo:v.no,termNumber:t.number,termType:t.type,approvalScheme:t.approvalScheme});data.payee.accountNumber='ACCOUNT-STAGE-'+t.number;kasbons.set(t.id,data);
 }
 const t=s.terms.find(t=>t.number===number)!,b=s.bapps.find(b=>b.termId===t.id)!;
 return {s,p,d,v,t,b,kasbons};
}

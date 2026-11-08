import type { State, Term } from './domain';
import { today } from './domain';
import { projectHeadIdentity } from './bakh-signer';
import { bakhDocumentDate } from './bakh-content';

export interface BarpData { version:1;date:string;place:string;start:string;end:string;location:string;sto:string;homepass:number|null;waspang:string;mandor:string;headName:string;headNik:string;headUserId:string }
export function blankBarp(s:State,t:Term):BarpData {
  const v=s.spks.find(v=>v.id===t.spkId)!,d=s.deals.find(d=>d.id===v.dealId)!,p=s.projects.find(p=>p.id===d.projectId)!;
  const head=(t.workflowVersion??1)>=3?projectHeadIdentity(s,{...p,approverUserIds:t.approverUserIds,approvals:[...t.approvals,...(p.approvals??[])]}):v.signerSource==='pp-head-pmo'?v:undefined;
  return {version:1,date:today(),place:v.place,start:v.start,end:v.end,location:d.location??'',sto:'',homepass:null,waspang:v.supervisor,mandor:d.mandor,headName:head?.signer??'',headNik:head?.signerNik??'',headUserId:head?.signerUserId??''};
}
export function saveBarp(s:State,t:Term,input:Term,submit:boolean):Term {
  if(!t.barp&&!Object.hasOwn(input,'barp'))return t;
  const data=Object.hasOwn(input,'barp')?input.barp:t.barp;
  if(!data||typeof data!=='object'||Array.isArray(data)||Object.keys(data).length===0)throw new Error('Informasi BARP harus berupa formulir yang valid.');
  const barp={...(t.barp??blankBarp(s,t))};
  for(const [key,limit] of [['date',10],['place',150],['start',10],['end',10],['location',500],['sto',150]] as const){
    if(Object.hasOwn(data,key)){if(typeof data[key]!=='string'||[...data[key]].length>limit)throw new Error(`Isian BARP ${key} tidak valid atau terlalu panjang.`);barp[key]=data[key].trim();}
  }
  const hp=Object.hasOwn(data,'homepass')?data.homepass:barp.homepass;
  if(hp!==null&&(typeof hp!=='number'||!Number.isInteger(hp)||hp<0||hp>1000000000))throw new Error('Homepass BARP harus bilangan bulat nonnegatif atau dikosongkan.');
  barp.homepass=hp;barp.version=1;
  for(const key of ['date','start','end'] as const)if(barp[key]&&!bakhDocumentDate(barp[key]))throw new Error('Tanggal BARP tidak valid.');
  const v=s.spks.find(v=>v.id===t.spkId)!;
  if((barp.start&&barp.start<v.start)||(barp.end&&barp.end>v.end)||(barp.start&&barp.end&&barp.start>barp.end))throw new Error('Periode rekonsiliasi harus berada dalam periode SPK.');
  if(submit&&(['date','place','start','end','location'] as const).some(key=>!barp[key]))throw new Error('Lengkapi tanggal, tempat, periode dan lokasi pada Informasi BARP sebelum mengajukan.');
  return {...t,barp};
}

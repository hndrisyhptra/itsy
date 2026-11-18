import { isSingleFinalBarp } from './barp-labels';
import { rupiahNumber, monetaryRound, moneyLine } from './currency';
import type { State,Project,Deal,Spk,Term } from './domain';
import { termVolumeData } from './term-volume';
import { barpDocumentData } from './barp-content';
import { paymentSigner } from './barp-signers';
const number=rupiahNumber;
const volume=new Intl.NumberFormat('id-ID',{maximumFractionDigits:3});
export const BARP_APPENDIX_TITLE='LAMPIRAN BERITA ACARA REKONSILIASI PEKERJAAN';
export const BARP_APPENDIX_WIDTHS=[3,10,23,6,7,7,12,6,8,8,10];
export const BARP_APPENDIX_LEFT=['NO','DESIGNATOR','URAIAN','SATUAN'];
export const BARP_APPENDIX_RIGHT=['TOTAL VOL','MATERIAL','JASA','JUMLAH'];
export function barpAppendixData(state:State,project:Project,deal:Deal,spk:Spk,term:Term){
  const singleFinal=isSingleFinalBarp(term);
  const numbers=singleFinal?[1]:Array.from({length:Math.max(1,Math.min(3,term.number))},(_,i)=>i+1);
  const weights=[...BARP_APPENDIX_WIDTHS.slice(0,6),...numbers.map(()=>12),numbers.length>1?8:BARP_APPENDIX_WIDTHS[7],...BARP_APPENDIX_WIDTHS.slice(8)];
  const weightTotal=weights.reduce((a,b)=>a+b,0),widths=weights.map(w=>100*w/weightTotal);
  widths[widths.length-1]=100-widths.slice(0,-1).reduce((a,b)=>a+b,0);
  const note=singleFinal?'Harga dan nilai dalam Rupiah (Rp). Total Vol dan nilai mengikuti aktual pembayaran Final satu kali.':'Harga dan nilai dalam Rupiah (Rp). Total Vol, Material, Jasa dan Jumlah adalah kumulatif termin sebelumnya yang disetujui ditambah termin ini, bukan tagihan ulang. Tagihan termin ini ditampilkan terpisah di rekap. Pembulatan mengikuti masing-masing termin.';
  const ledger=termVolumeData(state,project,deal,spk,term),letter=barpDocumentData(project,deal,spk,term);
  // Captured snapshot first. Historical fallback is an actual matching decision/PP snapshot, never today's account.
  const deployment=term.barp?.deployment??paymentSigner(state,project,term,'Mgr Deployment');
  const support=term.barp?.support??paymentSigner(state,project,term,'Mgr Project Support');
  const signatures=[
    {job:letter.headJob,name:letter.head,nik:term.barp?.headNik??''},
    {job:deployment.job?.trim()||'MGR PROJECT DEPLOYMENT AREA JAWA BALI',name:deployment.name||'…',nik:deployment.nik},
    {job:support.job?.trim()||'MGR PROJECT SUPPORT AREA JAWA BALI',name:support.name||'…',nik:support.nik},
    {job:'WASPANG PROJECT',name:letter.waspang,nik:''},
    {job:'KETUA MANDOR',name:letter.mandor,nik:''},
  ];
  const projectHeaders=numbers.map(n=>singleFinal?letter.job:`${letter.job} · Termin #${n}`);
  const previous=new Map(state.terms.filter(t=>t.spkId===spk.id&&t.number<term.number&&t.status==='Disetujui').map(t=>[t.number,t]));
  const agreements=new Map(deal.rows.map(r=>[r.rowId,r]));
  // Each saved term keeps its own money policy; cumulative display adds canonical line amounts.
  const add=(a:number,b:number)=>monetaryRound(a+b,{});
  const groups=new Map<string,typeof ledger.rows>();
  for(const row of ledger.rows){const group=groups.get(row.rowId)??[];group.push(row);groups.set(row.rowId,group);}
  const rows:{id:string;component:string;cells:string[]}[]=[];
  let splitVolumes=false;
  for(const [rowId,group] of groups){
    const agreement=agreements.get(rowId)!,m=group.find(r=>r.component==='m'),j=group.find(r=>r.component==='s');
    const split=!!m&&!!j&&(m.quantity!==j.quantity||numbers.some(n=>m.actuals[n-1]!==j.actuals[n-1]));
    splitVolumes ||= split;
    const amounts=(component:'m'|'s')=>numbers.reduce((sum,n)=>{
      if(n===term.number)return add(sum,group.find(r=>r.component===component)?.amount??0);
      const prior=previous.get(n);if(!prior)return sum;
      const actual=prior.actuals[rowId],material=moneyLine(agreement.m?(actual?.m??0):0,agreement.pm,0,0,prior);
      const total=moneyLine(agreement.m?(actual?.m??0):0,agreement.pm,agreement.s?(actual?.s??0):0,agreement.ps,prior);
      return add(sum,component==='m'?material:monetaryRound(total-material,prior));
    },0);
    const material=amounts('m'),service=amounts('s');
    for(const part of split?group.map(r=>[r]):[group]){
      const hasM=part.some(r=>r.component==='m'),hasS=part.some(r=>r.component==='s'),base=part[0];
      const quantities=numbers.map(n=>base.actuals[n-1]);
      const totalVol=Math.round(quantities.reduce<number>((sum,q)=>sum+(q??0),0)*1000)/1000;
      const amount=add(hasM?material:0,hasS?service:0);
      rows.push({id:`${rowId}-${split?base.component:'both'}`,component:split?(hasM?'Material':'Jasa'):'',cells:[
        String(rows.length+1),base.code,base.description,base.unit,
        hasM?number.format(agreement.pm):'',hasS?number.format(agreement.ps):'',
        ...quantities.map(q=>q===null?'':volume.format(q)),volume.format(totalVol),
        hasM?number.format(material):'',hasS?number.format(service):'',number.format(amount),
      ]});
    }
  }
  const perTerm=numbers.map(n=>n===term.number?ledger.current:ledger.prior.find(p=>p.number===n));
  const cumulative={material:perTerm.reduce((sum,a)=>add(sum,a?.material??0),0),service:perTerm.reduce((sum,a)=>add(sum,a?.service??0),0),total:perTerm.reduce((sum,a)=>add(sum,a?.total??0),0)};
  const summaryRows=(['material','service','total'] as const).map((category,i)=>({label:['JUMLAH MATERIAL','JUMLAH JASA','JUMLAH HARGA'][i],values:[
    '','',...perTerm.map(a=>a&&(category==='total'||category==='material'&&ledger.hasMaterial||category==='service'&&ledger.hasService)?number.format(a[category]):''),
    category==='total'||category==='material'&&ledger.hasMaterial||category==='service'&&ledger.hasService?number.format(cumulative[category]):'',
    category==='material'&&ledger.hasMaterial?number.format(cumulative.material):'',category==='service'&&ledger.hasService?number.format(cumulative.service):'',category==='total'?number.format(cumulative.total):'',
  ]}));
  const recap=singleFinal?[]:[
    {label:`MATERIAL TERMIN #${term.number}`,value:ledger.hasMaterial?number.format(ledger.current.material):''},
    {label:`JASA TERMIN #${term.number}`,value:ledger.hasService?number.format(ledger.current.service):''},
    {label:`TAGIHAN TERMIN #${term.number} — SAAT INI`,value:number.format(ledger.current.total)},
  ];
  return {...ledger,widths,numbers,projectHeaders,note,jobSuffix:singleFinal?'':` · Termin #${term.number}`,title:BARP_APPENDIX_TITLE,job:letter.job,witel:deal.witel||'',stage:term.number,status:letter.status,rows,recap,summaryRows,splitVolumes,cumulative,signatures};
}

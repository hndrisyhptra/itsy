import { isSingleFinalBarp } from './barp-labels';
import { rupiahNumber } from './currency';
import type { State,Project,Deal,Spk,Term } from './domain';
import { termVolumeData } from './term-volume';
import { barpDocumentData } from './barp-content';
import { paymentSigner } from './barp-signers';
const number=rupiahNumber;
const volume=new Intl.NumberFormat('id-ID',{maximumFractionDigits:3});
export const BARP_APPENDIX_TITLE='LAMPIRAN BERITA ACARA REKONSILIASI PEKERJAAN';
export const BARP_APPENDIX_WIDTHS=[3,9,23,5,5,5,5,5,6,8,7,9,10];
export const BARP_APPENDIX_LEFT=['NO','DESIGNATOR','URAIAN / KOMPONEN','SATUAN','VOLUME SPK'];
export const BARP_APPENDIX_RIGHT=['AKTUAL SAAT INI','ACCEPTED SEBELUMNYA','SISA PROYEKSI','HARGA SATUAN','NILAI SAAT INI'];
export function barpAppendixData(state:State,project:Project,deal:Deal,spk:Spk,term:Term){
  const singleFinal=isSingleFinalBarp(term);
  const widths=singleFinal?[...BARP_APPENDIX_WIDTHS.slice(0,5),15,...BARP_APPENDIX_WIDTHS.slice(8)]:BARP_APPENDIX_WIDTHS;
  const actualHeaders=singleFinal?['Final']:[1,2,3].map(n=>`Termin #${n}${n===term.number?' · Saat ini':''}`);
  const note=singleFinal?'Harga dan nilai dalam Rupiah (Rp). Material dan Jasa dicatat terpisah. Sisa proyeksi belum merupakan pelepasan volume. Tagihan merupakan pembayaran Final satu kali.':'Harga dan nilai dalam Rupiah (Rp). Material dan Jasa dicatat terpisah. Sisa proyeksi belum merupakan pelepasan volume. Tagihan hanya tahap ini; nilai kumulatif adalah informasi.';
  const ledger=termVolumeData(state,project,deal,spk,term),letter=barpDocumentData(project,deal,spk,term);
  // Captured snapshot first. Historical fallback is an actual matching decision/PP snapshot, never today's account.
  const deployment=term.barp?.deployment??paymentSigner(state,project,term,'Mgr Deployment');
  const support=term.barp?.support??paymentSigner(state,project,term,'Mgr Project Support');
  const signatures=[
    {job:'HEAD OF PMO AREA JAWA BALI',name:letter.head,nik:term.barp?.headNik??''},
    {job:'MGR PROJECT DEPLOYMENT AREA JAWA BALI',name:deployment.name||'…',nik:deployment.nik},
    {job:'MGR PROJECT SUPPORT AREA JAWA BALI',name:support.name||'…',nik:support.nik},
    {job:'WASPANG PROJECT',name:letter.waspang,nik:''},
    {job:'KETUA MANDOR',name:letter.mandor,nik:''},
  ];
  const rows=ledger.rows.map((r,i)=>({id:r.id,cells:[String(i+1),r.code,`${r.description}\n${r.component==='m'?'Material':'Jasa'}`,r.unit,volume.format(r.quantity),...(singleFinal?[r.actuals[0]]:r.actuals).map(q=>q===null?'':volume.format(q)),volume.format(r.current),volume.format(r.accepted),volume.format(r.remaining),number.format(r.price),number.format(r.amount)]}));
  const recap=[
    {label:`JUMLAH MATERIAL${singleFinal?'':` TAHAP ${term.number}`}`,value:ledger.hasMaterial?number.format(ledger.current.material):''},
    {label:`JUMLAH JASA${singleFinal?'':` TAHAP ${term.number}`}`,value:ledger.hasService?number.format(ledger.current.service):''},
    {label:`JUMLAH HARGA / TAGIHAN${singleFinal?'':` TAHAP ${term.number}`}`,value:number.format(ledger.current.total)},
    ...ledger.prior.map(p=>({label:`TERMIN ${p.number} — DISETUJUI SEBELUMNYA`,value:number.format(p.total)})),
    {label:`NILAI KUMULATIF${singleFinal?'':` SAMPAI TAHAP ${term.number}`}`,value:number.format(ledger.cumulativeTotal)},
  ];
  return {...ledger,widths,actualHeaders,note,actualHeading:singleFinal?'VOLUME AKTUAL':'VOLUME AKTUAL PER TERMIN',jobSuffix:singleFinal?'':` · Termin #${term.number}`,title:BARP_APPENDIX_TITLE,job:letter.job,witel:deal.witel||'',stage:term.number,status:letter.status,rows,recap,signatures};
}

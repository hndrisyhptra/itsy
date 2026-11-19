import { projectSigningPlace } from './document-place';
import { bappPhysicalReady } from './bapp-workflow';
import { dealTotal, type State, type Project, type Deal, type Spk, type Term, type Bapp } from './domain';
import { barpAppendixData } from './barp-appendix-content';
import type { WordRun } from './word-ooxml';
import { barpDocumentData } from './barp-content';
import { bakhDocumentDate } from './bakh-content';
import { isSingleFinalBarp } from './barp-labels';

const percent=new Intl.NumberFormat('id-ID',{maximumFractionDigits:2});
/** Presentation of the selected inspection/payment only; never write BA PP identity or ledger. */
export function bappDocumentData(project:Project,deal:Deal,spk:Spk,term:Term,bapp?:Bapp){
  const barp=barpDocumentData(project,deal,spk,term),single=isSingleFinalBarp(term);
  const date=bakhDocumentDate(term.barp?.date),start=bakhDocumentDate(term.barp?.start??spk.start),end=bakhDocumentDate(term.barp?.end??spk.end);
  const contract=dealTotal(deal);
  const data={
    title:'BERITA ACARA PENYELESAIAN PEKERJAAN',date,
    job:barp.job,mandor:barp.mandor,waspang:barp.waspang,head:barp.head,headJob:barp.headJob,status:bappPhysicalReady(bapp,term)?'':barp.status,
    start:start?.full??'…',end:end?.full??'…',progress:contract>0?`${percent.format(barp.total/contract*100)}%`:'…',
    stage:single?'':`Tahap ${term.number}`,payment:single?'pembayaran':`Termin ${term.number}`,
    total:barp.total,totalText:`Rp ${barp.totalText},-`,
    placeDate:`${projectSigningPlace(project)||'…'}, ${date?.full??'…'}`,
  };
  const opening:WordRun[]=[{text:'Pada hari '},{text:date?.weekday??'…',bold:true,italic:true},{text:' Tanggal '},{text:date?.dayWords??'…',bold:true,italic:true},{text:' Bulan '},{text:date?.month??'…',bold:true,italic:true},{text:' Tahun '},{text:date?.yearWords??'…',bold:true,italic:true},{text:', kami yang bertanda tangan di bawah ini :'}];
  const result:WordRun[]=[{text:'Prestasi kerja fisik pada saat pemeriksaan yang telah dikerjakan periode tanggal '},{text:data.start,bold:true},{text:' sampai dengan '},{text:data.end,bold:true},{text:' sesuai dengan Rekonsiliasi'},...(data.stage?[{text:' '},{text:data.stage,bold:true}]:[]),{text:' telah dikerjakan sebesar '},{text:data.progress,bold:true},{text:' dengan nilai '},{text:data.payment,bold:true},{text:' sebesar '},{text:data.totalText,bold:true},{text:' Belum dipotong pajak penghasilan.'}];
  const closing:WordRun[]=[{text:'Demikian Berita Acara ini dibuat sesuai dengan kondisi lapangan yang sebenarnya sebagai dasar Rekonsiliasi'},...(data.stage?[{text:' '},{text:data.stage,bold:true}]:[]),{text:', serta untuk dipergunakan seperlunya.'}];
  return {...data,opening,result,closing};
}

export function bappAppendixData(state:State,project:Project,deal:Deal,spk:Spk,term:Term,bapp?:Bapp){
  const data=barpAppendixData(state,project,deal,spk,term);
  return {...data,status:bappPhysicalReady(bapp,term)?'':data.status,title:'LAMPIRAN BERITA ACARA PENYELESAIAN PEKERJAAN',signatures:[data.signatures[0],...data.signatures.slice(-2)]};
}

import type { Project, Deal, Spk, Term } from './domain';
import { approvalRoles,selectedApprover } from './domain';
import { bakhDocumentDate } from './bakh-content';
import type { BarpData } from './barp-form';
const filled=(s?:string)=>s?.trim()||'…';
const round=(n:number)=>Math.round((n+Number.EPSILON)*100)/100;
const number=new Intl.NumberFormat('id-ID',{maximumFractionDigits:2});
const words=['Nol','Satu','Dua','Tiga','Empat','Lima','Enam','Tujuh','Delapan','Sembilan','Sepuluh','Sebelas'];
function integerWords(n:number):string {
  if(n<12)return words[n];
  if(n<20)return `${words[n-10]} Belas`;
  for(const [size,label] of [[1e12,'Triliun'],[1e9,'Miliar'],[1e6,'Juta'],[1e3,'Ribu'],[100,'Ratus'],[10,'Puluh']] as const){
    if(n<size)continue;
    const q=Math.floor(n/size),r=n%size;
    const prefix=q===1&&size===100?'Seratus':q===1&&size===1000?'Seribu':`${integerWords(q)} ${label}`;
    return prefix+(r?` ${integerWords(r)}`:'');
  }
  return '';
}
export function rupiahWords(value:number){
  if(!Number.isFinite(value)||value<0||value>Number.MAX_SAFE_INTEGER)throw new Error('Nilai BARP tidak valid.');
  const [whole,cents]=round(value).toFixed(2).split('.');
  return `${integerWords(Number(whole))}${cents==='00'?'':` Koma ${[...cents].map(n=>words[Number(n)]).join(' ')}`} Rupiah`;
}
export function canExportBarp(term:Term){return ['Diajukan','Disetujui'].includes(term.status);}
export function canPrintBarp(term:Term){return term.status==='Disetujui'&&approvalRoles(term).every(role=>term.approvals.some(a=>a.role===role&&a.decision==='Setujui'&&((term.workflowVersion??1)<2||a.userId===selectedApprover(term,role))));}
export const BARP_TEXT={
  sow:'TELKOM AKSES dan Mandor sepakat bahwa lingkup pekerjaan (SoW) berdasarkan hasil Rekonsiliasi proyek dimaksud adalah sebagaimana BoQ detail terlampir.',
  note:'TELKOM AKSES dan Mandor sepakat apabila di kemudian hari ditemukan adanya perbedaan hasil Rekonsiliasi dengan hasil pengecekan fisik di lapangan yang berakibat terjadinya kelebihan bayar dari Telkom Akses ke Mandor, maka Mandor bersedia mengembalikan kelebihan bayar tersebut ke Telkom Akses.',
  closing:'Demikian Berita Acara Rekonsiliasi ini dibuat sesuai keadaan yang sebenarnya untuk dipergunakan sebagaimana mestinya.',
};
/** Existing issued documents without metadata stay unchanged; never invent a current date or a current account. */
export function historicalBarp(deal:Deal,spk:Spk):BarpData {
  return {version:1,date:'',place:spk.place,start:spk.start,end:spk.end,location:deal.location??'',sto:'',homepass:null,waspang:spk.supervisor,mandor:deal.mandor,headName:spk.signerSource==='pp-head-pmo'?spk.signer:'',headNik:spk.signerSource==='pp-head-pmo'?spk.signerNik??'':'',headUserId:spk.signerSource==='pp-head-pmo'?spk.signerUserId??'':''};
}
export function barpDocumentData(project:Project,deal:Deal,spk:Spk,term:Term){
  const b=term.barp??historicalBarp(deal,spk),date=bakhDocumentDate(b.date),start=bakhDocumentDate(b.start),end=bakhDocumentDate(b.end);
  let material=0,total=0,contract=0;
  for(const row of deal.rows){
    const m=row.m?(term.actuals[row.rowId]?.m??0)*row.pm:0,j=row.s?(term.actuals[row.rowId]?.s??0)*row.ps:0;
    material+=round(m);total+=round(m+j);contract+=round((row.m?row.qm*row.pm:0)+(row.s?row.qs*row.ps:0));
  }
  material=round(material);total=round(total);contract=round(contract);
  const service=round(total-material),amount=`Rp ${number.format(total)},- (${rupiahWords(total)})`,job=filled(project.name),mandor=filled(b.mandor);
  return {
    title:`BERITA ACARA REKON PEKERJAAN - TAHAP ${term.number}`,job,mandor,location:filled(b.location),sto:b.sto.trim(),homepass:b.homepass===null?'':number.format(b.homepass),
    opening:`Pada hari ini ${date?.weekday??'…'} tanggal ${date?.full??'…'}, telah dilakukan rekonsiliasi terhadap pelaksanaan pekerjaan ${job}, antara PT. Telkom Akses selanjutnya disebut TELKOM AKSES dengan ${mandor} selanjutnya disebut MANDOR, dengan kesepakatan sebagai berikut:`,
    price:`TELKOM AKSES dan Mandor sepakat bahwa harga borongan berdasarkan hasil rekonsiliasi proyek periode tanggal ${start?.full??'…'} sampai ${end?.full??'…'} untuk tahap ${term.number} adalah sebesar ${amount}, dengan porsi nilai tahap ini ${contract>0?number.format(round(total/contract*100)):'0'}% dari nilai SPK. Rincian sebagai berikut:`,
    stageScope:`Harga Berdasarkan Surat Perintah Kerja — volume tahap ${term.number} :`,
    reconHeading:`Nilai Rekonsiliasi Tahap ${term.number} untuk Pengajuan Pembayaran`,
    reconText:`TELKOM AKSES dan Mandor sepakat bahwa nilai hasil rekonsiliasi tahap ${term.number} yang diajukan untuk pembayaran adalah sebesar ${amount}. Dengan rincian sebagai berikut:`,
    placeDate:`${filled(b.place)}, ${date?.full??'…'}`,status:canPrintBarp(term)?'':canExportBarp(term)?'SALINAN REVIEW — MENUNGGU PERSETUJUAN':'DRAF — BELUM DIAJUKAN / PERLU PERBAIKAN',
    waspang:filled(b.waspang),head:filled(b.headName),headNik:b.headNik?`NIK: ${b.headNik}`:'',material,service,total,
    materialText:deal.rows.some(r=>r.m)?number.format(material):'',serviceText:deal.rows.some(r=>r.s)?number.format(service):'',totalText:number.format(total),
    zeroMaterial:deal.rows.some(r=>r.m)?'0':'',zeroService:deal.rows.some(r=>r.s)?'0':'',
  };
}

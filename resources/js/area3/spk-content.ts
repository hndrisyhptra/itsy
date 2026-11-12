import { rupiahNumber, monetaryRound, moneyLine } from './currency';
import type { Project, Deal, Spk } from './domain';
import { bakhDocumentDate } from './bakh-content';

const filled=(value?:string)=>value?.trim()||'…';
const number=rupiahNumber;
export const SPK_TEXT={
  title:'SURAT PERINTAH KERJA (SPK)',
  coordination:'Untuk menghindari hal-hal yang tidak sesuai dengan kelancara tugas, Saudara dapat berkordinasi dengan Waspang PT. Telkom Akses saudara ',
  safety:'Dalam melaksanakan pekerjaan di lapangan wajib memperhatikan kesehatan, keselamatan kerja (K3) dan para pekerja agar di lengkapi dengan alat pelindung diri (APD) yang memadai, memakai kartu tanda pengenal.',
};
/** Shared form-prop presentation only: no identity inference, catalog reads or state changes. */
export function spkDocumentData(project:Project,deal:Deal,spk:Spk){
  const round=(n:number)=>monetaryRound(n,deal);
  let material=0,total=0;
  for(const row of deal.rows){
    material+=moneyLine(row.m?row.qm:0,row.pm,0,0,deal);total+=moneyLine(row.m?row.qm:0,row.pm,row.s?row.qs:0,row.ps,deal);
  }
  material=round(material);total=round(total);
  // Legacy keeps its cent remainder; new policy rounds each M/J component to whole rupiah.
  const service=round(total-material),date=bakhDocumentDate(spk.date),start=bakhDocumentDate(spk.start),end=bakhDocumentDate(spk.end);
  const job=filled(project.name),branch=filled(project.branch),mandor=filled(deal.mandor);
  return {
    compact:job.length>120||(spk.address?.length??0)>80,number:filled(spk.no),placeDate:`${filled(spk.place)}, ${date?.full??'…'}`,mandor,address:filled(spk.address),job,branch,
    subject:`Perihal : Perintah Mulai Kerja Pekerjaan ${job} Area ${branch}.`,
    opening:`Dengan ini PT. Telkom Akses Memerintahkan kepada mandor ${mandor} untuk melaksanakan pekerjaan ${job}, terhitung mulai Tanggal ${start?.full??'…'} s/d ${end?.full??'…'}. List lokasi yang dikerjakan sebagai berikut :`,
    supervisor:filled(spk.supervisor),phone:filled(spk.phone),signer:filled(spk.signer),nik:spk.signerNik?.trim()||'Belum tersedia',position:filled(spk.position).toLocaleUpperCase('id-ID'),
    material,service,total,materialText:deal.rows.some(row=>row.m)?number.format(material):'',serviceText:deal.rows.some(row=>row.s)?number.format(service):'',totalText:number.format(total),
  };
}

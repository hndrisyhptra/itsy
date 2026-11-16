import type { State } from './domain';
import { canPrintBarp } from './barp-content';

/** Exact saved lineage only; a shared business Project ID is never an export join key. */
export function documentPackage(s:State,bappId:string){
  const bapp=s.bapps.find(b=>b.id===bappId);
  if(!bapp||bapp.status!=='Sah')throw new Error('Paket dokumen tersedia setelah BA PP disahkan.');
  const final=s.terms.find(t=>t.id===bapp.termId),spk=s.spks.find(v=>v.id===final?.spkId),deal=s.deals.find(d=>d.id===spk?.dealId),project=s.projects.find(p=>p.id===deal?.projectId);
  if(!final||!spk||!deal||!project)throw new Error('Relasi dokumen belum lengkap. Muat ulang data.');
  if(project.status!=='Sah'||deal.status!=='Sah'||spk.status!=='Sah'||final.type!=='Final'||!canPrintBarp(final))throw new Error('Paket membutuhkan PP, BA KH, SPK Sah dan seluruh approval Final lengkap.');
  const terms=s.terms.filter(t=>t.spkId===spk.id&&t.number<=final.number&&t.status==='Disetujui').sort((a,b)=>a.number-b.number);
  if(terms.length>3||!terms.every(canPrintBarp))throw new Error('Persetujuan dokumen pembayaran belum lengkap.');
  return {bapp,final,spk,deal,project,terms};
}
export type DocumentPackage = ReturnType<typeof documentPackage>;
export function documentPackageFilename(no:string,extension:'docx'|'pdf'){
  const safe=no.replace(/[\\/:*?"<>|\u0000-\u001f]/g,'-').replace(/[. ]+$/g,'').slice(0,110)||'dokumen';
  return `Paket-Dokumen_${safe}.${extension}`;
}

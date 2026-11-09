import type { Project,Deal,Spk,Term } from './domain';
import { barpDocumentData,canExportBarp } from './barp-content';
import { BARP_WORD_PARTS } from './barp-word-template';
import { ooxmlZip } from './ooxml-zip';
export { BARP_DOCX_MIME,barpWordFilename } from './barp-file';
const xml=(s:string)=>s.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\uD800-\uDFFF\uFFFE\uFFFF]/gu,'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;').replace(/\r\n?/g,'\n').replace(/\n/g,'</w:t><w:br/><w:t xml:space="preserve">');
export function createBarpDocx(project:Project,deal:Deal,spk:Spk,term:Term){
  if(!canExportBarp(term))throw new Error('Word BARP tersedia setelah pengajuan dikirim. Ajukan kembali dokumen yang perlu perbaikan.');
  const v=barpDocumentData(project,deal,spk,term);
  const fields:Record<string,string>={TITLE:v.title,OBJECT_LABEL:v.objectLabel,JOB:v.job,MANDOR:v.mandor,OPENING:v.opening,PRICE:v.price,RECON_HEADING:v.reconHeading,RECON_TEXT:v.reconText,STATUS:v.status,PLACE_DATE:v.placeDate,LOCATION:v.location,STO:v.sto,HOMEPASS:v.homepass,STAGE_SCOPE:v.stageScope,MATERIAL:v.materialText,SERVICE:v.serviceText,TOTAL:v.totalText,ZERO_MATERIAL:v.zeroMaterial,ZERO_SERVICE:v.zeroService,ZERO_TOTAL:'0',WASPANG:v.waspang,HEAD:v.head,HEAD_NIK:v.headNik?'\n'+v.headNik:''};
  const document=BARP_WORD_PARTS['word/document.xml'].replace(/\{\{([A-Z_]+)\}\}/g,(_token,key:string)=>{
    if(!(key in fields))throw new Error('Template BARP belum lengkap.');
    return xml(fields[key]);
  });
  return ooxmlZip({...BARP_WORD_PARTS,'word/document.xml':document});
}

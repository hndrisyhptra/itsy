import type { Project, Deal, Spk } from './domain';
import { SPK_TEXT, spkDocumentData } from './spk-content';
import { SPK_WORD_PARTS } from './spk-word-template';
import { ooxmlZip } from './ooxml-zip';

export const SPK_DOCX_MIME='application/vnd.openxmlformats-officedocument.wordprocessingml.document';
function xml(value:string){
  return value.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\uD800-\uDFFF\uFFFE\uFFFF]/gu,'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;').replace(/\r\n?/g,'\n').replace(/\n/g,'</w:t><w:br/><w:t xml:space="preserve">');
}
export function spkWordFilename(spk:Spk){
  const source=(spk.no||spk.id||'belum-bernomor').replace(/[\\/:*?"<>|\u0000-\u001f]/g,'-').replace(/[. ]+$/g,'').slice(0,120);
  return `SPK-Mandor_${source||'dokumen'}.docx`;
}
/** Fill native copy-derived OOXML text slots once; unknown user text is never interpreted as a second slot. */
export function createSpkDocx(project:Project,deal:Deal,spk:Spk,logo:Uint8Array){
  if(logo.length<8||[137,80,78,71,13,10,26,10].some((byte,i)=>logo[i]!==byte))throw new Error('Logo template SPK belum dapat dimuat. Coba unduh kembali.');
  const data=spkDocumentData(project,deal,spk);
  const fields:Record<string,string>={SPK_MAIN_LINE:data.compact?'320':'420',SPK_NO:data.number,SPK_PLACE_DATE:data.placeDate,SPK_MANDOR:data.mandor,SPK_ADDRESS:data.address,SPK_JOB:data.job,SPK_BRANCH:data.branch,SPK_OPENING:data.opening,SPK_COORDINATION_BEFORE:SPK_TEXT.coordination,SPK_SUPERVISOR:data.supervisor,SPK_PHONE:data.phone,SPK_SIGNER:data.signer,SPK_NIK:data.nik,SPK_POSITION:data.position,SPK_MATERIAL:data.materialText,SPK_SERVICE:data.serviceText,SPK_TOTAL:data.totalText};
  const document=SPK_WORD_PARTS['word/document.xml'].replace(/\{\{([A-Z_]+)\}\}/g,(_token,key:string)=>{
    if(!(key in fields))throw new Error('Template SPK belum lengkap.');
    return xml(fields[key]);
  });
  return ooxmlZip({...SPK_WORD_PARTS,'word/document.xml':document,'word/media/image1.png':logo});
}

import {earlyBapp} from './bapp-workflow';
import { bappPhysicalReady } from './bapp-workflow';
import type { Project,Deal,Spk,Term,Bapp } from './domain';
import { bappDocumentData } from './bapp-content';
import { canExportBarp } from './barp-content';
import { BAPP_WORD_PARTS } from './bapp-word-template';
import { ooxmlZip } from './ooxml-zip';
import { paragraph,type WordRun } from './word-ooxml';

const runs=(value:WordRun[])=>paragraph(value,{size:22,font:'Calibri'}).replace(/^<w:p><w:pPr>[\s\S]*?<\/w:pPr>/,'').replace(/<\/w:p>$/,'');
const text=(value:string)=>value.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\uD800-\uDFFF\uFFFE\uFFFF]/gu,'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&apos;');
/** Patch the retained template's editable slots; keep section/styles/theme/table and real blank TTD. */
export function createBappDocx(project:Project,deal:Deal,spk:Spk,term:Term,bapp?:Bapp){
  if(earlyBapp(bapp)&&(project.status!=='Sah'||deal.status!=='Sah'||spk.status!=='Sah'))throw new Error('PP, BA KH dan SPK harus Sah sebelum mengunduh BA PP.');
  if(earlyBapp(bapp)?!bappPhysicalReady(bapp,term):!canExportBarp(term))throw new Error('Word BA PP tersedia setelah pengajuan dikirim.');
  const data=bappDocumentData(project,deal,spk,term,bapp);
  const slots:Record<string,string>={
    '<!--BAPP_OPENING-->':runs(data.opening),
    '<!--BAPP_JOB-->':runs([{text:'PEKERJAAN '+data.job,bold:true}]),
    '<!--BAPP_RESULT-->':runs(data.result),
    '<!--BAPP_CLOSING-->':runs(data.closing),
    '<!--BAPP_PLACE-->':runs([{text:data.placeDate}]),
    '<!--BAPP_STATUS-->':data.status?paragraph([{text:data.status,bold:true}],{size:22,font:'Calibri',align:'center',after:80}):'',
    '{{BAPP_MANDOR}}':text(data.mandor),
    '{{BAPP_WASPANG}}':text(data.waspang),
    '{{BAPP_HEAD}}':text(data.head),
    '{{BAPP_HEAD_JOB}}':text(data.headJob),
  };
  // One substitution pass: user text containing a template token cannot rewrite a later slot.
  const document=BAPP_WORD_PARTS['word/document.xml'].replace(/<!--BAPP_[A-Z]+-->|\{\{BAPP_[A-Z_]+\}\}/g,token=>slots[token]??'');
  return ooxmlZip({...BAPP_WORD_PARTS,'word/document.xml':document});
}

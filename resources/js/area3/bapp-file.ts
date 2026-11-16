import { barpWordFilename,BARP_DOCX_MIME } from './barp-file';
import type { Spk,Term } from './domain';
export const BAPP_DOCX_MIME=BARP_DOCX_MIME;
export function bappWordFilename(spk:Spk,term:Term,appendix=false){return (appendix?'Lampiran-':'')+barpWordFilename(spk,term).replace(/^BARP/,'BA-PP');}

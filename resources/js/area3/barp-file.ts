import type { Spk,Term } from './domain';
export const BARP_DOCX_MIME='application/vnd.openxmlformats-officedocument.wordprocessingml.document';
export function barpWordFilename(spk:Spk,term:Term){return `BARP-Tahap-${term.number}_${(spk.no||spk.id).replace(/[\\/:*?"<>|\u0000-\u001f]/g,'-').replace(/[. ]+$/g,'').slice(0,120)}.docx`;}

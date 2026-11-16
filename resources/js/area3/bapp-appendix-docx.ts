import type { State,Project,Deal,Spk,Term } from './domain';
import { bappAppendixData } from './bapp-content';
import { canExportBarp } from './barp-content';
import { createReconciliationAppendixDocx } from './barp-appendix-docx';
export function createBappAppendixDocx(state:State,project:Project,deal:Deal,spk:Spk,term:Term){
  if(!canExportBarp(term))throw new Error('Word lampiran BA PP tersedia setelah pengajuan dikirim.');
  return createReconciliationAppendixDocx(bappAppendixData(state,project,deal,spk,term));
}

import { bappPhysicalReady } from './bapp-workflow';
import type { State,Project,Deal,Spk,Term,Bapp } from './domain';
import { bappAppendixData } from './bapp-content';
import { canExportBarp } from './barp-content';
import { createReconciliationAppendixDocx } from './barp-appendix-docx';
export function createBappAppendixDocx(state:State,project:Project,deal:Deal,spk:Spk,term:Term,bapp?:Bapp){
  if(bapp?.completionPolicy==='barp-before-approval-v1'&&(project.status!=='Sah'||deal.status!=='Sah'||spk.status!=='Sah'))throw new Error('PP, BA KH dan SPK harus Sah sebelum mengunduh BA PP.');
  if(!canExportBarp(term)&&!bappPhysicalReady(bapp,term))throw new Error('Word lampiran BA PP tersedia setelah pengajuan dikirim.');
  return createReconciliationAppendixDocx(bappAppendixData(state,project,deal,spk,term,bapp));
}

import type { State,Project,Deal,Spk,Term,Bapp } from './domain';
import { bappAppendixData } from './bapp-content';
import { ReconciliationAppendix } from './barp-appendix';
export function BappAppendix({state,project,deal,spk,term,bapp}:{state:State;project:Project;deal:Deal;spk:Spk;term:Term;bapp?:Bapp}){
  return <ReconciliationAppendix data={bappAppendixData(state,project,deal,spk,term,bapp)} bapp/>;
}

import { WHOLE_RUPIAH } from './currency';
import { type State, type Project, boqTotal, canReviseSubmission } from './domain';

export const AUTO_KHS_NOTE = 'Harga BOQ otomatis mengikuti Master KHS. Harga Excel disimpan sebagai pembanding.';
export const AUTO_KHS_POLICY = 'khs-auto-v1' as const;

export function usesAutomaticBoqPricing(s:State,p:Project) {
  const saved=s.projects.find(row=>row.id===p.id),stored=saved?.workflowVersion??p.workflowVersion??1;
  const version=stored>=2?stored:s.schema>=3?3:2;
  return version>=3&&!p.parentId&&canReviseSubmission(saved??p);
}

/** Form feedback only; Laravel re-resolves every price/version from its SQL catalog. */
export function applyAutomaticBoqPricing(s:State,p:Project):Project {
  if(!usesAutomaticBoqPricing(s,p))return p;
  const catalog=new Map(s.khs.map(k=>[k.id,k]));
  const boq=p.boq.map(row=>{
    const k=catalog.get(row.khsId);
    return k?.active?{...row,m:k.m,s:k.s,version:k.version,reviewed:true,note:AUTO_KHS_NOTE}:{...row,reviewed:false};
  });
  return {...p,boq,moneyPolicy:WHOLE_RUPIAH,boqPricingPolicy:AUTO_KHS_POLICY,budget:boqTotal({...p,boq,moneyPolicy:WHOLE_RUPIAH})};
}

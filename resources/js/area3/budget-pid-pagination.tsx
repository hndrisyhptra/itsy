import {ChevronLeft,ChevronRight} from 'lucide-react';
import {Button} from './ui';

export const budgetPageSizes=[10,20,50,100] as const;
export function BudgetPagination({page,perPage,total,busy,onPage,onPerPage}:{page:number;perPage:number;total:number;busy:boolean;onPage:(page:number)=>void;onPerPage:(size:number)=>void}){
  const pages=Math.max(1,Math.ceil(total/perPage)),fmt=(v:number)=>v.toLocaleString('id-ID');
  return <div className="budget-pid-pagination"><div className="budget-pid-pagination-info"><label>Baris per halaman <select aria-label="Baris per halaman" value={perPage} disabled={busy} onChange={e=>onPerPage(Number(e.target.value))}>{budgetPageSizes.map(n=><option key={n} value={n}>{n}</option>)}</select></label><span>Total <b>{fmt(total)}</b> data</span><span>Halaman <b>{fmt(page)}</b> / {fmt(pages)}</span></div><div className="actions"><Button title="Halaman sebelumnya" disabled={busy||page<=1} onClick={()=>onPage(page-1)}><ChevronLeft size={15}/> Sebelumnya</Button><Button title="Halaman berikutnya" disabled={busy||page>=pages} onClick={()=>onPage(page+1)}>Berikutnya <ChevronRight size={15}/></Button></div></div>;
}

import { useState } from 'react';
import { FileSearch } from 'lucide-react';
import { type State, type Project, money, num } from './domain';
import { ownerPlanRows, lineAmount, cents } from './plan-budget';
import { Button, Modal, Table, Notice } from './ui';

export function boqMandorPreviewRows(s:State,p:Project) {
  const owners=new Map(ownerPlanRows(s,p).map(r=>[r.rowId,r]));
  return (p.planBudget?.mandor??[]).map(r=>{
    const owner=owners.get(r.rowId), material=!!owner&&owner.m!==null,service=!!owner&&owner.s!==null;
    return {...r,code:owner?.code??r.rowId,description:owner?.name??'',unit:owner?.unit??'',material,service,
      amountM:material?lineAmount(r.qtyM,r.priceM,p.planBudget?.moneyPolicy==='idr-whole-v1'?'half':p.planBudget?.moneyPolicy==='idr-trunc-v1'):0,amountS:service?lineAmount(r.qtyS,r.priceS,p.planBudget?.moneyPolicy==='idr-whole-v1'?'half':p.planBudget?.moneyPolicy==='idr-trunc-v1'):0};
  });
}

export function BoqMandorDocument({s,p}:{s:State;p:Project}) {
  const rows=boqMandorPreviewRows(s,p),totalM=rows.reduce((n,r)=>n+cents(r.amountM),0)/100,totalS=rows.reduce((n,r)=>n+cents(r.amountS),0)/100;
  return <article className="document plan-budget-section boq-mandor-preview"><h2>BoQ TA - Mandor</h2><p><strong>{p.no}</strong><br/>{p.projectCode} · {p.name}<br/>{p.branch}</p><Notice>Harga dan volume dari anggaran Mandor PP tersimpan. Nilai ini merupakan referensi anggaran, bukan harga kesepakatan SPK atau tagihan termin.</Notice>{!p.planBudget?<Notice tone="warning">Dokumen historis ini belum memiliki rincian BOQ Mandor. Harga tidak diganti dengan harga KHS atau BOQ Owner.</Notice>:<Table headers={['No','Designator','Uraian Pekerjaan','Satuan','Vol. Material','Vol. Jasa','Harga Material','Harga Jasa','Nilai Material','Nilai Jasa','Total']}>{rows.map((r,i)=><tr key={r.rowId}><td>{i+1}</td><td>{r.code}</td><td>{r.description}</td><td>{r.unit}</td><td className="numeric">{r.material?num(r.qtyM):''}</td><td className="numeric">{r.service?num(r.qtyS):''}</td><td className="numeric">{r.material?money(r.priceM):''}</td><td className="numeric">{r.service?money(r.priceS):''}</td><td className="numeric">{r.material?money(r.amountM):''}</td><td className="numeric">{r.service?money(r.amountS):''}</td><td className="numeric">{money((cents(r.amountM)+cents(r.amountS))/100)}</td></tr>)}<tr className="budget-total"><td colSpan={8}>Total BOQ Mandor</td><td className="numeric">{money(totalM)}</td><td className="numeric">{money(totalS)}</td><td className="numeric">{money((cents(totalM)+cents(totalS))/100)}</td></tr></Table>}</article>;
}

export function BoqMandorPreviewButton({s,p}:{s:State;p:Project}) {
  const [open,setOpen]=useState(false);
  return <><Button title="Lihat BOQ TA - Mandor dari PP yang terkait pengajuan ini" onClick={()=>setOpen(true)}><FileSearch size={14}/> Preview BOQ TA Mandor</Button>{open&&<Modal title={'Preview BOQ TA Mandor · '+p.no} onClose={()=>setOpen(false)} wide><BoqMandorDocument s={s} p={p}/></Modal>}</>;
}

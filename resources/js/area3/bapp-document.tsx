import type { Project,Deal,Spk,Term,Bapp } from './domain';
import { bappDocumentData } from './bapp-content';
import type { WordRun } from './word-ooxml';
import { Fragment } from 'react';

function Runs({items}:{items:WordRun[]}){return <>{items.map((r,i)=>{let text=<>{r.text}</>;if(r.italic)text=<em>{text}</em>;return r.bold?<strong key={i}>{text}</strong>:<Fragment key={i}>{text}</Fragment>;})}</>;}

export function BappDocument({project,deal,spk,term,bapp}:{project:Project;deal:Deal;spk:Spk;term:Term;bapp?:Bapp}){
  const v=bappDocumentData(project,deal,spk,term,bapp);
  return <article className={`document bapp-document${v.job.length>100?' bapp-compact':''}`}>
    <h2>{v.title}</h2>{v.status&&<p className="bapp-review">{v.status}</p>}
    <p><Runs items={v.opening}/></p>
    <dl className="bapp-identity"><dt>Nama</dt><dd>{v.mandor}</dd><dt>Jabatan</dt><dd>KETUA MANDOR</dd></dl>
    <p>Dalam hal ini bertindak sebagai pelaksana pekerjaan :</p>
    <p className="bapp-job"><strong>PEKERJAAN {v.job}</strong></p>
    <p>Adapun hasil pekerjaan adalah sebagai berikut :</p>
    <p className="bapp-result"><Runs items={v.result}/></p>
    <p className="bapp-closing"><Runs items={v.closing}/></p>
    <section className="bapp-signatures" aria-label="Penandatangan BA Penyelesaian Pekerjaan">
      <p className="bapp-place-date">{v.placeDate}</p>
      <div className="bapp-signature-pair">{[{job:'WASPANG PROJECT',name:v.waspang},{job:'KETUA MANDOR',name:v.mandor}].map(s=><div key={s.job}><strong>{s.job}</strong><div className="bapp-signature-space" aria-label="Area TTD basah dokumen asli"/><strong>{s.name}</strong></div>)}</div>
      <div className="bapp-head"><strong>Mengetahui / Menyetujui :</strong><div className="bapp-signature-space" aria-label="Area TTD basah dokumen asli"/><strong className="bapp-head-name">{v.head}</strong><br/><strong>{v.headJob}</strong></div>
    </section>
  </article>;
}

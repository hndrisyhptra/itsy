import { useState } from 'react';
import { type State, type Project } from './domain';
import { bundleDocuments, usesContractBundle } from './project-bundle';
import { Card, Button, Link, Notice, DocumentPreview } from './ui';

export function ProjectBundleReview({s,p}:{s:State;p:Project}){
  const [preview,setPreview]=useState<{kind:string;dealId:string;spkId?:string}|null>(null);
  if(!usesContractBundle(p))return null;
  const {deals,spks}=bundleDocuments(s,p),deal=deals.find(d=>d.id===preview?.dealId),spk=spks.find(v=>v.id===preview?.spkId);
  return <Card title="Paket Approval · PP, BA KH & SPK"><Notice>Kelima approver menyetujui satu versi paket. Seluruh berkas terkunci selama pengajuan; SPK sah dan volume committed setelah persetujuan Head of PMO.</Notice>{!deals.length&&<Link className="btn" to={'/spk/ba-kh/new?project='+p.id}>Lanjut isi BA KH</Link>}{deals.map(d=>{const v=spks.find(v=>v.dealId===d.id);return <div key={d.id} className="stack"><strong>{d.no} · {d.mandor}</strong><div className="actions">{['BA Kesepakatan Harga','Lampiran BA KH'].map(kind=><Button key={kind} onClick={()=>setPreview({kind,dealId:d.id})}>{kind}</Button>)}<Link className="btn" to={'/spk/ba-kh/'+d.id}>Buka BA KH</Link>{v?<>{['Surat Perintah Kerja','Lampiran SPK'].map(kind=><Button key={kind} onClick={()=>setPreview({kind,dealId:d.id,spkId:v.id})}>{kind}</Button>)}<Link className="btn" to={'/spk/'+v.id}>Buka SPK</Link></>:<Link className="btn" to={'/spk/new?deal='+d.id}>Lanjut buat SPK</Link>}</div></div>;})}{preview&&deal&&<DocumentPreview state={s} kind={preview.kind} project={p} deal={deal} spk={spk} onClose={()=>setPreview(null)}/>}</Card>;
}

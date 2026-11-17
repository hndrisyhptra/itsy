import { useState } from 'react';
import { FileSearch, Files } from 'lucide-react';
import { type State, type Project, operational } from './domain';
import { bundleDocuments, bundleEditable, usesContractBundle } from './project-bundle';
import { Card, Button, Link, Notice, Modal, DocumentPreview } from './ui';

export type BundlePreviewTarget = { projectId:string; kind:'Project Plan'|'BA Kesepakatan Harga'|'Lampiran BA KH'|'Surat Perintah Kerja'|'Lampiran SPK'; dealId?:string; spkId?:string };

/** Resolve exact loaded identities; never substitute a first/sibling document. */
export function projectBundlePreviewContext(s:State,p:Project,target:BundlePreviewTarget|null){
  if(!target||target.projectId!==p.id||!usesContractBundle(p))return null;
  if(target.kind==='Project Plan')return {kind:target.kind,project:p};
  const deal=s.deals.find(d=>d.id===target.dealId&&d.projectId===p.id);
  if(!deal)return null;
  if(target.kind==='BA Kesepakatan Harga'||target.kind==='Lampiran BA KH')return {kind:target.kind,project:p,deal};
  if(target.kind!=='Surat Perintah Kerja'&&target.kind!=='Lampiran SPK')return null;
  const spk=s.spks.find(v=>v.id===target.spkId&&v.dealId===deal.id);
  return spk?{kind:target.kind,project:p,deal,spk}:null;
}

export function ProjectBundleReview({s,p,embedded=false,modal=false,onClose}:{s:State;p:Project;embedded?:boolean;modal?:boolean;onClose?:()=>void}){
  const [preview,setPreview]=useState<BundlePreviewTarget|null>(null);
  if(!usesContractBundle(p))return null;
  const {deals,spks}=bundleDocuments(s,p),byDeal=new Map<string,typeof spks>();
  for(const v of spks){const group=byDeal.get(v.dealId)??[];group.push(v);byDeal.set(v.dealId,group);}
  const context=projectBundlePreviewContext(s,p,preview),manage=!modal&&operational(s),canCreate=manage&&bundleEditable(p);
  const open=(kind:BundlePreviewTarget['kind'],dealId?:string,spkId?:string)=>setPreview({projectId:p.id,kind,dealId,spkId});
  const document=context?<DocumentPreview key={p.id+'|'+preview?.kind+'|'+preview?.dealId+'|'+preview?.spkId} state={s} {...context} onClose={()=>setPreview(null)}/>:null;
  // Replace the picker while previewing so Escape, focus and print have one modal owner.
  if(modal&&document)return document;
  const content=<div className="project-bundle-review">
    <Notice>Periksa PP, BA KH, SPK dan masing-masing lampiran sebelum menyetujui paket. Membuka preview tidak memberikan persetujuan atau mengubah dokumen.</Notice>
    <div className="actions"><Button title="Lihat Project Plan dalam paket ini" onClick={()=>open('Project Plan')}><FileSearch size={14}/> Preview PP</Button></div>
    {!deals.length&&<p className="muted">BA KH dan SPK belum tersedia pada paket ini.</p>}
    {!deals.length&&canCreate&&<Link className="btn" to={'/spk/ba-kh/new?project='+p.id}>Lanjut isi BA KH</Link>}
    {deals.map(d=><section className="project-bundle-review-group" key={d.id} aria-label={'Dokumen '+d.no}>
      <strong>{d.no} · {d.mandor}</strong>
      <div className="actions"><Button title={'Lihat BA KH '+d.no} onClick={()=>open('BA Kesepakatan Harga',d.id)}><FileSearch size={14}/> Preview BA KH</Button><Button title={'Lihat lampiran BA KH '+d.no} onClick={()=>open('Lampiran BA KH',d.id)}><FileSearch size={14}/> Preview Lampiran BA KH</Button>{manage&&<Link className="btn" to={'/spk/ba-kh/'+d.id}>Buka BA KH</Link>}</div>
      {(byDeal.get(d.id)??[]).map(v=><div key={v.id} className="project-bundle-review-spk"><small>{v.no}</small><div className="actions"><Button title={'Lihat SPK '+v.no} onClick={()=>open('Surat Perintah Kerja',d.id,v.id)}><FileSearch size={14}/> Preview SPK</Button><Button title={'Lihat lampiran SPK '+v.no} onClick={()=>open('Lampiran SPK',d.id,v.id)}><FileSearch size={14}/> Preview Lampiran SPK</Button>{manage&&<Link className="btn" to={'/spk/'+v.id}>Buka SPK</Link>}</div></div>)}
      {!byDeal.has(d.id)&&<p className="muted">SPK belum tersedia untuk BA KH ini.</p>}{!byDeal.has(d.id)&&canCreate&&<Link className="btn" to={'/spk/new?deal='+d.id}>Lanjut buat SPK</Link>}
    </section>)}
  </div>;
  return <>{modal?<Modal title={'Preview Paket · '+p.no} onClose={()=>onClose?.()} wide>{content}</Modal>:embedded?<section className="project-bundle-review-section" aria-label="Preview dokumen paket"><h3>Berkas Paket untuk Ditinjau</h3>{content}</section>:<Card title="Paket Approval · PP, BA KH & SPK">{content}</Card>}{!modal&&document}</>;
}

export function ProjectBundleInboxPreview({s,p}:{s:State;p:Project}){
  const [open,setOpen]=useState(false);
  if(!usesContractBundle(p))return null;
  return <><Button title="Lihat PP, BA KH, SPK dan lampiran sebelum menyetujui paket" onClick={()=>setOpen(true)}><Files size={14}/> Preview Paket</Button>{open&&<ProjectBundleReview key={p.id} s={s} p={p} modal onClose={()=>setOpen(false)}/>}</>;
}

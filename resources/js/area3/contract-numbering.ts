import type { Deal, Spk, State } from './domain';

export function contractNumber(ppNo:string,code:'01'|'02'):string {
  const source=ppNo.trim();
  if(!source||!['01','02'].includes(code))throw new Error('Nomor PP tersimpan dan kode dokumen 01/02 diperlukan.');
  const slash=source.indexOf('/'),number=slash<0?`${source}-${code}`:`${source.slice(0,slash)}-${code}${source.slice(slash)}`;
  if(Array.from(number).length>150)throw new Error('Nomor BA KH/SPK turunan melebihi batas 150 karakter.');
  return number;
}

export function syncDealNumber(s:Pick<State,'schema'|'projects'|'deals'>,deal:Deal):Deal {
  const saved=s.deals.find(d=>d.id===deal.id);
  if(saved&&['Terbit','Sah'].includes(saved.status))return {...deal,no:saved.no};
  if(s.schema<3)return deal; // Historical offline workflows retain their existing behavior.
  const p=s.projects.find(p=>p.id===deal.projectId);
  if(!p)throw new Error('Project Plan tidak ditemukan.');
  return {...deal,no:contractNumber(p.no,'01')};
}

export function syncSpkNumber(s:Pick<State,'schema'|'projects'|'deals'|'spks'>,spk:Spk):Spk {
  const saved=s.spks.find(v=>v.id===spk.id);
  if(saved&&['Terbit','Sah'].includes(saved.status))return {...spk,no:saved.no};
  if(s.schema<3)return spk;
  const deal=s.deals.find(d=>d.id===spk.dealId),p=s.projects.find(p=>p.id===deal?.projectId);
  if(!p)throw new Error('Project Plan tidak ditemukan.');
  return {...spk,no:contractNumber(p.no,'02')};
}

import type { State } from './domain';
import type { DocumentPackage } from './document-package';
import type { PpWordBlock } from './pp-docx';
import type { KasbonData } from './kasbon-content';
import { createProjectPlanDocx } from './pp-docx';
import { createBakhDocx } from './bakh-docx';
import { createSpkDocx } from './spk-docx';
import { createSpkAppendixDocx } from './spk-appendix-docx';
import { createBarpDocx } from './barp-docx';
import { createBarpAppendixDocx } from './barp-appendix-docx';
import { createBappDocx } from './bapp-docx';
import { createBappAppendixDocx } from './bapp-appendix-docx';
import { createKasbonDocx } from './kasbon-docx';
import { mergeDocx } from './docx-merge';

/** Fixed order; never includes other SPKs, historical rejected cycles or unrelated PP. */
export function createDocumentPackageDocx(s:State,c:DocumentPackage,pp:PpWordBlock[],logo:Uint8Array,kasbons:Map<string,KasbonData>=new Map()){
  if(c.terms.some(t=>t.kasbon&&!kasbons.has(t.id)))throw new Error('Kasbon aktif belum lengkap. Muat ulang paket dokumen.');
  const {project:p,deal:d,spk:v,current}=c;
  const docs=[createProjectPlanDocx(pp),createBakhDocx(s,p,d,'bakh'),createBakhDocx(s,p,d,'appendix'),createSpkDocx(p,d,v,logo),createSpkAppendixDocx(s,p,d,v)];
  for(const t of c.terms){docs.push(createBarpDocx(p,d,v,t),createBarpAppendixDocx(s,p,d,v,t));const b=c.completions.get(t.id);if(t.id!==current.id&&b)docs.push(createBappDocx(p,d,v,t,b),createBappAppendixDocx(s,p,d,v,t,b));const data=kasbons.get(t.id);if(data)docs.push(createKasbonDocx(data,logo,t,t.kasbon?.id));}
  docs.push(createBappDocx(p,d,v,current,c.bapp),createBappAppendixDocx(s,p,d,v,current,c.bapp));
  return mergeDocx(docs);
}

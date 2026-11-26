import {ledger} from './domain';
import type {Project,State} from './domain';
export const BOQ_REFERENCE_MESSAGE='Item BOQ masih digunakan pada BA KH/SPK atau PP lanjutan. Pertahankan item dan komponen yang sudah digunakan; perubahan tidak disimpan.';
/** Same saved PP + exact catalog ID, independent of incoming Excel row order/UUID. */
export function stabilizeProjectBoq(input:Project,old?:Project):Project{
 if(!old||old.id!==input.id||old.parentId)return input;
 const byKhs=new Map(old.boq.map(r=>[r.khsId,r])),byId=new Map(old.boq.map(r=>[r.id,r])),aliases=new Map<string,string>();
 const boq=input.boq.map(r=>{const saved=byKhs.get(r.khsId);if(!saved||saved.id===r.id||byId.has(r.id)&&byId.get(r.id)!.khsId!==r.khsId)return r;aliases.set(r.id,saved.id);return {...r,id:saved.id};});
 if(!aliases.size)return input;
 const b=input.planBudget,remap=(id:string)=>aliases.get(id)??id;
 return {...input,boq,...(b?{planBudget:{...b,mandor:b.mandor.map(r=>({...r,rowId:remap(r.rowId)})),materials:b.materials.map(r=>r.sourceRowId?{...r,sourceRowId:remap(r.sourceRowId)}:r),...(b.excludedMaterialRowIds?{excludedMaterialRowIds:b.excludedMaterialRowIds.map(remap)}:{})}}:{})};
}
export function projectBoqReferences(s:Pick<State,'projects'|'deals'>,p:Project,old?:Project):Map<string,Set<'m'|'s'>>{
 const used=new Map<string,Set<'m'|'s'>>();if(p.parentId)return used;
 const oldById=new Map(old?.boq.map(r=>[r.id,r])??[]),rows=new Map(p.boq.map(r=>[r.id,r]));
 for(const row of p.boq)if(oldById.has(row.id)&&oldById.get(row.id)!.khsId!==row.khsId)throw Error('ID baris BOQ tersimpan tidak boleh dipakai untuk designator berbeda.');
 const related=new Set<string>(),add=(id:string,c:'m'|'s')=>{if(!used.has(id))used.set(id,new Set());used.get(id)!.add(c);};
 for(const project of s.projects){if(project.rootId!==p.id)continue;related.add(project.id);if(project.parentId)for(const [id,q] of Object.entries(project.allocations))for(const c of ['m','s'] as const)if(q[c]>0)add(id,c);}
 for(const d of s.deals)if(related.has(d.projectId))for(const r of d.rows)for(const c of ['m','s'] as const)if(r[c])add(r.rowId,c);
 for(const [id,components] of used)for(const c of components)if(!rows.has(id)||rows.get(id)![c]===null)throw Error(BOQ_REFERENCE_MESSAGE);
 return used;
}

export function validateProjectBoq(s:State,p:Project,old?:Project):void{
 const references=projectBoqReferences(s,p,old),previewState={...s,projects:s.projects.map(v=>v.id===p.id?p:v)};
 for(const [rowId,components] of references)for(const c of components)if(ledger(previewState,p.id,rowId,c).available<-.00001)throw Error('Volume BOQ tidak boleh lebih kecil daripada volume yang sudah digunakan atau dialokasikan pada BA KH/SPK dan PP lanjutan.');
}

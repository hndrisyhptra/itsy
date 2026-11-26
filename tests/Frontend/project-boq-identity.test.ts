import test from 'node:test';
import assert from 'node:assert/strict';
import {seed} from '../../resources/js/area3/seed';
import {stabilizeProjectBoq,projectBoqReferences,BOQ_REFERENCE_MESSAGE} from '../../resources/js/area3/project-boq-identity';
import {draftPlanBudget} from '../../resources/js/area3/plan-budget';

test('reupload of same exact catalog items preserves saved row identities and remaps editable budget only',()=>{
 const s=seed('D'),old=s.projects[0];old.status='Draft';const original=JSON.stringify(old),p=structuredClone(old);p.planBudget=draftPlanBudget(s,p);p.planBudget.mandor[0].priceM=100;p.planBudget.mandor[0].priceS=200;
 const oldId=p.boq[0].id;p.boq=p.boq.map((r,i)=>({...r,id:'new-'+i}));p.planBudget.mandor=p.planBudget.mandor.map((r,i)=>({...r,rowId:'new-'+i}));p.planBudget.materials=[{id:'manual',name:'Manual',qty:1,price:4,unit:'pcs'},{id:'derived',sourceRowId:'new-0',name:'Derived',qty:2,price:5,unit:'pcs'}];p.planBudget.excludedMaterialRowIds=['new-0'];p.boq.reverse();
 const raw=JSON.stringify(p),stable=stabilizeProjectBoq(p,old);assert.deepEqual(stable.boq.map(r=>r.id),old.boq.map(r=>r.id).reverse());assert.equal(stable.planBudget!.mandor[0].rowId,oldId);assert.equal(stable.planBudget!.mandor[0].priceM,100);assert.equal(stable.planBudget!.mandor[0].priceS,200);assert.equal(stable.planBudget!.materials[1].sourceRowId,oldId);assert.deepEqual(stable.planBudget!.excludedMaterialRowIds,[oldId]);assert.deepEqual(stable.planBudget!.materials[0],p.planBudget.materials[0]);assert.equal(JSON.stringify(p),raw);assert.equal(JSON.stringify(old),original);assert(projectBoqReferences(s,stable,old).has(oldId));
});
test('removal of an item or component referenced by agreement blocks; fresh sibling cannot replace source',()=>{
 const s=seed('D'),old=s.projects[0],p=structuredClone(old);p.boq.shift();assert.throws(()=>projectBoqReferences(s,p,old),new RegExp(BOQ_REFERENCE_MESSAGE.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')));
 const component=structuredClone(old);component.boq[0].m=null;assert.throws(()=>projectBoqReferences(s,component,old),/Item BOQ masih digunakan/);
 const rebound=structuredClone(old);rebound.boq[0].khsId=old.boq[1].khsId;assert.throws(()=>projectBoqReferences(s,stabilizeProjectBoq(rebound,old),old),/designator berbeda/);
 const sibling=structuredClone(old);sibling.id=sibling.rootId='other-pp';sibling.boq[0].id='sibling-only';const chosen=structuredClone(old);chosen.boq[0].id='new-unrelated-id';assert.equal(stabilizeProjectBoq(chosen,sibling).boq[0].id,'new-unrelated-id');
});
test('child allocations protect original component without agreement; unused removed rows still allowed',()=>{
 const s=seed('D'),old=s.projects[0];s.deals=[];s.spks=[];const child={...structuredClone(old),id:'child',parentId:old.id,allocations:{[old.boq[0].id]:{m:1,s:0}}};s.projects.push(child);const p=structuredClone(old);p.boq.shift();assert.throws(()=>projectBoqReferences(s,p,old),/PP lanjutan/);p.boq=old.boq.slice(0,1);assert.equal(projectBoqReferences(s,p,old).size,1);assert.equal(stabilizeProjectBoq(child,child),child);
});

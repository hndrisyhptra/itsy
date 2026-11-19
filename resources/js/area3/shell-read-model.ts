import type {State} from './domain';
import {canApproveProject,canApproveTerm,operational} from './domain';
import {canReviewLegacySettlement} from './legacy-settlement-review';
import {adminKasbonPending} from './finance-payment';
import {financeTaskPending} from './partial-payment';

export function workspaceInboxCount(s:State){
 let count=0;
 for(const p of s.projects)if(canApproveProject(s,p))count++;
 for(const t of s.terms)if(canApproveTerm(s,t)||canReviewLegacySettlement(s,t)||(s.role==='Finance'&&financeTaskPending(t))||(operational(s)&&adminKasbonPending(t)))count++;
 return count;
}
export interface WorkspaceSearchResult {title:string;detail:string;route:string}
/** Same PP-first order, capped before building unrelated result objects or scanning SPKs. */
export function workspaceSearch(s:State,query:string):WorkspaceSearchResult[]{
 if(!query.trim())return [];
 const match=query.toLowerCase(),results:WorkspaceSearchResult[]=[];
 for(const p of s.projects){if(`${p.no} ${p.name}`.toLowerCase().includes(match))results.push({title:p.no,detail:p.name,route:'/project-plan/'+p.id});if(results.length===6)return results;}
 const deals=new Map(s.deals.map(d=>[d.id,d]));
 for(const v of s.spks){const d=deals.get(v.dealId);if(d&&`${v.no} ${d.mandor}`.toLowerCase().includes(match))results.push({title:v.no,detail:d.mandor,route:'/spk/'+v.id});if(results.length===6)break;}
 return results;
}

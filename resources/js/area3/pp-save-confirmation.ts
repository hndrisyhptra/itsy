import type {Command} from './commands';
import type {State} from './domain';

export const PP_DRAFT_RESPONSE_MS=60000;
export type WorkspacePayload={state:State;revision:number;csrf?:string;confirmation?:unknown};

export function isProjectDraftSave(command:Command):boolean {
 return ['saveProject','saveProjectBundle'].includes(command.command)&&command.args[1]===false;
}

export function isWorkspacePayload(data:unknown):data is WorkspacePayload {
 if(!data||typeof data!=='object')return false;
 const d=data as Partial<WorkspacePayload>;
 return typeof d.revision==='number'&&Number.isSafeInteger(d.revision)&&d.revision>=0&&!!d.state&&typeof d.state==='object'&&Array.isArray(d.state.projects);
}

/** Existence alone is insufficient: only this actor's committed UUID proves this save. */
export function confirmsProjectSave(data:unknown,command:Command,requestId:string,expectedRevision:number):data is WorkspacePayload {
 if(!isProjectDraftSave(command)||!isWorkspacePayload(data)||!data.confirmation||typeof data.confirmation!=='object')return false;
 const proof=data.confirmation as Record<string,unknown>,target=command.args[0] as {id?:string}|undefined;
 return proof.requestId===requestId&&proof.command===command.command&&proof.expectedRevision===expectedRevision&&proof.projectId===target?.id
  &&Number.isSafeInteger(proof.resultingRevision)&&(proof.resultingRevision as number)>expectedRevision&&(proof.resultingRevision as number)<=data.revision
  &&typeof target?.id==='string'&&data.state.projects.some(p=>p?.id===target.id);
}

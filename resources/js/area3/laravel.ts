import type { State } from './domain';
export interface LaravelBoot { account?: {username?:string; id:string; name:string; email:string; role:State['role']; branches:string[]}; accountUrls?: {logout:string; manage:string; login:string}; state: State; revision: number; csrf: string; basePath: string; scenarios: Record<string, State>; urls: {state:string; commands:string; csv:string; backup:string} }
declare global { interface Window { __AREA3__?: LaravelBoot } }
export const boot = typeof window === 'undefined' ? undefined : window.__AREA3__;
export const basePath = boot?.basePath ?? '';
export const appUrl = (path: string) => basePath + path;
const workspacePaths = {state:'/api/prototype/state',commands:'/api/prototype/commands',csv:'/api/prototype/exports/csv',backup:'/api/prototype/backup'} as const;
export const workspaceUrl = (kind: keyof typeof workspacePaths) => appUrl(workspacePaths[kind]);
export const relativePath = (path: string) => basePath && (path === basePath || path.startsWith(basePath + '/')) ? path.slice(basePath.length) || '/' : path;
export function exportUrl(report: string, filters: Record<string, string>): string | undefined {
  if (!boot) return undefined;
  const query = new URLSearchParams({report});
  for (const [key,value] of Object.entries(filters)) if (value) query.set(key,value);
  return workspaceUrl('csv')+'?'+query;
}

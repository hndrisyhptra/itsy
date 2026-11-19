import type { Project } from './domain';

/** Exact related PP only; no root, work location, account or historical city fallback. */
export function projectSigningPlace(project: Pick<Project, 'branch'>): string {
  return project.branch?.trim() || '';
}

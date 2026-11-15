import type { Project, State } from './domain';

/** Administrative document marker requested by the user; never a sixth account decision. */
export const PP_EVP_SIGNATORY = Object.freeze({
  name: 'I GEDE WAHYU DANYNDRA',
  nik: '730371',
  job: 'EVP Area Jawa Bali',
  label: 'Disetujui Oleh',
});

export function ppEvpSignatory(s: Pick<State, 'deals'>, p: Pick<Project, 'id'>) {
  let firstDate: string | undefined;
  for (const deal of s.deals) {
    if (deal.projectId !== p.id || !/^\d{4}-\d{2}-\d{2}$/.test(deal.date)) continue;
    const date = new Date(`${deal.date}T00:00:00Z`);
    if (!Number.isFinite(date.getTime()) || date.getUTCFullYear() < 1 || date.toISOString().slice(0, 10) !== deal.date) continue;
    if (!firstDate || deal.date < firstDate) firstDate = deal.date;
  }
  // Calendar arithmetic in UTC keeps month/year/leap boundaries independent of browser timezone.
  const previous = firstDate ? new Date(`${firstDate}T00:00:00Z`) : undefined;
  previous?.setUTCDate(previous.getUTCDate() - 1);
  const date = previous && previous.getUTCFullYear() >= 1 ? previous.toISOString().slice(0, 10) : undefined;
  return { ...PP_EVP_SIGNATORY, approved: true, automatic: true, date, bakhDate: firstDate, status: 'Disetujui otomatis' } as const;
}

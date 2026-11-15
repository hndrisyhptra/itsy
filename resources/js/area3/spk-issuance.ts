import type { Spk } from './domain';

export const SPK_ISSUE_PLACE = 'Surabaya';
export function spkCalendarDate(value: unknown): string {
  if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value)||value<'0001-01-01')return '';
  const date=new Date(`${value}T12:00:00Z`);
  return Number.isFinite(date.getTime())&&date.toISOString().slice(0,10)===value?value:'';
}
/** Keep earlier dates; derive missing/late dates from the SPK's own valid work start. */
export function spkIssueDate(value: unknown, start: unknown): string {
  const date=spkCalendarDate(value),limit=spkCalendarDate(start);
  return limit&&(!date||date>limit)?limit:date;
}
export function syncSpkIssuance<T extends Pick<Spk,'place'|'date'|'start'>>(spk:T):T {
  return {...spk,place:SPK_ISSUE_PLACE,date:spkIssueDate(spk.date,spk.start)};
}

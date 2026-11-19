import { projectSigningPlace } from './document-place';
import type { Project, Spk } from './domain';

export function spkCalendarDate(value: unknown): string {
  if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value)||value<'0001-01-01')return '';
  const date=new Date(`${value}T12:00:00Z`);
  return Number.isFinite(date.getTime())&&date.toISOString().slice(0,10)===value?value:'';
}
/** Preserve any valid selected date; default missing/invalid dates to the SPK's own work start. */
export function spkIssueDate(value: unknown, start: unknown): string {
  return spkCalendarDate(value)||spkCalendarDate(start);
}
export function syncSpkIssuance<T extends Pick<Spk,'place'|'date'|'start'>>(spk:T,project:Pick<Project,'branch'>):T {
  return {...spk,place:projectSigningPlace(project),date:spkIssueDate(spk.date,spk.start)};
}

export function spkPeriodRange(period:{start:string;end:string}):string {
  const label=(value:string)=>spkCalendarDate(value)?value.split('-').reverse().join('/'):'belum valid';
  return `${label(period.start)}–${label(period.end)}`;
}
/** Compare the exact saved BA KH, independently from either document's issue date. */
export function spkPeriodError(spk:{start:string;end:string},deal:{no:string;start:string;end:string;status:string}):string {
  if(spk.start<=spk.end&&spk.start>=deal.start&&spk.end<=deal.end)return '';
  return `Periode SPK harus berada dalam periode BA KH. SPK: ${spkPeriodRange(spk)}. BA KH ${deal.no} tersimpan: ${spkPeriodRange(deal)}. ${deal.status==='Draft'?'Buka BA KH dan simpan draf untuk menyelaraskan periode dengan jadwal PP, lalu periksa kembali SPK.':'Periksa referensi BA KH dan sesuaikan periode SPK dengan dokumen tersimpan.'}`;
}

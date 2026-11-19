import type { Project, Deal, Spk } from './domain';

export const DOCUMENT_DATE_POLICY='pre-work-issue-v1' as const;
export function calendarDate(value:unknown):string {
  if(typeof value!=='string'||!/^\d{4}-\d{2}-\d{2}$/.test(value)||value<'0001-01-01')return '';
  const date=new Date(value+'T12:00:00Z');
  return Number.isFinite(date.getTime())&&date.toISOString().slice(0,10)===value?value:'';
}
export function savedWorkStart(p:Pick<Project,'schedule'>):string {
  return p.schedule.filter(row=>row.days>0).map(row=>calendarDate(row.start)).filter(Boolean).sort()[0]??'';
}
export function planningDates(p:Project,previous?:Project):Project {
  return {...p,documentDatePolicy:DOCUMENT_DATE_POLICY,issueDate:p.date,workStartDate:p.workStartDate??previous?.workStartDate??(previous?savedWorkStart(previous):'')};
}
export function issueDateSuggestion(p:Project,kind:'bakh'|'spk'):string {
  const start=calendarDate(p.workStartDate)||savedWorkStart(p);
  if(!start)return '';
  if(kind==='spk')return start;
  const previous=new Date(start+'T12:00:00Z');previous.setUTCDate(previous.getUTCDate()-1);
  const suggestion=calendarDate(previous.toISOString().slice(0,10))||start;
  const issue=calendarDate(p.issueDate);
  return issue&&issue>suggestion?issue:suggestion;
}
export function projectIssueError(p:Project,formal=false):string {
  if(p.documentDatePolicy!==DOCUMENT_DATE_POLICY)return '';
  if(p.issueDate&&!calendarDate(p.issueDate))return 'Saat Penggunaan pada Header PP tidak valid.';
  if(p.workStartDate&&!calendarDate(p.workStartDate))return 'Tanggal Mulai Pekerjaan tidak valid.';
  if(formal&&(!p.issueDate||!p.workStartDate))return 'Lengkapi Saat Penggunaan di Header PP dan Tanggal Mulai Pekerjaan di Jadwal Proyek.';
  if(p.issueDate&&p.workStartDate&&p.issueDate>p.workStartDate)return `Tanggal PP (Saat Penggunaan) ${p.issueDate} tidak boleh melewati tanggal mulai pekerjaan ${p.workStartDate}.`;
  return '';
}
export function bakhIssueError(p:Project,d:Deal,formal=false):string {
  if(p.documentDatePolicy!==DOCUMENT_DATE_POLICY&&d.documentDatePolicy!==DOCUMENT_DATE_POLICY)return '';
  const error=projectIssueError(p,formal);if(error)return error;
  if(!calendarDate(d.date))return formal||d.date?'Tanggal terbit BA KH tidak valid.':'';
  const start=calendarDate(p.workStartDate)||savedWorkStart(p);
  if(start&&d.date>start)return `Tanggal terbit BA KH ${d.date} tidak boleh melewati tanggal mulai pekerjaan ${start}.`;
  if(calendarDate(p.issueDate)&&d.date<p.issueDate!)return `Tanggal terbit BA KH ${d.date} tidak boleh sebelum tanggal terbit PP ${p.issueDate}.`;
  return '';
}
export function spkIssuanceError(p:Project,d:Deal,v:Spk,formal=false):string {
  if(p.documentDatePolicy!==DOCUMENT_DATE_POLICY&&v.documentDatePolicy!==DOCUMENT_DATE_POLICY)return '';
  const error=bakhIssueError(p,{...d,documentDatePolicy:DOCUMENT_DATE_POLICY},formal);if(error)return error;
  if(!calendarDate(v.date))return formal||v.date?'Tanggal terbit SPK tidak valid.':'';
  const start=calendarDate(p.workStartDate)||savedWorkStart(p);
  if(start&&v.date>start)return `Tanggal terbit SPK ${v.date} tidak boleh melewati tanggal mulai pekerjaan ${start}.`;
  if(calendarDate(d.date)&&v.date<d.date)return `Tanggal terbit SPK ${v.date} tidak boleh sebelum tanggal terbit BA KH ${d.date}.`;
  return '';
}

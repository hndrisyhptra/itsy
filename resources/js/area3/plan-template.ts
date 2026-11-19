import { DOCUMENT_DATE_POLICY, calendarDate } from './document-issuance';
import template from '../../data/project-plan-template.json';
import type { Project } from './domain';

export const ppTemplate = template;
export const templateIntroduction = template.introduction.map(s => `${s.title}:\n${s.text}`).join('\n\n');

/** Preview tolerates an incomplete Header; persistence always uses strict mode. */
export function applyPlanTemplate(p: Project, strict = false, complete = true): Project {
  const independent=p.documentDatePolicy===DOCUMENT_DATE_POLICY;
  const start=independent?p.workStartDate??'':p.date;
  let cursor = new Date(`${start}T00:00:00Z`);
  const validDate = !!calendarDate(start);
  let malformed=p.schedule.length>5||(complete&&p.schedule.length!==5);
  let total = 0, valid = validDate && (complete ? p.schedule.length === 5 : p.schedule.length <= 5);
  const schedule = template.stages.map((name, i) => {
    const days = p.schedule[i]?.days ?? 0;
    const validDays = Number.isSafeInteger(days) && days >= 0 && days <= 1000000;
    total += days;
    malformed ||= !validDays||total>1000000;
    valid &&= validDays && total <= 1000000;
    let start = '', end = '';
    if (valid && days) {
      start = cursor.toISOString().slice(0,10);
      cursor = new Date(cursor.getTime() + (days - 1) * 86400000);
      valid &&= cursor.getUTCFullYear() <= 9999;
      if (valid) end = cursor.toISOString().slice(0,10);
      cursor = new Date(cursor.getTime() + 86400000);
    }
    return { name, days, start, end };
  });
  if (strict && (malformed||(!valid && (complete || !!start)) || (complete && total <= 0))) throw new Error(independent?'Isi Tanggal Mulai Pekerjaan dan lima HK berupa bilangan bulat nonnegatif dengan total HK lebih dari nol.':'Isi Saat Penggunaan dan lima HK berupa bilangan bulat nonnegatif, dengan total HK lebih dari nol dalam rentang tanggal yang didukung.');
  return { ...p, planTemplateVersion: 1, intro: templateIntroduction, teamText: template.team, team: [], durationDays: total, schedule };
}

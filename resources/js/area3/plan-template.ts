import template from '../../data/project-plan-template.json';
import type { Project } from './domain';

export const ppTemplate = template;
export const templateIntroduction = template.introduction.map(s => `${s.title}:\n${s.text}`).join('\n\n');

/** Preview tolerates an incomplete Header; persistence always uses strict mode. */
export function applyPlanTemplate(p: Project, strict = false, complete = true): Project {
  let cursor = new Date(`${p.date}T00:00:00Z`);
  const validDate = /^\d{4}-\d{2}-\d{2}$/.test(p.date) && Number.isFinite(cursor.getTime()) && cursor.toISOString().slice(0,10) === p.date;
  let total = 0, valid = validDate && (complete ? p.schedule.length === 5 : p.schedule.length <= 5);
  const schedule = template.stages.map((name, i) => {
    const days = p.schedule[i]?.days ?? 0;
    const validDays = Number.isSafeInteger(days) && days >= 0 && days <= 1000000;
    total += days;
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
  if (strict && (!valid || (complete && total <= 0))) throw new Error('Isi Saat Penggunaan dan lima HK berupa bilangan bulat nonnegatif, dengan total HK lebih dari nol dalam rentang tanggal yang didukung.');
  return { ...p, planTemplateVersion: 1, intro: templateIntroduction, teamText: template.team, team: [], durationDays: total, schedule };
}

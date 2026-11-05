import type { Deal, Project, State } from './domain';

export function bakhPeriod(project: Pick<Project,'schedule'>): {start:string;end:string;hk:number}|null {
  const dates:string[]=[];
  let hk=0;
  for(const row of project.schedule) {
    if(!Number.isFinite(row.days)||row.days<0)return null;
    hk+=row.days;
    if(!row.days)continue;
    for(const value of [row.start,row.end]) {
      if(!/^\d{4}-\d{2}-\d{2}$/.test(value)||!Number.isFinite(Date.parse(value+'T00:00:00Z'))||new Date(value+'T00:00:00Z').toISOString().slice(0,10)!==value)return null;
      dates.push(value);
    }
    if(row.start>row.end)return null;
  }
  if(!dates.length||hk<1||hk>1000000)return null;
  dates.sort();
  const start=dates[0],last=dates.at(-1)!,limit=Date.parse(start+'T00:00:00Z')+(Math.floor(hk)-1)*86400000;
  return {start,end:Date.parse(last+'T00:00:00Z')<=limit?last:new Date(limit).toISOString().slice(0,10),hk};
}

export function syncBakhPeriod(s:Pick<State,'projects'|'deals'>,deal:Deal):Deal {
  const saved=s.deals.find(row=>row.id===deal.id);
  if(saved&&(saved.status==='Terbit'||saved.status==='Sah'))return {...deal,start:saved.start,end:saved.end};
  const project=s.projects.find(row=>row.id===deal.projectId),period=project?bakhPeriod(project):null;
  return {...deal,start:period?.start??'',end:period?.end??''};
}

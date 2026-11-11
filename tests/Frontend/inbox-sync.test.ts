import test from 'node:test';
import assert from 'node:assert/strict';
import { createInboxSync, inboxSyncRoute, INBOX_SYNC_INTERVAL } from '../../resources/js/area3/inbox-sync';

function clock(){
  let time=0,id=0;const jobs=new Map<number,{at:number;fn:()=>void}>();
  const timer=(fn:()=>void,ms:number)=>{const key=++id;jobs.set(key,{at:time+ms,fn});return key as unknown as ReturnType<typeof setTimeout>;};
  const cancel=(key:ReturnType<typeof setTimeout>)=>{jobs.delete(key as unknown as number);};
  const settle=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
  async function advance(ms:number){const end=time+ms;while(true){const next=[...jobs.entries()].filter(([,v])=>v.at<=end).sort((a,b)=>a[1].at-b[1].at)[0];if(!next)break;time=next[1].at;jobs.delete(next[0]);next[1].fn();await settle();}time=end;await settle();}
  return {timer,cancel,now:()=>time,advance,settle,pending:()=>jobs.size};
}

test('revision-only polling fetches state exactly once when changed and uses no requests while hidden',async()=>{
  const c=clock();let visible=true,eligible=true,remote=3,local=3,probes=0,refreshes=0;
  const sync=createInboxSync({...c,visible:()=>visible,eligible:()=>eligible,revision:()=>local,probe:async()=>{probes++;return remote;},refresh:async()=>{refreshes++;local=remote;}});
  await c.advance(0);assert.equal(probes,1);assert.equal(refreshes,0);
  await c.advance(INBOX_SYNC_INTERVAL*3);assert.equal(probes,4);assert.equal(refreshes,0);
  remote=4;await c.advance(INBOX_SYNC_INTERVAL);assert.equal(refreshes,1);assert.equal(local,4);
  visible=false;sync.wake();await c.advance(60000);assert.equal(probes,5);assert.equal(c.pending(),0);
  visible=true;sync.wake();await c.advance(0);assert.equal(probes,6);assert.equal(refreshes,1);
  eligible=false;await c.advance(30000);assert.equal(probes,6);
  eligible=true;sync.wake();await c.advance(0);assert.equal(probes,7);
  sync.stop();await c.advance(60000);assert.equal(probes,7);assert.equal(c.pending(),0);
});

test('focus bursts never overlap a request and navigation or form entry during the probe prevents a state refresh',async()=>{
  const c=clock();let eligible=true,probes=0,refreshes=0,resolve!:(n:number)=>void;
  const sync=createInboxSync({...c,visible:()=>true,eligible:()=>eligible,revision:()=>0,probe:async()=>{probes++;return new Promise<number>(r=>{resolve=r;});},refresh:async()=>{refreshes++;}});
  await c.advance(0);for(let i=0;i<10;i++)sync.wake();await c.advance(1000);assert.equal(probes,1);
  eligible=false;resolve(2);await c.settle();assert.equal(refreshes,0);
  eligible=true;sync.wake();await c.advance(1999);assert.equal(probes,1);await c.advance(1);assert.equal(probes,2);
  sync.stop();resolve(3);await c.settle();assert.equal(refreshes,0);assert.equal(c.pending(),0);
});

test('network errors back off to two minutes with one read per attempt; stopping aborts pending requests',async()=>{
  const c=clock();let calls=0;const status:string[]=[];let last:AbortSignal|undefined;
  const sync=createInboxSync({...c,visible:()=>true,eligible:()=>true,revision:()=>0,probe:async signal=>{calls++;last=signal;throw new Error('Offline');},refresh:async()=>{throw new Error('Must not refresh');},status:v=>status.push(v)});
  await c.advance(0);assert.equal(calls,1);await c.advance(29999);assert.equal(calls,1);await c.advance(1);assert.equal(calls,2);
  await c.advance(60000);assert.equal(calls,3);await c.advance(120000);assert.equal(calls,4);
  assert.ok(status.every(v=>v.includes('tertunda')));sync.stop();assert.equal(c.pending(),0);assert.ok(last);
  const blocked=createInboxSync({...c,visible:()=>true,eligible:()=>true,revision:()=>0,probe:signal=>{last=signal;return new Promise(()=>{});},refresh:async()=>{}});
  await c.advance(0);assert.equal(last!.aborted,false);blocked.stop();assert.equal(last!.aborted,true);
});

test('only known read-only lists are eligible; editors and document details never advance form revisions',()=>{
  for(const path of ['/inbox','/dashboard','/project-plan?branch=QA','/spk','/termin/','/ba-pp','/laporan'])assert.equal(inboxSyncRoute(path),true,path);
  for(const path of ['/project-plan/new','/project-plan/pp-a/edit','/project-plan/pp-a','/spk/ba-kh/new','/termin/term-a','/termin/new','/master-designator/khs','/pengaturan'])assert.equal(inboxSyncRoute(path),false,path);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { seed } from '../../resources/js/area3/seed';
import { blankProject, normalizeWorkflow, saveProject } from '../../resources/js/area3/domain';

test('PP draft/submission use same-origin subfolder endpoints despite old HTTP boot URLs; uncertain writes never auto-retry', async () => {
  const state = normalizeWorkflow(seed('B')); state.schema = 3;
  const p = { ...blankProject(), unit: 'Konstruksi', name: 'Transport QA', branch: state.branches![0].name, projectCode: 'URL-QA', budget: 10000, date: '2026-11-10' };
  const keys = ['window', 'localStorage', 'fetch'];
  const originals = new Map(keys.map(key => [key, Object.getOwnPropertyDescriptor(globalThis, key)]));
  const win = new EventTarget(), cache = new Map<string, string>(), calls: { url: string; options: RequestInit }[] = [];
  const base = '/its/area3-prototype/public'; let mode = 'network', latest = state;
  Object.assign(win, { location: { href: 'https://public.example.test'+base+'/project-plan/new' }, __AREA3__: {
    state, revision: 0, csrf: 'qa-token', basePath: base, scenarios: {}, account: {id:'qa',name:'QA',role:'Admin Konstruksi',branches:[p.branch]},
    urls: {state:'http://internal-upstream/state',commands:'http://internal-upstream/commands',csv:'http://internal-upstream/csv',backup:'http://internal-upstream/backup'},
  } });
  Object.defineProperty(globalThis, 'window', { configurable: true, value: win });
  Object.defineProperty(globalThis, 'localStorage', { configurable: true, value: { getItem: (k:string) => cache.get(k)??null, setItem: (k:string,v:string) => cache.set(k,v), removeItem: (k:string) => cache.delete(k) } });
  Object.defineProperty(globalThis, 'fetch', { configurable: true, value: async (url:string, options:RequestInit) => {
    calls.push({url,options});
    assert.ok(url.startsWith(base+'/api/prototype/')); assert.ok(!url.includes('internal-upstream'));
    if (options.method !== 'POST') return Response.json({state:latest,revision:0,csrf:'qa-token'});
    if (mode === 'network') throw new TypeError('Failed to fetch');
    if (mode === 'timeout') throw new DOMException('Timed out', 'AbortError');
    if (mode === 'html') return new Response('<html>Proxy rejected request</html>', {status:413});
    if (mode === 'validation') return Response.json({errors:{project:['Lengkapi BOQ sebelum pengajuan.']}}, {status:422});
    return Response.json({state:latest,revision:1,csrf:'new-token'});
  } });
  try {
    const store = await import('../../resources/js/area3/store');
    const { workspaceUrl, exportUrl } = await import('../../resources/js/area3/laravel');
    assert.equal(workspaceUrl('commands'), base+'/api/prototype/commands');
    assert.equal(workspaceUrl('backup'), base+'/api/prototype/backup');
    const csv = exportUrl('PP & Anggaran', {branch:'A/B',empty:''})!;
    assert.ok(csv.startsWith(base+'/api/prototype/exports/csv?'));
    assert.equal(new URL(csv,'https://public.example.test').searchParams.get('report'), 'PP & Anggaran');
    store.saveDraft('transport-qa', p);
    for (const [failure, expected] of [['network',/Status penyimpanan belum dapat dipastikan/],['timeout',/Batas waktu respons server/],['html',/HTTP 413/],['validation',/Lengkapi BOQ/]] as const) {
      mode = failure; calls.length = 0;
      store.mutate(s => saveProject(s,p,false));
      await assert.rejects(store.waitForPersistence(), expected);
      assert.equal(calls.filter(c => c.options.method === 'POST').length, 1);
      assert.equal(calls.filter(c => c.options.method !== 'POST').length, 1);
      assert.equal(calls[0].url, base+'/api/prototype/commands');
      assert.equal(calls[1].url, base+'/api/prototype/state');
      assert.equal(calls[0].options.credentials, 'same-origin');
      assert.equal((calls[0].options.headers as Record<string,string>)['X-CSRF-TOKEN'], 'qa-token');
      assert.match(JSON.parse(calls[0].options.body as string).request_id, /^[0-9a-f-]{36}$/);
      assert.equal(cache.size,1); assert.equal(store.persistencePending,false);
    }
    // The command may have succeeded before its response was lost. Recovery reads it;
    // the UI keeps the uncertain result explicit instead of silently sending another POST.
    latest = structuredClone(state); saveProject(latest,p,false); mode = 'network'; calls.length = 0;
    store.mutate(s => saveProject(s,p,false)); await assert.rejects(store.waitForPersistence(), /periksa daftar Project Plan/);
    assert.equal(calls.length,2); assert.equal(cache.size,1);
    mode = 'success'; calls.length = 0;
    store.mutate(s => saveProject(s,p,false)); await store.waitForPersistence();
    assert.equal(calls.length,1); assert.equal(store.persistenceError,'');
    store.clearDraft('transport-qa'); assert.equal(cache.size,0);
  } finally { for (const [key, descriptor] of originals) { if (descriptor) Object.defineProperty(globalThis,key,descriptor); else Reflect.deleteProperty(globalThis,key); } }
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { Contracts } from '../../resources/js/area3/contracts';

test('BA KH list shows the saved stage and only Sah without SPK offers creation to operational roles',()=>{
  const s=seed('C'),d=s.deals[0];
  for(const status of ['Draft','Terbit','Sah'] as const){
    d.status=status;const before=JSON.stringify(s),html=renderToStaticMarkup(createElement(Contracts,{s,kind:'deal'}));
    assert.match(html,/<th[^>]*>Tindak Lanjut SPK<\/th>/);
    assert.match(html,/table-scroll contract-list-plain/);
    if(status==='Sah'){
      assert.match(html,/Menunggu Pengajuan SPK/);assert.match(html,/Negosiasi &amp; BA KH selesai/);
      assert.ok(html.includes(`/spk/new?deal=${encodeURIComponent(d.id)}`));assert.match(html,/Buat SPK/);
    }else{
      assert.match(html,status==='Draft'?/Negosiasi Berlangsung/:/Menunggu Pengesahan BA KH/);
      assert.doesNotMatch(html,/Buat SPK|Menunggu Pengajuan SPK/);
    }
    assert.equal(JSON.stringify(s),before);
  }
  s.role='PM Konstruksi';
  const readOnly=renderToStaticMarkup(createElement(Contracts,{s,kind:'deal'}));
  assert.match(readOnly,/Menunggu Pengajuan SPK/);assert.doesNotMatch(readOnly,/Buat SPK|\/spk\/new\?deal=/);
});

test('existing SPK draft, issued and Sah show the exact saved document and never offer duplicate creation',()=>{
  const s=seed('D'),spk=s.spks[0];
  for(const status of ['Draft','Terbit','Sah'] as const){
    spk.status=status;const html=renderToStaticMarkup(createElement(Contracts,{s,kind:'deal'}));
    assert.ok(html.includes(`SPK ${status==='Draft'?'Draf':status}`));
    assert.ok(html.includes(spk.no));assert.ok(html.includes(`/spk/${spk.id}`));assert.match(html,/Buka SPK/);
    assert.doesNotMatch(html,/Menunggu Pengajuan SPK|Buat SPK|\/spk\/new\?deal=/);
  }
  const spkList=renderToStaticMarkup(createElement(Contracts,{s,kind:'spk'}));
  assert.doesNotMatch(spkList,/Tindak Lanjut SPK/);
  assert.equal((spkList.match(/<th\b/g)??[]).length,6);
});

test('same business Project ID cannot mix BA KH/SPK follow-ups; loaded collections are indexed without per-row find or state writes',()=>{
  const s=seed('D'),p=structuredClone(s.projects[0]),d=structuredClone(s.deals[0]);
  p.id=p.rootId='pp-follow-up-second';p.no='PP/SECOND';
  d.id='bakh-follow-up-second';d.projectId=p.id;d.no='BAKH/SECOND';
  s.projects.push(p);s.deals.push(d);
  const before=JSON.stringify(s);
  s.projects.find=s.deals.find=s.spks.find=()=>{throw new Error('Per-row collection lookup');};
  const html=renderToStaticMarkup(createElement(Contracts,{s,kind:'deal'}));
  assert.match(html,/Menunggu Pengajuan SPK/);assert.match(html,/SPK Sah/);
  assert.ok(html.includes(`/spk/${s.spks[0].id}`));
  assert.ok(html.includes('/spk/new?deal=bakh-follow-up-second'));
  assert.ok(!html.includes(`/spk/new?deal=${s.deals[0].id}`));
  assert.equal(JSON.stringify(s),before);
});

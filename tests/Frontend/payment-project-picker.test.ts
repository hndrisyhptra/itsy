import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { paymentAction, paymentStage, localPaymentProjects, localPaymentOrders, explicitPaymentChoice, paymentStartAction, type PaymentLatest } from '../../resources/js/area3/payment-projects';
import { PaymentProjectPicker, PaymentSpkChoices, PaymentSelectionSummary } from '../../resources/js/area3/payment-project-picker';

test('Pengajuan Baru starts with an empty Project ID search and no automatically selected SPK, draft or global dropdown',()=>{
  const s=seed('D'),before=JSON.stringify(s),html=renderToStaticMarkup(createElement(PaymentProjectPicker,{s}));
  assert.match(html,/Pengajuan Pembayaran Baru/);assert.match(html,/Cari ID Project atau nama kegiatan/);
  assert.match(html,/minimal 2 karakter/);assert.match(html,/SPK Sah/);
  assert.doesNotMatch(html,/<select|SPK terpilih|Buat Draf Pembayaran|Lanjutkan Draf/);assert.equal(JSON.stringify(s),before);
  s.role='Finance';const denied=renderToStaticMarkup(createElement(PaymentProjectPicker,{s}));
  assert.match(denied,/hanya untuk Admin Konstruksi/);assert.doesNotMatch(denied,/aria-label="Cari ID Project/);
});

test('only Sah orders group under their exact Project ID across PPs, match sibling activities and paginate without catalog finds per row',()=>{
  const s=seed('D'),p=s.projects[0],d=s.deals[0],v=s.spks[0];s.projects=[];s.deals=[];s.spks=[];
  for(let i=0;i<120;i++){
    s.projects.push({...structuredClone(p),id:'p-'+i,rootId:'p-'+i,projectCode:'BULK-'+String(i%30).padStart(3,'0'),name:i===90?'Needle activity':'Activity '+i,no:'PP-'+i});
    s.deals.push({...structuredClone(d),id:'d-'+i,projectId:'p-'+i});s.spks.push({...structuredClone(v),id:'v-'+i,dealId:'d-'+i,status:i===119?'Draft':i===118?'Terbit':'Sah'});
  }
  s.projects.find=s.deals.find=s.spks.find=()=>{throw new Error('Per-row collection find');};
  const before=JSON.stringify(s),found=localPaymentProjects(s,'Needle',1);
  assert.equal(found.items.length,1);assert.equal(found.items[0].spkCount,4);assert.equal(found.items[0].name,'Needle activity');
  const a=localPaymentProjects(s,'BULK',1),b=localPaymentProjects(s,'BULK',2);
  assert.equal(a.items.length,20);assert.equal(a.hasMore,true);assert.equal(b.items.length,10);
  assert.equal(new Set([...a.items,...b.items].map(p=>p.id)).size,30);
  const orders=localPaymentOrders(s,'BULK-000',1);assert.equal(orders.items.length,4);
  for(const row of orders.items){assert.equal(row.projectCode,'BULK-000');assert.equal(row.action,'create');assert.equal(row.nextNumber,1);}
  assert.equal(localPaymentOrders(s,'BULK-029',1).items.length,3);
  assert.equal(localPaymentProjects(s,'a',1).items.length,0);assert.equal(JSON.stringify(s),before);
});

test('shared routing vectors preserve existing drafts, rejected cycles, Finance/APM, early Final and three-term maximum',()=>{
  const vectors=JSON.parse(readFileSync(new URL('../Fixtures/payment-selection.json',import.meta.url),'utf8'));
  for(const v of vectors){const a=paymentAction(v.terms as PaymentLatest[],v.approved);assert.deepEqual([a.action,a.label,a.termId,a.nextNumber],v.expected,v.name);}
  const s=seed('overview'),before=JSON.stringify(s);
  for(const v of s.spks){if(v.status==='Sah'){const choice=explicitPaymentChoice(s,v.id)!;assert.equal(choice.action,'open');assert.ok(choice.termId);assert.equal(paymentStartAction(s,v.id).termId,choice.termId);}}
  assert.equal(JSON.stringify(s),before);
});

test('selection table and summary show exact PP/SPK/Mandor, values, latest status and accessible explicit actions without unsafe content',()=>{
  const s=seed('D'),v=s.spks[0],choice=explicitPaymentChoice(s,v.id)!;
  choice.name='<script>wrong</script>';choice.action='blocked';choice.reason='PP belum Sah.';
  const table=renderToStaticMarkup(createElement(PaymentSpkChoices,{data:{items:[choice],page:1,hasMore:false},picked:choice,onPick:()=>{},onOpen:()=>{}}));
  assert.match(table,/No PP \/ Nama Kegiatan/);assert.match(table,/Nilai SPK/);assert.match(table,/Pembayaran Terakhir/);
  assert.match(table,/aria-pressed="true"/);assert.match(table,/disabled=""/);assert.match(table,/PP belum Sah/);assert.match(table,/&lt;script&gt;/);assert.doesNotMatch(table,/<script>/);
  const summary=renderToStaticMarkup(createElement(PaymentSelectionSummary,{picked:{...choice,action:'create',nextNumber:3,reason:''},onOpen:()=>{},onChange:()=>{}}));
  assert.match(summary,/Termin 3 · Final/);assert.match(summary,/Buat Draf Pembayaran/);assert.match(summary,/Ganti SPK/);
});

test('contextual SPK deep links select only that exact Sah SPK; a missing/ineligible link never falls back to the first record',()=>{
  const s=seed('D'),v=s.spks[0];
  const html=renderToStaticMarkup(createElement(PaymentProjectPicker,{s,initialSpkId:v.id}));
  assert.match(html,/SPK terpilih/);assert.ok(html.includes(v.no));
  v.status='Terbit';assert.equal(explicitPaymentChoice(s,v.id),undefined);
  assert.throws(()=>paymentStartAction(s,v.id),/SPK Sah tidak tersedia/);
  const missing=renderToStaticMarkup(createElement(PaymentProjectPicker,{s,initialSpkId:'missing'}));
  assert.match(missing,/Referensi SPK belum tersedia/);assert.doesNotMatch(missing,/SPK terpilih|Buat Draf Pembayaran/);
});

test('existing-payment table actions open their exact document while a new SPK action selects it for confirmation',()=>{
  const s=seed('D'),choice=explicitPaymentChoice(s,s.spks[0].id)!;
  const calls:string[]=[];
  for(const action of ['create','open'] as const){
    const v={...choice,action,termId:action==='open'?'existing-term':''};
    const table=PaymentSpkChoices({data:{items:[v],page:1,hasMore:false},onPick:row=>calls.push('pick:'+row.id),onOpen:row=>calls.push('open:'+row.termId)});
    const row=table.props.children[0],button=row.props.children.at(-1)!.props.children;
    button.props.onClick();
  }
  assert.deepEqual(calls,['pick:'+choice.id,'open:existing-term']);
});

test('latest payment status explains scan, Finance, APM and settlement, while creation always checks current assignment gates',()=>{
  const t:PaymentLatest={id:'t',number:1,type:'Parsial',status:'Disetujui',version:3,apmStatus:'',scanStatus:'',financeApproved:false};
  assert.match(paymentStage(null),/Belum ada/);assert.match(paymentStage(t),/Menunggu scan TTD/);
  assert.match(paymentStage({...t,scanStatus:'Menunggu Verifikasi'}),/Menunggu Finance/);
  assert.match(paymentStage({...t,scanStatus:'Perlu Koreksi'}),/Scan perlu koreksi/);
  assert.match(paymentStage({...t,financeApproved:true}),/Menunggu APM/);
  assert.match(paymentStage({...t,version:2,scanStatus:'Valid'}),/Menunggu APM/);
  assert.match(paymentStage({...t,apmStatus:'Valid'}),/APM valid/);
  const s=seed('D');s.schema=3;s.projects[0].workflowVersion=3;s.projects[0].approverUserIds={};
  assert.throws(()=>paymentStartAction(s,s.spks[0].id),/approver aktif/);
  s.role='Finance';assert.throws(()=>paymentStartAction(s,s.spks[0].id),/Admin Konstruksi/);
});

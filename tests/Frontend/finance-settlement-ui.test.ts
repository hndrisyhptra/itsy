import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {normalizeWorkflow,termTotal} from '../../resources/js/area3/domain';

test('Finance sees settle only after persisted current proof and APM editing after payment; other roles cannot settle',async()=>{
 const s=normalizeWorkflow(seed('E'));s.schema=3;s.role='Finance';s.actorUserId=s.users.find(u=>u.role==='Finance'&&u.active)!.id;
 const t=s.terms[0];t.workflowVersion=3;t.paymentFlow='kasbon-v1';t.status='Disetujui';t.paid=0;t.apm='';t.apmStatus='Belum Terbit';
 t.kasbon={id:'kasbon-ui',version:1,amount:termTotal(s,t),issuedBy:'admin',issuedAt:'2026-11-16T01:00:00Z'};t.financePayment={status:'Diverifikasi'};delete t.transferProof;
 Object.assign(globalThis,{localStorage:{getItem:()=>null,setItem:()=>{},removeItem:()=>{}},window:{addEventListener:()=>{},__AREA3__:{account:{id:s.actorUserId,role:s.role},state:s,revision:0,csrf:'qa',basePath:'',scenarios:{},urls:{}}}});
 const {FinanceKasbonPanel}=await import('../../resources/js/area3/finance-private-ui');
 const render=()=>renderToStaticMarkup(createElement(FinanceKasbonPanel,{s,t}));
 assert.doesNotMatch(render(),/>Tandai Lunas<|Simpan Nomor APM/);
 t.transferProof={id:'proof-ui',kasbonId:'different',uploadedBy:s.actorUserId,uploadedAt:'2026-11-16T01:00:00Z'};assert.doesNotMatch(render(),/>Tandai Lunas</);
 t.transferProof.kasbonId=t.kasbon.id;let html=render();assert.match(html,/>Tandai Lunas</);assert.match(html,/Nomor APM \(opsional\)/);assert.doesNotMatch(html,/Nomor APM \*/);
 t.paid=t.kasbon.amount;Object.assign(t.financePayment,{status:'Dibayar',settledAt:'2026-11-16T02:00:00Z',kasbonId:t.kasbon.id,transferFileId:t.transferProof.id});html=render();
 assert.doesNotMatch(html,/>Tandai Lunas</);assert.match(html,/Simpan Nomor APM/);assert.match(html,/APM belum diisi/);
 s.role='Admin Konstruksi';html=render();assert.doesNotMatch(html,/>Tandai Lunas<|Simpan Nomor APM/);
});

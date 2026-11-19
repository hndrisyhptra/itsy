import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { normalizeWorkflow, APPROVERS_V3, createSettlementTerm,saveTerm,getTerm,attestBarp,prepareBapp,attestBapp } from '../../resources/js/area3/domain';

test('Admin can access kasbon from a new, returned or rejected payment Draft before any approval',async()=>{
  const s=normalizeWorkflow(seed('D'));s.schema=3;s.role='Admin Konstruksi';s.actorUserId=s.users.find(u=>u.role===s.role&&u.active)!.id;
  const p=s.projects[0];p.workflowVersion=3;p.approvalScheme='project-support-first';p.approverUserIds={};
  for(const [i,role] of APPROVERS_V3.entries()){const id='ui-approver-'+i;s.users.push({id,name:'QA '+role,role,email:i+'@example.test',active:true});p.approverUserIds[role]=id;}
  s.terms=[];const id=createSettlementTerm(s,s.spks[0].id),t=s.terms[0];
  const target=globalThis as unknown as {window:unknown;localStorage:unknown};
  target.localStorage={getItem:()=>null,setItem:()=>{},removeItem:()=>{}};
  target.window={addEventListener:()=>{},__AREA3__:{account:{id:s.actorUserId,name:'Admin QA',role:s.role,email:'admin@example.test',branches:[p.branch]},state:s,revision:0,csrf:'QA',basePath:'',scenarios:{},urls:{state:'',commands:'',csv:'',backup:''}}};
  const {TermEditor}=await import('../../resources/js/area3/payments');
  for(const status of ['Draft','Dikembalikan','Ditolak'] as const){
    t.status=status;const html=renderToStaticMarkup(createElement(TermEditor,{s,id}));
    assert.match(html,/>Isi Kasbon &amp; Ajukan Pembayaran<\/button>/,status+' must mount the kasbon button/event listener before approval');
    assert.doesNotMatch(html,/Ajukan Termin<\/button>|Ajukan Payment<\/button>/);assert.match(html,/<button[^>]*disabled[^>]*>Isi Kasbon &amp; Ajukan Pembayaran<\/button>/);assert.doesNotMatch(html,/Approve Payment<\/button>/);
  }
  t.status='Draft';t.actuals={[s.deals[0].rows[0].rowId]:{m:1,s:1}};t.barp!.date=s.spks[0].start;saveTerm(s,t,false);s.actorUserId='qa-admin';attestBarp(s,getTerm(s,id));prepareBapp(s,id);const current=getTerm(s,id),b=s.bapps.find(b=>b.termId===id)!;attestBapp(s,b.id,b.no);assert.doesNotMatch(renderToStaticMarkup(createElement(TermEditor,{s,id})),/<button[^>]*disabled[^>]*>Isi Kasbon &amp; Ajukan Pembayaran<\/button>/);
  current.status='Diajukan';const submitted=renderToStaticMarkup(createElement(TermEditor,{s,id}));
  assert.doesNotMatch(submitted,/>Isi Kasbon &amp; Ajukan Pembayaran<\/button>/);assert.match(submitted,/Menunggu tujuh approval berurutan/);
});

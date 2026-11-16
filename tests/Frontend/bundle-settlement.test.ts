import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { normalizeWorkflow, APPROVERS_V3, createSettlementTerm, approvalRoles, approvalRole, canApproveTerm, decide, type Role, type Term, type State } from '../../resources/js/area3/domain';
import { decideFinancePayment, submitFinanceTerm } from '../../resources/js/area3/finance-payment';
import { ProjectBundleReview } from '../../resources/js/area3/project-bundle-review';
import { KasbonDocument } from '../../resources/js/area3/kasbon-document';
import { kasbonSignatures, type KasbonData } from '../../resources/js/area3/kasbon-content';
import { createKasbonDocx, kasbonWordFilename } from '../../resources/js/area3/kasbon-docx';
import { docxParts } from './helpers/docx-parts';

const vector=JSON.parse(readFileSync(new URL('../Fixtures/kasbon-vector.json',import.meta.url),'utf8')) as KasbonData;
const roles:Role[]=[...APPROVERS_V3,'Mgr Finance & Settlement','Finance'];
function payment(){
  const s=normalizeWorkflow(seed('D'));s.schema=3;s.role='Admin Konstruksi';s.actorUserId=s.users.find(u=>u.role===s.role&&u.active)!.id;
  s.terms=[];s.bapps=[];const p=s.projects[0];p.workflowVersion=3;p.approvalScheme='project-support-first';p.approverUserIds={};
  for(const [i,role] of roles.entries()){const id='new-flow-'+i;s.users.push({id,name:role+' QA',role,active:true,email:i+'@example.test'});if(i<5)p.approverUserIds[role]=id;}
  const id=createSettlementTerm(s,s.spks[0].id),t=s.terms.find(t=>t.id===id)!;
  t.kasbon={id:'current-kasbon',version:1,amount:100000,issuedBy:s.actorUserId,issuedAt:'2026-11-15T01:00:00Z'};
  t.approverUserIds!['Mgr Finance & Settlement']='new-flow-5';t.status='Diajukan';return {s,t};
}
function actor(s:State,role:Role){s.role=role;s.actorUserId='new-flow-'+roles.indexOf(role);}

test('seven-stage payment freezes exact assignees and Finance records the seventh actual decision',()=>{
  const {s,t}=payment();assert.deepEqual(approvalRoles(t),roles);assert.deepEqual(approvalRoles(s.projects[0],true),APPROVERS_V3);
  actor(s,'Finance');assert.equal(canApproveTerm(s,t),false);assert.throws(()=>decide(s,t.id,'Setujui',''),/berurutan/);
  for(const [i,role] of roles.entries()){
    actor(s,role);assert.equal(approvalRole(t),role);assert.equal(canApproveTerm(s,t),true);
    if(role==='Finance')decideFinancePayment(s,t.id,'Setujui','Reviewed','current-kasbon');else decide(s,t.id,'Setujui','Reviewed');
    assert.equal(t.approvals[i].kasbonId,'current-kasbon');assert.equal(t.status,i===6?'Disetujui':'Diajukan');
  }
  assert.equal(t.approverUserIds!.Finance,'new-flow-6');assert.equal(t.financePayment?.status,'Diverifikasi');
});

test('new payment cannot bypass kasbon via legacy submit and rejection restarts the chain',()=>{
  const {s,t}=payment();actor(s,'Admin Konstruksi');t.status='Draft';assert.throws(()=>submitFinanceTerm(s,t),/kasbon/);t.status='Diajukan';
  actor(s,'PM Konstruksi');decide(s,t.id,'Setujui','');actor(s,'Admin Area');assert.throws(()=>decide(s,t.id,'Tolak',''),/Alasan/);decide(s,t.id,'Tolak','Perbaiki');
  assert.equal(t.status,'Ditolak');assert.equal(t.approvals.length,0);assert.equal(t.history.length,2);assert.equal(approvalRole(t),undefined);
});

test('package review exposes only selected PP documents and their exact BA/SPK previews',()=>{
  const s=normalizeWorkflow(seed('D')),p=s.projects[0];p.contractBundle={version:1,submissions:[]};const original=JSON.stringify(s);
  const html=renderToStaticMarkup(createElement(ProjectBundleReview,{s,p}));
  assert.match(html,/Paket Approval/);assert.match(html,/Lampiran BA KH/);assert.match(html,/Lampiran SPK/);assert.match(html,new RegExp('/spk/'+s.spks[0].id));assert.equal(JSON.stringify(s),original);
});

test('kasbon preview stamps only real decisions matching the displayed kasbon version and selected user',()=>{
  const {t}=payment(),d=structuredClone(vector);t.history=roles.map((role,i)=>({role,userId:role==='Finance'?'qa-finance':d.signers[role]!.userId,actorName:role+' QA',decision:'Setujui',note:'',at:'2026-11-15T02:00:00Z',kasbonId:'current-kasbon'}));
  const html=renderToStaticMarkup(createElement(KasbonDocument,{data:d,term:t,kasbonId:'current-kasbon'}));
  assert.equal((html.match(/kasbon-approval-stamp/g)??[]).length,5);assert.match(html,/Mgr Finance &amp; Settlement/);assert.equal((html.match(/class="kasbon-wet-signature"/g)??[]).length,7);assert.deepEqual(kasbonSignatures(d,t,'current-kasbon').map(rows=>rows.reduce((n,r)=>n+(r.span??1),0)),[6,6,6]);assert.equal(roles.length,7);assert.equal(t.history.length,7);assert.doesNotMatch(html,/OLD SLOT|MANDOR SIGNATURE|Ketua Mandor|Business Support|Admin Area|Admin Area QA|Finance QA|0011223344556677/);
  const archived=renderToStaticMarkup(createElement(KasbonDocument,{data:d,term:t,kasbonId:'other-kasbon'}));assert.doesNotMatch(archived,/APPROVED/);
  t.history[0].userId='different-pm';assert.equal(kasbonSignatures(d,t,'current-kasbon').flat().filter(r=>r.approved).length,4);
});

test('kasbon native Word has editable table, blank physical signature areas and safe names without marks',()=>{
  const logo=new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url)));
  const before=JSON.stringify(vector),parts=docxParts(createKasbonDocx(vector,logo)),xml=parts['word/document.xml'];
  assert.equal(Object.keys(parts).length,8);assert.match(xml,/<w:tblHeader\/>/);assert.match(xml,/<w:gridSpan w:val="4"/);assert.match(xml,/0001234567/);assert.match(xml,/Settlement QA/);assert.match(xml,/&lt;QA&gt; &amp;/);
  assert.doesNotMatch(xml,/APPROVED|MANDOR SIGNATURE|OLD SLOT|Business Support|0011223344556677|altChunk|<html|TargetMode="External"/);assert.match(xml,/<w:line w:val|w:line="800"/);
  const term={history:[{role:'Finance',userId:'qa-finance',actorName:'Finance Aktual QA',decision:'Setujui',note:'',at:'2026-11-15T02:00:00Z',kasbonId:'active'}]} as Term;
  const actual=docxParts(createKasbonDocx(vector,logo,term,'active'))['word/document.xml'];assert.doesNotMatch(actual,/Finance Aktual QA|Admin Area QA/);assert.doesNotMatch(actual,/APPROVED/);
  assert.equal(JSON.stringify(vector),before);assert.doesNotMatch(kasbonWordFilename({...vector,ppNo:'<>:/\\*?"|'}),/[<>:/\\*?"|]/);
  assert.throws(()=>createKasbonDocx(vector,new Uint8Array()),/Logo/);
});

test('kasbon preview and Word omit a single final label and retain only installment numbers without mutating snapshots',()=>{
  const logo=new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url)));
  for(const templateVersion of [undefined,2,3] as const)for(const [termNumber,termType] of [[1,'Final'],[1,'Parsial'],[2,'Parsial'],[2,'Final'],[3,'Final']] as const){
    const d={...structuredClone(vector),templateVersion,termNumber,termType},before=JSON.stringify(d);
    const html=renderToStaticMarkup(createElement(KasbonDocument,{data:d}));
    const xml=docxParts(createKasbonDocx(d,logo))['word/document.xml'];
    for(const output of [html,xml]){
      assert.ok(output.includes(d.spkNo));
      assert.doesNotMatch(output,/\(Final\)|\(Parsial\)/);
      if(termNumber===1&&termType==='Final')assert.doesNotMatch(output,/Termin 1/);
      else assert.ok(output.includes(` · Termin ${termNumber}`));
    }
    assert.equal(JSON.stringify(d),before);
  }
});

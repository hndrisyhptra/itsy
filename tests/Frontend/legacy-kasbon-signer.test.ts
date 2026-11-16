import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {normalizeWorkflow,projectForSpk,getSpk,type Role} from '../../resources/js/area3/domain';
import {setKasbonPrintSigner,canEditKasbonPrintSigner} from '../../resources/js/area3/finance-payment';
import {KasbonDocument} from '../../resources/js/area3/kasbon-document';
import {kasbonSignatures,type KasbonData} from '../../resources/js/area3/kasbon-content';
import {createKasbonDocx} from '../../resources/js/area3/kasbon-docx';
import {docxParts} from './helpers/docx-parts';

function fixture(){
 const s=normalizeWorkflow(seed('E')),t=s.terms[0];s.role='Finance';s.actorUserId=s.users.find(u=>u.role==='Finance')!.id;
 t.workflowVersion=3;t.paymentFlow='kasbon-v1';t.status='Disetujui';t.kasbon={id:'legacy-active',version:1,amount:1000,issuedBy:'admin',issuedAt:'2026-11-16T01:00:00Z'};
 s.users.push({id:'chosen-finance',name:'Finance Actual <QA> & Name',role:'Mgr Finance & Settlement',active:true,email:'qa@example.test',branches:[projectForSpk(s,getSpk(s,t.spkId)).branch]});
 return {s,t};
}
test('explicit legacy physical signer leaves original approvals, settlement and paid amounts unchanged, even when already paid',()=>{
 const {s,t}=fixture();t.paid=1000;t.apm='APM/PAID';t.apmStatus='Valid';t.financePayment={status:'Dibayar'};
 const before=structuredClone(t);assert.equal(canEditKasbonPrintSigner(s,t,'legacy-active'),true);
 setKasbonPrintSigner(s,t.id,'legacy-active','chosen-finance');const signer=t.kasbonPrintSigners!['legacy-active'];
 assert.equal(signer.name,'Finance Actual <QA> & Name');assert.equal(signer.by,s.actorUserId);
 const after=structuredClone(t);delete after.kasbonPrintSigners;delete after.kasbonPrintSignerHistory;assert.deepEqual(after,before);
 setKasbonPrintSigner(s,t.id,'legacy-active','chosen-finance');assert.equal(t.kasbonPrintSignerHistory!.length,1);
 for(const role of ['Admin Konstruksi','Superadmin','Finance'] as Role[]){s.role=role;assert.equal(canEditKasbonPrintSigner(s,t,'legacy-active'),true);}
});
test('legacy signer rejects wrong role, inactive account, Branch, stale version, pending document and seven-stage payment',()=>{
 const {s,t}=fixture();
 for(const role of ['PM Konstruksi','Mgr Finance & Settlement'] as Role[]){s.role=role;assert.equal(canEditKasbonPrintSigner(s,t,'legacy-active'),false);assert.throws(()=>setKasbonPrintSigner(s,t.id,'legacy-active','chosen-finance'),/Hanya/);}
 s.role='Finance';const user=s.users.at(-1)!;
 user.active=false;assert.throws(()=>setKasbonPrintSigner(s,t.id,'legacy-active',user.id),/aktif/);user.active=true;
 user.branches=['Other Branch'];assert.throws(()=>setKasbonPrintSigner(s,t.id,'legacy-active',user.id),/Branch/);
 user.branches=[projectForSpk(s,getSpk(s,t.spkId)).branch];
 assert.throws(()=>setKasbonPrintSigner(s,t.id,'old-archive',user.id),/aktif/);
 t.status='Diajukan';assert.throws(()=>setKasbonPrintSigner(s,t.id,'legacy-active',user.id),/disetujui/);t.status='Disetujui';
 t.paymentFlow='kasbon-settlement-v2';assert.equal(canEditKasbonPrintSigner(s,t,'legacy-active'),false);assert.throws(()=>setKasbonPrintSigner(s,t.id,'legacy-active',user.id),/tujuh/);
 assert.equal(t.kasbonPrintSigners,undefined);
});
test('legacy HTML and editable Word use explicitly saved Finance identity without inventing an approval; original and new templates retain their signers',()=>{
 const base=JSON.parse(readFileSync(new URL('../Fixtures/kasbon-vector.json',import.meta.url),'utf8')) as KasbonData;
 const logo=new Uint8Array(readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url)));
 const {s,t}=fixture();setKasbonPrintSigner(s,t.id,'legacy-active','chosen-finance');
 const original={...base,templateVersion:2 as const,businessSupport:'Original Business Support QA'},before=JSON.stringify(original);
 const data={...original,printSigner:t.kasbonPrintSigners!['legacy-active']};
 for(const output of [renderToStaticMarkup(createElement(KasbonDocument,{data})),docxParts(createKasbonDocx(data,logo))['word/document.xml']]){
  assert.match(output,/Finance Actual &lt;QA&gt; &amp; Name/);assert.match(output,/Mgr Finance &amp; Settlement Area Jawa Bali/);assert.doesNotMatch(output,/Original Business Support QA|Mgr Business Support|APPROVED/);
 }
 const slot=kasbonSignatures(data,t,'legacy-active').flat().at(-1)!;assert.equal(slot.approved,undefined);assert.equal(slot.role,undefined);
 assert.match(renderToStaticMarkup(createElement(KasbonDocument,{data:original})),/Original Business Support QA/);assert.equal(JSON.stringify(original),before);
 assert.equal(kasbonSignatures({...data,templateVersion:3}).flat().find(r=>r.role==='Mgr Finance & Settlement')?.name,base.signers['Mgr Finance & Settlement']!.name);
});

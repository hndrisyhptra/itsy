import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {documentPackage,documentPackageFilename} from '../../resources/js/area3/document-package';
import {DocumentPackageButton,DocumentPackageContents} from '../../resources/js/area3/document-package-ui';
import {createDocumentPackageDocx} from '../../resources/js/area3/document-package-docx';
import {mergeDocx} from '../../resources/js/area3/docx-merge';
import {docxParts} from './helpers/docx-parts';
import {ooxmlZip} from '../../resources/js/area3/ooxml-zip';
import {paymentFlowSteps,PaymentFlowOverview,ProjectFlowOverview} from '../../resources/js/area3/workflow-overview';
import {approvalRoles,type Term} from '../../resources/js/area3/domain';
import {historicalBarp} from '../../resources/js/area3/barp-content';
import type {KasbonData} from '../../resources/js/area3/kasbon-content';

const logo=readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url));
const setup=()=>{const s=seed('E'),b=s.bapps[0];b.status='Sah';return {s,c:documentPackage(s,b.id)};};

test('package follows exact BAPP → Final → SPK → BA KH → PP; only approved same-SPK stages and no mutations',()=>{
 const {s,c}=setup(),before=JSON.stringify(s);
 s.projects.push({...c.project,id:'unrelated',no:'DO-NOT-EXPORT',projectCode:c.project.projectCode});
 s.terms.push({...c.final,id:'other-spk',spkId:'other',number:1},{...c.final,id:'rejected',status:'Ditolak',number:1});
 const result=documentPackage(s,c.bapp.id);
 assert.equal(result.project.id,c.project.id);assert(!result.terms.some(t=>['other-spk','rejected'].includes(t.id)));
 assert.deepEqual(result.terms.map(t=>t.number),[1,2]);
 s.terms.splice(-2);s.projects.pop();assert.equal(JSON.stringify(s),before);
 c.bapp.status='Draft';assert.throws(()=>documentPackage(s,c.bapp.id),/disahkan/);
 c.bapp.status='Sah';c.project.status='Diajukan';assert.throws(()=>documentPackage(s,c.bapp.id),/PP, BA KH, SPK/);
});

test('package action only appears for Sah BAPP and renders all documents in fixed order',()=>{
 const {s,c}=setup(),before=JSON.stringify(s);
 const action=renderToStaticMarkup(createElement(DocumentPackageButton,{s,bappId:c.bapp.id}));assert.match(action,/Unduh semua dokumen PP sampai Lampiran BA PP/);
 const html=renderToStaticMarkup(createElement(DocumentPackageContents,{s,c}));
 const labels=['Project Plan','BA Kesepakatan Harga','Lampiran BA KH','Surat Perintah Kerja','Lampiran SPK','BA Rekonsiliasi Pekerjaan','BA Penyelesaian Pekerjaan','Lampiran BA PP'];
 let last=-1;for(const label of labels){const pos=html.indexOf('aria-label="'+label,last+1);assert(pos>last,label);last=pos;}
 assert.equal((html.match(/class="document-package-part"/g)??[]).length,11);assert.equal(JSON.stringify(s),before);
 c.bapp.status='Draft';assert.equal(renderToStaticMarkup(createElement(DocumentPackageButton,{s,bappId:c.bapp.id})), '');
});

test('native combined Word preserves independent sections/styles/numbering/media and omits stamps/signature images',()=>{
 const {s,c}=setup(),before=JSON.stringify(s),bytes=createDocumentPackageDocx(s,c,[{kind:'paragraph',heading:2,runs:[{text:'PROJECT PLAN QA <&>'}]}],logo),parts=docxParts(bytes),xml=parts['word/document.xml'];
 assert.match(xml,/PROJECT PLAN QA &lt;&amp;&gt;/);assert.match(xml,/BERITA ACARA/);assert.match(xml,/PENYELESAIAN/);assert.match(xml,/LAMPIRAN/);
 assert.equal((xml.match(/<w:sectPr\b/g)??[]).length,11);
 assert.match(xml,/w:orient="landscape"/);assert.match(xml,/w:orient="portrait"/);
 assert.match(parts['word/styles.xml'],/w:styleId="B0_Normal"/);assert.match(parts['word/styles.xml'],/w:styleId="B1_Normal"/);
 assert.match(parts['word/numbering.xml'],/w:abstractNumId="20000"/);assert.match(xml,/w:numId w:val="20001"/);
 assert.match(parts['word/_rels/document.xml.rels'],/Target="part3\/header1.xml"/);
 assert.match(parts['word/part3/_rels/header1.xml.rels'],/Target="media\/image1.png"/);
 assert(Object.keys(parts).some(p=>p==='word/part3/media/image1.png'));
 assert.doesNotMatch(xml,/APPROVED|Persetujuan otomatis|<w:altChunk|<html|BAPP_[A-Z]+|\{\{[A-Z_]+\}\}/);
 assert.equal(JSON.stringify(s),before);
});

test('merge rejects malformed/unbounded/external packages and filenames cannot escape download path',()=>{
 assert.throws(()=>mergeDocx([]),/terlalu besar/);assert.throws(()=>mergeDocx([new Uint8Array(4)]),/Ukuran/);
 const pkg=ooxmlZip({'word/document.xml':'<w:document><w:body><w:p/><w:sectPr></w:sectPr></w:body></w:document>','word/_rels/document.xml.rels':'<Relationships><Relationship Id="rId1" Type="image" Target="https://invalid.test" TargetMode="External"/></Relationships>'});
 assert.throws(()=>mergeDocx([pkg]),/eksternal/);
 assert.doesNotMatch(documentPackageFilename('../../\\bad:*?"|<>\u0000','docx'),/[\\/:*?"|<>\u0000]/);
 assert.ok(documentPackageFilename('X'.repeat(500),'docx').length<150);
});

test('all three approved payments include their own saved kasbon; no additional withholding or recipient substitution',()=>{
 const {s,c}=setup(),third={...structuredClone(c.final),id:'third-final',number:3};
 c.final.type='Parsial';s.terms.push(third);c.bapp.termId=third.id;
 const context=documentPackage(s,c.bapp.id),kasbons=new Map<string,KasbonData>();
 context.project.branch='MATARAM';
 for(const t of context.terms)t.barp={...historicalBarp(context.deal,context.spk),date:'2026-11-16',place:'OLD CITY'};
 for(const t of context.terms)kasbons.set(t.id,{templateVersion:3,costCenter:'CC-QA',wbs:'WBS-QA',activity:'ACTIVITY-QA',place:'Surabaya',date:'2026-11-16',evp:'EVP-QA',businessSupport:'',rows:[{accountCode:'1',accountName:'Jasa',text:'PAYMENT-'+t.number,amount:'123456'}],payee:{nik:'',accountNumber:'ACCOUNT-'+t.number,accountName:'PAYEE-'+t.number,bank:'BANK-QA'},mandor:'MANDOR-QA',unit:'Konstruksi',project:context.project.name,projectCode:context.project.projectCode,ppNo:context.project.no,spkNo:context.spk.no,termNumber:t.number,termType:t.type,amount:123456,signers:{},issuer:'ADMIN-QA'});
 const before=JSON.stringify([...kasbons]),parts=docxParts(createDocumentPackageDocx(s,context,[{kind:'paragraph',runs:[{text:'PROJECT PLAN'}]}],logo,kasbons)),xml=parts['word/document.xml'];
 assert.equal((xml.match(/<w:sectPr\b/g)??[]).length,16);
 assert.equal((xml.match(/Pengajuan Kasbon Project/g)??[]).length,3);
 assert.equal((xml.match(/MATARAM, \d+ November 2026/g)??[]).length,6);
 assert.match(xml,/Surabaya, 16 November 2026/); // Kasbon was not included in the requested city change.
 for(const n of [1,2,3]){assert.match(xml,new RegExp('ACCOUNT-'+n));assert.match(xml,new RegExp('PAYMENT-'+n));}
 assert.doesNotMatch(xml,/APPROVED|PPh|2,5%/);assert.equal(JSON.stringify([...kasbons]),before);
 for(const section of xml.matchAll(/<w:sectPr\b[\s\S]*?<\/w:sectPr>/g)){
   const kinds=[...section[0].matchAll(/<w:headerReference\b[^>]*w:type="([^"]+)"/g)].map(m=>m[1]);assert.equal(kinds.length,new Set(kinds).size);
 }
});

test('new payment detail shows kasbon before seven approvals and actual transfer/lunas/APM optional independently from scans',()=>{
 const s=seed('E'),t=s.terms[0];t.workflowVersion=3;t.paymentFlow='kasbon-settlement-v2';t.approvals=[];t.status='Draft';t.apmStatus='Belum Terbit';t.apm='';t.paid=0;delete t.kasbon;
 let steps=paymentFlowSteps(t);assert.deepEqual(steps.map(s=>s.label),['Kasbon Admin','7 Approval','Bukti Transfer','Tandai Lunas']);assert(!steps[0].done);assert(!steps[3].done);
 t.status='Disetujui';t.kasbon={id:'k',version:1,amount:100,issuedAt:'2026-11-16T00:00:00Z',issuedBy:'admin'};
 const roles=approvalRoles(t);t.approverUserIds=Object.fromEntries(roles.map((r,i)=>[r,'user-'+i]));t.approvals=roles.map((role,i)=>({role,userId:'user-'+i,actorName:'Actor '+i,at:'2026-11-16T00:00:00Z',decision:'Setujui',note:'',kasbonId:'k'}));
 steps=paymentFlowSteps(t);assert(steps[1].done);assert(!steps[2].done);
 t.transferProof={id:'proof',kasbonId:'k'} as Term['transferProof'];t.financePayment={status:'Dibayar',kasbonId:'k',transferFileId:'proof',settledAt:'2026-11-16T01:00:00Z',settledBy:'finance'};t.apm='';t.paid=100;
 const html=renderToStaticMarkup(createElement(PaymentFlowOverview,{t}));assert.match(html,/Lunas · APM belum diisi \(opsional\)/);assert.match(html,/pembayaran tidak menunggu upload berkas/);
 const p=s.projects[0];p.contractBundle={version:1,submissions:[]};const plan=renderToStaticMarkup(createElement(ProjectFlowOverview,{s,p}));assert.match(plan,/PP \+ BA KH \+ SPK satu pengajuan/);assert.match(plan,/5 Approval/);
});

test('List Payment contains no gradient and export remains lazy without new backend requests',()=>{
 const css=readFileSync(new URL('../../resources/js/area3/finance-payment-list.css',import.meta.url),'utf8');assert.doesNotMatch(css,/gradient\(/);assert.match(css,/background:var\(--surface,#fff\)/);
 const source=readFileSync(new URL('../../resources/js/area3/document-package-ui.tsx',import.meta.url),'utf8');assert.match(source,/await import|import\('\.\/document-package-docx'\)/);assert.match(source,/documents_only=1/);assert.match(source,/cache:'no-store'/);assert.doesNotMatch(source,/localStorage|savePrivateDocument|mutate\(/);
});

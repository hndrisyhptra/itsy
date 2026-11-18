import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {normalizeWorkflow,APPROVERS_V3,getTerm,getSpk,getDeal} from '../../resources/js/area3/domain';
import {signaturePerson,RiskQualitySection} from '../../resources/js/area3/plan-budget-ui';
import {defaultSignatureRows} from '../../resources/js/area3/plan-budget';
import {syncBakhSigner} from '../../resources/js/area3/bakh-signer';
import {syncSpkSigner} from '../../resources/js/area3/spk-signer';
import {bakhSignatures} from '../../resources/js/area3/bakh-content';
import {blankBarp} from '../../resources/js/area3/barp-form';
import {barpAppendixData} from '../../resources/js/area3/barp-appendix-content';
import {bappDocumentData} from '../../resources/js/area3/bapp-content';
import {kasbonSignatures,type KasbonData} from '../../resources/js/area3/kasbon-content';
import {createBakhDocx} from '../../resources/js/area3/bakh-docx';
import {createSpkDocx} from '../../resources/js/area3/spk-docx';
import {createSpkAppendixDocx} from '../../resources/js/area3/spk-appendix-docx';
import {createBarpDocx} from '../../resources/js/area3/barp-docx';
import {createBarpAppendixDocx} from '../../resources/js/area3/barp-appendix-docx';
import {createBappDocx} from '../../resources/js/area3/bapp-docx';
import {createBappAppendixDocx} from '../../resources/js/area3/bapp-appendix-docx';
import {createKasbonDocx} from '../../resources/js/area3/kasbon-docx';
import {docxParts} from './helpers/docx-parts';

function fixture(){
  const s=normalizeWorkflow(seed('E'));s.schema=3;
  const t=getTerm(s,s.bapps[0].termId),v=getSpk(s,t.spkId),d=getDeal(s,v.dealId),p=s.projects.find(p=>p.id===d.projectId)!;
  p.workflowVersion=3;p.approvalScheme='project-support-first';p.approverUserIds={};p.signatureUsers={};p.approvals=[];
  for(const [i,role] of APPROVERS_V3.entries()){
    const id='job-qa-'+i,job='Jabatan resmi QA '+i;
    s.users.push({id,name:'Nama QA '+i,nik:'QA'+i,email:i+'@example.test',active:true,role,jabatan:job});
    p.approverUserIds[role]=id;p.signatureUsers[role]={userId:id,name:'Nama QA '+i,nik:'QA'+i,job};
    p.approvals.push({role,userId:id,actorName:'Nama QA '+i,actorJob:job,decision:'Setujui',note:'',at:'2026-11-18T01:00:00Z'});
  }
  t.workflowVersion=3;t.approverUserIds={...p.approverUserIds};t.approvals=[...p.approvals];
  t.barp={...blankBarp(s,t),date:'2026-11-18'};
  return {s,p,d,v,t};
}

test('PP closing uses current draft account title, freezes saved submitted identity, and retains legacy/EVP defaults without changing roles',()=>{
  const {s,p}=fixture(),row=defaultSignatureRows()[0];
  p.status='Draft';s.users.find(u=>u.id===p.approverUserIds!['PM Konstruksi'])!.jabatan='Jabatan draft terkini';
  assert.equal(signaturePerson(s,p,row).job,'Jabatan draft terkini');
  p.status='Sah';assert.equal(signaturePerson(s,p,row).job,'Jabatan resmi QA 0');
  const before=JSON.stringify(s),html=renderToStaticMarkup(createElement(RiskQualitySection,{s,p,printing:true}));
  for(const i of [0,2,3,4])assert.ok(html.includes('Jabatan resmi QA '+i));
  assert.match(html,/EVP Area Jawa Bali/);assert.doesNotMatch(html,/Jabatan draft terkini/);assert.equal(JSON.stringify(s),before);
  delete p.signatureUsers?.['PM Konstruksi']?.job;delete p.approvals?.[0].actorJob;
  assert.equal(signaturePerson(s,p,row).job,'Jabatan draft terkini','PP without a captured title uses only its selected account title.');
  p.signatureUsers!['PM Konstruksi']!.userId='other';p.signatureUsers!['PM Konstruksi']!.job='FORGED';
  assert.equal(signaturePerson(s,p,row).job,'Jabatan draft terkini');
});

test('legacy PP missing or blank jobs displays the exact assignee title without replacing saved identities, decisions or other documents',()=>{
  const {s,p}=fixture(),row=defaultSignatureRows()[0],id=p.approverUserIds!['PM Konstruksi']!,user=s.users.find(u=>u.id===id)!;
  delete p.signatureUsers!['PM Konstruksi']!.job;delete p.approvals![0].actorJob;
  user.jabatan='Jabatan resmi akun untuk PP lama';user.branches=[p.branch];
  for(const status of ['Diajukan','Sah'] as const){
    p.status=status;
    for(const empty of [undefined,'','   ']){
      p.signatureUsers!['PM Konstruksi']!.job=empty;p.approvals![0].actorJob=empty;
      const before=JSON.stringify(s),person=signaturePerson(s,p,row);
      assert.equal(person.job,user.jabatan);assert.equal(person.name,'Nama QA 0');assert.equal(person.approved,true);
      const html=renderToStaticMarkup(createElement(RiskQualitySection,{s,p,printing:true}));
      assert.match(html,/Jabatan resmi akun untuk PP lama/);assert.match(html,/EVP Area Jawa Bali/);assert.equal(JSON.stringify(s),before);
    }
  }
  p.signatureUsers!['PM Konstruksi']!.job='Jabatan historis tersimpan';assert.equal(signaturePerson(s,p,row).job,'Jabatan historis tersimpan');
  delete p.signatureUsers!['PM Konstruksi']!.job;p.approvals![0].actorJob='Jabatan keputusan historis';assert.equal(signaturePerson(s,p,row).job,'Jabatan keputusan historis');
  delete p.approvals![0].actorJob;
  user.active=false;assert.equal(signaturePerson(s,p,row).job,row.job);user.active=true;
  user.branches=['BRANCH LAIN'];assert.equal(signaturePerson(s,p,row).job,row.job);user.branches=[p.branch];
  user.role='Admin Area';assert.equal(signaturePerson(s,p,row).job,row.job);user.role='PM Konstruksi';
  p.approverUserIds!['PM Konstruksi']='akun-tidak-tersedia';assert.equal(signaturePerson(s,p,row).job,row.job);
});

test('BA KH/SPK take server-equivalent selected snapshots, strip forged jobs and retain issued titles',()=>{
  const {s,p,d,v}=fixture();s.deals=[];s.spks=[];
  const deal=syncBakhSigner(s,{...d,status:'Draft',signerJob:'FORGED'}),spk=syncSpkSigner({...s,deals:[deal]},{...v,status:'Draft',position:'FORGED',signerJob:'FORGED'});
  assert.equal(deal.signerJob,'Jabatan resmi QA 4');assert.equal(bakhSignatures(deal)[0].job,deal.signerJob);assert.equal(spk.position,deal.signerJob);
  s.deals=[{...deal,status:'Sah'}];s.users.find(u=>u.id===p.approverUserIds!['Head of PMO'])!.jabatan='New account title';
  assert.equal(syncBakhSigner(s,{...deal,signerJob:'FORGED'}).signerJob,deal.signerJob);
  const historical={...deal,status:'Sah' as const};delete historical.signerJob;s.deals=[historical];
  assert.equal(syncBakhSigner(s,deal).signerJob,undefined);assert.equal(bakhSignatures(historical)[0].job,'HEAD OF PMO AREA JAWA BALI');
});

test('all native document generators share captured account titles including BARP/BA PP main and appendices',()=>{
  const {s,p,d,v,t}=fixture();
  const deal={...d,signerJob:'Jabatan resmi QA 4'},spk={...v,position:'Jabatan resmi QA 4',signerJob:'Jabatan resmi QA 4'};
  const appendix=barpAppendixData(s,p,deal,spk,t);assert.deepEqual(appendix.signatures.slice(0,3).map(x=>x.job),['Jabatan resmi QA 4','Jabatan resmi QA 3','Jabatan resmi QA 2']);
  assert.equal(bappDocumentData(p,deal,spk,t).headJob,'Jabatan resmi QA 4');
  const logo=readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url));
  const before=JSON.stringify(s);
  const docs=[createBakhDocx(s,p,deal,'bakh'),createBakhDocx(s,p,deal,'appendix'),createSpkDocx(p,deal,spk,logo),createSpkAppendixDocx(s,p,deal,spk),createBarpDocx(p,deal,spk,t),createBarpAppendixDocx(s,p,deal,spk,t),createBappDocx(p,deal,spk,t),createBappAppendixDocx(s,p,deal,spk,t)];
  for(const bytes of docs){const xml=docxParts(bytes)['word/document.xml'];assert.match(xml,/Jabatan resmi QA 4/i);assert.doesNotMatch(xml,/\{\{(?:BAPP_)?HEAD_JOB\}\}|APPROVED/);}
  assert.equal(JSON.stringify(s),before);
});

test('kasbon uses captured issuer and approver titles, keeps fallback for old versions and leaves wet signatures blank in Word',()=>{
  const data:KasbonData={templateVersion:3,costCenter:'QA',wbs:'QA',activity:'QA',place:'Surabaya',date:'2026-11-18',evp:'EVP QA',businessSupport:'',rows:[{accountCode:'QA',accountName:'QA',text:'QA',amount:'100'}],payee:{nik:'0000000000000000',accountNumber:'0000',accountName:'QA',bank:'QA'},mandor:'QA',unit:'Konstruksi',project:'QA',projectCode:'QA',ppNo:'QA',spkNo:'QA',termNumber:1,termType:'Final',amount:100,issuer:'Admin QA',issuerJob:'Jabatan Pengaju QA',signers:{'Head of PMO':{name:'Head QA',userId:'h',job:'Jabatan Head QA'}}};
  const before=JSON.stringify(data),rows=kasbonSignatures(data).flat();assert.equal(rows.find(r=>r.role==='Head of PMO')!.job,'Jabatan Head QA');assert.equal(rows.find(r=>r.action==='Membuat')!.job,'Jabatan Pengaju QA');
  const logo=readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url));
  const xml=docxParts(createKasbonDocx(data,logo))['word/document.xml'];assert.match(xml,/Jabatan Head QA/);assert.match(xml,/Jabatan Pengaju QA/);assert.doesNotMatch(xml,/APPROVED/);assert.equal(JSON.stringify(data),before);
  delete data.signers['Head of PMO']!.job;delete data.issuerJob;
  assert.equal(kasbonSignatures(data).flat().find(r=>r.role==='Head of PMO')!.job,'Head of PMO Area Jawa Bali');
});

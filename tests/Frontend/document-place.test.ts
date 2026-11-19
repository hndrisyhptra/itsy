import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {createElement as h} from 'react';
import {renderToStaticMarkup as render} from 'react-dom/server';
import {seed} from '../../resources/js/area3/seed';
import {projectSigningPlace} from '../../resources/js/area3/document-place';
import {bakhDocumentData} from '../../resources/js/area3/bakh-content';
import {spkDocumentData} from '../../resources/js/area3/spk-content';
import {barpDocumentData} from '../../resources/js/area3/barp-content';
import {bappDocumentData} from '../../resources/js/area3/bapp-content';
import {blankBarp,saveBarp} from '../../resources/js/area3/barp-form';
import {createBakhDocx} from '../../resources/js/area3/bakh-docx';
import {createSpkDocx} from '../../resources/js/area3/spk-docx';
import {createBarpDocx} from '../../resources/js/area3/barp-docx';
import {createBappDocx} from '../../resources/js/area3/bapp-docx';
import {BakhDocument} from '../../resources/js/area3/bakh-document';
import {SpkDocument} from '../../resources/js/area3/spk-document';
import {BarpDocument} from '../../resources/js/area3/barp-document';
import {BappDocument} from '../../resources/js/area3/bapp-document';
import {BakhPlaceField,SpkIssueFields} from '../../resources/js/area3/contracts';
import {applyPlanTemplate} from '../../resources/js/area3/plan-template';
import {ProjectPlanDocument} from '../../resources/js/area3/plan-document';
import {RiskQualitySection} from '../../resources/js/area3/plan-budget-ui';
import {docxParts} from './helpers/docx-parts';
const branches=['PEKALONGAN','SEMARANG','SURAKARTA','YOGYAKARTA','MAGELANG','PURWOKERTO','MADIUN','MALANG','SIDOARJO','JEMBER','DENPASAR','LAMONGAN','SURABAYA','MATARAM','KUPANG','FLORES'];
const logo=readFileSync(new URL('../../resources/js/area3/assets/spk-telkom-akses.png',import.meta.url));
function fixture(){const s=seed('E'),p=s.projects[0],d=s.deals[0],v=s.spks[0],t=s.terms[0];
 d.date=v.date='2026-11-16';d.signingPlace='CITY-CLIENT';d.place='WORK-LOCATION';v.place='OLD-CITY';
 t.barp={...blankBarp(s,t),date:'2026-11-16',place:'WRONG-SAVED-CITY'};
 return {s,p,d,v,t};}

test('every Branch and both units use exact PP signing place in all four letters without changing any snapshot/date',()=>{
 const {s,p,d,v,t}=fixture();
 for(const unit of ['Konstruksi','Operation'])for(const branch of branches){p.branch=branch;p.unit=unit;
 const before=JSON.stringify(s);const dates=[bakhDocumentData(p,d).place,spkDocumentData(p,d,v).placeDate,barpDocumentData(p,d,v,t).placeDate,bappDocumentData(p,d,v,t).placeDate];
 assert.deepEqual(dates,Array(4).fill(`${branch}, 16 November 2026`));assert.equal(JSON.stringify(s),before);}
});
test('missing Branch and invalid dates never fall back to work city, root PP, actor or today',()=>{
 const {s,p,d,v,t}=fixture();p.branch=' ';d.date=v.date=v.start=t.barp!.date='bad';
 assert.equal(projectSigningPlace(p),'');assert.equal(bakhDocumentData(p,d).place,'…, …');assert.equal(spkDocumentData(p,d,v).placeDate,'…, …');assert.equal(barpDocumentData(p,d,v,t).placeDate,'…, …');assert.equal(bappDocumentData(p,d,v,t).placeDate,'…, …');
 const child={...p,id:'child',branch:'KUPANG',rootId:p.id,parentId:p.id};s.projects.push(child);d.projectId=child.id;
 assert.equal(bakhDocumentData(child,d).place,'KUPANG, …');assert.equal(blankBarp(s,t).place,'KUPANG');
});
test('native Word and HTML share Branch/date for historical issued records; saved old place remains unchanged',()=>{
 const {s,p,d,v,t}=fixture();p.branch='DENPASAR';d.status=v.status='Sah';t.status='Disetujui';
 const before=JSON.stringify(s);
 const html=[render(h(BakhDocument,{project:p,deal:d})),render(h(SpkDocument,{project:p,deal:d,spk:v})),render(h(BarpDocument,{project:p,deal:d,spk:v,term:t})),render(h(BappDocument,{project:p,deal:d,spk:v,term:t}))];
 const docs=[createBakhDocx(s,p,d,'bakh'),createSpkDocx(p,d,v,logo),createBarpDocx(p,d,v,t),createBappDocx(p,d,v,t)];
 for(const text of [...html,...docs.map(b=>docxParts(b)['word/document.xml'])])assert.match(text,/DENPASAR, 16 November 2026/);
 assert.equal(JSON.stringify(s),before);
});
test('readonly form places and canonical BARP save ignore forged cities and respond to selected PP Branch',()=>{
 const {s,p,d,v,t}=fixture();p.branch='MATARAM';const input=structuredClone(t);input.barp!.place='FORGED';
 assert.equal(saveBarp(s,t,input,false).barp!.place,'MATARAM');assert.equal(blankBarp(s,t).place,'MATARAM');
 for(const html of [render(h(BakhPlaceField,{project:p})),render(h(SpkIssueFields,{project:p,spk:v,disabled:false,onDateChange:()=>{}}))])assert.match(html,/readOnly="" value="MATARAM"/);
});
test('PP Header keeps its Branch and VIII closing signature table including blank date cells stays byte-identical',()=>{
 const {s,p}=fixture();Object.assign(p,applyPlanTemplate(p));
 const first=render(h(RiskQualitySection,{s,p,printing:true}));p.branch='MATARAM';
 const second=render(h(RiskQualitySection,{s,p,printing:true}));assert.equal(second,first);
 assert.match(render(h(ProjectPlanDocument,{p})),/<th scope="row">Branch<\/th><td>:<\/td><td>MATARAM<\/td>/);
 const signature=second.match(/pp-signatures-table[\s\S]*?<table[\s\S]*?<\/table>/)?.[0];assert.ok(signature);assert.match(signature,/<td><\/td><td class="pp-signature-cell"/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { seed } from '../../resources/js/area3/seed';
import { dashboardMatrix } from '../../resources/js/area3/dashboard-matrix';
import { matrixDetailRows, matrixDetailPage, type MatrixSelection } from '../../resources/js/area3/dashboard-detail';
import { DashboardDetailModal } from '../../resources/js/area3/dashboard-detail-modal';
import { DashboardMatrix } from '../../resources/js/area3/dashboard-matrix-table';
import type { Term } from '../../resources/js/area3/domain';

function fixture(){
  const s=seed('D'),p=s.projects[0],d=s.deals[0];
  p.projectCode='SHARED';p.branch='Surabaya';p.boq=[{...p.boq[0],qty:10,m:100,s:null}];
  d.rows=[{...d.rows[0],rowId:p.boq[0].id,m:true,s:false,qm:10,pm:80}];
  s.branches=[{id:'sby',name:'Surabaya',code:'R501',active:true,regionId:'east',regionName:'Jawa Timur'},{id:'mlg',name:'Malang',code:'R531',active:true,regionId:'east',regionName:'Jawa Timur'}];
  const t:Term={id:'paid',spkId:s.spks[0].id,number:1,type:'Final',status:'Disetujui',actuals:{[p.boq[0].id]:{m:10,s:0}},approvals:[],history:[],paid:800,apm:'APM-001',apmStatus:'Valid',paymentHistory:[]};
  s.terms=[t];return s;
}
const selection=(metric:MatrixSelection['metric']):MatrixSelection=>({metric,scope:{kind:'all'},label:'Semua Branch'});

test('every matrix cell opens contributors matching unique ID counts, PP, BOQ and paid totals',()=>{
  const s=fixture();s.projects.push({...structuredClone(s.projects[0]),id:'second',rootId:'second',projectCode:'OPEN'});
  const data=dashboardMatrix(s),before=JSON.stringify(s);
  for(const metric of ['projects','open','closed','plans'] as const)assert.equal(matrixDetailRows(data,selection(metric)).length,data.totals[metric]);
  assert.equal(matrixDetailRows(data,selection('realized')).reduce((n,r)=>n+r.realized,0),data.totals.realized);
  assert.equal(matrixDetailRows(data,selection('total')).reduce((n,r)=>n+r.total,0),data.totals.total);
  assert.equal(matrixDetailRows(data,selection('realized'))[0].route,'/termin/paid');
  assert.equal(JSON.stringify(s),before);
});

test('Branch and Region drilldown preserve shared-ID global classification and never reveal omitted siblings',()=>{
  const s=fixture();s.projects.push({...structuredClone(s.projects[0]),id:'other',rootId:'other',branch:'Malang'});
  const model=dashboardMatrix(s,'Surabaya');
  const query:MatrixSelection={metric:'open',scope:{kind:'branch',id:'sby'},label:'Surabaya'};
  assert.equal(matrixDetailRows(model,query)[0].status,'OPEN');
  assert.equal(matrixDetailRows(model,query)[0].plans,1);
  assert.equal(matrixDetailRows(model,{...query,scope:{kind:'branch',id:'mlg'}}).length,0);
  const all=dashboardMatrix(s);assert.equal(matrixDetailRows(all,selection('projects')).length,1);
  assert.equal(matrixDetailRows(all,{...selection('plans'),code:'SHARED'}).length,2);
  s.projects.pop();assert.equal(matrixDetailRows(dashboardMatrix(s),selection('closed')).length,1);
});

test('continuation never adds root BOQ twice, missing codes stay in PP and unpaid terms do not enter realization',()=>{
  const s=fixture(),parent=s.projects[0];
  s.projects.push({...structuredClone(parent),id:'child',parentId:parent.id,rootId:parent.id}, {...structuredClone(parent),id:'missing',rootId:'missing',projectCode:'',boq:[]});
  s.terms.push({...s.terms[0],id:'pending',status:'Diajukan'}, {...s.terms[0],id:'unpaid',paid:0});
  const data=dashboardMatrix(s);
  assert.equal(matrixDetailRows(data,selection('plans')).length,3);
  assert.equal(matrixDetailRows(data,selection('projects')).length,1);
  assert.equal(matrixDetailRows(data,selection('realized')).length,1);
  assert.equal(matrixDetailRows(data,selection('total')).reduce((n,r)=>n+r.total,0),1000);
  assert.equal(matrixDetailRows(data,selection('total')).some(r=>r.id==='child'),false);
});

test('1000-row drilldown uses indexed relationships with bounded search pagination and exact routes',()=>{
  const s=fixture();for(let i=1;i<1000;i++)s.projects.push({...structuredClone(s.projects[0]),id:'p'+i,rootId:'p'+i,projectCode:'CODE-'+i,name:'Kegiatan '+i});
  for(const rows of [s.projects,s.spks,s.deals,s.terms,s.khs])Object.defineProperty(rows,'find',{value:()=>{throw new Error('Per-row collection lookup');}});
  const data=dashboardMatrix(s),rows=matrixDetailRows(data,selection('plans'));
  assert.equal(rows.length,1000);assert.equal(matrixDetailPage(rows,'',900).rows.length,20);
  assert.equal(matrixDetailPage(rows,'',900).current,50);
  const found=matrixDetailPage(rows,'CODE-999');assert.equal(found.count,1);assert.equal(found.rows[0].route,'/project-plan/p999');
  const html=renderToStaticMarkup(createElement(DashboardDetailModal,{data,selection:selection('plans'),onClose(){}}));
  assert.equal((html.match(/<tr>/g)??[]).length,21);assert.match(html,/role="dialog"/);assert.match(html,/aria-label="Cari rincian dashboard"/);
});

test('zero cells remain clickable, subtotal scopes are labeled and modal escapes names without writes',()=>{
  const s=fixture();s.projects[0].name='<script>bad</script>';
  const data=dashboardMatrix(s),html=renderToStaticMarkup(createElement(DashboardMatrix,{s}));
  assert.equal((html.match(/class="matrix-value"/g)??[]).length,24); // Region, two Branches, total × six cells.
  assert.match(html,/Lihat Nilai Realisasi · Malang/);
  const detail=renderToStaticMarkup(createElement(DashboardDetailModal,{data,selection:selection('plans'),onClose(){}}));
  assert.match(detail,/&lt;script&gt;bad&lt;\/script&gt;/);assert.doesNotMatch(detail,/<script>|storage_key|accountNumber/);
  assert.equal(matrixDetailPage([]).pages,1);
});

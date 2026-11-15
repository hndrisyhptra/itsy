import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import postcss from 'postcss';
import { readFileSync } from 'node:fs';
import { captureProjectPlan, createProjectPlanDocx, projectPlanWordFilename, PP_DOCX_MIME, type PpWordBlock } from '../../resources/js/area3/pp-docx';
import { DocumentPreview } from '../../resources/js/area3/ui';
import { seed } from '../../resources/js/area3/seed';
import { applyPlanTemplate } from '../../resources/js/area3/plan-template';
import { docxParts } from './helpers/docx-parts';

const blocks:PpWordBlock[]=[
  {kind:'paragraph',heading:2,runs:[{text:'PROJECT PLAN SWAKELOLA KONSTRUKSI'}]},
  {kind:'paragraph',runs:[{text:'PP <QA> & “UTF-8” 😀\nTab\tRp15.286',bold:true}]},
  {kind:'break'},
  {kind:'table',columns:5,labels:['Keterangan','NAMA/NIK','JABATAN','TANGGAL','TANDA TANGAN'],rows:[
    {header:true,cells:['Keterangan','NAMA/NIK','JABATAN','TANGGAL','TANDA TANGAN'].map(text=>({span:1,paragraphs:[{kind:'paragraph',runs:[{text}]}]}))},
    {header:false,cells:[{span:4,paragraphs:[{kind:'paragraph',runs:[{text:'I GEDE WAHYU DANYNDRA\n730371\nEVP Area Jawa Bali\n12 Nov 2026'}]}]},{span:1,paragraphs:[{kind:'paragraph',runs:[],blank:true}]}]},
  ]},
];

test('PP Word is native editable OOXML with correct relationships/styles and blank wet-signature cells',()=>{
  const before=JSON.stringify(blocks),parts=docxParts(createProjectPlanDocx(blocks)),xml=parts['word/document.xml'];
  assert.equal(PP_DOCX_MIME,'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  assert.equal(Object.keys(parts).length,5);assert.match(parts['_rels/.rels'],/Target="word\/document.xml"/);
  assert.match(xml,/<w:pgSz w:w="11906" w:h="16838" w:orient="portrait"/);
  assert.match(xml,/<w:pStyle w:val="Title"/);assert.match(xml,/<w:br w:type="page"/);
  assert.match(xml,/<w:gridSpan w:val="4"/);assert.match(xml,/<w:tblHeader\/>/);
  assert.match(xml,/w:line="1100" w:lineRule="exact"/);assert.match(xml,/EVP Area Jawa Bali/);
  assert.doesNotMatch(Object.values(parts).join(''),/APPROVED|Persetujuan otomatis|altChunk|<html|vbaProject|TargetMode="External"|<w:drawing|<w:pict/);
  assert.equal(JSON.stringify(blocks),before);
  // Optional BOQ compact margins must not change the existing closing table.
  assert.match(xml,/<w:top w:w="55"/);assert.match(xml,/<w:left w:w="65"/);
});

test('PP Word escapes arbitrary text and preserves Unicode, line breaks, numeric presentation and table width',()=>{
  const xml=docxParts(createProjectPlanDocx(blocks))['word/document.xml'];
  assert.match(xml,/&lt;QA&gt; &amp; “UTF-8” 😀/);assert.match(xml,/<w:br\/>/);assert.match(xml,/<w:tab\/>/);assert.match(xml,/Rp15.286/);
  const widths=[...xml.matchAll(/<w:gridCol w:w="(\d+)"/g)].map(m=>Number(m[1]));assert.equal(widths.reduce((a,b)=>a+b,0),10544);
  assert.equal(widths.length,5);assert.ok(widths[1]>widths[0]);
  const amount:PpWordBlock={kind:'table',columns:1,labels:[],rows:[{header:false,cells:[{span:1,align:'right',paragraphs:[{kind:'paragraph',runs:[{text:'0 / 1,25 / 15.286'}]}]}]}]};
  const data=docxParts(createProjectPlanDocx([amount]))['word/document.xml'];assert.match(data,/<w:jc w:val="right"/);assert.match(data,/0 \/ 1,25 \/ 15.286/);
  for(const columns of [9,10]) {
    const boq:PpWordBlock={kind:'table',columns,compactBoq:true,labels:['Designator','Uraian Pekerjaan'],rows:[{header:true,cells:Array.from({length:columns},(_,i)=>({span:1,paragraphs:[{kind:'paragraph',runs:[{text:i===0?'DESIGNATOR-UTUH-123':i===1?'Uraian & pekerjaan lengkap':i===2?'0':'Rp 140.960'}]}]}))}]};
    const compact=docxParts(createProjectPlanDocx([boq]))['word/document.xml'];
    assert.match(compact,/DESIGNATOR-UTUH-123/);assert.match(compact,/Uraian &amp; pekerjaan lengkap/);
    assert.match(compact,/<w:sz w:val="12"/);assert.match(compact,/<w:top w:w="25"/);assert.match(compact,/<w:left w:w="40"/);assert.match(compact,/<w:tblHeader\/>/);
    const grid=[...compact.matchAll(/<w:gridCol w:w="(\d+)"/g)].map(m=>Number(m[1]));
    assert.equal(grid.length,columns);assert.equal(grid.reduce((a,b)=>a+b,0),10544);assert.ok(grid[1]>grid[0]);
  }
});

test('PP download filenames are bounded and unsafe Windows/path characters are removed; empty preview fails clearly',()=>{
  const name=projectPlanWordFilename({no:'0001/LG.000/TA-03R501/11-2026',id:'p'});assert.equal(name,'Project-Plan_0001-LG.000-TA-03R501-11-2026.docx');
  assert.doesNotMatch(projectPlanWordFilename({no:'<>:"/\\|?*\u0000'+'.'.repeat(200),id:'p'}),/[\\/:*?"<>|\u0000-\u001f]/);
  assert.ok(projectPlanWordFilename({no:'a'.repeat(500),id:'p'}).length<150);
  assert.throws(()=>createProjectPlanDocx([]),/Isi PP kosong/);
  assert.throws(()=>captureProjectPlan({dataset:{}} as HTMLElement),/Preview PP belum siap/);
});

test('both current and historical PP previews have confirmation-protected Word and print actions scoped to the displayed document',()=>{
  const s=seed('B'),before=JSON.stringify(s);
  for(const project of [s.projects[0],applyPlanTemplate(s.projects[0])]) {
    const html=renderToStaticMarkup(createElement(DocumentPreview,{state:s,kind:'Project Plan',project,onClose:()=>{}}));
    assert.match(html,/Unduh Project Plan sebagai Word DOCX/);assert.match(html,/data-confirm="Unduh Project Plan lengkap sebagai Word sesuai preview ini\?"/);
    assert.match(html,/Cetak \/ Simpan PDF browser/);assert.match(html,/data-project-plan-export="true"/);
  }
  assert.equal(JSON.stringify(s),before);
});

test('print CSS preserves nested document ancestor paths, releases modal clipping, and scopes A4 portrait to PP',()=>{
  const css=postcss.parse(readFileSync(new URL('../../resources/js/area3/styles.css',import.meta.url),'utf8'));
  let safeHide=false,pathVisible=false,bodyOverflow=false,ppPage=false;
  css.walkAtRules('page',rule=>{if(rule.params==='pp-document-portrait')rule.walkDecls('size',d=>{ppPage=d.value==='A4 portrait';});});
  css.walkAtRules('media',media=>{if(media.params==='print')media.walkRules(rule=>{
    if(rule.selector.includes('.app>*:not(.modal-backdrop):not(:has(.modal-backdrop .document))'))safeHide=true;
    if(rule.selector.includes('.app :has(.modal-backdrop .document)'))rule.walkDecls('display',d=>{if(d.value==='block'&&d.important)pathVisible=true;});
    if(rule.selector==='html,body')rule.walkDecls('overflow',d=>{bodyOverflow=d.value==='visible'&&d.important;});
  });});
  assert.ok(safeHide&&pathVisible&&bodyOverflow&&ppPage);
});

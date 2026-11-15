import { ooxmlZip } from './ooxml-zip';
import { xmlHeader, wns, relns, officeRel, paragraph, table, tableRow, type WordRun } from './word-ooxml';

export const PP_DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
export interface PpWordParagraph { kind:'paragraph'; runs:WordRun[]; heading?:number; blank?:boolean }
export interface PpWordCell { paragraphs:PpWordParagraph[]; span:number; align?:'left'|'center'|'right' }
export type PpWordBlock = PpWordParagraph | { kind:'break' } | { kind:'table'; rows:{cells:PpWordCell[];header:boolean}[]; columns:number; labels:string[]; keepTogether?:boolean; compactBoq?:boolean };

const omitted='.preview-toolbar,.pp-signature-screen,.pp-approval-stamp,button,input,select,textarea,script,style';
const blockTags=new Set(['ARTICLE','DIV','SECTION','P','H1','H2','H3','H4','H5','H6','OL','UL','LI','TABLE','DL','FOOTER']);

function inlineRuns(node:Node, format:Omit<WordRun,'text'>={}):WordRun[] {
  if(node.nodeType===3)return [{text:node.textContent??'',...format}];
  if(node.nodeType!==1)return [];
  const e=node as Element;
  if(e.matches(omitted)||e.classList.contains('pp-signature-print'))return [];
  if(e.tagName==='BR')return [{text:'\n',...format}];
  const next={...format,bold:format.bold||['B','STRONG'].includes(e.tagName),italic:format.italic||['EM','I'].includes(e.tagName)};
  const runs=Array.from(e.childNodes).flatMap(child=>inlineRuns(child,next));
  return e.tagName==='SMALL'?[{text:'\n'},...runs]:runs;
}

/** Capture only this authorized, already rendered PP. No HTML embedding, URL fetch or state mutation. */
export function captureProjectPlan(root:HTMLElement):PpWordBlock[] {
  if(root.dataset.projectPlanExport!=='true'||!root.querySelector('article.document'))throw new Error('Preview PP belum siap. Buka kembali preview sebelum mengunduh.');
  const result:PpWordBlock[]=[];
  const add=(runs:WordRun[],heading?:number)=>{if(runs.some(r=>r.text.trim()))result.push({kind:'paragraph',runs,heading});};
  const cell=(e:Element):PpWordCell=>({span:Math.max(1,Math.min(20,Number(e.getAttribute('colspan')??1))),align:e.classList.contains('numeric')?'right':e.tagName==='TH'?'center':'left',paragraphs:e.querySelector('.pp-signature-print')?[{kind:'paragraph',runs:[],blank:true}]:[{kind:'paragraph',runs:inlineRuns(e)}]});
  const walk=(e:Element,prefix='')=>{
    if(e.matches(omitted))return;
    if(e.tagName==='TABLE') {
      const rows=Array.from((e as HTMLTableElement).rows).map(row=>({header:row.parentElement?.tagName==='THEAD',cells:Array.from(row.cells).map(c=>{
        const value=cell(c);
        if(c.querySelector('.pp-signature-print'))value.paragraphs=[{kind:'paragraph',runs:[],blank:true}];
        return value;
      })}));
      if(rows.length)result.push({kind:'table',rows,columns:Math.max(...rows.map(r=>r.cells.reduce((n,c)=>n+c.span,0))),labels:Array.from((e as HTMLTableElement).rows[0]?.cells??[]).map(c=>c.textContent??''),keepTogether:!!e.closest('.pp-signatures-table'),compactBoq:!!e.closest('.budget-boq-table')});
      return;
    }
    if(e.tagName==='DL') {
      const pairs=Array.from(e.querySelectorAll('dt')).map(dt=>({header:false,cells:[cell(dt),cell(dt.nextElementSibling??dt)]}));
      if(pairs.length)result.push({kind:'table',columns:2,labels:[],rows:pairs});
      return;
    }
    if(e.tagName==='OL'||e.tagName==='UL') {
      Array.from(e.children).forEach((li,i)=>walk(li,e.tagName==='UL'?'• ':e.getAttribute('type')==='a'?`${String.fromCharCode(97+i)}. `:`${i+1}. `));
      return;
    }
    if(e.tagName==='ARTICLE'&&e.classList.contains('pp-appendix'))result.push({kind:'break'});
    let pending:WordRun[]=prefix?[{text:prefix}]:[];
    const flush=()=>{add(pending,/^H[1-6]$/.test(e.tagName)?Number(e.tagName[1]):undefined);pending=[];};
    for(const child of Array.from(e.childNodes)) {
      if(child.nodeType===1&&blockTags.has((child as Element).tagName)) {flush();walk(child as Element);}
      else {pending.push(...inlineRuns(child));if(child.nodeType===1&&(child as Element).tagName==='SPAN')pending.push({text:'  '});}
    }
    flush();
  };
  Array.from(root.children).forEach(e=>walk(e));
  if(!result.some(b=>b.kind==='paragraph'&&b.runs.some(r=>r.text.trim())))throw new Error('Isi PP kosong. Muat ulang preview sebelum mengunduh.');
  return result;
}

function widths(block:Extract<PpWordBlock,{kind:'table'}>) {
  // Description and identity need more room than short numeric columns, while the grid stays A4 portrait.
  const total=10544,head=block.labels;
  const weights=block.compactBoq&&block.columns===10?[9,18,6,9,9,6,10,10,12,11]:block.compactBoq&&block.columns===9?[10,26,6,9,9,6,11,11,12]:block.columns===10?[8,22,6,9,9,6,10,10,10,10]:block.columns===9?[9,25,6,10,10,7,11,11,11]:head.includes('NAMA/NIK')?[14,25,26,13,22]:head[0]==='Unit Kerja'&&block.columns===3?[27,3,70]:head[0]==='Aktivitas'&&block.columns===2?[82,18]:head.includes('Stakeholder')?[5,18,18,24,22,13]:head.includes('Kebutuhan Anggaran')&&block.columns===4?[5,38,25,32]:head.includes('Uraian')&&block.columns===4?[8,42,30,20]:head.includes('Uraian')&&block.columns===5?[5,45,10,10,30]:head.includes('Material')&&block.columns===6?[5,40,10,10,17,18]:block.columns===2?[35,65]:Array(block.columns).fill(1);
  const sum=weights.reduce((n,v)=>n+v,0),values=weights.map(v=>Math.floor(total*v/sum));values[values.length-1]+=total-values.reduce((n,v)=>n+v,0);
  return values;
}

function wordParagraph(p:PpWordParagraph,cellSize?:number,align?:'left'|'center'|'right',keep=false) {
  return paragraph(p.runs,{style:p.heading===2?'Title':p.heading?'Heading1':undefined,size:cellSize??(p.heading===2?26:p.heading?20:18),align:align??(p.heading===2?'center':'left'),before:p.heading?160:0,after:cellSize?0:100,keep:keep||!!p.heading,blankHeight:p.blank?1100:undefined});
}

/** Native OOXML from captured presentation blocks; every table and paragraph remains editable. */
export function createProjectPlanDocx(blocks:PpWordBlock[]) {
  const body=blocks.map(b=>b.kind==='break'?'<w:p><w:r><w:br w:type="page"/></w:r></w:p>':b.kind==='paragraph'?wordParagraph(b):table(b.rows.map((row,i)=>tableRow(row.cells.map(c=>({span:c.span,paragraphs:c.paragraphs.map(p=>wordParagraph({...p,runs:p.runs.map(r=>({...r,bold:r.bold||row.header}))},b.compactBoq?12:b.columns>=9?13:16,c.align,!!b.keepTogether&&i<b.rows.length-1)).join('')})),widths(b),row.header)),widths(b),true,b.compactBoq?{vertical:25,horizontal:40}:undefined)).join('');
  if(!body)throw new Error('Isi PP kosong. Buka kembali preview sebelum mengunduh.');
  return ooxmlZip({
    '[Content_Types].xml':xmlHeader+'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
    '_rels/.rels':xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="rId1" Type="${officeRel}/officeDocument" Target="word/document.xml"/></Relationships>`,
    'word/document.xml':xmlHeader+`<w:document xmlns:w="${wns}"><w:body>${body}<w:sectPr><w:pgSz w:w="11906" w:h="16838" w:orient="portrait"/><w:pgMar w:top="680" w:right="681" w:bottom="680" w:left="681" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr></w:body></w:document>`,
    'word/_rels/document.xml.rels':xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="rId1" Type="${officeRel}/styles" Target="styles.xml"/></Relationships>`,
    'word/styles.xml':xmlHeader+`<w:styles xmlns:w="${wns}"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="18"/><w:color w:val="000000"/><w:lang w:val="id-ID"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:color w:val="000000"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:pPr><w:keepNext/></w:pPr><w:rPr><w:b/><w:color w:val="000000"/></w:rPr></w:style></w:styles>`,
  });
}

export function projectPlanWordFilename(project:{no:string;id:string}) {
  const name=(project.no||project.id||'belum-bernomor').replace(/[\\/:*?"<>|\u0000-\u001f]/g,'-').replace(/[. ]+$/g,'').slice(0,120);
  return `Project-Plan_${name||'dokumen'}.docx`;
}

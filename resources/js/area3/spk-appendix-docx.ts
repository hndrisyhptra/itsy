import type { State, Project, Deal, Spk } from './domain';
import { spkAppendixData } from './spk-appendix-content';
import { ooxmlZip } from './ooxml-zip';
import { xmlHeader, wns, relns, officeRel, paragraph, tableRow, table } from './word-ooxml';

export const SPK_APPENDIX_DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
export function spkAppendixWordFilename(spk: Spk) {
  const name = (spk.no || spk.id || 'belum-bernomor').replace(/[\\/:*?"<>|\u0000-\u001f]/g, '-').replace(/[. ]+$/g, '').slice(0, 120);
  return `Lampiran-SPK_${name || 'dokumen'}.docx`;
}

/** Native editable table from the same read-only model as the form/preview. */
export function createSpkAppendixDocx(state: State, project: Project, deal: Deal, spk: Spk) {
  const data = spkAppendixData(state, project, deal), pageWidth = 15704;
  const widths = [3,10,23,6,7,7,12,6,8,8,10].map(p => Math.floor(pageWidth*p/100));
  widths[10] += pageWidth - widths.reduce((a,b) => a+b,0);
  const left = ['NO','DESIGNATOR','URAIAN','SATUAN'], right = ['TOTAL VOL','MATERIAL','JASA','JUMLAH'];
  const rows = [
    tableRow([...left.map(text => ({text,merge:'restart' as const})), {text:'HARGA SATUAN',span:2}, {text:project.name || '…'}, ...right.map(text => ({text,merge:'restart' as const}))], widths, true, 16),
    tableRow([...left.map(() => ({merge:'continue' as const})), {text:'Material'}, {text:'Jasa'}, {text:'VOL'}, ...right.map(() => ({merge:'continue' as const}))], widths, true, 16),
    ...data.rows.map(row => tableRow(row.cells.map((text,i) => ({text: text+(i===6&&row.component?'\n'+row.component:''),align:i>=4?'right' as const:i===1||i===2?'left' as const:'center' as const})),widths,false,16)),
  ];
  if (!data.rows.length) rows.push(tableRow([{text:'Belum ada komponen pekerjaan yang dipilih.',span:11}],widths,false,16));
  for (const [label,value,col] of [['JUMLAH MATERIAL',data.materialText,8],['JUMLAH JASA',data.serviceText,9],['JUMLAH HARGA',data.totalText,10]] as const) {
    rows.push(tableRow([{text:label,span:4,bold:true,align:'left',keep:true},...Array.from({length:7},(_,j)=>({text:j+4===6||j+4===7||j+4===col?value:'',bold:true,align:'right' as const,keep:true}))],widths,false,16));
  }
  const body = paragraph([{text:'LAMPIRAN SURAT PERINTAH KERJA',bold:true}],{style:'Title',size:20,keep:true,after:40}) +
    paragraph(`PEKERJAAN: ${project.name || '…'}`,{size:18,keep:true,after:20}) +
    paragraph(`WITEL: ${deal.witel || '…'}`,{size:18,keep:true,after:130}) +
    paragraph('Harga satuan dan nilai dalam Rupiah (Rp)',{size:16,align:'right',keep:true,after:50}) + table(rows,widths,true) +
    (data.splitVolumes?paragraph('Komponen Material dan Jasa dengan volume berbeda ditampilkan pada baris terpisah.',{size:16,before:80,after:80,keep:true}):'') +
    paragraph('PT TELKOM AKSES',{size:20,before:300,after:0,keep:true}) + paragraph('',{blankHeight:1200,after:0,keep:true}) +
    paragraph([{text:spk.signer || '…',bold:true,underline:true}],{size:20,after:0,keep:true}) +
    paragraph([{text:spk.position?.toLocaleUpperCase('id-ID') || '…',bold:true}],{size:18,after:0,keep:true}) +
    paragraph(`NIK: ${spk.signerNik || 'Belum tersedia'}`,{size:18,after:0});
  return ooxmlZip({
    '[Content_Types].xml':xmlHeader+'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
    '_rels/.rels':xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="rId1" Type="${officeRel}/officeDocument" Target="word/document.xml"/></Relationships>`,
    'word/_rels/document.xml.rels':xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="rId1" Type="${officeRel}/styles" Target="styles.xml"/></Relationships>`,
    'word/document.xml':xmlHeader+`<w:document xmlns:w="${wns}"><w:body>${body}<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr></w:body></w:document>`,
    'word/styles.xml':xmlHeader+`<w:styles xmlns:w="${wns}"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="16"/><w:color w:val="000000"/><w:lang w:val="id-ID"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:color w:val="000000"/></w:rPr></w:style></w:styles>`,
  });
}

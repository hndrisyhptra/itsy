import type { State,Project,Deal,Spk,Term } from './domain';
import { barpAppendixData,BARP_APPENDIX_WIDTHS,BARP_APPENDIX_LEFT,BARP_APPENDIX_RIGHT } from './barp-appendix-content';
import { canExportBarp } from './barp-content';
import { barpWordFilename,BARP_DOCX_MIME } from './barp-file';
import { ooxmlZip } from './ooxml-zip';
import { xmlHeader,wns,relns,officeRel,paragraph,tableRow,table } from './word-ooxml';
export { BARP_DOCX_MIME };
export function barpAppendixWordFilename(spk:Spk,term:Term){return 'Lampiran-'+barpWordFilename(spk,term);}
export function createBarpAppendixDocx(state:State,project:Project,deal:Deal,spk:Spk,term:Term){
  if(!canExportBarp(term))throw new Error('Word lampiran tersedia setelah pengajuan dikirim.');
  const data=barpAppendixData(state,project,deal,spk,term),pageWidth=15704;
  const widths=BARP_APPENDIX_WIDTHS.map(p=>Math.floor(pageWidth*p/100));widths[12]+=pageWidth-widths.reduce((a,b)=>a+b,0);
  const rows=[
    tableRow([...BARP_APPENDIX_LEFT.map(text=>({text,merge:'restart' as const})),{text:'VOLUME AKTUAL PER TERMIN',span:3},...BARP_APPENDIX_RIGHT.map(text=>({text,merge:'restart' as const}))],widths,true,14),
    tableRow([...BARP_APPENDIX_LEFT.map(()=>({merge:'continue' as const})),...[1,2,3].map(n=>({text:`Termin #${n}${n===term.number?' · Saat ini':''}`})),...BARP_APPENDIX_RIGHT.map(()=>({merge:'continue' as const}))],widths,true,14),
    ...data.rows.map(r=>tableRow(r.cells.map((text,i)=>({text,align:i>=4?'right' as const:i===1||i===2?'left' as const:'center' as const})),widths,false,14)),
  ];
  if(!data.rows.length)rows.push(tableRow([{text:'Belum ada komponen pekerjaan.',span:13}],widths,false,14));
  for(const row of data.recap)rows.push(tableRow([{text:row.label,span:12,bold:true,align:'left',keep:true},{text:row.value,bold:true,align:'right',keep:true}],widths,false,14));
  const signWidths=[3140,3140,3140,3140,3144];
  const signatures=table([
    tableRow(data.signatures.map(s=>({text:s.job,bold:true,keep:true})),signWidths,false,15),
    tableRow(data.signatures.map(()=>({paragraphs:paragraph('',{blankHeight:1000,after:0,keep:true})})),signWidths,false,15),
    tableRow(data.signatures.map(s=>({text:s.name,bold:true,keep:true})),signWidths,false,15),
    tableRow(data.signatures.map(s=>({text:s.nik?`NIK: ${s.nik}`:''})),signWidths,false,14),
  ],signWidths,false);
  const grid=table(rows,widths,true).replace('<w:top w:w="55"','<w:top w:w="15"').replace('<w:bottom w:w="55"','<w:bottom w:w="15"');
  const body=paragraph([{text:data.title,bold:true}],{style:'Title',size:18,keep:true,after:40})+
    paragraph(`PEKERJAAN: ${data.job} · Termin #${data.stage}`,{size:16,keep:true,after:20})+
    paragraph(`WITEL: ${data.witel}`,{size:16,keep:true,after:100})+
    (data.status?paragraph([{text:data.status,bold:true}],{size:16,keep:true,after:80}):'')+
    grid+paragraph('Harga dan nilai dalam Rupiah (Rp). Material dan Jasa dicatat terpisah. Sisa proyeksi belum merupakan pelepasan volume. Tagihan hanya tahap ini; nilai kumulatif adalah informasi.',{size:14,before:80,after:80,keep:true})+signatures;
  return ooxmlZip({
    '[Content_Types].xml':xmlHeader+'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
    '_rels/.rels':xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="rId1" Type="${officeRel}/officeDocument" Target="word/document.xml"/></Relationships>`,
    'word/_rels/document.xml.rels':xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="rId1" Type="${officeRel}/styles" Target="styles.xml"/></Relationships>`,
    'word/document.xml':xmlHeader+`<w:document xmlns:w="${wns}"><w:body>${body}<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567" w:header="0" w:footer="0" w:gutter="0"/></w:sectPr></w:body></w:document>`,
    'word/styles.xml':xmlHeader+`<w:styles xmlns:w="${wns}"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="14"/><w:color w:val="000000"/><w:lang w:val="id-ID"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:color w:val="000000"/></w:rPr></w:style></w:styles>`,
  });
}

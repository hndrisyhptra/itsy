import type { Term } from './domain';
import { money } from './currency';
import { kasbonTerbilang, kasbonSignatures, kasbonDate, type KasbonData } from './kasbon-content';
import { xmlHeader, wns, relns, officeRel, paragraph, table, tableRow } from './word-ooxml';
import { ooxmlZip } from './ooxml-zip';

export function kasbonWordFilename(d:KasbonData){return `Kasbon_${d.ppNo}_Termin-${d.termNumber}`.replace(/[\\/:*?"<>|\u0000-\u001f]/g,'-').slice(0,150)+'.docx';}
/** Same saved fields as preview; approval marks and all signature images are intentionally absent. */
export function createKasbonDocx(d:KasbonData,logo:Uint8Array,term?:Term,kasbonId?:string){
  if(logo.length<8||[137,80,78,71,13,10,26,10].some((b,i)=>logo[i]!==b))throw new Error('Logo kasbon belum dapat dimuat.');
  const widths=[2200,1300,1900,3000,1800],meta=[1900,8300];
  let body=paragraph('Pengajuan Kasbon Project',{style:'Title',align:'center',size:26,after:220});
  body+=table([['Unit',d.unit],['Total Nilai',money(d.amount)],['Cost Center',d.costCenter],['WBS',d.wbs],['Jenis Kegiatan',d.activity]].map(([label,value])=>tableRow([{text:label,align:'left',bold:true},{text:value,align:'left'}],meta,false,18)),meta,false);
  body+=paragraph(`${d.projectCode} · ${d.project}\n${d.ppNo}\n${d.spkNo} · Termin ${d.termNumber} (${d.termType})`,{size:18,before:120,after:140});
  body+=table([
    tableRow(['Cost Center / WBS','No Akun','Nama Akun','Teks','Amount (Rp)'].map(text=>({text})),widths,true,16),
    ...d.rows.map(r=>tableRow([`${d.costCenter} / ${d.wbs}`,r.accountCode,r.accountName,r.text,money(Number(r.amount))].map((text,i)=>({text,align:i===4?'right' as const:'left' as const})),widths,false,16)),
    tableRow([{text:'Grand Total',span:4,bold:true,align:'right'},{text:money(d.amount),bold:true,align:'right'}],widths,false,16),
  ],widths,true);
  body+=paragraph([{text:'Pengajuan sebesar: '},{text:kasbonTerbilang(d.amount),italic:true}],{size:18,before:120,after:140});
  body+=paragraph(kasbonDate(d),{size:18,align:'right',after:120});
  body+=paragraph('Tujuan Transfer',{size:18,keep:true,after:60});
  body+=table([['Nama Penerima',d.payee.accountName],['No Rekening',d.payee.accountNumber],['Nama Bank',d.payee.bank]].map(([label,value])=>tableRow([{text:label,align:'left'},{text:value,align:'left'}],meta,false,18)),meta,false);
  const groups=kasbonSignatures(d,term,kasbonId),signatureWidths=d.templateVersion===3?[3400,3400,3400]:[2550,2550,2550,2550];
  for(let i=0;i<groups.length;i++){
    const cells=groups[i].map((r,j)=>({span:d.templateVersion!==3&&i===1&&j===0?2:1,paragraphs:paragraph(r.action,{align:'center',size:16,keep:true,before:100,after:0})+paragraph('',{blankHeight:800,keep:true,after:0})+paragraph([{text:r.name,bold:true}],{align:'center',size:16,keep:true,after:0})+paragraph(r.job,{align:'center',size:16,after:80})}));
    body+=table([tableRow(cells,signatureWidths,false,16)],signatureWidths,false);
  }
  const contentTypes=xmlHeader+'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/></Types>';
  return ooxmlZip({
    '[Content_Types].xml':contentTypes,
    '_rels/.rels':xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="rId1" Type="${officeRel}/officeDocument" Target="word/document.xml"/></Relationships>`,
    'word/document.xml':xmlHeader+`<w:document xmlns:w="${wns}" xmlns:r="${officeRel}"><w:body>${body}<w:sectPr><w:headerReference w:type="default" r:id="rIdHeader"/><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1250" w:right="853" w:bottom="680" w:left="853" w:header="300" w:footer="300"/></w:sectPr></w:body></w:document>`,
    'word/_rels/document.xml.rels':xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="rIdStyles" Type="${officeRel}/styles" Target="styles.xml"/><Relationship Id="rIdHeader" Type="${officeRel}/header" Target="header1.xml"/></Relationships>`,
    'word/styles.xml':xmlHeader+`<w:styles xmlns:w="${wns}"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="18"/><w:color w:val="000000"/></w:rPr></w:rPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:rPr><w:b/><w:color w:val="000000"/></w:rPr></w:style></w:styles>`,
    'word/header1.xml':xmlHeader+`<w:hdr xmlns:w="${wns}" xmlns:r="${officeRel}" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:drawing><wp:inline><wp:extent cx="1500000" cy="650000"/><wp:docPr id="1" name="Telkom Akses"/><a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="0" name="Logo"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rIdLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="1500000" cy="650000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p></w:hdr>`,
    'word/_rels/header1.xml.rels':xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="rIdLogo" Type="${officeRel}/image" Target="media/logo.png"/></Relationships>`,
    'word/media/logo.png':logo,
  });
}

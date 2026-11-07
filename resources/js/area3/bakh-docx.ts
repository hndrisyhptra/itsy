import { xmlHeader, wns, relns, officeRel, paragraph, tableRow, table } from './word-ooxml';
import type { State, Project, Deal } from './domain';
import { bakhDocumentData, bakhAppendixRows, bakhSignatures, BAKH_TEXT } from './bakh-content';
import { ooxmlZip } from './ooxml-zip';

export const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
export type BakhWordKind = 'bakh' | 'appendix';
function signatures(deal: Deal, widths: number[], appendix: boolean) {
  const size = appendix ? 18 : 22;
  return table([tableRow(bakhSignatures(deal, appendix).map(signer => ({ valign: 'top' as const, paragraphs:
    paragraph([{ text: signer.title, bold: true }], { align: 'center', size, after: 0 }) +
    paragraph('', { after: 0, blankHeight: 1300 }) +
    paragraph([{ text: signer.name, bold: true, underline: true }], { align: 'center', size, after: 0 }) +
    paragraph([{ text: signer.job, bold: true }], { align: 'center', size: appendix ? 16 : 20, after: 0 }) +
    (signer.nik ? paragraph(signer.nik, { align: 'center', size: appendix ? 16 : 18, after: 0 }) : ''),
  })), widths)], widths, false);
}

function mainDocument(project: Project, deal: Deal) {
  const data = bakhDocumentData(project, deal);
  return paragraph([{ text: BAKH_TEXT.title, bold: true }], { align: 'center', font: 'Arial', size: 28, keep: true, after: 0 }) +
    paragraph([{ text: BAKH_TEXT.subtitle, bold: true, underline: true }], { align: 'center', font: 'Arial', size: 28, keep: true, after: 30 }) +
    paragraph([{ text: `NOMOR : ${data.number}`, bold: true }], { align: 'center', after: 320, keep: true }) +
    data.metadata.map(([label, value], i) => paragraph([{ text: `${label}\t: ${value}`, bold: true }], { left: 1850, hanging: 1850, tabs: [1850], after: i === 2 ? 280 : 0, keep: i < 2 })).join('') +
    paragraph(data.opening, { align: 'both', after: 260 }) +
    paragraph(BAKH_TEXT.result, { numbered: true, keep: true, after: 50 }) +
    ['Harga Penawaran', 'Harga Kesepakatan'].map(label => paragraph([{ text: `${label}\t: ${BAKH_TEXT.price} ` }, { text: BAKH_TEXT.tax, italic: true }], { left: 2800, hanging: 2300, tabs: [2640], align: 'both', after: 210 })).join('') +
    paragraph(BAKH_TEXT.payment, { numbered: true, align: 'both', after: 80 }) +
    paragraph(data.team, { numbered: true, align: 'both', after: 260 }) +
    paragraph(BAKH_TEXT.closing, { align: 'both', after: 260 }) +
    paragraph(data.place, { align: 'right', keep: true, after: 300 }) +
    signatures(deal, [4713, 4714], false);
}

function appendixDocument(state: State, project: Project, deal: Deal) {
  const width = 15704, widths = [4, 14, 27, 7, 8, 8, 8, 8, 8, 8].map(percent => Math.floor(width * percent / 100));
  widths[9] += width - widths.reduce((a, b) => a + b, 0);
  const labels = ['NO', 'DESIGNATOR', 'URAIAN', 'SATUAN'];
  const rows = [
    tableRow([...labels.map(text => ({ text, merge: 'restart' as const })), { text: 'NEGOSIASI', span: 4 }, { text: 'KESEPAKATAN', span: 2, merge: 'restart' }], widths, true),
    tableRow([...labels.map(() => ({ merge: 'continue' as const })), { text: 'MANDOR', span: 2 }, { text: 'TELKOM AKSES', span: 2 }, { span: 2, merge: 'continue' }], widths, true),
    tableRow([...labels.map(() => ({ merge: 'continue' as const })), ...Array.from({ length: 6 }, (_, i) => ({ text: i % 2 ? 'Jasa' : 'Material' }))], widths, true),
  ];
  const data = bakhAppendixRows(state, project, deal);
  rows.push(...data.map(row => tableRow(row.cells.map((text, i) => ({ text, align: i >= 4 ? 'right' as const : i === 1 || i === 2 ? 'left' as const : 'center' as const })), widths)));
  if (!data.length) rows.push(tableRow([{ text: 'Belum ada komponen pekerjaan yang dipilih.', span: 10 }], widths));
  return paragraph([{ text: BAKH_TEXT.appendixTitle, bold: true }], { size: 16, keep: true, after: 0 }) +
    paragraph(`PEKERJAAN: ${project.name}`, { size: 16, keep: true, after: 80 }) +
    paragraph('Harga satuan (Rp)', { size: 16, align: 'right', after: 50, keep: true }) +
    table(rows, widths, true) + paragraph('', { after: 150 }) + signatures(deal, [7852, 7852], true);
}

export function bakhWordFilename(kind: BakhWordKind, deal: Deal) {
  const source = (deal.no || deal.id || 'belum-bernomor').replace(/[\\/:*?"<>|\u0000-\u001f]/g, '-').replace(/[. ]+$/g, '').slice(0, 120);
  return `${kind === 'bakh' ? 'BA-KH' : 'Lampiran-BA-KH'}_${source || 'dokumen'}.docx`;
}

/** Generate editable native paragraphs/tables from the same authorized preview props; no state writes or extra reads. */
export function createBakhDocx(state: State, project: Project, deal: Deal, kind: BakhWordKind) {
  const appendix = kind === 'appendix';
  const body = appendix ? appendixDocument(state, project, deal) : mainDocument(project, deal);
  const page = appendix ? '<w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/><w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567" w:header="0" w:footer="0" w:gutter="0"/>' : '<w:pgSz w:w="11906" w:h="16838" w:orient="portrait"/><w:pgMar w:top="1440" w:right="852" w:bottom="1282" w:left="1627" w:header="0" w:footer="0" w:gutter="0"/>';
  const font = appendix ? 'Arial' : 'Times New Roman', size = appendix ? 14 : 22;
  return ooxmlZip({
    '[Content_Types].xml': xmlHeader + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/></Types>',
    '_rels/.rels': xmlHeader + `<Relationships xmlns="${relns}"><Relationship Id="rId1" Type="${officeRel}/officeDocument" Target="word/document.xml"/></Relationships>`,
    'word/document.xml': xmlHeader + `<w:document xmlns:w="${wns}"><w:body>${body}<w:sectPr>${page}</w:sectPr></w:body></w:document>`,
    'word/_rels/document.xml.rels': xmlHeader + `<Relationships xmlns="${relns}"><Relationship Id="rId1" Type="${officeRel}/styles" Target="styles.xml"/><Relationship Id="rId2" Type="${officeRel}/numbering" Target="numbering.xml"/></Relationships>`,
    'word/styles.xml': xmlHeader + `<w:styles xmlns:w="${wns}"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="${font}" w:hAnsi="${font}"/><w:sz w:val="${size}"/><w:szCs w:val="${size}"/><w:color w:val="000000"/><w:lang w:val="id-ID"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="100" w:line="240" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>`,
    'word/numbering.xml': xmlHeader + `<w:numbering xmlns:w="${wns}"><w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="singleLevel"/><w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/><w:lvlJc w:val="left"/><w:pPr><w:tabs><w:tab w:val="num" w:pos="500"/></w:tabs><w:ind w:left="500" w:hanging="500"/></w:pPr></w:lvl></w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num></w:numbering>`,
  });
}

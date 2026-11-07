export interface WordRun { text: string; bold?: boolean; italic?: boolean; underline?: boolean }

export const xmlHeader = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
export const wns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
export const relns = 'http://schemas.openxmlformats.org/package/2006/relationships';
export const officeRel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

function xml(value: string) {
  return value.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F\uD800-\uDFFF\uFFFE\uFFFF]/gu, '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
}

function run(value: WordRun, size?: number, font?: string) {
  const format = (value.bold ? '<w:b/>' : '') + (value.italic ? '<w:i/>' : '') + (value.underline ? '<w:u w:val="single"/>' : '') + (size ? `<w:sz w:val="${size}"/><w:szCs w:val="${size}"/>` : '') + (font ? `<w:rFonts w:ascii="${font}" w:hAnsi="${font}"/>` : '');
  const text = value.text.replace(/\r\n?/g, '\n').split(/([\n\t])/).map(part => part === '\n' ? '<w:br/>' : part === '\t' ? '<w:tab/>' : `<w:t xml:space="preserve">${xml(part)}</w:t>`).join('');
  return `<w:r>${format ? `<w:rPr>${format}</w:rPr>` : ''}${text}</w:r>`;
}

interface ParagraphOptions { style?: string; align?: 'left' | 'center' | 'right' | 'both'; size?: number; font?: string; after?: number; before?: number; keep?: boolean; numbered?: boolean; left?: number; hanging?: number; blankHeight?: number; tabs?: number[] }
export function paragraph(content: string | WordRun[], options: ParagraphOptions = {}) {
  const props = (options.style ? `<w:pStyle w:val="${xml(options.style)}"/>` : '') + `<w:jc w:val="${options.align ?? 'left'}"/><w:spacing w:before="${options.before ?? 0}" w:after="${options.after ?? 100}" w:line="${options.blankHeight ?? 240}" w:lineRule="${options.blankHeight ? 'exact' : 'auto'}"/>` +
    (options.keep ? '<w:keepNext/>' : '') + (options.numbered ? '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>' : '') +
    (options.left ? `<w:ind w:left="${options.left}"${options.hanging ? ` w:hanging="${options.hanging}"` : ''}/>` : '') + (options.tabs ? `<w:tabs>${options.tabs.map(pos => `<w:tab w:val="left" w:pos="${pos}"/>`).join('')}</w:tabs>` : '');
  return `<w:p><w:pPr>${props}</w:pPr>${(typeof content === 'string' ? [{ text: content }] : content).map(value => run(value, options.size, options.font)).join('')}</w:p>`;
}

interface Cell { keep?: boolean; text?: string; paragraphs?: string; span?: number; merge?: 'restart' | 'continue'; align?: 'left' | 'center' | 'right'; bold?: boolean; valign?: 'top' | 'center' }
export function tableRow(cells: Cell[], widths: number[], header = false, fontSize = 14) {
  let column = 0;
  return `<w:tr><w:trPr>${header ? '<w:tblHeader/>' : ''}<w:cantSplit/></w:trPr>${cells.map(cell => {
    const span = cell.span ?? 1, width = widths.slice(column, column + span).reduce((a, b) => a + b, 0); column += span;
    const props = `<w:tcW w:w="${width}" w:type="dxa"/>` + (span > 1 ? `<w:gridSpan w:val="${span}"/>` : '') + (cell.merge ? `<w:vMerge w:val="${cell.merge}"/>` : '') + `<w:vAlign w:val="${cell.valign ?? 'center'}"/>`;
    return `<w:tc><w:tcPr>${props}</w:tcPr>${cell.paragraphs ?? paragraph([{ text: cell.text ?? '', bold: cell.bold ?? header }], { align: cell.align ?? 'center', size: fontSize, after: 0, keep: cell.keep })}</w:tc>`;
  }).join('')}</w:tr>`;
}

export function table(rows: string[], widths: number[], borders: boolean) {
  const width = widths.reduce((a, b) => a + b, 0);
  const border = ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'].map(side => `<w:${side} w:val="${borders ? 'single' : 'nil'}" w:sz="4" w:color="444444"/>`).join('');
  return `<w:tbl><w:tblPr><w:tblW w:w="${width}" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblBorders>${border}</w:tblBorders><w:tblCellMar><w:top w:w="55" w:type="dxa"/><w:left w:w="65" w:type="dxa"/><w:bottom w:w="55" w:type="dxa"/><w:right w:w="65" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>${widths.map(w => `<w:gridCol w:w="${w}"/>`).join('')}</w:tblGrid>${rows.join('')}</w:tbl>`;
}


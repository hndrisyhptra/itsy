"""Prepare the browser template from the retained reference using the bundled QA Python.

Runtime needs only the generated TS/PNG, never Downloads, Python or the original DOCX.
"""
import argparse
import copy
import hashlib
import json
from pathlib import Path
from zipfile import ZipFile

from lxml import etree as E

parser = argparse.ArgumentParser()
parser.add_argument('--source', required=True)
args = parser.parse_args()
source = Path(args.source)
root_dir = Path(__file__).resolve().parents[1]
W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
ns = {'w': W}
q = lambda name: '{' + W + '}' + name
with ZipFile(source) as archive:
    parts = {name: archive.read(name) for name in archive.namelist() if not name.endswith('/')}
document = E.fromstring(parts['word/document.xml'])
body = document.find('w:body', ns)
nodes = list(body)


def fill_paragraph(p, runs):
    reference = p.find('w:r/w:rPr', ns)
    for child in list(p):
        if child.tag != q('pPr'):
            p.remove(child)
    for text, bold, underline in runs:
        run = E.SubElement(p, q('r'))
        props = copy.deepcopy(reference) if reference is not None else E.Element(q('rPr'))
        for tag in ['b', 'bCs', 'u', 'color']:
            for old in props.findall('w:' + tag, ns):
                props.remove(old)
        if bold:
            E.SubElement(props, q('b'))
        if underline:
            E.SubElement(props, q('u')).set(q('val'), 'single')
        E.SubElement(props, q('color')).set(q('val'), '000000')
        run.append(props)
        for i, line in enumerate(text.split('\n')):
            if i:
                E.SubElement(run, q('br'))
            node = E.SubElement(run, q('t'))
            node.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')
            node.text = line


def fill(index, *runs):
    fill_paragraph(nodes[index], [(run, False, False) if isinstance(run, str) else run for run in runs])


fill(1, 'Nomor : {{SPK_NO}}')
fill(3, '{{SPK_PLACE_DATE}}')
fill(4, 'Kepada Yth :\n{{SPK_MANDOR}}')
fill(5, 'Mandor Project\n{{SPK_ADDRESS}}\nDi Tempat')
fill(7, 'Perihal : Perintah Mulai Kerja Pekerjaan ', ('{{SPK_JOB}}', True, False), ' Area ', ('{{SPK_BRANCH}}', True, False), '.')
fill(9, '{{SPK_OPENING}}')
fill(17, '{{SPK_COORDINATION_BEFORE}}', ('{{SPK_SUPERVISOR}}', True, False), ' Nomor Handphone ', ('{{SPK_PHONE}}', True, False))
fill(25, ('{{SPK_SIGNER}}', True, True), '\n', ('{{SPK_POSITION}}', True, False), '\n', ('NIK: {{SPK_NIK}}', True, False))
table = nodes[10]
for cell, text in zip(table.findall('w:tr', ns)[2].findall('w:tc', ns), ['1', '{{SPK_JOB}}', '{{SPK_MATERIAL}}', '{{SPK_SERVICE}}', '{{SPK_TOTAL}}']):
    fill_paragraph(cell.find('w:p', ns), [(text, False, False)])
# Keep the original grid/borders/fonts; let dynamic values flow instead of overlap.
props = table.find('w:tblPr', ns)
for tag in ['tblpPr', 'tblOverlap']:
    for node in props.findall('w:' + tag, ns):
        props.remove(node)
props.find('w:tblInd', ns).set(q('w'), '835')
for node in table.findall('.//w:noWrap', ns):
    node.getparent().remove(node)
for row in table.findall('w:tr', ns)[:2]:
    E.SubElement(row.find('w:trPr', ns), q('tblHeader'))
for index in [13, 14, 15, 16, 26]:
    body.remove(nodes[index])
for index in [2, 3, 4, 5, 6, 7, 8, 9, 11, 12]:
    spacing = nodes[index].find('w:pPr/w:spacing', ns)
    if spacing is not None:
        spacing.set(q('line'), '{{SPK_MAIN_LINE}}')
for index in [17, 18]:
    spacing = nodes[index].find('w:pPr/w:spacing', ns)
    spacing.set(q('line'), '264')
    spacing.set(q('lineRule'), 'auto')
for index in [0, 1, 7, 9, 18, 19, 20, 21, 22, 23, 24]:
    ppr = nodes[index].find('w:pPr', ns)
    if ppr is None:
        ppr = E.Element(q('pPr'))
        nodes[index].insert(0, ppr)
    E.SubElement(ppr, q('keepNext'))
for node in document.findall('.//w:color', ns):
    if node.get(q('val')) == 'FF0000':
        node.set(q('val'), '000000')
E.SubElement(nodes[25].find('w:pPr', ns), q('keepLines'))
# Dynamic title remains visually identical but uses Word's semantic Title style.
styles = E.fromstring(parts['word/styles.xml'])
title = E.SubElement(styles, q('style'), {q('type'): 'paragraph', q('styleId'): 'Area3SpkTitle'})
E.SubElement(title, q('name')).set(q('val'), 'Title')
E.SubElement(title, q('basedOn')).set(q('val'), '1')
title_style = E.Element(q('pStyle'))
title_style.set(q('val'), 'Area3SpkTitle')
nodes[0].find('w:pPr', ns).insert(0, title_style)
serialize = lambda node: E.tostring(node, xml_declaration=True, encoding='UTF-8', standalone=True)
parts['word/document.xml'] = serialize(document)
parts['word/styles.xml'] = serialize(styles)
keep = {'word/document.xml', 'word/styles.xml', 'word/settings.xml', 'word/fontTable.xml', 'word/theme/theme1.xml',
        'word/header1.xml', 'word/_rels/header1.xml.rels', 'word/footnotes.xml', 'word/endnotes.xml'}
rels = E.fromstring(parts['word/_rels/document.xml.rels'])
for rel in list(rels):
    if rel.get('Type').endswith('/customXml'):
        rels.remove(rel)
parts['word/_rels/document.xml.rels'] = serialize(rels)
keep.add('word/_rels/document.xml.rels')
rels = E.fromstring(parts['_rels/.rels'])
for rel in list(rels):
    if not rel.get('Type').endswith('/officeDocument'):
        rels.remove(rel)
parts['_rels/.rels'] = serialize(rels)
keep.add('_rels/.rels')
types = E.fromstring(parts['[Content_Types].xml'])
for node in list(types):
    if node.tag.endswith('Override') and node.get('PartName').lstrip('/') not in keep:
        types.remove(node)
parts['[Content_Types].xml'] = serialize(types)
keep.add('[Content_Types].xml')
target = root_dir / 'resources/js/area3'
(target / 'assets').mkdir(exist_ok=True)
(target / 'assets/spk-telkom-akses.png').write_bytes(parts['word/media/image1.png'])
output = {name: parts[name].decode('utf-8') for name in sorted(keep)}
(target / 'spk-word-template.ts').write_text('// Copy-derived SPK template; generated by scripts/build-spk-word-template.py.\n'
    '// Reference SHA256: ' + hashlib.sha256(source.read_bytes()).hexdigest() + '\n'
    'export const SPK_WORD_PARTS: Readonly<Record<string,string>> = ' + json.dumps(output, ensure_ascii=False, indent=2) + ';\n')
print(json.dumps({'template_parts': len(output), 'logo_bytes': len(parts['word/media/image1.png']), 'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest()}))

"""Copy-derived BARP OOXML preparation. Runtime has no Python/Downloads dependency."""
import argparse, copy, hashlib, json
from pathlib import Path
from zipfile import ZipFile
from lxml import etree as E
parser=argparse.ArgumentParser()
parser.add_argument('--source',required=True)
args=parser.parse_args()
source=Path(args.source)
expected='9c08a9075bc7110bbe4315add0823d4a7676e3c28c282897610757d45b7f498f'
assert hashlib.sha256(source.read_bytes()).hexdigest()==expected, 'Inspect new reference before changing indexed slots'
W='http://schemas.openxmlformats.org/wordprocessingml/2006/main'; ns={'w':W}; q=lambda n:'{'+W+'}'+n
with ZipFile(source) as z: parts={n:z.read(n) for n in z.namelist() if not n.endswith('/')}
doc=E.fromstring(parts['word/document.xml']); body=doc.find('w:body',ns); nodes=list(body)
def fill(p,text):
    reference=p.find('w:r/w:rPr',ns)
    for node in list(p):
        if node.tag!=q('pPr'):p.remove(node)
    run=E.SubElement(p,q('r'))
    if reference is not None:run.append(copy.deepcopy(reference))
    E.SubElement(run,q('t'),{'{http://www.w3.org/XML/1998/namespace}space':'preserve'}).text=text
def para(i,text):fill(nodes[i],text)
def cell(table,row,col,text):fill(nodes[table].findall('w:tr',ns)[row].findall('w:tc',ns)[col].find('w:p',ns),text)
for i,text in {0:'{{TITLE}}',3:'Nama Proyek : {{JOB}}',4:'Jenis Pekerjaan : SWAKELOLA MANDORAN',5:'Nama : {{MANDOR}}',8:'{{OPENING}}',20:'{{PRICE}}',26:'{{RECON_HEADING}}',27:'{{RECON_TEXT}}'}.items():para(i,text)
# First unused spacer carries the review status. Never put a fabricated approval into signature cells.
para(1,'{{STATUS}}');para(35,'{{PLACE_DATE}}')
cell(13,0,1,'{{OBJECT_LABEL}}');cell(28,0,1,'{{OBJECT_LABEL}}')
for col,text in enumerate(['1','{{LOCATION}}','{{STO}}','{{HOMEPASS}}']):cell(13,1,col,text)
cell(23,1,1,'{{STAGE_SCOPE}}')
for start,prefix in [(2,''),(6,'ZERO_'),(10,'ZERO_'),(14,'')]:
    for offset,key in enumerate(['MATERIAL','SERVICE','TOTAL']):cell(23,start+offset,2,'{{'+prefix+key+'}}')
cell(13,0,1,'{{OBJECT_LABEL}}');cell(28,0,1,'{{OBJECT_LABEL}}')
for col,text in enumerate(['1','{{LOCATION}}','{{STO}}','{{MATERIAL}}','{{SERVICE}}','{{TOTAL}}']):cell(28,2,col,text)
for col,text in enumerate(['','TOTAL','{{MATERIAL}}','{{SERVICE}}','{{TOTAL}}']):cell(28,3,col,text)
cell(36,2,0,'{{WASPANG}}');cell(36,2,1,'{{MANDOR}}');cell(39,2,0,'{{HEAD}}')
cell(39,3,0,'HEAD OF PMO AREA JAWA BALI{{HEAD_NIK}}')
# Source contains floating tables/fixed heights which hide pricing rows in LibreOffice.
# Keep the source grid/borders, make all tables inline, repeat headers and allow body flow.
for table in doc.findall('.//w:tbl',ns):
    pr=table.find('w:tblPr',ns)
    for tag in ['tblpPr','tblOverlap']:
        for n in pr.findall('w:'+tag,ns):pr.remove(n)
    for n in table.findall('.//w:noWrap',ns):n.getparent().remove(n)
    for ri,row in enumerate(table.findall('w:tr',ns)):
        rp=row.find('w:trPr',ns)
        if rp is None:rp=E.SubElement(row,q('trPr'))
        for height in rp.findall('w:trHeight',ns):rp.remove(height)
        E.SubElement(rp,q('cantSplit'))
        if ri==0 and table not in [nodes[36],nodes[39]]:E.SubElement(rp,q('tblHeader'))
        if table in [nodes[36],nodes[39]] and ri==1:
            E.SubElement(rp,q('trHeight'),{q('val'):'700',q('hRule'):'atLeast'})
# Remove surplus spacers; signatures stay together, not split across pages.
for i in [2,6,7,9,12,14,18,21,22,24,25,29,30,33,37,38,40]:body.remove(nodes[i])
for p in doc.findall('.//w:p',ns):
    pp=p.find('w:pPr',ns)
    if pp is None:pp=E.Element(q('pPr'));p.insert(0,pp)
    for tag in ['pageBreakBefore','keepNext','keepLines','spacing']:
        for n in pp.findall('w:'+tag,ns):pp.remove(n)
    E.SubElement(pp,q('spacing'),{q('before'):'0',q('after'):'60',q('line'):'240',q('lineRule'):'auto'})
    # Source body is Cambria 10; keep table type compact and remove tracked sample color.
    for color in p.findall('.//w:color',ns):color.set(q('val'),'000000')
for i in [0,1,3,4,5,10,11,15,16,19,26,31,34,35]:
    E.SubElement(nodes[i].find('w:pPr',ns),q('keepNext'))
for table in [nodes[36],nodes[39]]:
    for p in table.findall('.//w:p',ns):E.SubElement(p.find('w:pPr',ns),q('keepNext'))
last=nodes[39].findall('.//w:p',ns)[-1]
for n in last.findall('w:pPr/w:keepNext',ns):n.getparent().remove(n)
styles=E.fromstring(parts['word/styles.xml'])
title=E.SubElement(styles,q('style'),{q('type'):'paragraph',q('styleId'):'Area3BarpTitle'})
E.SubElement(title,q('name'),{q('val'):'Title'})
E.SubElement(nodes[0].find('w:pPr',ns),q('pStyle'),{q('val'):'Area3BarpTitle'})
serialize=lambda n:E.tostring(n,xml_declaration=True,encoding='UTF-8',standalone=True)
parts['word/document.xml']=serialize(doc);parts['word/styles.xml']=serialize(styles)
keep={'word/document.xml','word/styles.xml','word/settings.xml','word/fontTable.xml','word/theme/theme1.xml','word/numbering.xml','word/footnotes.xml','word/endnotes.xml','word/_rels/document.xml.rels','_rels/.rels','[Content_Types].xml'}
for name in ['word/_rels/document.xml.rels','_rels/.rels']:
    rels=E.fromstring(parts[name])
    for rel in list(rels):
        if rel.get('TargetMode')=='External' or (name=='_rels/.rels' and not rel.get('Type').endswith('/officeDocument')) or rel.get('Type').endswith('/customXml'):rels.remove(rel)
    parts[name]=serialize(rels)
types=E.fromstring(parts['[Content_Types].xml'])
for n in list(types):
    if n.tag.endswith('Override') and n.get('PartName').lstrip('/') not in keep:types.remove(n)
parts['[Content_Types].xml']=serialize(types)
target=Path(__file__).resolve().parents[1]/'resources/js/area3/barp-word-template.ts'
target.write_text('// Copy-derived BARP template. scripts/build-barp-word-template.py\n// Reference SHA256: '+expected+'\nexport const BARP_WORD_PARTS: Readonly<Record<string,string>> = '+json.dumps({n:parts[n].decode() for n in sorted(keep)},ensure_ascii=False,indent=2)+';\n')
print(json.dumps({'parts':len(keep),'source_sha256':expected}))

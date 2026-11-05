from pathlib import Path
import json, re, hashlib, collections, xml.etree.ElementTree as ET
from docx import Document
from pypdf import PdfReader

root=Path(__file__).resolve().parents[2]
data=root/'docs/database'
s=json.loads((data/'schema-catalog.json').read_text())
ts={t['name']:t for t in s['tables']}
assert len(ts)==61
assert sum(len(t['columns']) for t in ts.values())==497
assert sum(len(t['fks']) for t in ts.values())==106
assert all(t['pk'] and t['purpose'] and t['note'] for t in ts.values())
for n,t in ts.items():
    names={c['name'] for c in t['columns']}
    for c in t['fields'].split(', '): assert c in names,(n,c)
    for fk in t['fks']:
        assert set(fk['columns'])<=names
        pt=ts[fk['target']]
        assert set(fk['target_columns'])<={c['name'] for c in pt['columns']}
        assert any(set(fk['target_columns'])==set(u) for u in [pt['pk'],*pt['unique']])
mmd=(data/'area3-relations.mmd').read_text()
assert len(re.findall(r'^    \w+ \{',mmd,re.M))==61
assert len(re.findall(r'^    \w+ [|o}{]+--[|o}{]+ \w+ :',mmd,re.M))==106
for svg in (data/'diagrams').glob('*.svg'):ET.parse(svg)
assert len(list((data/'diagrams').glob('*.svg')))==9
docfile=root/'output/documents/Buku-Saku-Relasi-Database-its-Area-3.docx'
doc=Document(docfile)
heads=[p.text for p in doc.paragraphs if p.style.name=='Heading 2']
for n in ts: assert any(re.fullmatch(r'\d{2} '+n,h) for h in heads),n
assert not doc.styles['Title'].element.findall('.//{http://schemas.openxmlformats.org/wordprocessingml/2006/main}pBdr')
pdf=root/'tmp/database-handbook/render/Buku-Saku-Relasi-Database-its-Area-3.pdf'
r=PdfReader(pdf)
full='\n'.join(p.extract_text() for p in r.pages)
assert len(r.pages)==42,len(r.pages)
assert all(n in full for n in ts)
assert '\ufffd' not in full
for i,p in enumerate(r.pages,1):
    assert abs(float(p.mediabox.width)-419.53)<1
    assert abs(float(p.mediabox.height)-595.28)<1
    txt=p.extract_text(); assert len(txt)>100
    assert f'Edisi 5 November 2026   |   {i}' in txt
h=(data/'area3-relasi-tabel.html').read_text()
assert len(re.findall('<article id=',h))==61
assert len(re.findall('<figure>',h))==9
assert not re.search(r'<script[^>]+src=|<link[^>]+href=["\']https?://',h)
js=re.findall(r'<script>(.*?)</script>',h,re.S)[0]
(root/'tmp/database-handbook/companion-script.js').write_text(js)
report=dict(schema={'tables':61,'columns':497,'foreign_keys':106,'primary_keys':61},
            descriptions_complete=True, column_references_valid=True,
            mermaid_complete=True,svg_xml_valid=True,html_offline=True,
            catalog_entries=61,diagram_count=9,pdf_pages=len(r.pages),pdf_size='A5',
            database_access='metadata only, read-only transaction',
            runtime_code_changed=False, test_scope='documentation and artifact validation',
            files={str(docfile.relative_to(root)):hashlib.sha256(docfile.read_bytes()).hexdigest(),
                   str(pdf.relative_to(root)):hashlib.sha256(pdf.read_bytes()).hexdigest()})
(root/'tmp/database-handbook/validation.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))

import { ooxmlZip } from './ooxml-zip';
import { xmlHeader,wns,relns,officeRel } from './word-ooxml';

const decode=new TextDecoder();
const attr=(value:string,name:string)=>value.match(new RegExp(`\\b${name}="([^"]*)"`))?.[1]??'';
/** Only our in-memory, stored ZIP generators are accepted. Never used for uploaded Word files. */
function parts(bytes:Uint8Array){
  if(bytes.length<22||bytes.length>64*1024*1024)throw new Error('Ukuran paket Word tidak valid.');
  const v=new DataView(bytes.buffer,bytes.byteOffset,bytes.byteLength),end=bytes.length-22;
  if(v.getUint32(end,true)!==0x06054b50)throw new Error('Paket Word tidak valid.');
  const result:Record<string,Uint8Array>={},count=v.getUint16(end+10,true);let at=v.getUint32(end+16,true);
  for(let i=0;i<count;i++){
    if(at+46>end||v.getUint32(at,true)!==0x02014b50||v.getUint16(at+10,true)!==0)throw new Error('Format Word gabungan tidak didukung.');
    const size=v.getUint32(at+24,true),len=v.getUint16(at+28,true),local=v.getUint32(at+42,true),name=decode.decode(bytes.subarray(at+46,at+46+len));
    if(!name||name.includes('..')||name.startsWith('/')||local+30>at||v.getUint32(local,true)!==0x04034b50)throw new Error('Bagian Word tidak valid.');
    const start=local+30+v.getUint16(local+26,true)+v.getUint16(local+28,true);
    if(start+size>at||name in result)throw new Error('Bagian Word tidak valid.');
    result[name]=bytes.subarray(start,start+size);
    at+=46+len+v.getUint16(at+30,true)+v.getUint16(at+32,true);
  }
  return result;
}
const inner=(xml:string,tag:string)=>xml.match(new RegExp(`<${tag}[^>]*>([\\s\\S]*?)<\\/${tag}>`))?.[1]??'';
const defaults=(xml:string,kind:'r'|'p')=>inner(inner(xml,`w:${kind}PrDefault`),`w:${kind}Pr`);
function missingProperties(fallback:string,current:string){
  return (fallback.match(/<w:\w+\b[^>]*(?:\/>|>[\s\S]*?<\/w:\w+>)/g)??[]).filter(p=>!current.includes(p.match(/^<w:\w+/)![0]+' ')&&!current.includes(p.match(/^<w:\w+/)![0]+'>')&&!current.includes(p.match(/^<w:\w+/)![0]+'/>')).join('');
}
function explicitDefaults(body:string,style:string,rDefaults:string,pDefaults:string){
  body=body.replace(/<w:p(?:\s[^>]*)?>[\s\S]*?<\/w:p>/g,p=>{
    const own=p.match(/<w:pPr>[\s\S]*?<\/w:pPr>/)?.[0]??'',extra=(/<w:pStyle\b/.test(own)?'':`<w:pStyle w:val="${style}"/>`)+missingProperties(pDefaults,own);
    return own?p.replace('<w:pPr>','<w:pPr>'+extra):p.replace(/^(<w:p(?:\s[^>]*)?>)/,'$1<w:pPr>'+extra+'</w:pPr>');
  });
  return body.replace(/<w:r(?:\s[^>]*)?>[\s\S]*?<\/w:r>/g,r=>{
    const own=r.match(/<w:rPr>[\s\S]*?<\/w:rPr>/)?.[0]??'',extra=missingProperties(rDefaults,own);
    return own?r.replace('<w:rPr>','<w:rPr>'+extra):r.replace(/^(<w:r(?:\s[^>]*)?>)/,'$1<w:rPr>'+extra+'</w:rPr>');
  });
}

/** Isolated styles/numbering/media plus real next-page sections preserve each native template. */
export function mergeDocx(documents:Uint8Array[]){
  if(!documents.length||documents.length>20||documents.reduce((n,d)=>n+d.length,0)>48*1024*1024)throw new Error('Paket terlalu besar. Unduh dokumen secara terpisah.');
  const output:Record<string,string|Uint8Array>={},styles:string[]=[],numbering:string[]=[],relationships:string[]=[],contentTypes:string[]=[],bodies:string[]=[],namespaces=new Map<string,string>([['w',wns],['r',officeRel]]);
  let finalSection='';
  documents.forEach((bytes,index)=>{
    const pkg=parts(bytes),read=(path:string)=>decode.decode(pkg[path]??new Uint8Array()),prefix=`B${index}_`,base=`part${index}/`,doc=read('word/document.xml'),style=read('word/styles.xml');
    if(!doc.includes('<w:body>'))throw new Error('Isi dokumen Word belum tersedia.');
    for(const [,,name,url] of [...doc.matchAll(/(xmlns:)([\w]+)="([^"]*)"/g)].map(m=>[m[0],m[1],m[2],m[3]])){
      if(namespaces.has(name)&&namespaces.get(name)!==url)throw new Error('Namespace Word tidak cocok.');namespaces.set(name,url);
    }
    const omittedParts=new Set(['footnotes','endnotes'].filter(kind=>!doc.includes(`<w:${kind==='footnotes'?'footnote':'endnote'}Reference`)).map(kind=>'word/'+kind+'.xml'));
    const ids=new Map([...style.matchAll(/w:styleId="([^"]+)"/g)].map(m=>[m[1],prefix+m[1]]));
    const rewrite=(xml:string)=>xml.replace(/w:styleId="([^"]+)"/g,(_,id)=>`w:styleId="${ids.get(id)??prefix+id}"`).replace(/(<w:(?:pStyle|rStyle|tblStyle|basedOn|next|link)\b[^>]*w:val=")([^"]+)(")/g,(_,a,id,b)=>a+(ids.get(id)??prefix+id)+b)
      .replace(/(<w:(?:abstractNum|num)\b[^>]*w:(?:abstractNumId|numId)=")([0-9]+)(")|(<w:(abstractNumId|numId)\b[^>]*w:val=")([0-9]+)(")/g,(_,a,id,b,c,kind,value,d)=>{const n=Number(id??value);if(n>=10000)throw new Error('Penomoran Word terlalu besar.');return a?a+(n+(index+1)*10000)+b:c+(kind==='numId'&&n===0?0:n+(index+1)*10000)+d;});
    const rels=read('word/_rels/document.xml.rels'),relIds=new Map<string,string>();
    for(const m of rels.matchAll(/<Relationship\b[^>]*\/>/g)){
      const id=attr(m[0],'Id'),type=attr(m[0],'Type'),target=attr(m[0],'Target');
      if(attr(m[0],'TargetMode')==='External'||target.includes('..')||target.startsWith('/'))throw new Error('Relasi eksternal tidak diizinkan pada paket Word.');
      if(omittedParts.has('word/'+target)||/\/(styles|numbering|settings|webSettings|fontTable|theme)$/.test(type))continue;
      const newId=prefix+id;relIds.set(id,newId);
      relationships.push(`<Relationship Id="${newId}" Type="${type}" Target="${base+target}"/>`);
    }
    let body=inner(doc,'w:body'),section=body.match(/<w:sectPr\b[\s\S]*?<\/w:sectPr>\s*$/)?.[0];
    if(!section)throw new Error('Ukuran halaman Word belum tersedia.');
    body=body.slice(0,body.length-section.length);section=section.replace(/<w:type\b[^>]*\/>/g,'').replace('<w:sectPr>','<w:sectPr><w:type w:val="nextPage"/>');
    const remap=(xml:string)=>rewrite(xml).replace(/(\br:(?:id|embed|link)=")([^"]+)(")/g,(_,a,id,b)=>{if(!relIds.has(id))throw new Error('Relasi isi Word tidak tersedia.');return a+relIds.get(id)+b;})
      .replace(/(<w:bookmark(?:Start|End)\b[^>]*w:id=")([0-9]+)(")/g,(_,a,id,b)=>a+(Number(id)+(index+1)*100000)+b)
      .replace(/(<w:bookmarkStart\b[^>]*w:name=")([^"]+)(")/g,(_,a,name,b)=>a+prefix+name+b)
      .replace(/(<wp:docPr\b[^>]*\bid=")([0-9]+)(")/g,(_,a,id,b)=>a+(Number(id)+(index+1)*100000)+b);
    const defaultStyle=style.match(/<w:style\b(?=[^>]*w:type="paragraph")(?=[^>]*w:default="(?:1|true)")[^>]*w:styleId="([^"]+)"/)?.[1]??'Normal';
    if(!ids.has(defaultStyle))styles.push(`<w:style w:type="paragraph" w:styleId="${prefix+defaultStyle}"><w:name w:val="${prefix+defaultStyle}"/></w:style>`);
    body=explicitDefaults(remap(body),ids.get(defaultStyle)??prefix+defaultStyle,defaults(style,'r'),defaults(style,'p'));
    section=remap(section);
    // A section with no header otherwise inherits the SPK letterhead from the preceding section.
    const blanks=['default','first','even'].filter(kind=>!new RegExp(`<w:headerReference\\b[^>]*w:type="${kind}"`).test(section!)).map(kind=>`<w:headerReference w:type="${kind}" r:id="bundleBlankHeader"/>`).join('');
    section=section.replace(/^(<w:sectPr\b[^>]*>)/,'$1'+blanks);
    if(index<documents.length-1)bodies.push(body+`<w:p><w:pPr>${section}</w:pPr></w:p>`);else{bodies.push(body);finalSection=section;}
    for(const m of style.matchAll(/<w:style\b[\s\S]*?<\/w:style>/g))styles.push(rewrite(m[0]).replace(/ w:default="1"/g,''));
    numbering.push(rewrite(inner(read('word/numbering.xml'),'w:numbering')));
    for(const [name,data] of Object.entries(pkg)){
      if(name.endsWith('/')||omittedParts.has(name)||!name.startsWith('word/')||['word/document.xml','word/_rels/document.xml.rels','word/styles.xml','word/numbering.xml'].includes(name))continue;
      if(/footnotes|endnotes|comments|vbaProject/i.test(name))throw new Error('Bagian Word ini belum didukung untuk penggabungan.');
      const dest='word/'+base+name.slice(5);
      const xml=name.endsWith('.xml')||name.endsWith('.rels');output[dest]=xml?rewrite(decode.decode(data)):data;
    }
    for(const m of read('[Content_Types].xml').matchAll(/<(?:Default|Override)\b[^>]*\/>/g)){
      if(m[0].startsWith('<Default')){if(!contentTypes.includes(m[0]))contentTypes.push(m[0]);continue;}
      const name=attr(m[0],'PartName');if(omittedParts.has(name.slice(1))||!name.startsWith('/word/')||['/word/document.xml','/word/styles.xml','/word/numbering.xml'].includes(name))continue;
      contentTypes.push(m[0].replace(`PartName="${name}"`,`PartName="/word/${base+name.slice(6)}"`));
    }
  });
  relationships.push(`<Relationship Id="bundleStyles" Type="${officeRel}/styles" Target="styles.xml"/>`,`<Relationship Id="bundleNumbering" Type="${officeRel}/numbering" Target="numbering.xml"/>`,`<Relationship Id="bundleBlankHeader" Type="${officeRel}/header" Target="blank-header.xml"/>`);
  output['word/blank-header.xml']=xmlHeader+`<w:hdr xmlns:w="${wns}"><w:p/></w:hdr>`;
  output['[Content_Types].xml']=xmlHeader+`<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">${contentTypes.join('')}<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/><Override PartName="/word/blank-header.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/></Types>`;
  output['_rels/.rels']=xmlHeader+`<Relationships xmlns="${relns}"><Relationship Id="main" Type="${officeRel}/officeDocument" Target="word/document.xml"/></Relationships>`;
  const ns=[...namespaces].map(([k,v])=>`xmlns:${k}="${v}"`).join(' ');
  output['word/document.xml']=xmlHeader+`<w:document ${ns}><w:body>${bodies.join('')}${finalSection}</w:body></w:document>`;
  output['word/styles.xml']=xmlHeader+`<w:styles ${ns}>${styles.join('')}</w:styles>`;
  output['word/numbering.xml']=xmlHeader+`<w:numbering ${ns}>${numbering.join('')}</w:numbering>`;
  output['word/_rels/document.xml.rels']=xmlHeader+`<Relationships xmlns="${relns}">${relationships.join('')}</Relationships>`;
  return ooxmlZip(output);
}

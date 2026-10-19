(() => {
  'use strict';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[character]));
  const xml = value => String(value ?? '').replace(/[&<>"']/g, character => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&apos;'}[character]));
  const splitLines = value => esc(value).replace(/\r\n|\r|\n/g, '<br>');
  const dateTokens = value => esc(value).replace(/(?<![0-9/])(?:(?:0?[1-9]|[12][0-9]|3[01])\/(?:0?[1-9]|1[0-2])\/(?:19|20)[0-9]{2}|(?:0?[1-9]|1[0-2])\/(?:19|20)[0-9]{2})(?![0-9/])/g, '<span class="date-token">$&</span>');

  function safeUrl(value) {
    const raw = String(value || '').trim();
    if (!raw || /[\x00-\x20\x7f]/.test(raw)) return '';
    let url = raw;
    if (url.startsWith('//')) url = 'https:' + url;
    else if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
    try {
      const parsed = new URL(url);
      if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) return '';
      return parsed.href;
    } catch (_) { return ''; }
  }

  function recentFirst(rows, primary, fallback) {
    return (rows || []).map((row, index) => ({row,index})).sort((left,right) => {
      const value = row => {
        let date = String(row[primary] || '').trim();
        if (/^(present|current|now)$/i.test(date)) return Number.MAX_SAFE_INTEGER;
        if (!date) date = String(row[fallback] || '').trim();
        if (/^(present|current|now)$/i.test(date)) return Number.MAX_SAFE_INTEGER;
        const parsed = Date.parse(date);
        if (!Number.isNaN(parsed)) return parsed;
        const years = date.match(/(?:19|20)\d{2}/g);
        return years?.length ? Number(years[years.length-1]) * 31536000 : 0;
      };
      return value(right.row)-value(left.row) || left.index-right.index;
    }).map(item => item.row);
  }

  function text(cv) {
    const p=cv.personal||{}, sections=cv.enabled_sections||{}, lines=[];
    [p.name,p.title,[p.email,p.phone,p.location,p.linkedin,p.github].filter(Boolean).join(' | ')].filter(Boolean).forEach(line=>lines.push(line));
    const add=(heading, values)=>{const clean=values.filter(value=>String(value||'').trim()!=='');if(!clean.length)return;lines.push('',heading,...clean,'');};
    if(sections.summary&&cv.summary)add('SUMMARY',[cv.summary]);
    if(sections.skills)add('SKILLS',(cv.skills||[]).map(row=>[row.name&&row.details?row.name+': ':row.name,row.details].filter(Boolean).join('')));
    if(sections.experience)add('EXPERIENCE',recentFirst(cv.experience,'end','start').map(row=>[ [row.title,row.company,row.location].filter(Boolean).join(' | '), [row.start,row.end].filter(Boolean).join(' – '), ...(row.bullets||[]).map(bullet=>'- '+bullet) ].filter(Boolean).join('\n')));
    if(sections.education)add('EDUCATION',recentFirst(cv.education,'year','year').map(row=>[row.degree,row.institution,row.location,row.year,row.details].filter(Boolean).join(' | ')));
    if(sections.projects)add('PROJECTS',(cv.projects||[]).map(row=>[row.name,row.technologies,row.description,row.url].filter(Boolean).join(' | ')));
    if(sections.certifications)add('CERTIFICATIONS',(cv.certifications||[]).map(row=>[row.name,row.issuer,row.date,row.credential_id].filter(Boolean).join(' | ')));
    if(sections.languages)add('LANGUAGES',(cv.languages||[]).map(row=>[row.name,row.level?` — ${row.level}`:''].join('')));
    if(sections.custom_sections)(cv.custom_sections||[]).forEach(row=>{if(row.title)add(String(row.title).toLocaleUpperCase(),[row.content]);});
    return lines.join('\n').trim()+'\n';
  }

  function html(cv) {
    const p=cv.personal||{}, sections=cv.enabled_sections||{};
    const contacts=[p.email,p.phone,p.location].filter(Boolean).map(esc);
    const icons={
      linkedin:'<svg viewBox="0 0 24 24" width="17" height="17" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="#0a66c2" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.35V9h3.414v1.561h.049c.476-.9 1.637-1.85 3.37-1.85 3.6 0 4.264 2.37 4.264 5.455v6.286ZM5.337 7.433a2.062 2.062 0 1 1 0-4.124 2.062 2.062 0 0 1 0 4.124Zm1.782 13.019H3.555V9h3.564v11.452ZM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003Z"/></svg>',
      github:'<svg viewBox="0 0 24 24" width="17" height="17" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="#181717" d="M12 .297a12 12 0 0 0-3.79 23.39c.6.111.82-.259.82-.577v-2.234c-3.338.726-4.043-1.416-4.043-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.09-.745.083-.729.083-.729 1.205.085 1.84 1.237 1.84 1.237 1.07 1.835 2.807 1.305 3.492.997.108-.775.418-1.305.762-1.604-2.665-.305-5.466-1.332-5.466-5.93 0-1.31.469-2.381 1.236-3.221-.124-.303-.536-1.524.117-3.176 0 0 1.008-.322 3.301 1.23a11.5 11.5 0 0 1 6.004 0c2.293-1.552 3.297-1.23 3.297-1.23.655 1.652.243 2.873.12 3.176.77.84 1.235 1.911 1.235 3.221 0 4.61-2.805 5.621-5.477 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.216.694.825.576A12.005 12.005 0 0 0 12 .297Z"/></svg>',
      website:'<svg viewBox="0 0 24 24" width="17" height="17" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false"><path fill="none" stroke="#444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M14 3h7v7M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/></svg>'
    };
    const link=(kind,value)=>{
      const href=safeUrl(value);if(!href)return '';
      const host=new URL(href).hostname.toLowerCase();
      let label=kind==='linkedin'?'LinkedIn':'GitHub or portfolio', cls=kind;
      if(kind==='github'&&host!=='github.com'&&!host.endsWith('.github.com')){label='Portfolio website';cls='website';}
      return `<a class="social-link ${cls}" href="${esc(href)}" target="_blank" rel="noopener noreferrer" aria-label="${label}" title="${label}">${icons[cls]}</a>`;
    };
    let output=`<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${esc(p.name||'CV')}</title><style>
      body{font-family:Arial,Helvetica,sans-serif;color:#171717;font-size:11pt;line-height:1.45;margin:0 auto;padding:28px;max-width:780px}h1{font-size:22pt;margin:0 0 2px}h2{font-size:12pt;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #444;padding-bottom:3px;margin:18px 0 8px}h3{font-size:11pt;margin:0 0 2px}p{margin:3px 0 7px}.subtle{color:#333}.entry{margin:0 0 9px}.date,.date-token{font-weight:normal;white-space:nowrap}.cv-list{padding-left:18px;margin:4px 0}.contacts{margin:4px 0 10px;overflow-wrap:anywhere}.contact-divider{display:inline-block;margin:0 4px;color:#666}.social-link{display:inline-block;width:18px;height:18px;margin:0 2px;vertical-align:middle}.social-link svg{display:block;width:17px;height:17px}.profile-photo{float:right;width:90px;height:120px;object-fit:cover;margin:0 0 10px 15px;border-radius:3px}@media print{body{max-width:none;padding:0}}
      </style></head><body>`;
    if(p.photo)output+=`<img class="profile-photo" src="${esc(p.photo)}" alt="Profile photo">`;
    output+=`<h1>${esc(p.name||'')}</h1>`;
    if(p.title)output+=`<p class="subtle">${esc(p.title)}</p>`;
    const socials=[link('linkedin',p.linkedin),link('github',p.github)].filter(Boolean);
    const contactHtml=[...contacts,...socials].join(' <span class="contact-divider" aria-hidden="true">|</span> ');
    if(contactHtml)output+=`<p class="contacts">${contactHtml}</p>`;
    const section=(title,body)=>{if(body)output+=`<section><h2>${esc(title)}</h2>${body}</section>`;};
    if(sections.summary&&cv.summary)section('Summary',`<p>${splitLines(cv.summary)}</p>`);
    if(sections.skills)section('Skills',(cv.skills||[]).map(row=>row.name&&row.details?`<p><strong>${esc(row.name)}:</strong> ${esc(row.details)}</p>`:`<p>${esc(row.name||row.details||'')}</p>`).join(''));
    if(sections.experience)section('Experience',recentFirst(cv.experience,'end','start').map(row=>{
      const title=[row.title,row.company,row.location].filter(Boolean).join(' | '),dates=[row.start,row.end].filter(Boolean).join(' – ');
      return `<div class="entry"><h3>${esc(title)}</h3>${dates?`<p class="date">${dateTokens(dates)}</p>`:''}${row.bullets?.length?`<ul class="cv-list">${row.bullets.map(bullet=>`<li>${dateTokens(bullet)}</li>`).join('')}</ul>`:''}</div>`;
    }).join(''));
    if(sections.education)section('Education',recentFirst(cv.education,'year','year').map(row=>`<div class="entry"><h3>${esc([row.degree,row.institution].filter(Boolean).join(' | '))}</h3><p class="subtle">${[row.location?esc(row.location):'',row.year?`<span class="date">${dateTokens(row.year)}</span>`:''].filter(Boolean).join(' | ')}</p>${row.details?`<p>${splitLines(row.details)}</p>`:''}</div>`).join(''));
    const named=(rows,title,fields)=>section(title,(rows||[]).map(row=>`<p class="entry">${fields.slice(0,2).filter(key=>row[key]).map(key=>`<strong>${esc(row[key])}</strong>`).concat(fields.slice(2).filter(key=>row[key]).map(key=>esc(row[key]))).join(' — ')}</p>`).join(''));
    if(sections.projects)named(cv.projects,'Projects',['name','technologies','description','url']);
    if(sections.certifications)named(cv.certifications,'Certifications',['name','issuer','date','credential_id']);
    if(sections.languages)section('Languages',`<p>${(cv.languages||[]).map(row=>esc(row.name+(row.level?' — '+row.level:''))).join('; ')}</p>`);
    if(sections.custom_sections)(cv.custom_sections||[]).forEach(row=>section(row.title,`<p>${splitLines(row.content)}</p>`));
    return output+'</body></html>';
  }

  function zipStore(files) {
    const encoder=new TextEncoder(), crcTable=new Uint32Array(256);
    for(let n=0;n<256;n++){let c=n;for(let k=0;k<8;k++)c=(c&1)?(0xedb88320^(c>>>1)):(c>>>1);crcTable[n]=c>>>0;}
    const crc32=bytes=>{let c=0xffffffff;for(const byte of bytes)c=crcTable[(c^byte)&0xff]^(c>>>8);return(c^0xffffffff)>>>0;};
    const u16=value=>{const out=new Uint8Array(2);new DataView(out.buffer).setUint16(0,value,true);return out;};
    const u32=value=>{const out=new Uint8Array(4);new DataView(out.buffer).setUint32(0,value>>>0,true);return out;};
    const join=parts=>{const length=parts.reduce((sum,part)=>sum+part.length,0),out=new Uint8Array(length);let offset=0;for(const part of parts){out.set(part,offset);offset+=part.length;}return out;};
    const local=[],central=[];let offset=0;
    for(const [name,content] of Object.entries(files)){
      const nameBytes=encoder.encode(name),data=typeof content==='string'?encoder.encode(content):content,crc=crc32(data);
      const header=join([u32(0x04034b50),u16(20),u16(0x0800),u16(0),u16(0),u16(0),u32(crc),u32(data.length),u32(data.length),u16(nameBytes.length),u16(0),nameBytes]);
      local.push(header,data);
      const directory=join([u32(0x02014b50),u16(20),u16(20),u16(0x0800),u16(0),u16(0),u16(0),u32(crc),u32(data.length),u32(data.length),u16(nameBytes.length),u16(0),u16(0),u16(0),u16(0),u32(0),u32(offset),nameBytes]);
      central.push(directory);offset+=header.length+data.length;
    }
    const directory=join(central),end=join([u32(0x06054b50),u16(0),u16(0),u16(central.length),u16(central.length),u32(directory.length),u32(offset),u16(0)]);
    return join([...local,directory,end]);
  }

  async function imageBytes(dataUrl) {
    if(!dataUrl)return null;
    const image=new Image();image.src=dataUrl;
    await new Promise((resolve,reject)=>{image.onload=resolve;image.onerror=()=>reject(new Error('The profile photo could not be added to the DOCX file.'));});
    const canvas=document.createElement('canvas');canvas.width=image.naturalWidth;canvas.height=image.naturalHeight;
    canvas.getContext('2d').drawImage(image,0,0);
    const png=canvas.toDataURL('image/png').split(',')[1];return fromBase64(png);
  }
  function fromBase64(value){const binary=atob(value),bytes=new Uint8Array(binary.length);for(let i=0;i<binary.length;i++)bytes[i]=binary.charCodeAt(i);return bytes;}

  async function docx(cv) {
    const paragraphs=[];let image=null;
    const paragraph=(value,{style='',bold=false,bullet=false,align=''}={})=>{
      if(!value)return;
      const safe=xml(value).replace(/(?<![\d/])\d{1,2}\/\d{1,2}\/\d{4}(?![\d/])|(?<![\d/])\d{1,2}\/\d{4}(?![\d/])/g, token => token.replace(/\//g,'&#x2060;/&#x2060;'));
      const pPr=(style?`<w:pStyle w:val="${style}"/>`:'')+(bullet?'<w:ind w:left="360" w:hanging="240"/>':'')+(align?`<w:jc w:val="${align}"/>`:'');
      paragraphs.push(`<w:p>${pPr?`<w:pPr>${pPr}</w:pPr>`:''}<w:r>${bold?'<w:rPr><w:b/></w:rPr>':''}<w:t xml:space="preserve">${bullet?'• ':''}${safe}</w:t></w:r></w:p>`);
    };
    const heading=value=>paragraph(value.toLocaleUpperCase(),{style:'Heading1'});
    const p=cv.personal||{},sections=cv.enabled_sections||{};
    if(p.photo){try{image=await imageBytes(p.photo);}catch(_){image=null;}}
    const imageParagraph=image?'<w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="914400" cy="1219200"/><wp:docPr id="1" name="Profile photo"/><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="profile.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="rId2"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="914400" cy="1219200"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>':'';
    paragraph(p.name||'',{style:'Title'});paragraph(p.title||'');
    const contact=[p.email,p.phone,p.location,p.linkedin,p.github].filter(Boolean).join(' | ');paragraph(contact);
    if(sections.summary&&cv.summary){heading('Summary');paragraph(cv.summary);}
    if(sections.skills&&(cv.skills||[]).length){heading('Skills');for(const row of cv.skills)paragraph([row.name&&row.details?row.name+': ':row.name,row.details].filter(Boolean).join(''));}
    if(sections.experience&&(cv.experience||[]).length){heading('Experience');for(const row of recentFirst(cv.experience,'end','start')){paragraph([row.title,row.company,row.location].filter(Boolean).join(' | '),{bold:true});paragraph([row.start,row.end].filter(Boolean).join(' – '));for(const bullet of row.bullets||[])paragraph(bullet,{bullet:true});}}
    if(sections.education&&(cv.education||[]).length){heading('Education');for(const row of recentFirst(cv.education,'year','year')){paragraph([row.degree,row.institution].filter(Boolean).join(' | '),{bold:true});paragraph([row.location,row.year].filter(Boolean).join(' | '));paragraph(row.details||'');}}
    if(sections.projects&&(cv.projects||[]).length){heading('Projects');for(const row of cv.projects)paragraph([row.name,row.technologies,row.description,row.url].filter(Boolean).join(' | '));}
    if(sections.certifications&&(cv.certifications||[]).length){heading('Certifications');for(const row of cv.certifications)paragraph([row.name,row.issuer,row.date,row.credential_id].filter(Boolean).join(' | '));}
    if(sections.languages&&(cv.languages||[]).length){heading('Languages');paragraph(cv.languages.map(row=>row.name+(row.level?' — '+row.level:'')).join('; '));}
    if(sections.custom_sections)for(const row of cv.custom_sections||[]){if(row.title){heading(row.title);paragraph(row.content);}}
    const documentXml=`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>${imageParagraph}${paragraphs.join('')}<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="900" w:right="900" w:bottom="900" w:left="900"/></w:sectPr></w:body></w:document>`;
    const files={
      '[Content_Types].xml':`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>${image?'<Default Extension="png" ContentType="image/png"/>':''}<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>`,
      '_rels/.rels':'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
      'word/document.xml':documentXml,
      'word/_rels/document.xml.rels':`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>${image?'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/profile.png"/>':''}</Relationships>`,
      'word/styles.xml':'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="22"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:rPr><w:b/><w:sz w:val="40"/></w:rPr></w:style><w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="240" w:after="100"/><w:outlineLvl w:val="0"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style></w:styles>'
    };
    if(image)files['word/media/profile.png']=image;
    const bytes=zipStore(files);
    return new Blob([bytes],{type:'application/vnd.openxmlformats-officedocument.wordprocessingml.document'});
  }

  window.ATSCvRenderer={text,html,docx};
})();

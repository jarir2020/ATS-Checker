(() => {
  const base = window.ATS_BASE_URL;
  const aiProvider = window.ATS_AI_PROVIDER || 'configured AI provider';
  const aiEnabled = window.ATS_AI_ENABLED === true;
  const vault = window.ATSCvVault;
  const renderer = window.ATSCvRenderer;
  const initial = document.getElementById('initialCv');
  const emptyCv = () => ({schema_version:1,personal:{name:'',title:'',phone:'',email:'',location:'',linkedin:'',github:'',photo:''},enabled_sections:{summary:true,skills:true,experience:true,education:true,projects:true,certifications:true,languages:true,custom_sections:true},summary:'',skills:[],experience:[],education:[],projects:[],certifications:[],languages:[],custom_sections:[]});
  function cleanText(value){
    if(!['string','number','boolean'].includes(typeof value))return '';
    return String(value).replace(/\0/g,'').replace(/\f/g,'\n').replace(/[\x01-\x08\x0B\x0E-\x1F]/g,' ').trim().slice(0,20000);
  }
  function normalizeState(input){
    const out=emptyCv(),source=input&&typeof input==='object'?input:{},personal=source.personal&&typeof source.personal==='object'?source.personal:{};
    ['name','title','phone','email','location','linkedin','github'].forEach(key=>out.personal[key]=cleanText(personal[key]));
    const photo=typeof personal.photo==='string'?personal.photo:'';
    out.personal.photo=/^data:image\/jpeg;base64,[A-Za-z0-9+/]+={0,2}$/.test(photo)&&photo.length<=550000?photo:'';
    const enabled=source.enabled_sections&&typeof source.enabled_sections==='object'?source.enabled_sections:{};
    Object.keys(out.enabled_sections).forEach(key=>{out.enabled_sections[key]=Object.prototype.hasOwnProperty.call(enabled,key)?Boolean(enabled[key]):true;});
    out.summary=cleanText(source.summary);
    const row=(value,fields)=>{const item=value&&typeof value==='object'?value:{};const result={};fields.forEach(key=>result[key]=cleanText(item[key]));return result;};
    const rows=(key,fields)=>Array.isArray(source[key])?source[key].slice(0,80).map(item=>row(item,fields)).filter(item=>Object.values(item).some(value=>value!=='')):[];
    out.skills=rows('skills',['name','details']);
    out.experience=Array.isArray(source.experience)?source.experience.slice(0,80).map(item=>{const entry=row(item,['company','location','title','start','end']);const bullets=Array.isArray(item?.bullets)?item.bullets:item?.bullets?String(item.bullets).split(/\r?\n/):[];entry.bullets=bullets.slice(0,80).map(cleanText).filter(Boolean);return entry;}).filter(item=>Object.entries(item).some(([key,value])=>key==='bullets'?value.length>0:value!=='')):[];
    out.education=rows('education',['institution','location','degree','year','details']);
    out.projects=rows('projects',['name','technologies','description','url']);
    out.certifications=rows('certifications',['name','issuer','date','credential_id']);
    out.languages=rows('languages',['name','level']);
    out.custom_sections=rows('custom_sections',['title','content']);
    return out;
  }
  let state = normalizeState(JSON.parse(initial?.textContent || '{}'));
  let draftId = 0;
  let vaultMetadata = null;
  let pendingVaultCandidate = null;
  let migrationBusy = false;
  let migrationPauseRequested = false;
  let migrationVerified = false;
  let toastTimer;
  let autofixStateSnapshot = null;
  const editor = document.getElementById('editorFields');
  const previewState = document.getElementById('previewState');
  const toast = document.getElementById('appToast');
  const visualPreviewTab = document.getElementById('visualPreviewTab');
  const atsTextTab = document.getElementById('atsTextTab');
  const atsTextStatus = document.getElementById('atsTextStatus');
  const atsTextWarnings = document.getElementById('atsTextWarnings');
  const atsTextPreview = document.getElementById('atsTextPreview');
  const refreshAtsTextButton = document.getElementById('refreshAtsText');
  const copyAtsTextButton = document.getElementById('copyAtsText');
  let atsPreviewDirty = true;
  let atsPreviewLoading = false;
  const schemas = {
    skills:{title:'Skills',hint:'Use accurate skill names and optional groups.',fields:[['name','Skill or group'],['details','Details / tools']]},
    experience:{title:'Experience',hint:'List the most recent role first. Use one accomplishment per bullet.',fields:[['title','Job title'],['company','Company'],['location','Location'],['start','Start date'],['end','End date'],['bullets','Accomplishments and responsibilities']]},
    education:{title:'Education',hint:'List the most recent qualification first.',fields:[['degree','Degree / qualification'],['institution','Institution'],['location','Location'],['year','Dates'],['details','Relevant details']]},
    projects:{title:'Projects',hint:'Include only projects relevant to your target role.',fields:[['name','Project name'],['technologies','Tools / technologies'],['description','Description'],['url','Project URL']]},
    certifications:{title:'Certifications',hint:'Add the issuing organization and date when available.',fields:[['name','Certification'],['issuer','Issuer'],['date','Date'],['credential_id','Credential ID']]},
    languages:{title:'Languages',hint:'Add a level only if it accurately describes your ability.',fields:[['name','Language'],['level','Proficiency']]},
    custom_sections:{title:'Additional sections',hint:'Use conventional headings where possible.',fields:[['title','Section heading'],['content','Section content']]}
  };
  const defaults = {skills:{name:'',details:''},experience:{company:'',location:'',title:'',start:'',end:'',bullets:[]},education:{institution:'',location:'',degree:'',year:'',details:''},projects:{name:'',technologies:'',description:'',url:''},certifications:{name:'',issuer:'',date:'',credential_id:''},languages:{name:'',level:''},custom_sections:{title:'',content:''}};
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  function flash(message) { toast.textContent = message; toast.classList.add('visible'); clearTimeout(toastTimer); toastTimer = setTimeout(() => toast.classList.remove('visible'), 3200); }
  async function post(action, data = {}) {
    const body = new FormData(); body.append('action', action); body.append('csrf_token', window.ATS_CSRF);
    Object.entries(data).forEach(([key, value]) => body.append(key, value));
    const response = await fetch(base + 'index.php', {method:'POST',body});
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'Request failed.');
    return result;
  }
  function addField(parent, labelText, key, value, setter, wide = false) {
    const wrap = document.createElement('div'); wrap.className = 'form-field' + (wide ? ' wide' : '');
    const label = document.createElement('label'); label.textContent = labelText; wrap.append(label);
    const multiline = ['bullets','details','description','content'].includes(key);
    const control = document.createElement(multiline ? 'textarea' : 'input');
    if (!multiline) control.type = key === 'url' ? 'url' : 'text';
    control.value = Array.isArray(value) ? value.join('\n') : String(value ?? '');
    control.addEventListener('input', () => {setter(multiline && key === 'bullets' ? control.value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean) : control.value);schedulePreview();if(aiEnabled&&['summary','bullets'].includes(key))renderAutofixTargets();});
    wrap.append(control); parent.append(wrap);
  }
  function addPhotoField(parent) {
    const wrap=document.createElement('div');wrap.className='form-field wide photo-field';
    const label=document.createElement('span');label.className='photo-field-label';label.textContent='Profile photo (optional)';wrap.append(label);
    const row=document.createElement('div');row.className='photo-controls';
    const preview=document.createElement('img');preview.className='photo-thumbnail';preview.alt='Profile photo preview';preview.hidden=true;
    const actions=document.createElement('div');actions.className='photo-actions';
    const choose=document.createElement('label');choose.className='button button-light photo-file-button';choose.append(document.createTextNode('Choose or replace photo'));
    const input=document.createElement('input');input.className='photo-file-input';input.type='file';input.accept='image/jpeg,image/png,image/webp';input.setAttribute('aria-label','Choose a profile photo');choose.append(input);
    input.addEventListener('change',()=>{
      const file=input.files?.[0];if(!file)return;
      if(file.size>10*1024*1024){flash('Choose a photo smaller than 10 MB.');input.value='';return;}
      if(!['image/jpeg','image/png','image/webp'].includes(file.type)){flash('Choose a JPEG, PNG, or WebP image.');input.value='';return;}
      const objectUrl=URL.createObjectURL(file),image=new Image();
      image.onload=()=>{
        URL.revokeObjectURL(objectUrl);
        if(image.naturalWidth<32||image.naturalHeight<32||image.naturalWidth>6000||image.naturalHeight>6000||image.naturalWidth*image.naturalHeight>20000000){flash('This photo has unsupported dimensions.');input.value='';return;}
        let sx=0,sy=0,sw=image.naturalWidth,sh=image.naturalHeight;const ratio=.75;
        if(sw/sh>ratio){sw=Math.floor(sh*ratio);sx=Math.floor((image.naturalWidth-sw)/2);}else{sh=Math.floor(sw/ratio);sy=Math.floor((image.naturalHeight-sh)/2);}
        const scale=Math.min(384/sw,512/sh,1),width=Math.max(1,Math.round(sw*scale)),height=Math.max(1,Math.round(sh*scale));
        const canvas=document.createElement('canvas');canvas.width=width;canvas.height=height;const context=canvas.getContext('2d');if(!context){flash('Photo editing is unavailable in this browser.');input.value='';return;}
        context.fillStyle='#fff';context.fillRect(0,0,width,height);context.drawImage(image,sx,sy,sw,sh,0,0,width,height);
        let photo=canvas.toDataURL('image/jpeg',.8);
        if(photo.length>533000)photo=canvas.toDataURL('image/jpeg',.64);
        if(photo.length>533000){canvas.width=288;canvas.height=384;const small=canvas.getContext('2d');small.fillStyle='#fff';small.fillRect(0,0,288,384);small.drawImage(image,sx,sy,sw,sh,0,0,288,384);photo=canvas.toDataURL('image/jpeg',.62);}
        if(photo.length>533000){flash('This photo could not be reduced enough. Choose a smaller image.');input.value='';return;}
        state.personal.photo=photo;renderEditor();schedulePreview();flash('Profile photo updated.');
      };
      image.onerror=()=>{URL.revokeObjectURL(objectUrl);input.value='';flash('This photo could not be read.');};image.src=objectUrl;
    });
    const remove=document.createElement('button');remove.type='button';remove.className='text-button photo-remove';remove.textContent='Remove photo';remove.hidden=!state.personal.photo;
    remove.addEventListener('click',()=>{state.personal.photo='';renderEditor();schedulePreview();flash('Profile photo removed.');});
    actions.append(choose,remove);
    const hint=document.createElement('span');hint.className='photo-hint';hint.textContent='Only appears when you add one. The photo is cropped to a portrait shape for the CV header.';
    row.append(preview,actions,hint);wrap.append(row);parent.append(wrap);
    if(state.personal.photo){preview.src=state.personal.photo;preview.hidden=false;}
  }
  function autofixTargets() {
    const targets=[];
    if(state.enabled_sections?.summary!==false&&String(state.summary||'').trim())targets.push({id:'summary',label:'Professional summary',text:String(state.summary)});
    if(state.enabled_sections?.experience!==false)normalizedExperienceRows().forEach((entry,experienceIndex)=>(entry.bullets||[]).forEach((bullet,bulletIndex)=>{if(String(bullet||'').trim()&&targets.length<30)targets.push({id:`experience:${experienceIndex}:bullet:${bulletIndex}`,label:`Experience ${experienceIndex+1}, bullet ${bulletIndex+1}`,text:String(bullet)});}));
    return targets.slice(0,30);
  }
  function normalizedExperienceRows(){
    return (state.experience||[]).filter(entry=>{const bullets=(entry.bullets||[]).map(value=>String(value||'').trim()).filter(Boolean);return ['company','location','title','start','end'].some(field=>String(entry[field]||'').trim())||bullets.length>0;});
  }
  function updateAutofixSelectionCount() {
    const count=document.getElementById('autofixTargets')?.querySelectorAll('[data-autofix-target]:checked').length||0;
    const label=document.getElementById('autofixTargetCount');if(label)label.textContent=`${count} selected; choose up to 30.`;
  }
  function renderAutofixTargets() {
    const container=document.getElementById('autofixTargets');if(!container)return;
    container.replaceChildren();const targets=autofixTargets();
    if(!targets.length){container.append(document.createTextNode('Add a summary or experience bullets to get started.'));updateAutofixSelectionCount();return;}
    targets.forEach(target=>{
      const label=document.createElement('label');label.className='autofix-target';
      const checkbox=document.createElement('input');checkbox.type='checkbox';checkbox.dataset.autofixTarget=target.id;checkbox.disabled=target.text.length>4000;
      checkbox.addEventListener('change',()=>{if(container.querySelectorAll('[data-autofix-target]:checked').length>30){checkbox.checked=false;flash('Choose no more than 30 text items.');}updateAutofixSelectionCount();});
      const copy=document.createElement('span');copy.className='autofix-target-copy';const name=document.createElement('strong');name.textContent=target.label;const text=document.createElement('span');text.className='autofix-target-text';text.textContent=target.text;copy.append(name,text);
      if(checkbox.disabled){label.classList.add('autofix-target-disabled');const limit=document.createElement('small');limit.textContent='Shorten this text to 4,000 characters or less before sending.';copy.append(limit);}
      label.append(checkbox,copy);container.append(label);
    });
    updateAutofixSelectionCount();
  }
  function block(title, hint, sectionKey = null) {
    const section = document.createElement('section'); section.className = 'editor-block';
    const head = document.createElement('div'); head.className = 'block-head';
    const copy = document.createElement('div'); const h = document.createElement('h3'); h.textContent = title; copy.append(h);
    const p = document.createElement('p'); p.textContent = hint; copy.append(p); head.append(copy);
    if(sectionKey){const include=document.createElement('label');include.className='include-toggle';const input=document.createElement('input');input.type='checkbox';input.checked=state.enabled_sections?.[sectionKey]!==false;input.addEventListener('change',()=>{state.enabled_sections[sectionKey]=input.checked;schedulePreview();if(aiEnabled)renderAutofixTargets();});include.append(input,document.createTextNode('Include'));head.append(include);}
    section.append(head);
    return {section, head};
  }
  function renderEditor() {
    editor.replaceChildren();
    const personal = block('Contact details','Keep your name and contact information in the document body.');
    const pgrid = document.createElement('div'); pgrid.className = 'field-grid';
    [['name','Full name'],['title','Professional title'],['email','Email'],['phone','Phone'],['location','Location'],['linkedin','LinkedIn URL'],['github','GitHub / portfolio URL']].forEach(([key,label]) => addField(pgrid,label,key,state.personal[key],value=>state.personal[key]=value));
    addPhotoField(pgrid);
    personal.section.append(pgrid); editor.append(personal.section);
    const summary = block('Professional summary','Write a short overview supported by examples in your CV.','summary');
    const sgrid = document.createElement('div'); sgrid.className='field-grid'; addField(sgrid,'Summary','summary',state.summary,value=>state.summary=value,true); summary.section.append(sgrid); editor.append(summary.section);
    Object.entries(schemas).forEach(([key, schema]) => {
      const {section,head} = block(schema.title,schema.hint,key);
      const add = document.createElement('button'); add.type='button'; add.className='add-entry'; add.textContent='+ Add';
      add.addEventListener('click',()=>{state[key].push(structuredClone(defaults[key]));renderEditor();schedulePreview();}); head.append(add);
      const list = document.createElement('div');
      state[key].forEach((item,index)=>{
        const card=document.createElement('div');card.className='entry-card';
        const cardHead=document.createElement('div');cardHead.className='entry-card-head';
        const title=document.createElement('strong');title.textContent=`${schema.title} ${index+1}`;cardHead.append(title);
        const actions=document.createElement('div');actions.className='entry-actions';
        [['↑','Move up',-1],['↓','Move down',1],['Remove','Remove',0]].forEach(([caption,aria,delta])=>{
          const button=document.createElement('button');button.type='button';button.className='icon-button';button.textContent=caption;button.setAttribute('aria-label',aria+' '+schema.title.toLowerCase());
          button.disabled=(delta<0&&index===0)||(delta>0&&index===state[key].length-1);
          button.addEventListener('click',()=>{if(delta===0)state[key].splice(index,1);else{const next=index+delta;[state[key][index],state[key][next]]=[state[key][next],state[key][index]];}renderEditor();schedulePreview();});actions.append(button);
        });
        cardHead.append(actions);card.append(cardHead);
        const grid=document.createElement('div');grid.className='field-grid';
        schema.fields.forEach(([field,label])=>addField(grid,label,field,item[field],value=>item[field]=value,field==='bullets'||field==='description'||field==='content'||field==='details'));
        card.append(grid);
        if(aiEnabled&&key==='experience'){const suggest=document.createElement('button');suggest.type='button';suggest.className='text-button';suggest.textContent='Suggest an AI rewrite for these bullets';suggest.addEventListener('click',()=>requestRewrite('rewrite_bullet',(item.bullets||[]).join('\n'),suggestion=>{const bulletIndex=(item.bullets||[]).findIndex(b=>b.trim()===suggestion.original.trim());if(bulletIndex>=0){item.bullets[bulletIndex]=suggestion.rewrite;renderEditor();schedulePreview();flash('Bullet suggestion applied.');}else flash('This suggestion did not match a current bullet.');}));card.append(suggest);}
        list.append(card);
      });
      section.append(list);editor.append(section);
    });
    if(aiEnabled)renderAutofixTargets();
  }
  let previewTimer;
  async function updatePreview() {
    previewState.textContent='Updating…';
    try { document.getElementById('cvPreview').srcdoc=renderer.html(state); previewState.textContent='Up to date · local'; }
    catch(error){previewState.textContent='Preview unavailable';}
  }
  function renderAtsTextWarnings(warnings){
    atsTextWarnings.replaceChildren();
    if(!warnings.length){const note=document.createElement('p');note.className='ats-text-clear';note.textContent='No obvious extraction warnings were detected. Review the extracted text below for accuracy.';atsTextWarnings.append(note);return;}
    warnings.forEach(warning=>{const item=document.createElement('article');item.className='ats-text-warning';const title=document.createElement('strong');title.textContent=warning.title;const message=document.createElement('p');message.textContent=warning.message;item.append(title,message);atsTextWarnings.append(item);});
  }
  async function updateAtsTextPreview(){
    if(atsPreviewLoading)return;
    atsPreviewLoading=true;refreshAtsTextButton.disabled=true;refreshAtsTextButton.textContent='Checking…';atsTextStatus.textContent='Building text order locally from the current CV…';atsTextWarnings.replaceChildren();
    const requestSnapshot=JSON.stringify(state);
    try{
      const text=renderer.text(state),warnings=[];
      if(JSON.stringify(state)!==requestSnapshot){atsPreviewDirty=true;atsTextStatus.textContent='The CV changed while this check was running. Check the PDF text again.';return;}
      const lines=text.split(/\r?\n/);
      const fragment=lines.find(line=>/(?:^|\s)(?:0?[1-9]|1[0-2])\/(?:0?[1-9]|[12]\d|3[01])?\s*$/.test(line));
      if(fragment)warnings.push({title:'Possible split numeric date',message:`A date fragment ends a line: ${fragment.trim()}. Check the exported PDF after printing.`});
      const expected=[];
      if(state.enabled_sections.summary&&state.summary.trim())expected.push('SUMMARY');
      [['skills','SKILLS'],['experience','EXPERIENCE'],['education','EDUCATION'],['projects','PROJECTS'],['certifications','CERTIFICATIONS'],['languages','LANGUAGES']].forEach(([key,label])=>{if(state.enabled_sections[key]&&(state[key]||[]).length)expected.push(label);});
      if(state.enabled_sections.custom_sections)(state.custom_sections||[]).forEach(row=>{const heading=String(row.title||'').trim().toLocaleUpperCase();if(heading&&!expected.includes(heading))expected.push(heading);});
      let last=-1;expected.forEach(heading=>{const index=lines.findIndex(line=>line.trim().toLocaleUpperCase()===heading);if(index<0)warnings.push({title:'Section heading missing from text order',message:`The ${heading} heading was not found in the generated text.`});else if(index<last)warnings.push({title:'Section order differs',message:`The ${heading} heading appears out of order.`});else last=index;});
      atsTextPreview.textContent=text;copyAtsTextButton.disabled=!text;atsPreviewDirty=false;
      atsTextStatus.textContent=text?'Text order built locally. This is not a text extraction check of the final PDF.':'Add CV content to inspect its text order.';
      renderAtsTextWarnings(warnings);
    }catch(error){atsPreviewDirty=true;atsTextStatus.textContent='Text-order check failed: '+error.message;atsTextPreview.textContent='';copyAtsTextButton.disabled=true;}
    finally{atsPreviewLoading=false;refreshAtsTextButton.disabled=false;refreshAtsTextButton.textContent=atsPreviewDirty?'Check updated text order':'Refresh text order';}
  }
  function setPreviewMode(mode){
    const showAtsText=mode==='ats';
    visualPreviewTab.classList.toggle('is-active',!showAtsText);visualPreviewTab.setAttribute('aria-selected',String(!showAtsText));
    atsTextTab.classList.toggle('is-active',showAtsText);atsTextTab.setAttribute('aria-selected',String(showAtsText));
    document.getElementById('visualPreviewView').hidden=showAtsText;document.getElementById('atsTextView').hidden=!showAtsText;
    if(showAtsText&&atsPreviewDirty)updateAtsTextPreview();
  }
  visualPreviewTab.addEventListener('click',()=>setPreviewMode('visual'));
  atsTextTab.addEventListener('click',()=>setPreviewMode('ats'));
  refreshAtsTextButton.addEventListener('click',updateAtsTextPreview);
  copyAtsTextButton.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(atsTextPreview.textContent);flash('CV text copied.');}catch(error){flash('Clipboard access is unavailable in this browser.');}});
  function schedulePreview(){clearTimeout(previewTimer);atsPreviewDirty=true;copyAtsTextButton.disabled=true;if(!atsPreviewLoading&&atsTextStatus)atsTextStatus.textContent='The CV changed. Check the updated PDF text when ready.';previewTimer=setTimeout(updatePreview,350);}
  async function refreshDrafts(){
    const list=document.getElementById('draftList');list.replaceChildren();
    try{
      const response=await fetch(base+'index.php?action=drafts_list',{cache:'no-store'});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Draft storage is unavailable.');
      if(!data.drafts.length){const p=document.createElement('p');p.className='muted';p.textContent='No saved drafts yet.';list.append(p);return;}
      for(const draft of data.drafts){
        const row=document.createElement('div');row.className='draft-row';const name=document.createElement('div');name.className='draft-name';
        const strong=document.createElement('strong');
        if(draft.encrypted){
          if(vault.unlocked&&draft.name_envelope){try{strong.textContent=await vault.decryptName(draft.id,draft.name_envelope);}catch(_){strong.textContent='Encrypted draft';}}
          else strong.textContent=`Encrypted draft ${draft.id}`;
        }else strong.textContent=draft.draft_name||`Legacy draft ${draft.id}`;
        const date=document.createElement('span');date.textContent=(draft.updated_at||'')+(draft.plaintext_remaining?' · legacy plaintext remains':'');name.append(strong,date);
        const actions=document.createElement('div');actions.className='draft-actions';
        const load=document.createElement('button');load.type='button';load.textContent='Load';load.addEventListener('click',()=>loadDraft(draft.id));
        const remove=document.createElement('button');remove.type='button';remove.textContent='Delete';remove.addEventListener('click',()=>deleteDraft(draft.id));
        actions.append(load,remove);row.append(name,actions);list.append(row);
      }
    }catch(error){const p=document.createElement('p');p.className='muted';p.textContent=error.message||'Draft storage is unavailable. Check the database configuration and encrypted-draft migration.';list.append(p);}
  }
  async function getDraftRecord(id){const r=await fetch(base+'index.php?action=draft_get&id='+encodeURIComponent(id),{cache:'no-store'});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Draft could not be loaded.');return d;}
  async function loadDraft(id){
    try{
      if(!vault.unlocked)throw new Error('Unlock the encrypted vault before loading drafts.');
      const d=await getDraftRecord(id);let record;
      if(d.encrypted)record=await vault.decryptDraft(Number(id),d.envelope);
      else record={draft_name:d.draft_name,cv:d.cv};
      state=normalizeState(record.cv);draftId=Number(id);renderEditor();schedulePreview();document.getElementById('draftName').value=record.draft_name;flash(d.encrypted?'Encrypted draft loaded.':`Legacy draft loaded. Save it to encrypt it.`);
    }catch(error){flash(error.message);}
  }
  async function deleteDraft(id){if(!window.confirm('Delete this saved draft?'))return;try{await post('delete_draft',{id});migrationVerified=false;if(draftId===Number(id))draftId=0;refreshDrafts();refreshMigrationStatus();flash('Draft deleted.');}catch(error){flash(error.message);}}
  document.getElementById('saveDraft').addEventListener('click',()=>{document.getElementById('draftName').value= document.getElementById('draftName').value || state.personal.name || '';document.getElementById('saveDialog').showModal();});
  document.getElementById('confirmSave').addEventListener('click',async event=>{
    event.preventDefault();const name=cleanText(document.getElementById('draftName').value).slice(0,255);if(!name){flash('Enter a name for this draft.');return;}if(!vault.unlocked){flash('Unlock the encrypted vault before saving drafts.');return;}
    let reservedId=0;
    try{
      state=normalizeState(state);
      let id=draftId;
      if(!id){const reserved=await post('draft_reserve');id=Number(reserved.id);reservedId=id;}
      const encrypted=await vault.encryptDraft(id,name,state);
      await post('draft_save_encrypted',{id:String(id),envelope:JSON.stringify(encrypted.payload),name_envelope:JSON.stringify(encrypted.name)});
      draftId=id;migrationVerified=false;document.getElementById('saveDialog').close();refreshDrafts();refreshMigrationStatus();flash('Encrypted draft saved.');
    }catch(error){if(reservedId){try{await post('delete_draft',{id:String(reservedId)});}catch(_){}}flash(error.message);}
  });
  document.getElementById('refreshDrafts').addEventListener('click',refreshDrafts);
  document.getElementById('newCv').addEventListener('click',()=>{if(!window.confirm('Start a new CV? Unsaved edits will be cleared.'))return;state=emptyCv();draftId=0;document.getElementById('draftName').value='';renderEditor();schedulePreview();});
  document.getElementById('exportCv').addEventListener('click',async()=>{
    const format=document.getElementById('exportFormat').value;
    try{
      const filename=(String(state.personal.name||'cv').trim().replace(/[^\p{L}\p{N}_-]+/gu,'-').replace(/^-|-$/g,'').slice(0,60)||'cv');
      let blob,extension=format;
      if(format==='txt')blob=new Blob([renderer.text(state)],{type:'text/plain;charset=utf-8'});
      else if(format==='html')blob=new Blob([renderer.html(state)],{type:'text/html;charset=utf-8'});
      else if(format==='docx')blob=await renderer.docx(state);
      else if(format==='pdf'){
        const printWindow=window.open('about:blank','_blank');
        if(!printWindow)throw new Error('Allow pop-ups for this site to open the browser print dialog.');
        printWindow.document.open();printWindow.document.write(renderer.html(state));printWindow.document.close();printWindow.focus();
        setTimeout(()=>{try{printWindow.print();}catch(_){flash('Use the new tab’s browser menu to print or save as PDF.');}},500);
        return;
      }else throw new Error('Choose a supported export format.');
      const url=URL.createObjectURL(blob),link=document.createElement('a');link.href=url;link.download=`${filename}.${extension}`;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
    }catch(error){flash(error.message);}
  });
  async function requestRewrite(task, selectedText, applySuggestion){
    if(!document.getElementById('aiConsent').checked){flash(`Confirm the ${aiProvider} disclosure first.`);return;}
    if(!selectedText.trim()){flash('Add text before requesting a rewrite.');return;}
    const output=document.getElementById('aiSuggestions');output.textContent=`Asking ${aiProvider}…`;
    try{
      const result=await post('ai_suggest',{task,ai_consent:'yes',selected_text:selectedText,job_description:document.getElementById('targetJob').value});
      output.replaceChildren();
      if(!result.result.suggestions.length){output.textContent='No suggestion was returned.';return;}
      result.result.suggestions.forEach(s=>{
        const card=document.createElement('div');card.className='suggestion-card';const original=document.createElement('p');original.textContent='Original: '+s.original;const rewrite=document.createElement('p');rewrite.className='suggestion-text';rewrite.textContent=s.rewrite;const reason=document.createElement('p');reason.textContent=s.reason;
        const accept=document.createElement('button');accept.type='button';accept.className='button button-light';accept.textContent='Use this suggestion';accept.addEventListener('click',()=>applySuggestion(s));card.append(original,rewrite,reason,accept);output.append(card);
      });
    }catch(error){output.textContent=error.message;}
  }
  function getAutofixTarget(id){
    if(id==='summary')return state.summary;
    const match=/^experience:(\d+):bullet:(\d+)$/.exec(id);if(!match)return null;
    return normalizedExperienceRows()[Number(match[1])]?.bullets?.[Number(match[2])]??null;
  }
  function applyAutofix(fix,button){
    if(autofixStateSnapshot===null||JSON.stringify(state)!==autofixStateSnapshot){flash('The CV changed after this review. Run the autofixer again before applying suggestions.');return;}
    const current=getAutofixTarget(fix.id);
    if(current===null||String(current).trim()!==String(fix.original).trim()){flash('This CV text changed. Run the autofixer again before applying this suggestion.');return;}
    const match=/^experience:(\d+):bullet:(\d+)$/.exec(fix.id);
    if(fix.id==='summary')state.summary=fix.replacement;
    else if(match){const entry=normalizedExperienceRows()[Number(match[1])];if(!entry)return;entry.bullets[Number(match[2])]=fix.replacement;}
    else return;
    autofixStateSnapshot=JSON.stringify(state);
    renderEditor();schedulePreview();button.disabled=true;button.textContent='Applied to editor';
  }
  function renderAutofixResults(result){
    const output=document.getElementById('autofixResults');output.replaceChildren();
    const findingsHeading=document.createElement('h4');findingsHeading.textContent='Local ATS findings';output.append(findingsHeading);
    if(result.findings.length){
      result.findings.forEach(finding=>{const item=document.createElement('div');item.className='autofix-finding '+finding.severity;const title=document.createElement('strong');title.textContent=finding.title;const message=document.createElement('p');message.textContent=finding.message;item.append(title,message);output.append(item);});
    }else{const none=document.createElement('p');none.className='muted';none.textContent='No rule-based findings were detected.';output.append(none);}
    const fixesHeading=document.createElement('h4');fixesHeading.textContent='AI suggestions to review';output.append(fixesHeading);
    if(!result.fixes.length){const none=document.createElement('p');none.className='muted';none.textContent='No safe text rewrites were suggested. Some findings may need details only you can provide.';output.append(none);return;}
    result.fixes.forEach(fix=>{
      const card=document.createElement('article');card.className='suggestion-card autofix-card';
      const label=document.createElement('strong');label.textContent=fix.label;
      const original=document.createElement('p');original.className='autofix-before';original.textContent='Before: '+fix.original;
      const replacement=document.createElement('p');replacement.className='suggestion-text';replacement.textContent='Suggested: '+fix.replacement;
      const reason=document.createElement('p');reason.textContent=fix.reason;
      const apply=document.createElement('button');apply.type='button';apply.className='button button-light';apply.textContent='Apply to editor';apply.addEventListener('click',()=>applyAutofix(fix,apply));
      card.append(label,original,replacement,reason,apply);output.append(card);
    });
  }
  function localAutofixFindings(){
    const text=renderer.text(state).trim(),findings=[];
    const add=(severity,title,message,section)=>findings.push({severity,title,message,section});
    if(text.length<250)add('warning','Very little CV text was found','Add accurate detail or confirm that the CV content is complete.','Document text');
    if(!/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i.test(text))add('warning','Email address not detected','Include a plain-text email address in the contact section.','Contact details');
    const sections=[['Summary',/\b(summary|professional summary|profile|objective)\b/i],['Experience',/\b(experience|work history|employment history|professional experience)\b/i],['Education',/\b(education|academic background)\b/i],['Skills',/\b(skills|technical skills|core competencies)\b/i]];
    sections.forEach(([label,pattern])=>{if(!pattern.test(text))add('suggestion',`${label} section not detected`,'Use a conventional heading if this information applies to your background.',label);});
    if(!/(?:^|\s)(?:[•●▪◦]|[-*])\s+\S/m.test(text))add('suggestion','Few bullet points detected','Use concise bullets for accomplishments and responsibilities where they improve readability.','Experience and projects');
    if(!/\b(?:\d+%|\$\s?\d[\d,.]*|\b\d+\s+(?:users|customers|projects|people|hours|days))\b/i.test(text))add('suggestion','No measurable results detected','Where accurate, add evidence such as scope, time saved, quality, or delivery outcomes.','Experience and projects');
    return findings;
  }
  async function runAutofix(){
    if(!document.getElementById('aiConsent').checked){flash(`Confirm the ${aiProvider} disclosure first.`);return;}
    const selectedIds=[...document.querySelectorAll('#autofixTargets [data-autofix-target]:checked')].map(input=>input.dataset.autofixTarget);
    const targets=autofixTargets().filter(target=>selectedIds.includes(target.id));
    if(!targets.length){flash('Select at least one summary or experience bullet to review.');return;}
    if(targets.length>30){flash('Choose no more than 30 text items.');return;}
    const includeJob=document.getElementById('includeAutofixJob').checked,jobDescription=document.getElementById('targetJob').value;
    if(includeJob&&!jobDescription.trim()){flash('Add a target job description first, or leave that option unchecked.');return;}
    const output=document.getElementById('autofixResults');output.textContent=`Running local checks and asking ${aiProvider} for reviewable fixes…`;
    const requestSnapshot=JSON.stringify(state);
    const runButton=document.getElementById('runAutofix');runButton.disabled=true;
    try{
      const result=await post('ai_autofix',{ai_consent:'yes',selected_targets:JSON.stringify(targets),local_findings:JSON.stringify(localAutofixFindings()),include_job_description:includeJob?'yes':'no',job_description:includeJob?jobDescription:''});
      if(JSON.stringify(state)!==requestSnapshot){autofixStateSnapshot=null;output.textContent='The CV changed while the review was running. Run the autofixer again to review the current version.';return;}
      autofixStateSnapshot=requestSnapshot;
      renderAutofixResults(result.result);
    }catch(error){output.replaceChildren();const message=document.createElement('p');message.className='error-message';message.textContent=error.message;output.append(message);}
    finally{runButton.disabled=false;}
  }
  if (aiEnabled) document.getElementById('rewriteSummary').addEventListener('click',()=>requestRewrite('rewrite_summary',state.summary,suggestion=>{state.summary=suggestion.rewrite;renderEditor();schedulePreview();flash('Suggestion applied.');}));
  if (aiEnabled) {
    document.getElementById('selectAutofixTargets').addEventListener('click',()=>{let selected=0;document.querySelectorAll('#autofixTargets [data-autofix-target]').forEach(input=>{input.checked=!input.disabled&&selected<30;if(input.checked)selected++;});updateAutofixSelectionCount();});
    document.getElementById('clearAutofixTargets').addEventListener('click',()=>{document.querySelectorAll('#autofixTargets [data-autofix-target]').forEach(input=>input.checked=false);updateAutofixSelectionCount();});
    document.getElementById('runAutofix').addEventListener('click',runAutofix);
  }
  const vaultLocked=document.getElementById('vaultLocked');
  const vaultUnlocked=document.getElementById('vaultUnlocked');
  const vaultRecoveryConfirm=document.getElementById('vaultRecoveryConfirm');
  const vaultFeedback=document.getElementById('vaultFeedback');
  function vaultNotice(message,error=false){vaultFeedback.textContent=message||'';vaultFeedback.classList.toggle('is-error',error);}
  function showVaultLocked(setup=false){
    vaultLocked.hidden=false;vaultUnlocked.hidden=true;vaultRecoveryConfirm.hidden=true;
    document.getElementById('vaultConfirmWrap').hidden=!setup;
    document.getElementById('vaultPassphraseConfirm').required=setup;
    document.getElementById('vaultUnlockButton').textContent=setup?'Prepare encrypted vault':'Unlock vault';
    document.getElementById('vaultBadge').textContent='Locked';document.getElementById('vaultBadge').classList.remove('vault-active');
    document.querySelector('.vault-recovery').hidden=!vaultMetadata;
  }
  function showVaultUnlocked(){
    vaultLocked.hidden=true;vaultUnlocked.hidden=false;vaultRecoveryConfirm.hidden=true;
    document.getElementById('vaultBadge').textContent='Unlocked in this tab';document.getElementById('vaultBadge').classList.add('vault-active');
    document.querySelector('.vault-recovery').hidden=false;vaultNotice('Vault unlocked in this browser tab. Lock it before leaving a shared computer.');
    refreshDrafts();refreshMigrationStatus();
  }
  async function getVaultMetadata(){
    const response=await fetch(base+'index.php?action=vault_meta',{cache:'no-store'}),result=await response.json();
    if(!response.ok||!result.ok)throw new Error(result.error||'Encrypted draft storage is unavailable. Apply the database migration after making a verified backup.');
    return result.metadata;
  }
  async function initializeVaultUi(){
    try{vaultMetadata=await getVaultMetadata();showVaultLocked(!vaultMetadata);refreshDrafts();}
    catch(error){showVaultLocked(true);vaultNotice('Apply migrations/20261020_encrypted_cv_v1.sql after making a verified database backup. '+error.message,true);refreshDrafts();}
  }
  document.getElementById('vaultPassForm').addEventListener('submit',async event=>{
    event.preventDefault();vaultNotice('Deriving the vault key in this browser…');
    const passphrase=document.getElementById('vaultPassphrase').value;
    try{
      if(!vaultMetadata){
        const confirmation=document.getElementById('vaultPassphraseConfirm').value;
        if(passphrase!==confirmation)throw new Error('The vault passphrases do not match.');
        pendingVaultCandidate=await vault.createCandidate(passphrase);
        document.getElementById('vaultRecoveryDisplay').textContent=pendingVaultCandidate.recoveryKey;
        document.getElementById('recoverySaved').checked=false;document.getElementById('confirmVaultSetup').disabled=true;
        vaultLocked.hidden=true;vaultRecoveryConfirm.hidden=false;vaultNotice('Download or copy the recovery key and store it offline.');
      }else{
        await vault.unlockWithPassphrase(vaultMetadata,passphrase);document.getElementById('vaultPassphrase').value='';showVaultUnlocked();vaultNotice('Vault unlocked.');
      }
    }catch(error){vaultNotice(error.message==='The operation failed for an operation-specific reason'?'The passphrase is incorrect, or the vault metadata was changed.':error.message,true);}
  });
  document.getElementById('downloadRecoveryKey').addEventListener('click',()=>{
    if(!pendingVaultCandidate)return;
    const content=`ATS CV vault recovery key\n\nKeep this key offline and separate from your application server and database. Anyone with this key can decrypt the shared CV vault.\n\n${pendingVaultCandidate.recoveryKey}\n`;
    const url=URL.createObjectURL(new Blob([content],{type:'text/plain;charset=utf-8'})),link=document.createElement('a');link.href=url;link.download='ats-cv-recovery-key.txt';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
  });
  document.getElementById('recoverySaved').addEventListener('change',event=>{document.getElementById('confirmVaultSetup').disabled=!event.currentTarget.checked;});
  document.getElementById('confirmVaultSetup').addEventListener('click',async()=>{
    if(!pendingVaultCandidate||!document.getElementById('recoverySaved').checked)return;
    const button=document.getElementById('confirmVaultSetup');button.disabled=true;button.textContent='Creating…';
    try{
      const response=await vault.request('vault_initialize',{metadata:JSON.stringify(pendingVaultCandidate.metadata)});
      if(!response.created){
        pendingVaultCandidate.rawMaster.fill(0);pendingVaultCandidate=null;vaultMetadata=response.metadata;
        await vault.unlockWithPassphrase(vaultMetadata,document.getElementById('vaultPassphrase').value);
        document.getElementById('vaultPassphrase').value='';document.getElementById('vaultPassphraseConfirm').value='';showVaultUnlocked();vaultNotice('Another session already initialized the vault. The recovery key shown in this tab is not valid; use the existing recovery key.');return;
      }
      vaultMetadata=response.metadata;vault.activate(vaultMetadata,pendingVaultCandidate.rawMaster,pendingVaultCandidate.master);pendingVaultCandidate.rawMaster.fill(0);pendingVaultCandidate=null;
      document.getElementById('vaultPassphrase').value='';document.getElementById('vaultPassphraseConfirm').value='';showVaultUnlocked();vaultNotice('Encrypted vault created. Keep the recovery key offline.');
    }catch(error){vaultNotice(error.message,true);button.disabled=false;}
    finally{button.textContent='Create encrypted vault';}
  });
  document.getElementById('vaultRecoveryUnlock').addEventListener('click',async()=>{
    try{if(!vaultMetadata)throw new Error('Set up the vault before using a recovery key.');await vault.unlockWithRecovery(vaultMetadata,document.getElementById('vaultRecoveryInput').value);document.getElementById('vaultRecoveryInput').value='';showVaultUnlocked();vaultNotice('Vault unlocked with the recovery key. Change the vault passphrase if it was lost.');}
    catch(error){vaultNotice('The recovery key was not accepted, or the vault metadata was changed.',true);}
  });
  document.getElementById('changeVaultPassForm').addEventListener('submit',async event=>{
    event.preventDefault();const next=document.getElementById('newVaultPassphrase').value,confirm=document.getElementById('newVaultPassphraseConfirm').value;
    if(next!==confirm){vaultNotice('The new vault passphrases do not match.',true);return;}
    try{await vault.changePassphrase(next);vaultMetadata=vault.metadata;event.currentTarget.reset();vaultNotice('Vault passphrase changed. Existing drafts remain encrypted with the same vault key.');}
    catch(error){vaultNotice(error.message,true);}
  });
  document.getElementById('lockVault').addEventListener('click',()=>{
    if(!window.confirm('Lock the vault and clear the current CV from this editor? Unsaved edits will be discarded.'))return;
    vault.lock();state=emptyCv();draftId=0;document.getElementById('draftName').value='';renderEditor();schedulePreview();
    document.getElementById('cvPreview').srcdoc='';atsTextPreview.textContent='The vault is locked.';atsTextWarnings.replaceChildren();copyAtsTextButton.disabled=true;
    document.getElementById('targetJob')?.replaceChildren();if(document.getElementById('targetJob'))document.getElementById('targetJob').value='';
    document.getElementById('aiConsent')?.removeAttribute('checked');if(document.getElementById('aiConsent'))document.getElementById('aiConsent').checked=false;
    document.getElementById('includeAutofixJob')?.removeAttribute('checked');if(document.getElementById('includeAutofixJob'))document.getElementById('includeAutofixJob').checked=false;
    document.getElementById('aiSuggestions')?.replaceChildren();document.getElementById('autofixResults')?.replaceChildren();autofixStateSnapshot=null;
    showVaultLocked(false);refreshDrafts();vaultNotice('Vault locked. Browser memory clearing is best-effort.');
  });
  document.getElementById('migrateDrafts').addEventListener('click',async()=>{
    if(!vault.unlocked){vaultNotice('Unlock the vault first.',true);return;}
    if(!document.getElementById('backupConfirmed').checked){vaultNotice('Make and verify an offline database backup before migrating drafts.',true);return;}
    if(migrationBusy)return;migrationVerified=false;migrationBusy=true;migrationPauseRequested=false;const button=document.getElementById('migrateDrafts');button.disabled=true;document.getElementById('purgePlaintext').hidden=true;document.getElementById('pauseMigration').hidden=false;refreshMigrationStatus();
    try{
      const listResponse=await fetch(base+'index.php?action=drafts_list',{cache:'no-store'}),listData=await listResponse.json();if(!listResponse.ok||!listData.ok)throw new Error(listData.error||'Could not read draft list.');
      const drafts=listData.drafts;
      for(let index=0;index<drafts.length;index++){
        const item=drafts[index];document.getElementById('migrationStatus').textContent=`Encrypting and verifying draft ${index+1} of ${drafts.length}…`;
        let record=await getDraftRecord(item.id),legacyCopy=null;
        const legacyResponse=await fetch(base+'index.php?action=draft_migration_source&id='+encodeURIComponent(item.id),{cache:'no-store'});
        if(legacyResponse.ok){const legacyData=await legacyResponse.json();if(!legacyData.ok)throw new Error(legacyData.error||`Could not read draft ${item.id} for migration verification.`);legacyCopy=legacyData.legacy;}
        else if(legacyResponse.status!==404)throw new Error(`Could not read draft ${item.id} for migration verification.`);
        if(!record.encrypted){
          legacyCopy=legacyCopy||{draft_name:record.draft_name,cv:record.cv};
          const encrypted=await vault.encryptDraft(Number(item.id),record.draft_name,record.cv);
          await post('draft_migrate_save',{id:String(item.id),envelope:JSON.stringify(encrypted.payload),name_envelope:JSON.stringify(encrypted.name)});
          record=await getDraftRecord(item.id);
        }
        if(!record.encrypted)throw new Error(`Draft ${item.id} was not encrypted.`);
        const decrypted=await vault.decryptDraft(Number(item.id),record.envelope);
        const decryptedName=await vault.decryptName(Number(item.id),record.name_envelope);
        if((legacyCopy&&(JSON.stringify(decrypted.cv)!==JSON.stringify(legacyCopy.cv)||decrypted.draft_name!==legacyCopy.draft_name))||decryptedName!==decrypted.draft_name)throw new Error(`Draft ${item.id} could not be verified after encryption.`);
        if(migrationPauseRequested){document.getElementById('migrationStatus').textContent=`Migration paused after ${index+1} of ${drafts.length} drafts. Resume to continue.`;await refreshDrafts();await refreshMigrationStatus();return;}
      }
      migrationVerified=true;await refreshDrafts();await refreshMigrationStatus();document.getElementById('backupConfirmed').checked=false;vaultNotice('Every saved draft was decrypted and verified. Take and verify a fresh offline backup now, then confirm it before removing plaintext.');
    }catch(error){migrationVerified=false;vaultNotice(error.message,true);}
    finally{migrationBusy=false;button.disabled=false;document.getElementById('pauseMigration').hidden=true;refreshMigrationStatus();}
  });
  document.getElementById('pauseMigration').addEventListener('click',()=>{migrationPauseRequested=true;document.getElementById('migrationStatus').textContent='Pausing after the current draft…';});
  async function refreshMigrationStatus(){
    const label=document.getElementById('migrationStatus');if(!vault.unlocked){label.textContent='Unlock the vault to inspect and migrate saved drafts.';return;}
    try{
      const response=await fetch(base+'index.php?action=migration_status',{cache:'no-store'}),status=await response.json();if(!response.ok||!status.ok)throw new Error(status.error||'Could not read migration status.');
      label.textContent=`${status.encrypted} of ${status.total} drafts encrypted · ${status.plaintext_remaining} plaintext rows remain in the live database.`;
      document.getElementById('purgePlaintext').hidden=!(migrationVerified&&status.total>0&&status.encrypted===status.total&&status.plaintext_remaining>0);
      document.getElementById('purgePlaintext').disabled=migrationBusy;
      document.getElementById('migrateDrafts').disabled=migrationBusy||status.total===0;
    }catch(error){label.textContent='Migration status unavailable. Apply the encrypted-draft SQL migration after taking a verified backup.';}
  }
  document.getElementById('purgePlaintext').addEventListener('click',async()=>{
    if(migrationBusy)return;
    if(!document.getElementById('backupConfirmed').checked){vaultNotice('Make and verify an offline database backup before removing plaintext.',true);return;}
    const statusResponse=await fetch(base+'index.php?action=migration_status',{cache:'no-store'}),status=await statusResponse.json();
    if(!statusResponse.ok||!status.ok||status.encrypted!==status.total||!migrationVerified){vaultNotice('Run the full encrypt and verify pass before removing plaintext.',true);return;}
    if(!window.confirm('This removes readable draft names and CV JSON from the live database. The offline backup will still contain plaintext.'))return;
    if(window.prompt('Type REMOVE PLAINTEXT to continue:')!=='REMOVE PLAINTEXT')return;
    try{const result=await post('draft_purge_plaintext',{confirmation:'REMOVE PLAINTEXT',backup_confirmed:'yes'});migrationVerified=false;vaultNotice(`${result.cleared} plaintext draft row(s) cleared from the live database.`);await refreshMigrationStatus();await refreshDrafts();}
    catch(error){vaultNotice(error.message,true);}
  });
  document.querySelector('.nav-logout')?.addEventListener('submit',()=>vault.lock());
  window.addEventListener('pagehide',()=>vault.lock());
  window.addEventListener('pageshow',event=>{if(!event.persisted)return;vault.lock();migrationVerified=false;state=emptyCv();draftId=0;renderEditor();schedulePreview();document.getElementById('cvPreview').srcdoc='';showVaultLocked(false);refreshDrafts();});
  window.addEventListener('message',event=>{
    if(event.origin!==window.location.origin||event.source!==window.opener||event.data?.type!=='ats-cv-import')return;
    if(!event.data.cv||typeof event.data.cv!=='object')return;
    state=normalizeState(event.data.cv);draftId=0;renderEditor();schedulePreview();document.getElementById('draftName').value=state.personal?.name||'';
    flash('Imported CV content is ready for review. Check each field before saving.');
    window.opener.postMessage({type:'ats-cv-import-received',transferId:event.data.transferId},window.location.origin);
  });
  renderEditor();schedulePreview();initializeVaultUi();
})();

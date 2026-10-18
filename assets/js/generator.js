(() => {
  const base = window.ATS_BASE_URL;
  const aiProvider = window.ATS_AI_PROVIDER || 'configured AI provider';
  const aiEnabled = window.ATS_AI_ENABLED === true;
  const initial = document.getElementById('initialCv');
  const emptyCv = () => ({schema_version:1,personal:{name:'',title:'',phone:'',email:'',location:'',linkedin:'',github:''},enabled_sections:{summary:true,skills:true,experience:true,education:true,projects:true,certifications:true,languages:true,custom_sections:true},summary:'',skills:[],experience:[],education:[],projects:[],certifications:[],languages:[],custom_sections:[]});
  let state = JSON.parse(initial?.textContent || '{}');
  if (!state.personal) state = emptyCv();
  let draftId = 0;
  let toastTimer;
  let autofixStateSnapshot = null;
  const editor = document.getElementById('editorFields');
  const previewState = document.getElementById('previewState');
  const toast = document.getElementById('appToast');
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
    control.addEventListener('input', () => {setter(multiline && key === 'bullets' ? control.value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean) : control.value);if(aiEnabled&&['summary','bullets'].includes(key))renderAutofixTargets();});
    wrap.append(control); parent.append(wrap);
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
    try { const result=await post('preview',{cv_json:JSON.stringify(state)}); document.getElementById('cvPreview').srcdoc=result.html; previewState.textContent='Up to date'; }
    catch(error){previewState.textContent='Preview unavailable';}
  }
  function schedulePreview(){clearTimeout(previewTimer);previewTimer=setTimeout(updatePreview,350);}
  async function refreshDrafts(){
    const list=document.getElementById('draftList');list.replaceChildren();
    try{
      const response=await fetch(base+'index.php?action=drafts_list',{cache:'no-store'});const data=await response.json();if(!data.ok)throw new Error(data.error);
      if(!data.drafts.length){const p=document.createElement('p');p.className='muted';p.textContent='No saved drafts yet.';list.append(p);return;}
      data.drafts.forEach(draft=>{
        const row=document.createElement('div');row.className='draft-row';const name=document.createElement('div');name.className='draft-name';
        const strong=document.createElement('strong');strong.textContent=draft.draft_name;const date=document.createElement('span');date.textContent=draft.updated_at||'';name.append(strong,date);
        const actions=document.createElement('div');actions.className='draft-actions';
        const load=document.createElement('button');load.type='button';load.textContent='Load';load.addEventListener('click',()=>loadDraft(draft.id,draft.draft_name));
        const remove=document.createElement('button');remove.type='button';remove.textContent='Delete';remove.addEventListener('click',()=>deleteDraft(draft.id));
        actions.append(load,remove);row.append(name,actions);list.append(row);
      });
    }catch(error){const p=document.createElement('p');p.className='muted';p.textContent='Draft storage is unavailable. Check the database configuration.';list.append(p);}
  }
  async function loadDraft(id,name){try{const r=await fetch(base+'index.php?action=draft_get&id='+encodeURIComponent(id),{cache:'no-store'});const d=await r.json();if(!d.ok)throw new Error(d.error);state=d.cv;draftId=Number(id);renderEditor();schedulePreview();document.getElementById('draftName').value=name;flash('Draft loaded.');}catch(error){flash(error.message);}}
  async function deleteDraft(id){if(!window.confirm('Delete this saved draft?'))return;try{await post('delete_draft',{id});if(draftId===Number(id))draftId=0;refreshDrafts();flash('Draft deleted.');}catch(error){flash(error.message);}}
  document.getElementById('saveDraft').addEventListener('click',()=>{document.getElementById('draftName').value= document.getElementById('draftName').value || state.personal.name || '';document.getElementById('saveDialog').showModal();});
  document.getElementById('confirmSave').addEventListener('click',async event=>{event.preventDefault();const name=document.getElementById('draftName').value.trim();if(!name){flash('Enter a draft name.');return;}try{const result=await post('save_draft',{draft_name:name,draft_id:String(draftId),cv_json:JSON.stringify(state)});draftId=result.id;document.getElementById('saveDialog').close();refreshDrafts();flash('Draft saved.');}catch(error){flash(error.message);}});
  document.getElementById('refreshDrafts').addEventListener('click',refreshDrafts);
  document.getElementById('newCv').addEventListener('click',()=>{if(!window.confirm('Start a new CV? Unsaved edits will be cleared.'))return;state=emptyCv();draftId=0;document.getElementById('draftName').value='';renderEditor();schedulePreview();});
  document.getElementById('exportCv').addEventListener('click',()=>{
    const form=document.createElement('form');form.method='post';form.action=base+'index.php';form.hidden=true;
    [['action','export'],['csrf_token',window.ATS_CSRF],['format',document.getElementById('exportFormat').value],['cv_json',JSON.stringify(state)]].forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value;form.append(input);});document.body.append(form);form.submit();form.remove();
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
  async function runAutofix(){
    if(!document.getElementById('aiConsent').checked){flash(`Confirm the ${aiProvider} disclosure first.`);return;}
    const targets=[...document.querySelectorAll('#autofixTargets [data-autofix-target]:checked')].map(input=>input.dataset.autofixTarget);
    if(!targets.length){flash('Select at least one summary or experience bullet to review.');return;}
    if(targets.length>30){flash('Choose no more than 30 text items.');return;}
    const includeJob=document.getElementById('includeAutofixJob').checked,jobDescription=document.getElementById('targetJob').value;
    if(includeJob&&!jobDescription.trim()){flash('Add a target job description first, or leave that option unchecked.');return;}
    const output=document.getElementById('autofixResults');output.textContent=`Running local checks and asking ${aiProvider} for reviewable fixes…`;
    const requestSnapshot=JSON.stringify(state);
    const runButton=document.getElementById('runAutofix');runButton.disabled=true;
    try{
      const result=await post('ai_autofix',{ai_consent:'yes',cv_json:JSON.stringify(state),target_ids:JSON.stringify(targets),include_job_description:includeJob?'yes':'no',job_description:includeJob?jobDescription:''});
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
  renderEditor();schedulePreview();refreshDrafts();
})();

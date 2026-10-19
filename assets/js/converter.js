(() => {
  const form=document.getElementById('importForm'), out=document.getElementById('importResult');
  const base=window.ATS_BASE_URL, provider=window.ATS_AI_PROVIDER||'configured AI provider', aiEnabled=window.ATS_AI_ENABLED===true;
  const node=(tag,className,text)=>{const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=text;return el;};
  async function post(data){const response=await fetch(base+'index.php',{method:'POST',body:data});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Request failed.');return result;}
  function transferToGenerator(cv,button){
    const targetOrigin=new URL(base,window.location.href).origin,child=window.open(base+'?page=generator','_blank');
    if(!child){out.append(node('div','error-message','Allow pop-ups for this site to transfer the imported CV to the generator.'));return;}
    const transferId=`${Date.now()}-${Math.random().toString(36).slice(2)}`;let attempts=0;
    button.disabled=true;button.textContent='Opening generator…';
    const listener=event=>{if(event.origin!==targetOrigin||event.source!==child||event.data?.type!=='ats-cv-import-received'||event.data.transferId!==transferId)return;window.removeEventListener('message',listener);clearInterval(timer);button.textContent='CV sent to generator';};
    window.addEventListener('message',listener);
    const timer=setInterval(()=>{
      if(child.closed||attempts++>80){clearInterval(timer);window.removeEventListener('message',listener);button.disabled=false;button.textContent='Review mapping in generator →';return;}
      child.postMessage({type:'ats-cv-import',transferId,cv},targetOrigin);
    },250);
  }

  form.addEventListener('submit',async event=>{
    event.preventDefault();out.replaceChildren(node('p','loading','Uploading the file for server-side extraction…'));const data=new FormData(form);data.append('action','import_file');
    try{
      const result=await post(data);out.replaceChildren();
      const box=node('div','import-notes');box.append(node('strong','',result.format+' text extracted'));const list=node('ul');(result.notes||[]).forEach(note=>list.append(node('li','',note)));box.append(list);out.append(box);
      const details=node('details','import-text-details');details.append(node('summary','','Show extracted source text'),node('pre','',result.text||''));out.append(details);
      const open=node('button','button button-primary full-button','Review mapping in generator →');open.type='button';open.addEventListener('click',()=>transferToGenerator(result.cv,open));out.append(open);
      if(aiEnabled)renderAiMapper(result.text||'',result.cv?.personal?.photo||'');
    }catch(error){out.replaceChildren(node('div','error-message',error.message));}
  });

  function renderAiMapper(extractedText, importedPhoto){
    const section=node('section','ai-box ai-import-box');
    section.append(node('h3','',`Optional ${provider} section mapping`));
    section.append(node('p','','The excerpt below is editable and is sent only if you request a mapping. Remove names or contact details, or narrow it first. Requests are limited to 16 KB. Review the provider’s current privacy and retention terms before sending text. The AI result is a suggestion; verify it in the generator before saving or exporting.'));
    const label=node('label','field-label','Text excerpt to send');label.htmlFor='aiImportText';section.append(label);
    const textArea=node('textarea','ai-import-text');textArea.id='aiImportText';textArea.rows=9;textArea.value=extractedText;section.append(textArea);
    const consentLabel=node('label','checkline');const consent=node('input');consent.type='checkbox';consent.id='aiImportConsent';consentLabel.append(consent,document.createTextNode(` I understand and want to send this excerpt to ${provider}.`));section.append(consentLabel);
    const suggest=node('button','button button-light',`Suggest mapping with ${provider}`);suggest.type='button';section.append(suggest);
    const resultBox=node('div','ai-import-result');resultBox.setAttribute('aria-live','polite');section.append(resultBox);out.append(section);

    suggest.addEventListener('click',async()=>{
      if(!consent.checked){resultBox.replaceChildren(node('p','error-message',`Confirm the ${provider} disclosure first.`));return;}
      if(!textArea.value.trim()){resultBox.replaceChildren(node('p','error-message','Enter the CV text you want mapped.'));return;}
      const request=new FormData();request.append('action','ai_import_map');request.append('csrf_token',window.ATS_CSRF);request.append('ai_consent','yes');request.append('cv_text',textArea.value);
      suggest.disabled=true;resultBox.replaceChildren(node('p','loading',`Asking ${provider} to suggest structured fields…`));
      try{
        const response=await post(request);resultBox.replaceChildren();
        const suggestedCv={...response.result.cv,personal:{...response.result.cv.personal,photo:importedPhoto||''}};
        const notes=node('ul','import-ai-notes');(response.result.notes||[]).forEach(note=>notes.append(node('li','',note)));if(notes.childNodes.length)resultBox.append(notes);
        const review=node('details','import-text-details');review.append(node('summary','','Review suggested fields'),node('pre','',JSON.stringify(suggestedCv,null,2)));resultBox.append(review);
        const use=node('button','button button-primary full-button','Review this suggested mapping in generator →');use.type='button';resultBox.append(use);
        use.addEventListener('click',()=>transferToGenerator(suggestedCv,use));
      }catch(error){resultBox.replaceChildren(node('div','error-message',error.message));}
      finally{suggest.disabled=false;}
    });
  }
})();

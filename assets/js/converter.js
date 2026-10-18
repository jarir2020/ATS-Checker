(() => {
  const form=document.getElementById('importForm'), out=document.getElementById('importResult');
  const base=window.ATS_BASE_URL, provider=window.ATS_AI_PROVIDER||'configured AI provider', aiEnabled=window.ATS_AI_ENABLED===true;
  const node=(tag,className,text)=>{const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=text;return el;};
  async function post(data){const response=await fetch(base+'index.php',{method:'POST',body:data});const result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'Request failed.');return result;}

  form.addEventListener('submit',async event=>{
    event.preventDefault();out.replaceChildren(node('p','loading','Extracting document text locally…'));const data=new FormData(form);data.append('action','import_file');
    try{
      const result=await post(data);out.replaceChildren();
      const box=node('div','import-notes');box.append(node('strong','',result.format+' text extracted'));const list=node('ul');(result.notes||[]).forEach(note=>list.append(node('li','',note)));box.append(list);out.append(box);
      const details=node('details','import-text-details');details.append(node('summary','','Show extracted source text'),node('pre','',result.text||''));out.append(details);
      const link=node('a','button button-primary full-button','Review current mapping in generator →');link.href=base+'?page=generator&imported=1';out.append(link);
      if(aiEnabled)renderAiMapper(result.text||'');
    }catch(error){out.replaceChildren(node('div','error-message',error.message));}
  });

  function renderAiMapper(extractedText){
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
        const notes=node('ul','import-ai-notes');(response.result.notes||[]).forEach(note=>notes.append(node('li','',note)));if(notes.childNodes.length)resultBox.append(notes);
        const review=node('details','import-text-details');review.append(node('summary','','Review suggested fields'),node('pre','',JSON.stringify(response.result.cv,null,2)));resultBox.append(review);
        const use=node('button','button button-primary full-button','Review this suggested mapping in generator →');use.type='button';resultBox.append(use);
        use.addEventListener('click',async()=>{
          use.disabled=true;use.textContent='Preparing generator…';
          const stage=new FormData();stage.append('action','stage_import');stage.append('csrf_token',window.ATS_CSRF);stage.append('cv_json',JSON.stringify(response.result.cv));
          try{await post(stage);window.location.assign(base+'?page=generator&imported=1');}
          catch(error){use.disabled=false;use.textContent='Review this suggested mapping in generator →';resultBox.append(node('div','error-message',error.message));}
        });
      }catch(error){resultBox.replaceChildren(node('div','error-message',error.message));}
      finally{suggest.disabled=false;}
    });
  }
})();

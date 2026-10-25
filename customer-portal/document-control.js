/* Read-only ANKH documentation overview filtering. No server mutations. */
(() => {
  'use strict';
  const list=document.getElementById('ankh-doc-list');
  if(!list)return;
  const search=document.getElementById('ankh-doc-search');
  const filter=document.getElementById('ankh-doc-filter');
  const result=document.getElementById('ankh-doc-results');
  const empty=document.getElementById('ankh-doc-empty');
  const copy=document.getElementById('ankh-doc-copy');
  const records=[...list.querySelectorAll('[data-document-product]')];
  const missing=()=>records.filter(el=>el.dataset.sds==='missing')
      .map(el=>'☐ '+el.querySelector('.ankh-doc-product-main strong')?.textContent.trim())
      .filter(Boolean);
  const update=()=>{
    const term=(search?.value||'').trim().toLocaleLowerCase();
    const mode=filter?.value||'all';
    let shown=0;
    records.forEach(el=>{
      const status=el.dataset.sds;
      const ok=(!term||el.dataset.name.includes(term)||(el.dataset.id||'').includes(term))
        && (mode==='all'||(mode==='missing'&&status==='missing')
          ||(mode==='review'&&status!=='reviewed')
          ||(mode==='coa'&&el.dataset.coa==='no')
          ||(mode==='ready'&&status==='reviewed'));
      el.hidden=!ok;if(ok)shown++;
    });
    if(result)result.textContent=shown+' of '+records.length+' live product'+(records.length===1?'':'s')+' shown';
    if(empty)empty.hidden=shown!==0;
  };
  search?.addEventListener('input',update);
  filter?.addEventListener('change',update);
  copy?.addEventListener('click',async()=>{
    const lines=missing();
    if(!lines.length){copy.textContent='No missing SDS records';return;}
    const content='ANKH — no SDS recorded ('+lines.length+')\n'+lines.join('\n');
    const original=copy.textContent;
    try{
      if(navigator.clipboard?.writeText)await navigator.clipboard.writeText(content);
      else {
        const area=document.createElement('textarea');area.value=content;
        area.style.position='fixed';area.style.opacity='0';document.body.append(area);
        area.select();if(!document.execCommand('copy'))throw new Error('Copy unavailable');
        area.remove();
      }
      copy.textContent='✓ Missing SDS list copied';
    }catch(_){copy.textContent='Copy unavailable on this browser';}
    setTimeout(()=>{copy.textContent=original;},2600);
  });
  update();
})();

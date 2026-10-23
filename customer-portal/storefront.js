/* Progressive enhancements only: the server-rendered ANKH storefront works without JS. */
(() => {
  'use strict';
  const search = document.getElementById('sf-search');
  const suggestions = document.getElementById('sf-suggestions');
  const corpus = [...document.querySelectorAll('[data-suggest-name]')].map(node => ({
    name: node.dataset.suggestName || '',
    kind: node.dataset.suggestKind || '',
    href: node.dataset.suggestHref || ''
  }));
  if (search && suggestions) {
    let lastFocus = -1;
    const close = () => {suggestions.hidden = true; suggestions.replaceChildren(); search.setAttribute('aria-expanded','false'); lastFocus = -1;};
    const getItems = () => [...suggestions.querySelectorAll('a')];
    const update = () => {
      const query = search.value.trim().toLocaleLowerCase();
      suggestions.replaceChildren();
      lastFocus = -1;
      if(query.length < 2) {close(); return;}
      const found = corpus.filter(item => (item.name+' '+item.kind).toLocaleLowerCase().includes(query)).slice(0,6);
      if(!found.length) {close();return;}
      const heading=document.createElement('span'); heading.className='sf-suggest-label'; heading.textContent='QUICK RESULTS';suggestions.append(heading);
      found.forEach(item=>{
        const link=document.createElement('a');link.href=item.href;
        const name=document.createElement('strong');name.textContent=item.name;
        const kind=document.createElement('small');kind.textContent=item.kind;
        link.append(name,kind);suggestions.append(link);
      });
      suggestions.hidden=false;search.setAttribute('aria-expanded','true');
    };
    search.addEventListener('input',update);
    search.addEventListener('keydown',event=>{
      if(suggestions.hidden)return;
      const items=getItems();
      if(event.key==='Escape'){close();return;}
      if(event.key==='ArrowDown'){event.preventDefault();lastFocus=Math.min(items.length-1,lastFocus+1);items[lastFocus]?.focus();}
    });
    suggestions.addEventListener('keydown',event=>{
      const items=getItems(),focused=items.indexOf(document.activeElement);
      if(event.key==='Escape'){close();search.focus();}
      if(event.key==='ArrowDown'){event.preventDefault();items[Math.min(items.length-1,focused+1)]?.focus();}
      if(event.key==='ArrowUp'){event.preventDefault();if(focused<=0)search.focus();else items[focused-1]?.focus();}
    });
    document.addEventListener('pointerdown',event=>{
      if(!suggestions.contains(event.target) && event.target!==search)close();
    });
    search.setAttribute('aria-expanded','false');
  }
  const dialog = document.getElementById('sf-quick');
  if(dialog && typeof dialog.showModal === 'function'){
    document.querySelectorAll('[data-quick-name]').forEach(button=>{
      button.addEventListener('click',()=>{
        const data=button.dataset;
        const photo=document.getElementById('sf-quick-photo');
        const fallback=document.getElementById('sf-quick-fallback');
        document.getElementById('sf-quick-title').textContent=data.quickName || '';
        document.getElementById('sf-quick-kind').textContent=data.quickKind || '';
        document.getElementById('sf-quick-summary').textContent=data.quickSummary || 'Explore the technical product profile.';
        const target=document.getElementById('sf-quick-link');
        target.href=data.quickHref || './';
        if(data.quickPhoto){
          photo.src=data.quickPhoto;photo.alt=data.quickName || 'Product photo';photo.hidden=false;fallback.hidden=true;
        }else {photo.hidden=true;photo.removeAttribute('src');fallback.hidden=false;}
        dialog.showModal();
      });
    });
    dialog.querySelector('[data-close-quick]')?.addEventListener('click',()=>dialog.close());
    dialog.addEventListener('click',event=>{
      if(event.target===dialog)dialog.close();
    });
  }
  // Opt-in, on-device shortlist. No personal data, order or medicine purchase.
  const shortlistKey='ankh-public-research-shortlist-v1';
  const getSaved=()=>{
    try{
      const ids=JSON.parse(window.localStorage.getItem(shortlistKey)||'[]');
      return Array.isArray(ids)?new Set(ids.filter(x=>Number.isInteger(x)&&x>0).slice(0,100)) : new Set();
    }catch(_){return new Set();}
  };
  let saved=getSaved();
  const repaintSaved=()=>{
    document.querySelectorAll('[data-saved-count]').forEach(el=>{el.textContent=String(saved.size);});
    document.querySelectorAll('[data-save-id]').forEach(button=>{
      const active=saved.has(Number(button.dataset.saveId));
      button.setAttribute('aria-pressed',active?'true':'false');
      button.classList.toggle('is-saved',active);
      if(button.classList.contains('sf-save-mini'))button.textContent=active?'♥ Saved':'♡ Save';
      else button.textContent=active?'♥ Saved to shortlist':'♡ Save to research shortlist';
    });
    const grid=document.querySelector('[data-saved-grid]'),empty=document.querySelector('[data-saved-empty]');
    if(grid&&empty){
      let visible=0;
      grid.querySelectorAll('[data-profile-id]').forEach(card=>{
        const show=saved.has(Number(card.dataset.profileId));card.hidden=!show;if(show)visible++;
      });
      grid.hidden=visible===0;empty.hidden=visible>0;
    }
  };
  document.querySelectorAll('[data-save-id]').forEach(button=>button.addEventListener('click',()=>{
    const id=Number(button.dataset.saveId);if(!Number.isInteger(id)||id<1)return;
    if(saved.has(id))saved.delete(id);else if(saved.size<100)saved.add(id);
    try{window.localStorage.setItem(shortlistKey,JSON.stringify([...saved]));}catch(_){}
    repaintSaved();
  }));
  window.addEventListener('storage',event=>{if(event.key===shortlistKey){saved=getSaved();repaintSaved();}});
  repaintSaved();
})();

/* ANKH atelier storefront: fast native interactions, no libraries or network requests. */
(() => {
  'use strict';
  const reduceMotion=window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches ?? false;

  // Swipable native scroll-snap gallery: real product photograph plus factual spec panel.
  const gallery=document.querySelector('[data-gallery]');
  if(gallery){
    const track=gallery.querySelector('.sf-gallery-track');
    const slides=[...gallery.querySelectorAll('.sf-gallery-slide')];
    const selectors=[...gallery.querySelectorAll('[data-gallery-go]')];
    const go=index=>{
      const next=(index+slides.length)%slides.length;
      track.scrollTo({left:next*track.clientWidth,behavior:reduceMotion?'instant':'smooth'});
      selectors.forEach((button,i)=>button.setAttribute('aria-pressed',i===next?'true':'false'));
    };
    if(track && slides.length>1){
      selectors.forEach(button=>button.addEventListener('click',()=>go(Number(button.dataset.galleryGo))));
      gallery.querySelector('[data-gallery-prev]')?.addEventListener('click',()=>go(Math.round(track.scrollLeft/track.clientWidth)-1));
      gallery.querySelector('[data-gallery-next]')?.addEventListener('click',()=>go(Math.round(track.scrollLeft/track.clientWidth)+1));
      let frame=0;
      track.addEventListener('scroll',()=>{
        if(frame)cancelAnimationFrame(frame);
        frame=requestAnimationFrame(()=>{
          const index=Math.min(slides.length-1,Math.max(0,Math.round(track.scrollLeft/Math.max(track.clientWidth,1))));
          selectors.forEach((button,i)=>button.setAttribute('aria-pressed',i===index?'true':'false'));
        });
      },{passive:true});
      track.addEventListener('keydown',event=>{
        if(event.key==='ArrowLeft'||event.key==='ArrowRight'){
          event.preventDefault();go(Math.round(track.scrollLeft/Math.max(track.clientWidth,1))+(event.key==='ArrowLeft'?-1:1));
        }
      });
    }
    const zoom=document.getElementById('sf-product-zoom');
    if(zoom && typeof zoom.showModal==='function'){
      gallery.querySelector('[data-gallery-zoom]')?.addEventListener('click',()=>zoom.showModal());
      zoom.querySelector('[data-close-zoom]')?.addEventListener('click',()=>zoom.close());
      zoom.addEventListener('click',event=>{if(event.target===zoom)zoom.close();});
      const photo=zoom.querySelector('img');
      photo?.addEventListener('dblclick',()=>photo.classList.toggle('is-magnified'));
    }
  }

  // Nonblocking real-image placeholders. No fake timeout loading screens.
  document.querySelectorAll('.sf-card-v3 .sf-card-image img, .sf-gallery-photo img, .sf-hero-photo img').forEach(img=>{
    const frame=img.closest('.sf-card-image,.sf-photo-stage,.sf-hero-photo');
    if(!frame)return;
    if(!img.complete){
      frame.classList.add('sf-image-pending');
      const finish=()=>frame.classList.remove('sf-image-pending');
      img.addEventListener('load',finish,{once:true});
      img.addEventListener('error',finish,{once:true});
    }
  });

  // Light scroll reveals; content is never hidden when script/observer fails.
  if(!reduceMotion && 'IntersectionObserver' in window){
    const targets=[...document.querySelectorAll('.sf-card-v3, .sf-trust-columns article, .sf-process>div')];
    const observer=new IntersectionObserver(entries=>{
      entries.forEach(entry=>{
        if(entry.isIntersecting){
          entry.target.classList.add('sf-in-view');
          observer.unobserve(entry.target);
        }
      });
    },{rootMargin:'0px 0px 80px 0px',threshold:0.045});
    targets.forEach((item,i)=>{
      item.classList.add('sf-motion-prep');
      item.style.setProperty('--sf-reveal-stagger',String((i%4)*45)+'ms');
      observer.observe(item);
    });
  }

  // Instant local facets when the full published collection is initially present.
  // Server GET filters remain the authoritative fallback when JS is unavailable.
  const grid=document.getElementById('sf-product-grid');
  const form=document.getElementById('sf-search-form');
  const results=document.querySelector('[data-live-results]');
  if(!grid||!form||grid.dataset.fullCollection!=='1')return;
  const cards=[...grid.querySelectorAll('.sf-card-v3')];
  const search=form.querySelector('[name="q"]');
  const category=form.querySelector('[name="category"]');
  const strength=form.querySelector('[name="strength"]');
  const format=form.querySelector('[name="format"]');
  const sort=form.querySelector('[name="sort"]');
  const chips=[...document.querySelectorAll('.sf-category-chips a')];
  const empty=document.createElement('div');
  empty.className='sf-empty sf-instant-empty';empty.hidden=true;
  empty.innerHTML='<h3>No matching profiles.</h3><p>Try clearing a filter or searching for a different product.</p><button type="button">Clear filters ↗</button>';
  grid.after(empty);
  const clean=value=>String(value||'').toLocaleLowerCase('en-GB').trim();
  const status=document.createElement('span');
  status.className='sf-live-tag';status.textContent='LIVE FILTERS';
  form.querySelector('.sf-filter-apply')?.append(status);
  const refresh=(updateURL=false)=>{
    const phrase=clean(search?.value);
    const selectedCategory=category?.value||'All research';
    const selectedStrength=strength?.value||'';
    const selectedFormat=format?.value||'';
    let matched=cards.filter(card=>{
      if(selectedCategory!=='All research'&&card.dataset.filterKind!==selectedCategory)return false;
      if(selectedStrength&&card.dataset.filterStrength!==selectedStrength)return false;
      if(selectedFormat&&card.dataset.filterFormat!==selectedFormat)return false;
      const hay=clean(card.dataset.filterText);
      return !phrase||phrase.split(/\s+/).every(word=>hay.includes(word));
    });
    if(sort?.value==='strength')matched.sort((a,b)=>(a.dataset.filterStrength||'').localeCompare(b.dataset.filterStrength||'','en-GB',{numeric:true})||(a.dataset.filterName||'').localeCompare(b.dataset.filterName||''));
    else if(sort?.value==='family')matched.sort((a,b)=>(a.dataset.filterName||'').replace(/[0-9]+(?:\.[0-9]+)?\s*(?:mg|mcg|g|ml)/gi,'').localeCompare((b.dataset.filterName||'').replace(/[0-9]+(?:\.[0-9]+)?\s*(?:mg|mcg|g|ml)/gi,''),'en-GB')||(a.dataset.filterName||'').localeCompare(b.dataset.filterName||''));
    else matched.sort((a,b)=>(a.dataset.filterName||'').localeCompare(b.dataset.filterName||'','en-GB',{numeric:true}));
    const visible=new Set(matched);
    cards.forEach(card=>{
      card.hidden=!visible.has(card);
      if(visible.has(card))card.classList.add('sf-in-view');
    });
    matched.forEach(card=>grid.appendChild(card));
    if(results)results.firstChild.textContent=matched.length+' matching profile'+(matched.length===1?'':'s')+' ';
    empty.hidden=matched.length>0;grid.hidden=matched.length===0;
    chips.forEach(chip=>{
      const text=chip.dataset.categoryValue;
      const selected=text===selectedCategory;
      chip.classList.toggle('is-active',selected);
      if(selected)chip.setAttribute('aria-current','true');else chip.removeAttribute('aria-current');
    });
    if(updateURL){
      const params=new URLSearchParams();
      if(phrase)params.set('q',search.value.trim());
      if(selectedCategory!=='All research')params.set('category',selectedCategory);
      if(selectedStrength)params.set('strength',selectedStrength);
      if(selectedFormat)params.set('format',selectedFormat);
      if(sort?.value && sort.value!=='name')params.set('sort',sort.value);
      const next='./'+(params.toString()?'?'+params.toString():'')+'#collection';
      window.history.replaceState(null,'',next);
    }
  };
  let debounce=0;
  search?.addEventListener('input',()=>{
    window.clearTimeout(debounce);
    debounce=window.setTimeout(()=>refresh(true),55);
  });
  [category,strength,format,sort].forEach(select=>select?.addEventListener('change',()=>refresh(true)));
  chips.forEach(chip=>chip.addEventListener('click',event=>{
    const value=chip.dataset.categoryValue;
    if(!value||!category)return;
    event.preventDefault();
    category.value=value;
    refresh(true);
    document.getElementById('collection')?.scrollIntoView({behavior:reduceMotion?'instant':'smooth',block:'start'});
  }));
  empty.querySelector('button')?.addEventListener('click',()=>{
    if(search)search.value='';
    if(category)category.value='All research';
    if(strength)strength.value='';
    if(format)format.value='';
    if(sort)sort.value='name';
    refresh(true);
    search?.focus();
  });
  window.addEventListener('popstate',()=>window.location.reload());
  form.addEventListener('submit',event=>{event.preventDefault();refresh(true);});
})();

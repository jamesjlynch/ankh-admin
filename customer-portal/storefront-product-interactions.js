/* ANKH PRODUCT / Small, accessible interactions. No external dependencies.
   Never alters prices, stock, availability or ordering permissions. */
(() => {
  'use strict';
  if (!document.body || !document.body.classList.contains('sf-is-detail')) return;
  const bar = document.querySelector('[data-product-sticky-action]');
  const primary = document.querySelector('.sf-shop-inline [data-cart-id], .sf-reference-state');
  const footer = document.querySelector('.sf-footer');
  const dialogs = Array.from(document.querySelectorAll('dialog'));

  if (bar && primary) {
    // Derive visibility from actual live element positions on each scroll.
    // An observer alone can miss scroll transitions when browser viewports resize.
    const updateBar = () => {
      const primaryBottom = primary.getBoundingClientRect().bottom;
      const footerTop = footer ? footer.getBoundingClientRect().top : Infinity;
      const modalOpen = dialogs.some(dialog => dialog.open);
      const scrolledPastAction = primaryBottom < 72;
      const reachedFooter = footerTop < window.innerHeight - 108;
      const show = scrolledPastAction && !reachedFooter && !modalOpen
        && window.matchMedia('(max-width: 600px)').matches;
      bar.classList.toggle('is-visible', show);
      bar.inert = !show;
      bar.setAttribute('aria-hidden', show ? 'false' : 'true');
    };
    bar.inert = true;
    bar.setAttribute('aria-hidden', 'true');
    document.body.classList.add('sf-detail-interactions-ready');
    let frame = 0;
    const scheduleUpdate = () => {
      if (frame) return;
      frame = window.requestAnimationFrame(() => { frame = 0; updateBar(); });
    };
    window.addEventListener('scroll', scheduleUpdate, { passive: true });
    window.addEventListener('resize', scheduleUpdate, { passive: true });
    if ('MutationObserver' in window) {
      const modalObserver = new MutationObserver(scheduleUpdate);
      dialogs.forEach(dialog => modalObserver.observe(dialog, { attributes: true, attributeFilter: ['open'] }));
    }
    updateBar();
  }

  const share = document.querySelector('[data-share-product]');
  if (share) {
    const originalLabel = share.textContent;
    share.addEventListener('click', async () => {
      const title = share.getAttribute('data-share-title') || document.title;
      // Share only the canonical product address, not staff preview flags.
      const canonical = new URL(window.location.href);
      const params = new URLSearchParams(canonical.search);
      canonical.search = params.has('product') ? '?product=' + encodeURIComponent(params.get('product')) : '';
      canonical.hash = '';
      const url = canonical.toString();
      try {
        if (navigator.share) {
          await navigator.share({ title: title + ' | ANKH', url });
        } else if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(url);
          share.textContent = '✓ Link copied';
          window.setTimeout(() => { share.textContent = originalLabel; }, 2300);
        } else {
          // A native share/copy API is unavailable: display the link for manual copying.
          window.prompt('Copy product link', url);
        }
      } catch (err) {
        if (err?.name !== 'AbortError') {
          share.textContent = 'Unable to share';
          window.setTimeout(() => { share.textContent = originalLabel; }, 2300);
        }
      }
    });
  }

  // The product's published documents can be viewed in-app without losing the
  // current product. New-tab PDF access remains a fallback on every device.
  const documentViewer=document.getElementById('sf-doc-viewer');
  if(documentViewer && typeof documentViewer.showModal==='function'){
    const frame=document.getElementById('sf-doc-viewer-frame');
    const titleNode=document.getElementById('sf-doc-viewer-title');
    const statusNode=document.getElementById('sf-doc-viewer-status');
    const warning=document.getElementById('sf-doc-viewer-warning');
    const openLink=document.getElementById('sf-doc-viewer-open');
    const downloadLink=document.getElementById('sf-doc-viewer-download');
    const shareControl=document.getElementById('sf-doc-viewer-share');
    let activeUrl='';
    const allowedPaths=['/product-document.php','/product-specification.php','/product-safety-document.php'];
    document.querySelectorAll('#sf-product-documents .sf-product-doc-link').forEach(link=>{
      link.addEventListener('click',event=>{
        if(event.defaultPrevented||event.button!==0||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey)return;
        let target;
        try{target=new URL(link.getAttribute('href'),location.href);}catch(_){return;}
        if(target.origin!==location.origin||!allowedPaths.includes(target.pathname))return;
        event.preventDefault();
        const unverified=link.classList.contains('sf-product-doc-sds')
          && !!link.querySelector('strong')?.textContent.includes('Unverified');
        const name=link.querySelector('strong')?.textContent.trim()||'Product document';
        const metadata=link.querySelector('small')?.textContent.trim()||'Published ANKH reference';
        activeUrl=target.toString();
        if(titleNode)titleNode.textContent=name;
        if(statusNode)statusNode.textContent=metadata;
        if(warning)warning.hidden=!unverified;
        if(openLink)openLink.href=activeUrl;
        if(downloadLink){
          downloadLink.href=activeUrl;
          downloadLink.download=unverified?'ankh-unverified-reference.pdf':'ankh-product-document.pdf';
        }
        if(frame)frame.src=activeUrl;
        documentViewer.showModal();
      });
    });
    const close=()=>{
      if(documentViewer.open)documentViewer.close();
      if(frame)frame.removeAttribute('src');
      activeUrl='';
    };
    documentViewer.querySelectorAll('[data-doc-close]').forEach(button=>button.addEventListener('click',close));
    documentViewer.addEventListener('click',event=>{
      if(event.target===documentViewer)close();
    });
    documentViewer.addEventListener('close',()=>{if(frame)frame.removeAttribute('src');activeUrl='';});
    shareControl?.addEventListener('click',async()=>{
      if(!activeUrl)return;
      const original=shareControl.textContent;
      try{
        if(navigator.share)await navigator.share({title:titleNode?.textContent||'ANKH product document',url:activeUrl});
        else if(navigator.clipboard?.writeText){
          await navigator.clipboard.writeText(activeUrl);shareControl.textContent='✓ Link copied';
        }else{
          window.prompt('Copy document address',activeUrl);
        }
      }catch(error){if(error?.name!=='AbortError')shareControl.textContent='Open the PDF to share';}
      window.setTimeout(()=>{shareControl.textContent=original;},2300);
    });
  }
})();

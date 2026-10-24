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
})();

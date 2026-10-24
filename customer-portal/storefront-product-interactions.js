/* ANKH PRODUCT / Small, accessible interactions. No external dependencies.
   Never alters prices, stock, availability or ordering permissions. */
(() => {
  'use strict';
  if (!document.body || !document.body.classList.contains('sf-is-detail')) return;
  const bar = document.querySelector('[data-product-sticky-action]');
  const primary = document.querySelector('.sf-shop-inline [data-cart-id], .sf-reference-state');
  const dialogs = Array.from(document.querySelectorAll('dialog'));
  let primaryVisible = true;
  let footerVisible = false;

  if (bar && primary && 'IntersectionObserver' in window) {
    const updateBar = () => {
      const modalOpen = dialogs.some(dialog => dialog.open);
      const show = !primaryVisible && !footerVisible && !modalOpen
        && window.matchMedia('(max-width: 600px)').matches;
      bar.classList.toggle('is-visible', show);
      bar.inert = !show;
      bar.setAttribute('aria-hidden', show ? 'false' : 'true');
    };
    // The bar only takes the screen after the primary action scrolls out.
    // Without JavaScript the original product action remains usable.
    bar.inert = true;
    bar.setAttribute('aria-hidden', 'true');
    document.body.classList.add('sf-detail-interactions-ready');
    const actionObserver = new IntersectionObserver(entries => {
      for (const entry of entries) if (entry.target === primary) primaryVisible = entry.isIntersecting;
      updateBar();
    }, { threshold: 0, rootMargin: '0px 0px -92px 0px' });
    actionObserver.observe(primary);

    const footer = document.querySelector('.sf-footer');
    if (footer) {
      const footerObserver = new IntersectionObserver(entries => {
        for (const entry of entries) footerVisible = entry.isIntersecting;
        updateBar();
      }, { threshold: 0 });
      footerObserver.observe(footer);
    }
    const modalObserver = new MutationObserver(updateBar);
    dialogs.forEach(dialog => modalObserver.observe(dialog, { attributes: true, attributeFilter: ['open'] }));
    window.addEventListener('resize', updateBar, { passive: true });
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

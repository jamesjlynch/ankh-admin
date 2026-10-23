/* ANKH image-led admin presentation — read-only enhancements.
   Product images remain in customer_catalogue_content and are served by
   the authenticated catalogue-image endpoint. No stock/order mutations. */
(() => {
  'use strict';
  const payload = document.getElementById('ankh-visual-product-data');
  if (!payload) return;
  let products;
  try { products = JSON.parse(payload.textContent || '[]'); }
  catch (_) { return; }
  if (!Array.isArray(products)) return;
  const catalogue = products.filter(p => p && Number.isInteger(Number(p.id)));
  const byId = new Map(catalogue.map(p => [Number(p.id), p]));
  const norm = s => String(s || '').toLocaleLowerCase('en-GB').replace(/[\u2010-\u2015]/g, '-').replace(/\s+/g, ' ').trim();
  const byName = new Map(catalogue.map(p => [norm(p.name), p]));
  const photoProducts = catalogue.filter(p => p.has_photo).sort((a,b) => String(b.name).length - String(a.name).length);
  const find = value => {
    const text = norm(value);
    if (!text) return null;
    if (byName.has(text)) return byName.get(text);
    return photoProducts.find(p => {
      const name = norm(p.name);
      const i = text.indexOf(name);
      return i >= 0 && (i === 0 || /[\s×:·(]/.test(text[i-1])) && (i + name.length === text.length || /[\s·,)@]/.test(text[i+name.length]));
    }) || null;
  };
  const imageUrl = product => '?api=catalogue-image&id=' + encodeURIComponent(String(product.id));
  function picture(product, className = 'ankh-inline-thumb') {
    if (!product || !product.has_photo) return null;
    const wrapper = document.createElement('span');
    wrapper.className = className;
    wrapper.setAttribute('aria-hidden', 'true');
    const img = document.createElement('img');
    img.className = 'ankh-product-image';
    img.alt = '';
    img.loading = 'lazy';
    img.decoding = 'async';
    img.src = imageUrl(product);
    img.addEventListener('error', () => {
      const icon = wrapper.parentElement;
      if (icon?.classList.contains('ankh-has-photo')) {
        icon.classList.remove('ankh-has-photo');
        delete icon.dataset.ankhPhotoDone;
      }
      wrapper.remove();
    }, { once: true });
    wrapper.append(img);
    return wrapper;
  }
  function replaceIcon(icon, product) {
    if (!icon || !product?.has_photo || icon.dataset.ankhPhotoDone) return;
    const p = picture(product, 'ankh-icon-picture');
    if (!p) return;
    icon.dataset.ankhPhotoDone = '1';
    icon.classList.add('ankh-has-photo');
    icon.append(p);
  }
  function prependThumb(target, product, className = 'ankh-inline-thumb') {
    if (!target || target.dataset.ankhThumbDone || !product?.has_photo) return;
    const p = picture(product, className);
    if (!p) return;
    target.dataset.ankhThumbDone = '1';
    target.classList.add('ankh-with-thumb');
    target.prepend(p);
  }

  // Products: replace vial icons with original catalogue photographs.
  document.querySelectorAll('.product-catalog-grid .product-tile').forEach(tile => {
    const id = Number(tile.querySelector('input[name="id"]')?.value);
    const product = byId.get(id);
    if (!product) return;
    replaceIcon(tile.querySelector(':scope > summary .product-vial-icon'), product);
    if (product.tracked) {
      const summary = tile.querySelector(':scope > summary');
      if (summary && !summary.querySelector('.ankh-product-stock-badge')) {
        const b = document.createElement('span');
        b.className = 'ankh-product-stock-badge' + (Number(product.available) <= Number(product.low_at) ? ' is-low' : '');
        b.textContent = Number(product.available) + ' available';
        summary.append(b);
      }
    }
  });
  document.querySelectorAll('.hidden-product-grid .product-admin-card').forEach(card => {
    const product = byId.get(Number(card.querySelector('input[name="id"]')?.value));
    if (product) prependThumb(card.querySelector(':scope > summary > div'), product);
  });

  // Stock: keep the live physical/reserved/available figures and all editing controls intact.
  document.querySelectorAll('.stock-product-card[id^="stock-product-"]').forEach(card => {
    const product = byId.get(Number(card.id.replace('stock-product-','')));
    replaceIcon(card.querySelector('.stock-product-title .product-vial-icon'), product);
  });
  document.querySelectorAll('.ios-reorder-card').forEach(card => {
    const product = find(card.querySelector('h3')?.textContent);
    replaceIcon(card.querySelector('.ios-product-icon'), product);
  });
  document.querySelectorAll('.incoming-by-product-row, .stock-on-way-row, .stock-movement-row, .forecast-product-card').forEach(row => {
    const name = row.querySelector('strong,h3')?.textContent;
    prependThumb(row, find(name));
  });

  // Orders: small identification images, never replacing item quantities or prices.
  document.querySelectorAll('.order-list .line, .todo-accordion-products > span, .report-product-summary, .report-product-order, .report-product-row .report-product-link').forEach(item => {
    const product = find(item.dataset.ankhProductName || item.textContent);
    if (!product) return;
    prependThumb(item, product, item.matches('.todo-accordion-products > span') ? 'ankh-micro-thumb' : 'ankh-inline-thumb');
  });

  // Order creation uses its existing buttons and selection/format handlers.
  // Only add images to the result/strength buttons and selected product headers.
  function enhancePicker() {
    document.querySelectorAll('#product-results > .product-result').forEach(button => {
      if (button.dataset.ankhThumbDone) return;
      const base = norm(button.querySelector('strong')?.textContent);
      const matches = catalogue.filter(p => p.has_photo && norm(p.base || p.name) === base);
      const photo = matches[0];
      if (!photo) return;
      const strong = button.querySelector('strong'), sub = button.querySelector('span');
      if (!strong || !sub) return;
      const copy = document.createElement('span');
      copy.className = 'ankh-picker-copy';
      strong.before(copy); copy.append(strong, sub);
      prependThumb(button, photo);
    });
    const group = norm(document.querySelector('#strength-product-name')?.textContent);
    document.querySelectorAll('#strength-options > .strength-option').forEach(button => {
      if (button.dataset.ankhThumbDone) return;
      const strength = norm(button.querySelector('strong')?.textContent);
      const match = catalogue.find(p => p.has_photo && norm(p.base || p.name) === group && norm(p.strength) === strength);
      prependThumb(button, match, 'ankh-strength-thumb');
    });
    document.querySelectorAll('#selected-order-lines > .order-line-card').forEach(card => {
      const id = Number(card.querySelector('[data-line-product]')?.value);
      prependThumb(card.querySelector('.order-line-head'), byId.get(id) || find(card.dataset.productName), 'ankh-selected-thumb');
    });
    document.querySelectorAll('#check-products > .check-product-line').forEach(line => {
      prependThumb(line, find(line.textContent), 'ankh-inline-thumb');
    });
  }
  enhancePicker();
  ['product-results','strength-options','selected-order-lines','check-products'].forEach(id => {
    const target = document.getElementById(id);
    if (target) new MutationObserver(enhancePicker).observe(target, { childList:true });
  });

  // Grid/list preference is visual only; search, stock filters and links continue to work.
  function mountLayoutToggle(selector, gridSelector, key, defaultMode) {
    const head = document.querySelector(selector), grid = document.querySelector(gridSelector);
    if (!head || !grid || head.querySelector('.ankh-view-toggle')) return;
    const group = document.createElement('div');
    group.className = 'ankh-view-toggle';
    group.setAttribute('role','group');
    group.setAttribute('aria-label','Product layout');
    const modes = [['grid','▦','Grid'], ['list','☷','List']];
    let mode = defaultMode;
    try { const stored = localStorage.getItem('ankh.visual.' + key); if (stored === 'grid' || stored === 'list') mode = stored; } catch (_) {}
    const buttons = modes.map(([value,icon,label]) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'ankh-view-button';
      button.dataset.mode = value;
      button.setAttribute('aria-label',label + ' view');
      button.textContent = icon + ' ' + label;
      group.append(button);
      button.addEventListener('click', () => {
        mode = value;
        apply();
        try { localStorage.setItem('ankh.visual.' + key, mode); } catch (_) {}
      });
      return button;
    });
    function apply() {
      grid.classList.toggle('ankh-visual-list', mode === 'list');
      grid.classList.toggle('ankh-visual-grid', mode === 'grid');
      buttons.forEach(button => {
        const active = button.dataset.mode === mode;
        button.classList.toggle('active', active);
        button.setAttribute('aria-pressed',String(active));
      });
    }
    head.append(group);
    apply();
  }
  mountLayoutToggle('.product-catalog-heading', '.product-catalog-grid', 'products', 'grid');
  mountLayoutToggle('.stock-products-heading', '.stock-product-grid', 'stock', 'list');
})();

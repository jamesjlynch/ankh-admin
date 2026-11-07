/* ANKH OS — presentation-only motion for the Today screen.
   External file so the admin app can stay on a strict CSP. */
(() => {
  'use strict';

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const moneyFormat = new Intl.NumberFormat('en-GB', {
    style: 'currency',
    currency: 'GBP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
  const numberFormat = new Intl.NumberFormat('en-GB');

  const formatValue = (value, type) => type === 'money' ? moneyFormat.format(value / 100) : numberFormat.format(value);

  const countUp = (element) => {
    const finalText = element.textContent;
    const target = Number.parseInt(element.getAttribute('data-ankh-count') || '', 10);
    if (!Number.isFinite(target)) return;
    const type = element.getAttribute('data-ankh-format') || 'number';
    const delay = Number.parseInt(element.getAttribute('data-ankh-delay') || '0', 10) || 0;
    const duration = 900;
    const startAt = performance.now() + delay;
    const tick = (now) => {
      try {
        if (now < startAt) {
          requestAnimationFrame(tick);
          return;
        }
        const progress = Math.min(1, (now - startAt) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        element.textContent = formatValue(Math.round(target * eased), type);
        if (progress < 1) requestAnimationFrame(tick);
        else element.textContent = finalText;
      } catch {
        element.textContent = finalText;
      }
    };
    element.textContent = formatValue(0, type);
    requestAnimationFrame(tick);
  };

  const init = () => {
    if (reduceMotion) return;

    document.documentElement.classList.add('ankh-motion');

    const mainContent = document.getElementById('ankh-main');
    if (mainContent) mainContent.classList.add('ankh-page-enter');

    const revealSelector = [
      '#ankh-main > header',
      '#ankh-main .today-hero',
      '#ankh-main .dashboard-stat-card',
      '#ankh-main .focus-worklist .focus-order',
      '#ankh-main .dashboard-secondary',
      '#ankh-main .ux-mobile-actions a',
      '#ankh-main .panel',
      '#ankh-main .card',
      '#ankh-main .dashboard-panel',
      '#ankh-main .todo-board',
      '#ankh-main .todo-card',
      '#ankh-main .more-card',
      '#ankh-main .customer-card',
      '#ankh-main .product-tile',
      '#ankh-main .stock-product-card',
      '#ankh-main .fridge-overview-card',
      '#ankh-main .balance-account-card',
      '#ankh-main .customer-cycle-card',
      '#ankh-main .cycle-preview-card',
      '#ankh-main .supplier-order-card',
      '#ankh-main .future-order-card',
      '#ankh-main .report-metrics article',
      '#ankh-main .reportboard-kpi',
      '#ankh-main .stockboard-card',
      '#ankh-main .metric',
      '#ankh-main .order',
      '#ankh-main .list > *',
      '#ankh-main [class$="-list"] > *',
      '#ankh-main .customer-intelligence-grid > *'
    ].join(',');

    const seen = new Set();
    const revealTargets = [];
    const addTarget = (element) => {
      if (!element || seen.has(element)) return;
      seen.add(element);
      revealTargets.push(element);
    };
    document.querySelectorAll(revealSelector).forEach(addTarget);
    document.querySelectorAll('#ankh-main table tbody tr').forEach((row, index) => {
      if (index < 20) addTarget(row);
    });

    revealTargets.forEach((element, index) => {
      element.classList.add('ankh-reveal');
      element.style.setProperty('--ankh-delay', Math.min(index, 15) * 40 + 'ms');
      element.classList.add('ankh-in');
    });

    document.querySelectorAll('[data-ankh-sparkline]').forEach((element) => {
      requestAnimationFrame(() => requestAnimationFrame(() => element.classList.add('is-drawn')));
    });

    document.querySelectorAll('[data-ankh-count]').forEach((element, index) => {
      if (!element.hasAttribute('data-ankh-delay')) element.setAttribute('data-ankh-delay', String(Math.min(index, 6) * 55));
      countUp(element);
    });

    const pressSelector = 'button,a,summary,.dashboard-stat-card,.todo-card,.more-card,.fridge-overview-card';
    let pressed = null;
    const release = () => {
      if (pressed) pressed.classList.remove('ankh-press');
      pressed = null;
    };
    document.addEventListener('pointerdown', (event) => {
      if (event.button !== 0) return;
      const target = event.target instanceof Element ? event.target.closest(pressSelector) : null;
      if (!target) return;
      release();
      pressed = target;
      target.classList.add('ankh-press');
    }, { passive: true });
    document.addEventListener('pointerup', release, { passive: true });
    document.addEventListener('pointercancel', release, { passive: true });
    window.addEventListener('blur', release);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();

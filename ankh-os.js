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

  const TOAST_TIMEOUT = 3500;
  let toastRegion = null;

  const getToastRegion = () => {
    if (toastRegion && toastRegion.isConnected) return toastRegion;
    toastRegion = document.querySelector('[data-ankh-toast-region]');
    if (!toastRegion) {
      toastRegion = document.createElement('div');
      toastRegion.className = 'ankh-toast-region';
      toastRegion.dataset.ankhToastRegion = '';
      document.body.append(toastRegion);
    }
    toastRegion.setAttribute('aria-live', 'polite');
    return toastRegion;
  };

  const dismissToast = (toast) => {
    if (!toast || !toast.isConnected || toast.classList.contains('is-leaving')) return;
    toast.classList.add('is-leaving');
    const remove = () => toast.remove();
    toast.addEventListener('animationend', remove, { once: true });
    window.setTimeout(remove, 420);
  };

  const armToastTimeout = (toast) => {
    if (!toast || toast.dataset.type === 'error') return;
    const timeout = Number.parseInt(toast.dataset.timeout || '', 10);
    window.setTimeout(() => dismissToast(toast), Number.isFinite(timeout) && timeout > 0 ? timeout : TOAST_TIMEOUT);
  };

  const wireToast = (toast) => {
    if (!toast || toast.dataset.ankhToastWired === 'true') return;
    toast.dataset.ankhToastWired = 'true';
    toast.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (target && target.closest('form,button,a,input,select,textarea')) return;
      dismissToast(toast);
    });
    toast.querySelector('[data-ankh-toast-close]')?.addEventListener('click', (event) => {
      event.preventDefault();
      dismissToast(toast);
    });
    armToastTimeout(toast);
  };

  const createToast = (message, options = {}) => {
    const text = String(message ?? '').trim();
    if (!text) return null;
    const toast = document.createElement('div');
    toast.className = 'ankh-toast';
    toast.dataset.ankhToast = '';
    toast.dataset.type = options.type === 'error' ? 'error' : 'ok';
    toast.setAttribute('role', 'status');
    toast.setAttribute('aria-live', 'polite');
    if (Number.isFinite(Number(options.timeout)) && Number(options.timeout) > 0) toast.dataset.timeout = String(options.timeout);

    const copy = document.createElement('p');
    copy.className = 'ankh-toast-message';
    copy.textContent = text;
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'ankh-toast-close';
    close.dataset.ankhToastClose = '';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.innerHTML = '<span aria-hidden="true">×</span>';

    toast.append(copy, close);
    getToastRegion().append(toast);
    wireToast(toast);
    return toast;
  };

  window.ankhToast = createToast;

  const adoptToasts = () => {
    document.querySelectorAll('[data-ankh-toast]').forEach((toast) => {
      if (!toast.getAttribute('role')) toast.setAttribute('role', 'status');
      if (!toast.hasAttribute('aria-live')) toast.setAttribute('aria-live', 'polite');
      wireToast(toast);
    });
  };

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

    const skeletonSelector = [
      '#ankh-main .today-hero',
      '#ankh-main .dashboard-stat-card',
      '#ankh-main .focus-worklist .focus-order',
      '#ankh-main .dashboard-secondary',
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
      '#ankh-main .order'
    ].join(',');

    const skeletonTargets = [];
    revealTargets.forEach((element, index) => {
      const delay = Math.min(index, 15) * 40;
      element.classList.add('ankh-reveal');
      element.style.setProperty('--ankh-delay', delay + 'ms');
      if (element.matches(skeletonSelector)) {
        element.classList.add('ankh-skeleton');
        skeletonTargets.push({ element, delay });
      } else {
        element.classList.add('ankh-in');
      }
    });

    skeletonTargets.forEach(({ element, delay }) => {
      window.setTimeout(() => {
        element.classList.remove('ankh-skeleton');
        element.classList.add('ankh-in');
      }, delay);
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

  const start = () => {
    adoptToasts();
    init();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();

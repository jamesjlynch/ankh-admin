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

  const todayLocal = () => {
    const date = new Date();
    const pad = (value) => String(value).padStart(2, '0');
    return date.getFullYear() + '-' + pad(date.getMonth() + 1) + '-' + pad(date.getDate());
  };

  const csrfToken = () => document.querySelector('input[name="csrf"]')?.value || '';

  const closeAllCardActions = (except) => {
    document.querySelectorAll('[data-order-card].is-actions-open').forEach((card) => {
      if (card !== except) card.classList.remove('is-actions-open');
    });
  };

  const syncCardActions = (card) => {
    const outstanding = Number.parseInt(card.dataset.orderOutstanding || '0', 10) || 0;
    const paidButton = card.querySelector('.ankh-order-action-paid');
    const deliveredButton = card.querySelector('.ankh-order-action-delivered');
    const tray = card.querySelector('.ankh-order-actions');
    if (paidButton) paidButton.hidden = !(outstanding > 0) || card.dataset.orderCancelled === '1';
    if (deliveredButton) {
      deliveredButton.hidden = card.dataset.orderDelivered === '1'
        || card.dataset.orderBackOrder === '1'
        || card.dataset.orderCancelled === '1';
    }
    if (tray) tray.hidden = [...tray.querySelectorAll('button')].every((button) => button.hidden);
  };

  const updateCardInPlace = (card, data, action) => {
    card.dataset.orderOutstanding = String(data.balance ?? 0);
    card.dataset.orderTotal = String(data.total ?? 0);
    card.dataset.orderPaid = Number(data.balance) <= 0 ? '1' : '0';
    if (action === 'delivery_mark' || data.delivered) card.dataset.orderDelivered = '1';

    const statuses = card.querySelector('.focus-statuses');
    if (statuses) {
      const chips = [...statuses.querySelectorAll('.focus-status')];
      if (chips[0]) {
        chips[0].textContent = data.payment_label || chips[0].textContent;
        chips[0].className = 'focus-status ' + (data.payment_state === 'paid' ? 'focus-paid' : 'focus-unpaid');
      }
      if (action === 'delivery_mark') {
        chips.slice(1).forEach((chip) => chip.remove());
        if (!statuses.querySelector('.focus-status-delivered')) {
          const delivered = document.createElement('span');
          delivered.className = 'focus-status focus-paid focus-status-delivered';
          delivered.textContent = 'Delivered';
          statuses.append(delivered);
        }
      }
    }

    const summary = card.querySelector('.order-right>small');
    if (summary && data.summary_label) summary.textContent = data.summary_label;
    syncCardActions(card);
  };

  const submitOrderAction = async (card, action, button) => {
    if (card.dataset.orderBusy === '1' || !button || button.hidden) return;
    const token = csrfToken();
    if (!token) {
      window.ankhToast('Please refresh the page and try again.', { type: 'error' });
      return;
    }
    const id = card.dataset.orderId;
    if (!id) return;

    const body = new URLSearchParams({ csrf: token, action, id });
    if (action === 'payment_add') {
      body.set('payment_type', 'full');
      body.set('method', card.dataset.orderPaymentMethod || 'Cash');
      body.set('payment_date', todayLocal());
      body.set('return', 'orders');
    } else {
      body.set('delivery_date', todayLocal());
      body.set('return', 'orders');
    }

    card.dataset.orderBusy = '1';
    button.disabled = true;
    button.classList.add('is-busy');
    try {
      const url = new URL(window.location.href);
      url.hash = '';
      const response = await fetch(url.toString(), {
        method: 'POST',
        headers: {
          'X-Requested-With': 'fetch',
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
        },
        body: body.toString(),
        credentials: 'same-origin'
      });
      let data = null;
      try { data = await response.json(); } catch {}
      if (!response.ok || !data || data.ok !== true) {
        throw new Error(data && data.error ? data.error : 'Could not save. Please try again.');
      }
      updateCardInPlace(card, data, action);
      closeAllCardActions();
      const verb = action === 'payment_add' ? 'Marked paid' : 'Marked delivered';
      window.ankhToast(verb + ' · ' + (data.client || card.dataset.orderClient || 'order'));
    } catch (error) {
      window.ankhToast(error && error.message ? error.message : 'Could not save. Please try again.', { type: 'error' });
    } finally {
      delete card.dataset.orderBusy;
      button.disabled = false;
      button.classList.remove('is-busy');
      syncCardActions(card);
    }
  };

  const attachCardSwipe = (card) => {
    const summary = card.querySelector(':scope > summary') || card.querySelector('summary');
    if (!summary || summary.dataset.orderSwipeReady === '1') return;
    summary.dataset.orderSwipeReady = '1';

    let start = null;
    const open = () => {
      closeAllCardActions(card);
      card.classList.add('is-actions-open');
    };
    const close = () => card.classList.remove('is-actions-open');

    summary.addEventListener('pointerdown', (event) => {
      if (event.pointerType === 'mouse' || !event.isPrimary) return;
      if (event.target instanceof Element && event.target.closest('.ankh-order-actions')) return;
      start = { x: event.clientX, y: event.clientY, decided: false, horizontal: false };
    }, { passive: true });

    summary.addEventListener('pointermove', (event) => {
      if (!start) return;
      const dx = event.clientX - start.x;
      const dy = event.clientY - start.y;
      if (!start.decided) {
        if (Math.abs(dx) < 12 && Math.abs(dy) < 12) return;
        start.decided = true;
        start.horizontal = Math.abs(dx) > Math.abs(dy) * 1.2 && Math.abs(dx) > 12;
        if (start.horizontal) card._ankhSuppressClick = true;
      }
      if (!start.horizontal) return;
      if (dx < -30) open();
      else if (dx > 30) close();
    }, { passive: true });

    summary.addEventListener('pointerup', (event) => {
      if (start && start.horizontal) {
        card._ankhSuppressClick = true;
        const dx = event.clientX - start.x;
        if (dx < -30) open();
        else if (dx > 30) close();
        window.setTimeout(() => { card._ankhSuppressClick = false; }, 350);
      }
      start = null;
    }, { passive: true });

    summary.addEventListener('pointercancel', () => { start = null; }, { passive: true });

    summary.addEventListener('click', (event) => {
      if (event.target instanceof Element && event.target.closest('.ankh-order-actions')) return;
      if (card._ankhSuppressClick) {
        event.preventDefault();
        event.stopPropagation();
        card._ankhSuppressClick = false;
      }
    }, true);
  };

  const buildCardActions = (card) => {
    if (card.dataset.orderActionsReady === '1') return;
    const summary = card.querySelector(':scope > summary') || card.querySelector('summary');
    if (!summary) return;
    card.dataset.orderActionsReady = '1';

    const tray = document.createElement('div');
    tray.className = 'ankh-order-actions';
    tray.setAttribute('role', 'group');
    tray.setAttribute('aria-label', 'Quick actions for this order');

    const paid = document.createElement('button');
    paid.type = 'button';
    paid.className = 'ankh-order-action ankh-order-action-paid';
    paid.innerHTML = '<span aria-hidden="true">£</span><span>Paid</span>';
    paid.title = 'Mark the outstanding balance as paid';

    const delivered = document.createElement('button');
    delivered.type = 'button';
    delivered.className = 'ankh-order-action ankh-order-action-delivered';
    delivered.innerHTML = '<span aria-hidden="true">✓</span><span>Delivered</span>';
    delivered.title = 'Mark this order delivered today';

    paid.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      submitOrderAction(card, 'payment_add', paid);
    });
    delivered.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      submitOrderAction(card, 'delivery_mark', delivered);
    });

    tray.append(paid, delivered);
    summary.append(tray);
    syncCardActions(card);
  };

  const initOrderCards = () => {
    document.querySelectorAll('[data-order-card]').forEach((card) => {
      buildCardActions(card);
      attachCardSwipe(card);
    });
  };

  document.addEventListener('pointerdown', (event) => {
    const openCard = document.querySelector('[data-order-card].is-actions-open');
    if (!openCard) return;
    if (event.target instanceof Node && openCard.contains(event.target)) return;
    closeAllCardActions();
  }, { passive: true });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeAllCardActions();
  });

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
    initOrderCards();
    init();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();

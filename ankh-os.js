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

  const orderActionNames = new Set([
    'payment_add', 'payment_delete', 'delivery_mark', 'order_preparation',
    'order_reconstituted', 'order_schedule', 'order_delivery_section',
    'order_dates', 'status', 'packing_pick', 'order_backorder', 'backorder_cancel',
    'order_delete'
  ]);

  const orderActionName = (form) => form instanceof HTMLFormElement
    ? String(form.querySelector('input[name="action"]')?.value || '')
    : '';

  const isInPlaceOrderForm = (form) => form instanceof HTMLFormElement
    && orderActionNames.has(orderActionName(form))
    && Boolean(form.closest('[data-order-card]') || form.closest('.focus-sheet-content') || form.closest('.od-detail'));

  let progressBar = null;
  let progressTimer = 0;

  const startProgress = () => {
    window.clearTimeout(progressTimer);
    progressTimer = window.setTimeout(finishProgress, 3000);
    if (!document.body) return;
    if (!progressBar || !progressBar.isConnected) {
      progressBar = document.createElement('div');
      progressBar.className = 'ankh-nav-progress';
      progressBar.setAttribute('aria-hidden', 'true');
      document.body.append(progressBar);
    }
    progressBar.hidden = false;
    progressBar.classList.remove('is-done');
    progressBar.style.width = '8%';
    requestAnimationFrame(() => requestAnimationFrame(() => {
      if (progressBar) progressBar.style.width = '72%';
    }));
  };

  const finishProgress = () => {
    window.clearTimeout(progressTimer);
    if (!progressBar) return;
    const bar = progressBar;
    bar.classList.add('is-done');
    bar.style.width = '100%';
    window.setTimeout(() => {
      bar.hidden = true;
      bar.classList.remove('is-done');
      bar.style.width = '0';
    }, 240);
  };

  const initNavigationProgress = () => {
    document.addEventListener('click', (event) => {
      const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      if (link.target && link.target !== '_self') return;
      if (link.hasAttribute('download')) return;
      const href = link.getAttribute('href') || '';
      if (href === '' || href.startsWith('#') || /^(mailto|tel|javascript):/i.test(href)) return;
      let url = null;
      try { url = new URL(link.href, window.location.href); } catch { return; }
      if (url.origin !== window.location.origin) return;
      if (url.pathname === window.location.pathname && url.search === window.location.search) return;
      startProgress();
    });

    document.addEventListener('submit', (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || event.defaultPrevented || isInPlaceOrderForm(form)) return;
      startProgress();
    });

    window.addEventListener('pageshow', finishProgress);
    window.addEventListener('pagehide', () => {
      if (progressBar) progressBar.style.width = '100%';
    });
  };

  const initLaunch = () => {
    let launch = false;
    try {
      launch = document.documentElement.dataset.ankhLaunch === '1' || !sessionStorage.getItem('ankh-launched');
      sessionStorage.setItem('ankh-launched', '1');
    } catch {
      launch = document.documentElement.dataset.ankhLaunch === '1';
    }
    const splash = document.getElementById('ankh-launch-splash');
    if (!splash) return launch;
    if (!launch || reduceMotion) {
      splash.hidden = true;
      return launch;
    }
    splash.hidden = false;
    const hide = () => {
      splash.classList.add('is-leaving');
      window.setTimeout(() => {
        splash.hidden = true;
        splash.classList.remove('is-leaving');
      }, 260);
    };
    const timer = window.setTimeout(hide, 520);
    const dismiss = () => {
      window.clearTimeout(timer);
      hide();
    };
    window.addEventListener('pointerdown', dismiss, { once: true });
    window.addEventListener('keydown', dismiss, { once: true });
    return launch;
  };

  const orderCardFor = (id) => document.querySelector('[data-order-card][data-order-id="' + id + '"]');

  const returnedOrderCard = (doc, id) => {
    const dashboard = doc.getElementById('work-order-' + id);
    if (dashboard) return dashboard;
    const anchor = doc.getElementById('order-' + id);
    if (anchor) return anchor.closest('[data-order-card]') || anchor;
    return doc.querySelector('[data-order-card][data-order-id="' + id + '"]');
  };

  const applyReturnedOrderCard = (doc, card, id, dialog, crossView = false) => {
    const fresh = crossView ? null : returnedOrderCard(doc, id);
    const dialogBody = document.getElementById('focus-sheet-body');
    const inDialog = Boolean(dialog && dialog.open && dialogBody);
    const scrollY = window.scrollY;
    const dialogScroll = inDialog ? dialogBody.scrollTop : 0;

    const restoreScroll = () => {
      window.scrollTo(0, scrollY);
      if (inDialog && dialogBody) dialogBody.scrollTop = dialogScroll;
    };
    const restoreScrollSoon = () => {
      restoreScroll();
      requestAnimationFrame(() => {
        restoreScroll();
        requestAnimationFrame(restoreScroll);
      });
    };

    if (!card) return;

    if (!fresh) {
      card.remove();
      if (inDialog) dialog.close();
      const list = document.querySelector('.focus-worklist');
      if (list && !list.querySelector('[data-order-card]') && !list.querySelector('.queue-empty')) {
        const empty = doc.querySelector('.queue-empty');
        if (empty) list.append(document.importNode(empty, true));
      }
      restoreScrollSoon();
      return;
    }

    const wasOpen = card.open;
    const openDetails = [...card.querySelectorAll('details')]
      .map((details, index) => details.open ? index : -1)
      .filter((index) => index >= 0);
    const imported = document.importNode(fresh, true);
    [...imported.attributes].forEach((attribute) => {
      if (attribute.name === 'open' || attribute.name === 'id') return;
      card.setAttribute(attribute.name, attribute.value);
    });
    card.innerHTML = imported.innerHTML;
    card.open = wasOpen;
    [...card.querySelectorAll('details')].forEach((details, index) => {
      if (openDetails.includes(index)) details.open = true;
    });
    if (inDialog) {
      const content = card.querySelector('.focus-sheet-content');
      if (content && dialogBody) {
        dialogBody.replaceChildren(content);
      }
    }
    restoreScrollSoon();
  };

  const submitOrderFormInPlace = async (form, submitter) => {
    if (form.dataset.ankhSubmitting === '1') return;
    const card = form.closest('[data-order-card]') || orderCardFor(String(form.querySelector('input[name="id"]')?.value || ''));
    const id = String(form.querySelector('input[name="id"]')?.value || card?.dataset.orderId || '');
    if (!id) return;
    const dialog = document.getElementById('focus-action-sheet');
    const body = new FormData(form, submitter || undefined);
    const buttons = [...form.querySelectorAll('button, input[type="submit"]')];
    form.dataset.ankhSubmitting = '1';
    buttons.forEach((button) => { button.disabled = true; });
    form.setAttribute('aria-busy', 'true');
    startProgress();
    try {
      const url = new URL(window.location.href);
      url.hash = '';
      const response = await fetch(url.toString(), {
        method: 'POST',
        headers: {
          'X-Requested-With': 'fetch',
          'Accept': 'text/html'
        },
        body,
        credentials: 'same-origin'
      });
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      if (!response.ok) {
        const error = doc.querySelector('.error[role="alert"], [role="alert"].error, .error');
        throw new Error(error && error.textContent.trim() ? error.textContent.trim() : 'Could not save. Please try again.');
      }
      const finalUrl = new URL(response.url || url.toString());
      const currentUrl = new URL(window.location.href);
      const currentView = currentUrl.searchParams.get('view') || 'dashboard';
      const finalView = finalUrl.searchParams.get('view') || 'dashboard';
      const crossView = finalView !== currentView
        || (currentView === 'orders' && (finalUrl.searchParams.get('section') || '') !== (currentUrl.searchParams.get('section') || ''));
      applyReturnedOrderCard(doc, card, id, dialog, crossView);
      const toast = doc.querySelector('.ankh-toast[data-ankh-toast]');
      const message = toast?.querySelector('.ankh-toast-message')?.textContent?.trim()
        || doc.querySelector('.ankh-toast-message')?.textContent?.trim();
      if (message) window.ankhToast?.(message, { type: toast?.dataset.type === 'error' ? 'error' : 'ok' });
      initOrderCards();
      window.ankhInitFocusSheets?.();
    } catch (error) {
      window.ankhToast?.(error && error.message ? error.message : 'Could not save. Please try again.', { type: 'error' });
    } finally {
      delete form.dataset.ankhSubmitting;
      form.removeAttribute('aria-busy');
      buttons.forEach((button) => { if (button.isConnected) button.disabled = false; });
      finishProgress();
    }
  };


  const submitEditOrderInPlace = async (form, submitter) => {
    if (form.dataset.ankhSubmitting === '1') return;
    const save = form.querySelector('.save-order');
    const label = save ? save.textContent : '';
    const body = new FormData(form, submitter || undefined);
    form.dataset.ankhSubmitting = '1';
    form.setAttribute('aria-busy', 'true');
    if (save) save.disabled = true;
    startProgress();
    try {
      const url = new URL(window.location.href);
      url.hash = '';
      const response = await fetch(url.toString(), {
        method: 'POST',
        headers: {
          'X-Requested-With': 'fetch',
          'Accept': 'text/html'
        },
        body,
        credentials: 'same-origin'
      });
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      if (!response.ok) {
        const error = doc.querySelector('.error[role="alert"], [role="alert"].error, .error');
        throw new Error(error && error.textContent.trim() ? error.textContent.trim() : 'Could not save. Please try again.');
      }
      const toast = doc.querySelector('.ankh-toast[data-ankh-toast]');
      const message = toast?.querySelector('.ankh-toast-message')?.textContent?.trim()
        || doc.querySelector('.ankh-toast-message')?.textContent?.trim();
      if (message) window.ankhToast?.(message, { type: toast?.dataset.type === 'error' ? 'error' : 'ok' });
    } catch (error) {
      window.ankhToast?.(error && error.message ? error.message : 'Could not save. Please try again.', { type: 'error' });
    } finally {
      delete form.dataset.ankhSubmitting;
      form.removeAttribute('aria-busy');
      if (save) {
        save.disabled = false;
        if (label) save.textContent = label;
      }
      finishProgress();
    }
  };

  const initOrderFormsInPlace = () => {
    document.addEventListener('submit', (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
      if (form.id === 'edit-order-form' && orderActionName(form) === 'order_edit') {
        event.preventDefault();
        submitEditOrderInPlace(form, event.submitter);
        return;
      }
      if (!isInPlaceOrderForm(form)) return;
      if (form.matches('[data-run-deliver]') || form.closest('[data-delivery-run]')) return;
      event.preventDefault();
      submitOrderFormInPlace(form, event.submitter);
    });
  };

  const init = (launch = false) => {
    const useMotion = launch && !reduceMotion;
    const mainContent = document.getElementById('ankh-main');
    if (useMotion) {
      document.documentElement.classList.add('ankh-motion');
      if (mainContent) mainContent.classList.add('ankh-page-enter');
    } else if (mainContent) {
      mainContent.classList.add('ankh-quick-fade');
    }

    if (useMotion) {

    const revealSelector = [
      '#ankh-main > header',
      '#ankh-main .today-hero',
      '#ankh-main .dashboard-stat-card',
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

    }

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

  const initSearch = () => {
    const sheet = document.getElementById('ankh-search-sheet');
    const input = document.getElementById('ankh-search-input');
    if (!sheet || !input) return;
    const results = document.getElementById('ankh-search-results');
    const status = document.getElementById('ankh-search-status');
    const triggers = [...document.querySelectorAll('[data-ankh-search-open]')];
    const groupLabels = { clients: 'Clients', orders: 'Orders', products: 'Products' };
    const groupViews = { clients: 'customer', orders: 'edit', products: 'product' };
    let lastTrigger = null;
    let controller = null;
    let debounceTimer = 0;

    const setStatus = (message) => {
      if (status) status.textContent = message;
    };

    const setTriggers = (open) => {
      triggers.forEach((trigger) => trigger.setAttribute('aria-expanded', String(open)));
    };

    const renderMessage = (message) => {
      if (!results) return;
      results.replaceChildren();
      const empty = document.createElement('p');
      empty.className = 'ankh-search-empty';
      empty.textContent = message;
      results.append(empty);
    };

    const renderResults = (data) => {
      if (!results) return;
      results.replaceChildren();
      const groups = data && data.groups && typeof data.groups === 'object' ? data.groups : {};
      let count = 0;
      ['clients', 'orders', 'products'].forEach((key) => {
        const items = Array.isArray(groups[key]) ? groups[key].slice(0, 5) : [];
        if (!items.length) return;
        const section = document.createElement('section');
        section.className = 'ankh-search-group';
        const heading = document.createElement('h3');
        heading.textContent = groupLabels[key];
        const list = document.createElement('ul');
        list.className = 'ankh-search-list';
        items.forEach((item) => {
          const id = Number(item && item.id);
          if (!Number.isInteger(id) || id <= 0) return;
          const entry = document.createElement('li');
          const link = document.createElement('a');
          link.className = 'ankh-search-result';
          link.href = '?view=' + groupViews[key] + '&id=' + encodeURIComponent(String(id));
          const name = document.createElement('span');
          name.className = 'ankh-search-result-name';
          name.textContent = String((item && item.name) || '');
          const detail = document.createElement('small');
          detail.className = 'ankh-search-result-detail';
          detail.textContent = String((item && item.detail) || '');
          link.append(name, detail);
          link.addEventListener('click', () => closeSearch(false));
          entry.append(link);
          list.append(entry);
          count += 1;
        });
        if (!list.children.length) return;
        section.append(heading, list);
        results.append(section);
      });
      if (!count) renderMessage('No matches for “' + input.value.trim() + '”.');
      setStatus(count ? count + (count === 1 ? ' result' : ' results') : 'No results');
    };

    const runSearch = async (value) => {
      if (controller) controller.abort();
      controller = new AbortController();
      const term = value.trim();
      if (term.length < 2) {
        renderMessage('Type at least 2 characters to search.');
        setStatus('Type at least 2 characters to search.');
        return;
      }
      try {
        const response = await fetch('?api=search&q=' + encodeURIComponent(term), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
          signal: controller.signal
        });
        if (!response.ok) throw new Error('Search unavailable');
        const data = await response.json();
        if (input.value.trim() !== term) return;
        renderResults(data);
      } catch (error) {
        if (error && error.name === 'AbortError') return;
        renderMessage('Search is unavailable right now.');
        setStatus('Search is unavailable right now.');
      }
    };

    const closeSearch = (restore = true) => {
      if (sheet.hidden) return;
      sheet.hidden = true;
      document.documentElement.classList.remove('ankh-search-open');
      if (controller) controller.abort();
      setTriggers(false);
      if (restore && lastTrigger && lastTrigger.isConnected) lastTrigger.focus();
    };

    const openSearch = (trigger) => {
      const visibleTrigger = triggers.find((button) => button.getClientRects().length > 0);
      lastTrigger = trigger && trigger.isConnected && trigger !== document.body
        ? trigger
        : (visibleTrigger || document.activeElement);
      sheet.hidden = false;
      document.documentElement.classList.add('ankh-search-open');
      setTriggers(true);
      input.value = '';
      renderMessage('Type at least 2 characters to search.');
      setStatus('');
      requestAnimationFrame(() => input.focus());
    };

    triggers.forEach((trigger) => {
      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        openSearch(trigger);
      });
    });

    input.addEventListener('input', () => {
      window.clearTimeout(debounceTimer);
      const value = input.value;
      debounceTimer = window.setTimeout(() => runSearch(value), 150);
    });

    sheet.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target.closest('[data-ankh-search-close]') : null;
      if (!target) return;
      event.preventDefault();
      closeSearch();
    });

    document.addEventListener('keydown', (event) => {
      const commandK = event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey);
      if (commandK) {
        event.preventDefault();
        if (sheet.hidden) openSearch(document.activeElement);
        else input.focus();
        return;
      }
      if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey) {
        const target = event.target;
        const typing = target instanceof Element && (target.matches('input,textarea,select,[contenteditable="true"]') || target.closest('[contenteditable="true"]'));
        if (!typing && sheet.hidden) {
          event.preventDefault();
          openSearch(document.activeElement);
        }
        return;
      }
      if (sheet.hidden) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeSearch();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusables = [...sheet.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),[tabindex]:not([tabindex="-1"])')]
        .filter((element) => !element.hasAttribute('hidden') && element.getClientRects().length > 0);
      if (!focusables.length) {
        event.preventDefault();
        input.focus();
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || !sheet.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    });
  };

  const initDeliveryRun = () => {
    const run = document.querySelector('[data-delivery-run]');
    const list = run?.querySelector('.delivery-run-list');
    if (!run || !list) return;

    const storageKey = 'ankhDeliveryRunOrder:' + (run.dataset.runDate || '');
    const allStops = () => [...list.querySelectorAll('[data-run-stop]')];
    const money = (pence) => new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format((Number(pence) || 0) / 100);

    const readOrder = () => {
      try {
        const stored = JSON.parse(localStorage.getItem(storageKey) || '[]');
        return Array.isArray(stored) ? stored.map(String) : [];
      } catch {
        return [];
      }
    };

    const saveOrder = () => {
      try {
        localStorage.setItem(storageKey, JSON.stringify(allStops().map((stop) => String(stop.dataset.orderId || ''))));
      } catch {}
    };

    const renumber = () => {
      const stops = allStops();
      stops.forEach((stop, index) => {
        const number = stop.querySelector('[data-run-number]');
        if (number) number.textContent = String(index + 1);
        const up = stop.querySelector('[data-run-up]');
        const down = stop.querySelector('[data-run-down]');
        if (up) up.disabled = index === 0;
        if (down) down.disabled = index === stops.length - 1;
      });
    };

    const applyOrder = () => {
      const order = readOrder();
      if (!order.length) return;
      const byId = new Map(allStops().map((stop) => [String(stop.dataset.orderId || ''), stop]));
      const fragment = document.createDocumentFragment();
      order.forEach((id) => {
        const stop = byId.get(id);
        if (!stop) return;
        fragment.append(stop);
        byId.delete(id);
      });
      byId.forEach((stop) => fragment.append(stop));
      list.append(fragment);
      renumber();
    };

    const updateTotals = () => {
      const remaining = allStops().filter((stop) => !stop.classList.contains('is-done'));
      const total = remaining.reduce((sum, stop) => sum + (Number(stop.dataset.runBalance) || 0), 0);
      const countLabel = run.querySelector('[data-run-count]');
      const totalLabel = run.querySelector('[data-run-total-label]');
      if (countLabel) countLabel.textContent = String(remaining.length);
      if (totalLabel) totalLabel.textContent = money(total);
    };

    const markDone = (stop) => {
      stop.classList.add('is-done');
      const done = stop.querySelector('.delivery-run-done');
      if (done) done.hidden = false;
      updateTotals();
      saveOrder();
    };

    applyOrder();

    list.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target : null;
      if (!target) return;
      const up = target.closest('[data-run-up]');
      const down = target.closest('[data-run-down]');
      const button = up || down;
      if (!button) return;
      const stop = button.closest('[data-run-stop]');
      if (!stop) return;
      if (up && stop.previousElementSibling) list.insertBefore(stop, stop.previousElementSibling);
      if (down && stop.nextElementSibling) list.insertBefore(stop.nextElementSibling, stop);
      renumber();
      saveOrder();
    });

    list.addEventListener('submit', async (event) => {
      const form = event.target instanceof HTMLFormElement ? event.target.closest('[data-run-deliver]') : null;
      if (!form) return;
      event.preventDefault();
      const stop = form.closest('[data-run-stop]');
      const button = form.querySelector('button[type="submit"]');
      if (!stop || stop.classList.contains('is-done') || stop.dataset.runBusy === '1') return;
      const token = csrfToken();
      if (!token) {
        window.ankhToast?.('Please refresh the page and try again.', { type: 'error' });
        return;
      }
      const body = new URLSearchParams();
      new FormData(form).forEach((value, key) => body.append(key, String(value)));
      stop.dataset.runBusy = '1';
      if (button) button.disabled = true;
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
        if (!response.ok || !data || data.ok !== true) throw new Error(data && data.error ? data.error : 'Could not save. Please try again.');
        markDone(stop);
        window.ankhToast?.('Marked delivered · ' + (data.client || 'order'));
      } catch (error) {
        window.ankhToast?.(error && error.message ? error.message : 'Could not save. Please try again.', { type: 'error' });
      } finally {
        delete stop.dataset.runBusy;
        if (button) button.disabled = false;
      }
    });
  };

  const initBalanceReminders = () => {
    const sheet = document.getElementById('balance-reminder-sheet');
    const textarea = document.getElementById('balance-reminder-text');
    const customerLabel = document.getElementById('balance-reminder-customer');
    const copyButton = document.getElementById('balance-reminder-copy');
    const whatsappLink = document.getElementById('balance-reminder-whatsapp');
    const phoneNote = document.getElementById('balance-reminder-phone-note');
    const triggers = [...document.querySelectorAll('[data-balance-reminder]')];
    if (!sheet || !textarea || !customerLabel || !whatsappLink || !phoneNote || !triggers.length) return;
    let lastTrigger = null;
    let currentPhone = '';

    const toWhatsAppNumber = (phone) => {
      const digits = String(phone || '').replace(/[\s+()\[\]]/g, '');
      if (/^07\d{9}$/.test(digits)) return '44' + digits.slice(1);
      if (/^447\d{9}$/.test(digits)) return digits;
      return '';
    };

    const syncLinks = () => {
      const message = textarea.value;
      const number = toWhatsAppNumber(currentPhone);
      whatsappLink.hidden = false;
      if (!number) {
        whatsappLink.removeAttribute('href');
        whatsappLink.setAttribute('aria-disabled', 'true');
        whatsappLink.classList.add('is-disabled');
        phoneNote.hidden = false;
        return;
      }
      whatsappLink.href = 'https://wa.me/' + number + '?text=' + encodeURIComponent(message);
      whatsappLink.removeAttribute('aria-disabled');
      whatsappLink.classList.remove('is-disabled');
      phoneNote.hidden = true;
    };

    const focusableItems = () => [...sheet.querySelectorAll('a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]),[tabindex]:not([tabindex="-1"])')]
      .filter((element) => !element.hasAttribute('hidden') && element.getClientRects().length > 0);

    const close = (restore = true) => {
      if (sheet.hidden) return;
      sheet.hidden = true;
      document.documentElement.classList.remove('balance-reminder-open');
      if (restore && lastTrigger && lastTrigger.isConnected) lastTrigger.focus();
    };

    const open = (trigger) => {
      lastTrigger = trigger;
      currentPhone = String(trigger.dataset.reminderPhone || '');
      customerLabel.textContent = String(trigger.dataset.reminderCustomer || '');
      textarea.value = String(trigger.dataset.reminderMessage || '');
      sheet.hidden = false;
      document.documentElement.classList.add('balance-reminder-open');
      syncLinks();
      requestAnimationFrame(() => textarea.focus());
    };

    triggers.forEach((trigger) => {
      trigger.addEventListener('click', (event) => {
        event.preventDefault();
        event.stopPropagation();
        open(trigger);
      });
    });

    sheet.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target.closest('[data-balance-reminder-close]') : null;
      if (!target) return;
      event.preventDefault();
      close();
    });

    textarea.addEventListener('input', syncLinks);

    whatsappLink.addEventListener('click', (event) => {
      if (whatsappLink.getAttribute('aria-disabled') === 'true') event.preventDefault();
    });

    copyButton?.addEventListener('click', async () => {
      const message = textarea.value;
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(message);
        } else {
          textarea.focus();
          textarea.select();
          if (!document.execCommand('copy')) throw new Error('Copy unavailable');
        }
        window.ankhToast?.('Copied');
      } catch {
        window.ankhToast?.('Could not copy. Select the text and copy it manually.', { type: 'error' });
      }
    });

    document.addEventListener('keydown', (event) => {
      if (sheet.hidden) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        close();
        return;
      }
      if (event.key !== 'Tab') return;
      const items = focusableItems();
      if (!items.length) {
        event.preventDefault();
        textarea.focus();
        return;
      }
      const first = items[0];
      const last = items[items.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || !sheet.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        first.focus();
      }
    });
  };

  const initReportTakings = () => {
    document.querySelectorAll('[data-takings]').forEach((root) => {
      const tabs = [...root.querySelectorAll('[data-takings-tab]')];
      const panels = [...root.querySelectorAll('[data-takings-panel]')];
      if (!tabs.length || !panels.length) return;

      const reveal = (panel) => {
        const chart = panel.querySelector('.takings-chart');
        if (!chart) return;
        if (reduceMotion) {
          chart.classList.add('is-revealed');
          return;
        }
        if (chart.classList.contains('is-revealed')) return;
        chart.classList.add('is-ready');
        chart.querySelectorAll('.takings-bar').forEach((bar, index) => {
          bar.style.transitionDelay = (index * 34) + 'ms';
        });
        requestAnimationFrame(() => requestAnimationFrame(() => chart.classList.add('is-revealed')));
      };

      const activate = (key) => {
        tabs.forEach((tab) => {
          const on = tab.dataset.takingsTab === key;
          tab.setAttribute('aria-selected', on ? 'true' : 'false');
          tab.tabIndex = on ? 0 : -1;
        });
        panels.forEach((panel) => {
          const on = panel.dataset.takingsPanel === key;
          panel.hidden = !on;
          if (on) reveal(panel);
        });
      };

      tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activate(tab.dataset.takingsTab));
        tab.addEventListener('keydown', (event) => {
          let next = null;
          if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
          else if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
          else if (event.key === 'Home') next = 0;
          else if (event.key === 'End') next = tabs.length - 1;
          if (next === null) return;
          event.preventDefault();
          tabs[next].focus();
          activate(tabs[next].dataset.takingsTab);
        });
      });

      const initial = panels.find((panel) => !panel.hidden) || panels[0];
      if (initial) reveal(initial);

      panels.forEach((panel) => {
        const chart = panel.querySelector('.takings-chart');
        const wrap = chart?.closest('.takings-chart-wrap');
        const tip = wrap?.querySelector('.takings-tooltip');
        if (!chart || !wrap || !tip) return;
        let pinned = null;
        const show = (group) => {
          const bar = group.querySelector('.takings-bar') || group;
          const wrapRect = wrap.getBoundingClientRect();
          const barRect = bar.getBoundingClientRect();
          const left = Math.min(Math.max(barRect.left - wrapRect.left + barRect.width / 2, 42), wrapRect.width - 42);
          tip.textContent = (group.dataset.label || '') + ' · ' + (group.dataset.value || '');
          tip.style.left = left + 'px';
          tip.style.top = Math.max(2, barRect.top - wrapRect.top - 34) + 'px';
          tip.hidden = false;
        };
        const hide = () => {
          if (!pinned) tip.hidden = true;
        };
        chart.querySelectorAll('[data-takings-bar]').forEach((group) => {
          group.addEventListener('pointerenter', () => { if (!pinned) show(group); });
          group.addEventListener('pointermove', () => { if (!pinned) show(group); });
          group.addEventListener('pointerleave', hide);
          group.addEventListener('focus', () => show(group));
          group.addEventListener('blur', () => { if (!pinned) tip.hidden = true; });
          group.addEventListener('click', (event) => {
            event.stopPropagation();
            if (pinned === group) {
              pinned = null;
              tip.hidden = true;
              return;
            }
            pinned = group;
            show(group);
          });
          group.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            if (pinned === group) {
              pinned = null;
              tip.hidden = true;
              return;
            }
            pinned = group;
            show(group);
          });
        });
        document.addEventListener('click', () => {
          if (!pinned) return;
          pinned = null;
          tip.hidden = true;
        });
      });
    });
  };

  const stockLowText = (available) => available < 0
    ? 'Out – short by ' + Math.abs(available)
    : 'Low – ' + available + ' left after today’s orders';

  const refreshStockLowBanners = (view) => {
    const grid = document.querySelector('.stock-product-grid');
    const activeView = view || grid?.dataset.fridgeView || 'combined';
    const qtyKey = activeView === 'jay' ? 'jayQty' : (activeView === 'tony' ? 'tonyQty' : 'combinedQty');
    document.querySelectorAll('.stock-product-card').forEach((card) => {
      const banner = card.querySelector('[data-stock-low-banner]');
      if (!banner) return;
      const available = Number(card.dataset[qtyKey] || 0);
      const threshold = Number(card.dataset.lowThreshold || 0);
      const low = card.dataset.stockActive === '1' && available <= threshold;
      banner.hidden = !low;
      banner.classList.toggle('is-out', available < 0);
      banner.classList.toggle('is-low', available >= 0);
      const label = banner.querySelector('[data-stock-low-label]');
      if (label) label.textContent = low ? stockLowText(available) : '';
    });
  };

  window.ankhRefreshStockLowBanners = refreshStockLowBanners;

  const initLowStock = () => {
    refreshStockLowBanners();

    document.addEventListener('click', (event) => {
      const trigger = event.target instanceof Element ? event.target.closest('[data-stock-add-product]') : null;
      if (!trigger) return;
      const action = document.querySelector('[data-stock-add-stock]');
      if (!action) return;
      if (action.tagName === 'DETAILS') action.open = true;
      const select = action.querySelector('select[name="product_id"]');
      if (select instanceof HTMLSelectElement) {
        select.value = trigger.dataset.stockAddProduct || '';
        select.dispatchEvent(new Event('change', { bubbles: true }));
      }
      action.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
      window.setTimeout(() => select?.focus?.({ preventScroll: true }), reduceMotion ? 0 : 320);
    });
  };

  const initCycleSearch = () => {
    const input = document.querySelector('[data-cycle-search]');
    const list = document.querySelector('[data-cycle-list]');
    if (!input || !list) return;
    const empty = document.querySelector('[data-cycle-empty]');
    const rows = [...list.querySelectorAll('[data-cycle-row]')].map((row) => ({
      row,
      text: row.textContent.toLowerCase()
    }));
    const filter = () => {
      const query = input.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach(({ row, text }) => {
        const match = query === '' || text.includes(query);
        row.hidden = !match;
        if (match) shown++;
      });
      if (empty) empty.hidden = shown > 0 || query === '';
    };
    input.addEventListener('input', filter);
    filter();
  };

  const start = () => {
    const launch = initLaunch();
    adoptToasts();
    initOrderCards();
    initOrderFormsInPlace();
    initNavigationProgress();
    initDeliveryRun();
    initSearch();
    initBalanceReminders();
    initReportTakings();
    initLowStock();
    initCycleSearch();
    init(launch);
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();

// Sticky step footers (New order, Edit order) must clear the pinned bottom nav
// whichever element scrolls. CI scrolls <main>; the iOS Home Screen app scrolls
// the page behind the nav. Measure the real overlap and expose it as a CSS var.
(function () {
  var root = document.documentElement;
  function scroller() {
    var main = document.getElementById('ankh-main');
    if (main) {
      var oy = getComputedStyle(main).overflowY;
      if ((oy === 'auto' || oy === 'scroll') && main.scrollHeight > main.clientHeight + 1) return main;
    }
    return null;
  }
  function measure() {
    var nav = document.querySelector('.admin-app-shell > nav.mobile-nav');
    if (!nav || getComputedStyle(nav).display === 'none') { root.style.removeProperty('--ankh-sticky-bottom'); return; }
    var navRect = nav.getBoundingClientRect();
    var plus = nav.querySelector('.nav-new');
    var top = Math.min(navRect.top, plus ? plus.getBoundingClientRect().top : navRect.top);
    var el = scroller();
    var portBottom = el ? el.getBoundingClientRect().bottom : (window.visualViewport ? window.visualViewport.height : window.innerHeight);
    var offset = Math.max(0, Math.ceil(portBottom - top)) + 6;
    root.style.setProperty('--ankh-sticky-bottom', offset + 'px');
  }
  var raf = 0;
  function queue() { if (raf) return; raf = requestAnimationFrame(function () { raf = 0; measure(); }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', measure, { once: true }); else measure();
  window.addEventListener('load', measure, { once: true });
  window.addEventListener('resize', queue);
  window.addEventListener('orientationchange', queue);
  if (window.visualViewport) window.visualViewport.addEventListener('resize', queue);
  document.addEventListener('scroll', queue, { passive: true, capture: true });
})();

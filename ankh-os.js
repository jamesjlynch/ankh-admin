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

    const revealTargets = document.querySelectorAll([
      '.today-hero',
      '.dashboard-stats .dashboard-stat-card',
      '.focus-worklist .focus-order',
      '.dashboard-secondary',
      '.ux-mobile-actions a'
    ].join(','));
    revealTargets.forEach((element, index) => {
      element.classList.add('ankh-reveal');
      element.style.setProperty('--ankh-delay', Math.min(index, 14) * 45 + 'ms');
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

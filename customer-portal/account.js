'use strict';
// Progressive enhancement only. Order filtering, navigation and detail pages work without JS.
document.addEventListener('DOMContentLoaded', () => {
  const print = document.getElementById('pc-print-action');
  if (print) print.addEventListener('click', () => window.print());
});


document.addEventListener('DOMContentLoaded', () => {
  const dialog = document.querySelector('.pcs-image-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  const image = dialog.querySelector('[data-image-full]');
  const toggle = dialog.querySelector('[data-image-toggle]');
  let trigger;
  document.querySelectorAll('[data-product-zoom]').forEach(link => link.addEventListener('click', event => {
    event.preventDefault(); trigger = link;
    image.src = link.href; image.alt = link.querySelector('img').alt;
    dialog.classList.remove('is-zoomed'); toggle.textContent = 'Zoom in'; toggle.setAttribute('aria-pressed', 'false');
    dialog.showModal();
  }));
  dialog.querySelector('[data-image-close]').addEventListener('click', () => dialog.close());
  toggle.addEventListener('click', () => {
    const zoomed = dialog.classList.toggle('is-zoomed');
    toggle.textContent = zoomed ? 'Fit photograph' : 'Zoom in'; toggle.setAttribute('aria-pressed', String(zoomed));
  });
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => { image.removeAttribute('src'); if (trigger) trigger.focus(); });
});

// Prevent accidental duplicate taps while ordinary, CSRF-protected forms navigate.
document.addEventListener('DOMContentLoaded', () => {
  const announcement = document.createElement('div');
  announcement.className = 'pc-sr-only'; announcement.setAttribute('role', 'status');
  document.body.appendChild(announcement);
  document.querySelectorAll('.pc-app form').forEach(form => {
    const action = form.querySelector('input[name="action"]');
    if (!action || !/^(catalogue_|collection_|watch_|fridge_|notification_|reminder_)/.test(action.value)) return;
    form.addEventListener('submit', event => {
      if (form.dataset.pcSubmitting === '1') { event.preventDefault(); return; }
      form.dataset.pcSubmitting = '1';
      const button = event.submitter;
      if (button && !button.disabled) {
        button.dataset.pcBusy = '1'; button.disabled = true; button.setAttribute('aria-busy', 'true');
      }
      announcement.textContent = /^(catalogue_enquire|collection_send)$/.test(action.value) ? 'Sending your enquiry…' : 'Saving your changes…';
    });
  });
});
window.addEventListener('pageshow', () => {
  document.querySelectorAll('[data-pc-busy]').forEach(button => {
    button.disabled = false; button.removeAttribute('aria-busy'); delete button.dataset.pcBusy;
  });
  document.querySelectorAll('[data-pc-submitting]').forEach(form => { delete form.dataset.pcSubmitting; });
});


// Home Screen installation without caching any private account data.
document.addEventListener('DOMContentLoaded', () => {
  if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('./sw.js', {scope:'./'}).catch(() => {});
  }
  const button = document.getElementById('pc-install-app');
  if (!button) return;
  let installPrompt = null;
  window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    installPrompt = event;
    button.hidden = false;
  });
  button.addEventListener('click', async () => {
    if (!installPrompt) return;
    const prompt = installPrompt;
    installPrompt = null;
    button.hidden = true;
    await prompt.prompt();
  });
  window.addEventListener('appinstalled', () => { button.hidden = true; });
  if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true) {
    const help = document.getElementById('pc-install-help');
    if (help) help.hidden = true;
  }
});

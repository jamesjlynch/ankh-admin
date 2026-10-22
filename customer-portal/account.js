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

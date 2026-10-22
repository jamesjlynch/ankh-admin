'use strict';
// Progressive enhancement only. Order filtering, navigation and detail pages work without JS.
document.addEventListener('DOMContentLoaded', () => {
  const print = document.getElementById('pc-print-action');
  if (print) print.addEventListener('click', () => window.print());
});

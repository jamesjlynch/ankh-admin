'use strict';
// ANKH privacy-first service worker. It NEVER intercepts requests or caches account data.
// Network-only: offline access to private orders and enquiries is intentionally unavailable.
self.addEventListener('install', () => { self.skipWaiting(); });
self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const cacheKeys = await caches.keys();
    await Promise.all(cacheKeys.filter(key => key.startsWith('ankh-customer')).map(key => caches.delete(key)));
    await self.clients.claim();
  })());
});

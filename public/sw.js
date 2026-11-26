/* Minimal service worker: keeps the shop front working on a bad connection.
   - static assets: cache first
   - the menu page: network first, last good copy when offline
   Everything else (owner console, orders, statements) always goes to the network. */
const VERSION = 'mm-v1';
const ASSETS = ['/offline', '/assets/app.css', '/assets/js/basket.js', '/assets/js/pwa.js', '/assets/icon.svg'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(caches.match(request).then((hit) => hit || fetch(request).then((res) => {
      const copy = res.clone();
      caches.open(VERSION).then((cache) => cache.put(request, copy));
      return res;
    })));
    return;
  }

  if (request.mode === 'navigate' && url.pathname === '/') {
    event.respondWith(
      fetch(request).then((res) => {
        if (res.ok) {
          const copy = res.clone();
          caches.open(VERSION).then((cache) => cache.put('/', copy));
        }
        return res;
      }).catch(() => caches.match('/').then((hit) => hit || caches.match('/offline'))),
    );
  }
});

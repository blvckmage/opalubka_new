const CACHE_NAME = 'opalubka-crm-v2';
const APP_SHELL = [
  '/assets/style.css',
  '/assets/app.js',
  '/assets/icon.svg',
  '/manifest.webmanifest'
];

self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll(APP_SHELL);
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.map(function(key) {
        if (key !== CACHE_NAME) return caches.delete(key);
      }));
    })
  );
  self.clients.claim();
});

self.addEventListener('fetch', function(event) {
  if (event.request.method !== 'GET') return;
  var url = new URL(event.request.url);
  var isStatic = url.pathname.startsWith('/assets/') || url.pathname === '/manifest.webmanifest';

  if (!isStatic) {
    event.respondWith(
      fetch(event.request).catch(function() {
        return new Response('Нет соединения. Откройте приложение, когда сеть снова появится.', {
          status: 503,
          headers: {'Content-Type': 'text/plain; charset=utf-8'}
        });
      })
    );
    return;
  }

  event.respondWith(
    fetch(event.request)
      .then(function(response) {
        var copy = response.clone();
        caches.open(CACHE_NAME).then(function(cache) {
          cache.put(event.request, copy);
        });
        return response;
      })
      .catch(function() {
        return caches.match(event.request).then(function(cached) {
          return cached || caches.match('/');
        });
      })
  );
});

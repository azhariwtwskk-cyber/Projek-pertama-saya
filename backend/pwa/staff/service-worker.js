const CACHE_NAME = 'cpms-staff-v2-gallery';
const STATIC_ASSETS = [
  './staff_login.php',
  './staff_dashboard.php',
  './staff_work_orders.php',
  './staff_work_form.php',
  './staff_work_history.php',
  './pwa/staff/offline.html',
  './pwa/staff/staff-mobile.css?v=2-gallery',
  './pwa/staff/staff-pwa.js?v=2-gallery',
  './pwa/staff/icons/icon-192.png',
  './pwa/staff/icons/icon-512.png'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache =>
      Promise.allSettled(
        STATIC_ASSETS.map(asset => cache.add(asset))
      )
    )
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(key => key !== CACHE_NAME)
          .map(key => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const request = event.request;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);

  if (url.origin !== self.location.origin) {
    return;
  }

  const isStatic =
    url.pathname.includes('/pwa/staff/') ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.png') ||
    url.pathname.endsWith('.jpg') ||
    url.pathname.endsWith('.webp');

  if (isStatic) {
    event.respondWith(
      caches.match(request).then(cached => {
        const network = fetch(request)
          .then(response => {
            if (response && response.ok) {
              const copy = response.clone();
              caches.open(CACHE_NAME).then(cache =>
                cache.put(request, copy)
              );
            }
            return response;
          })
          .catch(() => cached);

        return cached || network;
      })
    );
    return;
  }

  event.respondWith(
    fetch(request).catch(() =>
      caches.match(request).then(cached =>
        cached || caches.match('./pwa/staff/offline.html')
      )
    )
  );
});

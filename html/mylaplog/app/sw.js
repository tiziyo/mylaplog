// MyLapLog Service Worker for PWA Installation & Offline Shell Support
const CACHE_NAME = 'mylaplog-pwa-v3';
const ASSETS_TO_CACHE = [
  '/',
  '/index.html',
  '/app/',
  '/app/index.html',
  '/manifest.json',
  '/app/manifest.json',
  '/icon-192.png',
  '/app/icon-192.png',
  '/icon-512.png',
  '/app/icon-512.png',
  '/favicon.ico',
  '/apple-touch-icon.png',
  '/app/apple-touch-icon.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(ASSETS_TO_CACHE).catch((err) => {
        console.warn('PWA Cache pre-fetch warning:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  // Pass through non-GET and API requests directly
  if (event.request.method !== 'GET' || event.request.url.includes('/api.php') || event.request.url.includes('/api/')) {
    return;
  }

  event.respondWith(
    fetch(event.request).catch(() => {
      return caches.match(event.request).then((res) => {
        if (res) return res;
        if (event.request.headers.get('accept') && event.request.headers.get('accept').includes('text/html')) {
          return caches.match('/index.html') || caches.match('/app/index.html');
        }
      });
    })
  );
});

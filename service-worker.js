const CACHE_NAME = 'nitpliks-shell-v50';
const APP_SHELL = [
  './',
  './index.html',
  './anime.html',
  './collections.html',
  './kdrama.html',
  './marvel.html',
  './my-list.html',
  './my-ratings.html',
  './release-calendar.html',
  './search-results.html',
  './watch.html',
  './account-page.html',
  './history.html',
  './collections-page.html',
  './notifications.html',
  './social.html',
  './admin-management.js?v=2',
  './visitor-log.js?v=2',
  './public-collection.html',
  './theme.css',
  './site-redesign.css',
  './watch-redesign.css',
  './homepage-overrides.css',
  './homepage-refresh.css',
  './details-modal.js',
  './sync.js',
  './manifest.json',
  './icon-192.png',
  './icon-512.png',
  './nitpliks-logo.png?v=40',
  './brand-logo.png',
  './logo.png',
  './logo.jpg'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(APP_SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys
          .filter(key => key !== CACHE_NAME)
          .map(key => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') {
    return;
  }

  const requestUrl = new URL(event.request.url);
  if (requestUrl.origin !== self.location.origin) {
    return;
  }
  if (requestUrl.pathname.endsWith('.php')) {
    return;
  }

  event.respondWith(
    fetch(event.request)
      .then(response => {
        if (response.ok) {
          const responseCopy = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, responseCopy));
        }
        return response;
      })
      .catch(() => caches.match(event.request).then(response => response || caches.match('./index.html')))
  );
});

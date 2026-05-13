// ANBG PAS Service Worker — static asset cache
const CACHE = 'anbg-static-v3';
const STATIC_EXTENSIONS = ['.css', '.js', '.woff2', '.woff', '.ttf', '.png', '.ico', '.svg', '.webp'];

self.addEventListener('install', function (e) {
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); })
            );
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (e) {
    var url = new URL(e.request.url);
    // Only cache GET requests for same-origin static assets
    if (e.request.method !== 'GET') return;
    if (url.origin !== self.location.origin) return;

    if (url.pathname === '/sw.js' || url.pathname.indexOf('/build/') === 0) {
        e.respondWith(
            fetch(e.request, { cache: 'no-store' }).then(function (response) {
                if (response && response.status === 200) {
                    return caches.open(CACHE).then(function (cache) {
                        cache.put(e.request, response.clone());
                        return response;
                    });
                }

                return response;
            }).catch(function () {
                return caches.match(e.request);
            })
        );
        return;
    }

    var ext = url.pathname.split('.').pop().toLowerCase();
    if (!STATIC_EXTENSIONS.some(function (s) { return url.pathname.endsWith(s); })) return;

    e.respondWith(
        caches.open(CACHE).then(function (cache) {
            return cache.match(e.request).then(function (cached) {
                var networkFetch = fetch(e.request).then(function (response) {
                    if (response && response.status === 200) {
                        cache.put(e.request, response.clone());
                    }
                    return response;
                }).catch(function () { return cached; });
                return cached || networkFetch;
            });
        })
    );
});

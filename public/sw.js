/**
 * Service worker for the installed app.
 *
 * The caching here is deliberately narrow, and the reason is worth stating
 * plainly: this app's screens are a capture form and a review queue, and a
 * stale review queue is actively harmful. Serving a cached page listing
 * proposals that were approved ten minutes ago invites an admin to rule on the
 * same record twice, and the second ruling 404s or errors with no explanation.
 * A slow queue is a nuisance; a wrong one wastes the review pass.
 *
 * So: HTML is never cached. What is cached is only content-addressed build
 * output (Vite writes a new filename for new bytes, so it cannot go stale), the
 * icons, and one offline page — the things whose absence turns a bad connection
 * into a blank screen.
 *
 * Bump CACHE when this file changes; activate then drops everything older.
 */

const CACHE = 'collectai-v1';

const PRECACHE = [
    '/offline.html',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable-512.png',
    '/manifest.json',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE)
            // addAll is all-or-nothing, and a single 404 would leave the worker
            // uninstalled with no offline page at all. Each entry is allowed to
            // fail on its own instead.
            .then((cache) => Promise.allSettled(PRECACHE.map((url) => cache.add(url))))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

/** Content-hashed Vite output and the icons: same URL always means same bytes. */
const isImmutable = (url) =>
    url.pathname.startsWith('/build/') || url.pathname.startsWith('/icons/');

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Anything that isn't a plain GET is left entirely alone. The capture
    // upload and the approve/reject posts must reach the server or fail loudly
    // — a service worker that retries or buffers them would produce duplicate
    // submissions and rulings applied out of order.
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // Photos live on the R2 custom domain and the catalog is public: caching
    // other origins here would mean guessing at their freshness rules.
    if (url.origin !== self.location.origin) {
        return;
    }

    if (isImmutable(url)) {
        event.respondWith(
            caches.match(request).then(
                (hit) =>
                    hit ??
                    fetch(request).then((response) => {
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(CACHE).then((cache) => cache.put(request, copy));
                        }

                        return response;
                    }),
            ),
        );

        return;
    }

    // Navigations: always the network, with the offline page as the only
    // fallback. Note this never writes HTML into the cache — the fallback is
    // the one page precached at install, and it says "you are offline" rather
    // than impersonating a screen with real data on it.
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
    }

    // Everything else — /storage/*, health checks, anything added later —
    // falls through to the network untouched.
});

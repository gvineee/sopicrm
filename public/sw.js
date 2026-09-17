// ODA CRM service worker — app-shell/offline fallback only.
//
// Scope (spec section 17 + hard constraints):
//  - Only static build assets (/build/**, icons, manifest, the offline
//    fallback page) are precached/runtime-cached. Authorized/per-user
//    responses (Inertia page payloads, /api/**, anything carrying a
//    session) are NEVER put in this cache — spec 17: "ავტორიზებული
//    responses-ის საერთო cache დაუშვებელია."
//  - Cache versioning: bump CACHE_VERSION on any change to the precache
//    list or fetch strategy below; the activate handler deletes every
//    cache that doesn't match the current version.
//  - Update flow is safe by construction: this file never touches
//    IndexedDB (drafts/photos live there — see resources/js/lib/
//    offlineQueue.ts) and never force-activates itself. It only takes
//    over once the page explicitly posts {type:'SKIP_WAITING'} — see
//    resources/js/lib/pwa.ts — which happens only after a deliberate
//    user click on the "update available" banner.

const CACHE_VERSION = 'v1';
const SHELL_CACHE = `oda-shell-${CACHE_VERSION}`;
const RUNTIME_CACHE = `oda-runtime-${CACHE_VERSION}`;
const OFFLINE_URL = '/offline.html';

// Small, fixed precache — everything else (hashed /build/ assets) is added
// to RUNTIME_CACHE lazily on first fetch (stale-while-revalidate below), so
// this list never needs to track Vite's per-build hashed filenames.
const PRECACHE_URLS = [
    OFFLINE_URL,
    '/manifest.webmanifest',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            .then((cache) => cache.addAll(PRECACHE_URLS))
            .catch((error) => {
                // Never let a single missing precache asset block install —
                // the offline fallback degrades gracefully instead.
                console.warn('[sw] precache failed', error);
            }),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) =>
                                key !== SHELL_CACHE && key !== RUNTIME_CACHE,
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

function isCacheableStaticAsset(url) {
    return (
        url.origin === self.location.origin &&
        (url.pathname.startsWith('/build/') ||
            url.pathname.startsWith('/icons/') ||
            url.pathname === '/manifest.webmanifest' ||
            url.pathname === '/favicon.ico' ||
            url.pathname === '/favicon.svg' ||
            url.pathname === '/apple-touch-icon.png')
    );
}

function isNavigationRequest(request) {
    return (
        request.mode === 'navigate' ||
        (request.method === 'GET' &&
            request.headers.get('accept')?.includes('text/html'))
    );
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        // Never intercept mutating requests (POST/PUT/PATCH/DELETE) — those
        // carry idempotency keys and must always hit the network/queue
        // logic in resources/js/lib/offlineQueue.ts, never the SW cache.
        return;
    }

    const url = new URL(request.url);

    if (isCacheableStaticAsset(url)) {
        // Stale-while-revalidate: instant repeat loads, still self-healing
        // when a new build ships (the new hashed filename is a cache miss
        // anyway; this only helps re-fetching the *same* hashed asset).
        event.respondWith(
            caches.open(RUNTIME_CACHE).then(async (cache) => {
                const cached = await cache.match(request);
                const networkFetch = fetch(request)
                    .then((response) => {
                        if (response.ok) {
                            cache.put(request, response.clone());
                        }
                        return response;
                    })
                    .catch(() => undefined);

                return cached ?? (await networkFetch) ?? Response.error();
            }),
        );
        return;
    }

    if (isNavigationRequest(request)) {
        // Network-first for pages (they carry auth/tenant state and must
        // never be served stale-and-authorized to the wrong session); on
        // failure (offline / DNS / server down) fall back to the generic
        // offline shell, never to a cached authorized page.
        event.respondWith(
            fetch(request).catch(
                () =>
                    caches.match(OFFLINE_URL, { cacheName: SHELL_CACHE }) ??
                    Response.error(),
            ),
        );
        return;
    }

    // Everything else (API calls, etc.) passes straight through to the
    // network with no SW involvement.
});

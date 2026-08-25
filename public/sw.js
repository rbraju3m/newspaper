/**
 * Service worker.
 *
 * Bangladeshi mobile networks drop constantly, so the priorities are:
 *   1. never show the browser's dead-dinosaur page
 *   2. let a reader re-open something they already read, offline
 *   3. never serve a stale headline when the network is actually available
 *
 * Strategy per request type:
 *   navigations  → network-first, fall back to cached page, then /offline
 *   hashed assets→ cache-first (the filename changes when content changes)
 *   images       → stale-while-revalidate, capped
 *   everything else → network, no caching
 */

const VERSION = 'v1';
const SHELL = `shell-${VERSION}`;
const PAGES = `pages-${VERSION}`;
const ASSETS = `assets-${VERSION}`;
const IMAGES = `images-${VERSION}`;

const OFFLINE_URL = new URL('offline', self.registration.scope).pathname;

const PAGE_LIMIT = 60;
const IMAGE_LIMIT = 120;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL)
            .then((cache) => cache.addAll([OFFLINE_URL]))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => !k.endsWith(VERSION)).map((k) => caches.delete(k)),
            ))
            .then(() => self.clients.claim()),
    );
});

/** Trim a cache to a maximum entry count, oldest first. */
async function trim(cacheName, max) {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    if (keys.length <= max) return;
    await Promise.all(keys.slice(0, keys.length - max).map((k) => cache.delete(k)));
}

function isHashedAsset(url) {
    return url.pathname.includes('/build/assets/');
}

function isImage(request) {
    return request.destination === 'image';
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only ever touch same-origin GETs. Never cache POST/PATCH — a cached
    // comment submission would be actively harmful.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Admin and account pages are per-user and often stale-sensitive.
    if (url.pathname.includes('/admin') || url.pathname.includes('/account')) return;

    if (request.mode === 'navigate') {
        event.respondWith(handleNavigation(request));
        return;
    }

    if (isHashedAsset(url)) {
        event.respondWith(cacheFirst(request, ASSETS));
        return;
    }

    if (isImage(request)) {
        event.respondWith(staleWhileRevalidate(request, IMAGES, IMAGE_LIMIT));
    }
});

async function handleNavigation(request) {
    try {
        const response = await fetch(request);

        // Only cache successful HTML responses.
        if (response.ok && response.headers.get('content-type')?.includes('text/html')) {
            const cache = await caches.open(PAGES);
            cache.put(request, response.clone());
            trim(PAGES, PAGE_LIMIT);
        }

        return response;
    } catch {
        const cached = await caches.match(request);
        if (cached) return cached;

        const offline = await caches.match(OFFLINE_URL);
        return offline ?? new Response('অফলাইন', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' },
        });
    }
}

async function cacheFirst(request, cacheName) {
    const cached = await caches.match(request);
    if (cached) return cached;

    const response = await fetch(request);
    if (response.ok) {
        const cache = await caches.open(cacheName);
        cache.put(request, response.clone());
    }
    return response;
}

async function staleWhileRevalidate(request, cacheName, max) {
    const cached = await caches.match(request);

    const network = fetch(request).then(async (response) => {
        if (response.ok) {
            const cache = await caches.open(cacheName);
            cache.put(request, response.clone());
            trim(cacheName, max);
        }
        return response;
    }).catch(() => cached);

    return cached ?? network;
}

/** Lets the page tell a waiting worker to take over immediately. */
self.addEventListener('message', (event) => {
    if (event.data === 'skip-waiting') self.skipWaiting();
});

/* ── Breaking-news push ───────────────────────────────────────────────────
   The subscription and delivery plumbing is not built yet, but the handlers
   are here so an existing installed worker can receive them once it is. */
self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload;
    try { payload = event.data.json(); } catch { return; }

    event.waitUntil(self.registration.showNotification(payload.title ?? 'ব্রেকিং নিউজ', {
        body: payload.body ?? '',
        icon: payload.icon ?? 'images/icon-192.png',
        badge: 'images/icon-192.png',
        tag: payload.tag ?? 'breaking',
        renotify: true,
        data: { url: payload.url ?? '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = event.notification.data?.url ?? '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            const existing = clients.find((c) => 'focus' in c);
            return existing ? existing.focus().then((c) => c.navigate(target)) : self.clients.openWindow(target);
        }),
    );
});

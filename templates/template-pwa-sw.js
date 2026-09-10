// template-pwa-sw.js
// Written to the WordPress root as pwa-sw.js by rm_maybe_refresh_pwa_files()
// (includes/pwa-handler.php). Available placeholders:
//      [siteUrl]        URL of the main site
//      [livePagesUrl]   URL of the live pages
//      [pwaScope]       relative path to the live pages, eg '/live/'
//      [pwaStartPage]   relative path to the PWA start page, eg '/live/?resume=1'
//      [iconFolderUrl]  URL of the folder containing the icons (in the plugin folder /img)
//      [cacheVersion]   plugin version plus a hash of this template; names the offline cache

self.addEventListener('push', function (event) {
    if (!(self.Notification && self.Notification.permission === 'granted')) {
        return;
    }
    let data = {};
    if (event.data) {
        data = event.data.json();
    }
    const title = data.title || 'Race Update';
    const options = {
        body: data.body || 'Please get in touch with race master for more details.',
        data: data.url || '', // standard url to open on notification click. no external URL allowed here! // TODO: special "resources" page for each event? --> goal: provide important info and links to the participants (schedule, local directions, lunch order, etc.)
        icon: data.icon || '[iconFolderUrl]/icon_192.png',
        badge: data.badge || '[iconFolderUrl]/icon_192.png',
    };
    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    /*event.notification.close();
      event.waitUntil(
      clients.openWindow(event.notification.data)
    ); */
    // event.notification holds all the data from the push notification .data, .title, .body, .icon etc
    const eventData = event.notification.data;
    //event.notification.close();  // disable to keep the notification open

    // The path you open should be inside the PWA's scope, usually matching the "start_url" in your manifest.
    const pwaStartPage = '[pwaStartPage]';
    const pwaScope = '[pwaScope]';

    event.waitUntil(
        // Query all open windows/tabs controlled by this service worker.
        clients.matchAll({ type: 'window', includeUncontrolled: false })
            .then((clientList) => {
                // If there's already an open window for the PWA, focus it.
                for (const client of clientList) {
                    //console.log("Client.url: " + client.url); // URL the client is currently displaying
                    // find window/tab that is open and matches the PWA start URL
                    if (client.url.includes(pwaScope) && 'focus' in client) {
                        if ('navigate' in client && eventData && eventData !== '') {
                            // if supplied, open the URL from the notification
                            client.navigate(eventData);
                        }
                        return client.focus();
                    }
                }

                // Otherwise, open a new window in the PWA scope.
                // If the user has the PWA installed, most modern browsers will launch it in standalone mode.
                if (eventData && eventData !== '') {
                    // if supplied, open the URL from the notification
                    return clients.openWindow(eventData);
                }
                // else open the PWA start URL
                return clients.openWindow(pwaStartPage);
            }
        )
    );
});

// ---------------------------------------------------------------------------------------------
// Offline (L6 in docs/live-webapp-improvements.md)
//
// The worker is a fallback, not a faster path: while the network answers, a page and its files
// come from the network exactly as they would without a worker, and a kept copy is shown only
// when the network does not answer -- at once when the device knows it is offline, after
// NETWORK_DEADLINE on a link that is up but not delivering.
//
// Network first for everything, including files whose URL carries ?ver=, and for three reasons:
// the page HTML is not static (it carries the race's live flag, which decides whether the page
// polls at all); the modules reached through relative imports -- rm-m-dataLoader.js among them --
// never carry a version, and must not be paired with newer modules after an update; and a file
// changed without a version bump would otherwise be served from the cache for as long as the
// cache lives, where the browser's own cache at least revalidates on a reload.
//
// The race JSON under /uploads/races/ is not touched at all. The loader keeps the payload in
// localStorage itself, and it has to reach the network for the timestamp: a kept timestamp would
// let the freshness pill claim a currency the page does not have.
//
// Every cache this worker owns starts with CACHE_PREFIX and is named for [cacheVersion];
// activation deletes the other ones, so a release or a change to this file starts empty.
// ---------------------------------------------------------------------------------------------

const CACHE_PREFIX = 'rm-live-';
const CACHE_NAME = CACHE_PREFIX + '[cacheVersion]';
const SCOPE_PATH = '[pwaScope]';

// How long a request may take before its kept copy is shown instead. The request carries on
// regardless, and refreshes the copy if it does come back.
const NETWORK_DEADLINE = 5000;

// Once a request has failed or run out its deadline, the link is presumed bad for this long, and
// a page's files are answered from their kept copies at once. Without it the page would wait out
// its deadline and then every file on it would wait out its own. Pages themselves always get
// their chance at the network, so that the first page loaded after reception returns is a fresh
// one; any request that comes back ends the presumption early.
const BAD_LINK_FOR = 30000;
let badLinkUntil = 0;

const STATIC_FILE = /\.(?:m?js|css|woff2?|ttf|otf|png|jpe?g|gif|svg|webp|ico)$/i;

function isRaceData(url) {
    return url.pathname.includes('/uploads/races/');
}

function isStaticFile(url) {
    return STATIC_FILE.test(url.pathname);
}

function skipTheNetwork(isPage) {
    return self.navigator.onLine === false || (!isPage && Date.now() < badLinkUntil);
}

// Whether a fresh response is the same file as the kept copy, going by the validators the
// server sends for static files. Keeps the cache from being rewritten on every page view.
function sameFile(kept, response) {
    const etag = response.headers.get('ETag');
    if (etag) {
        return etag === kept.headers.get('ETag');
    }
    const modified = response.headers.get('Last-Modified');
    return !!modified && modified === kept.headers.get('Last-Modified');
}

// Fetch, read the whole body, keep a copy when it is worth keeping, and hand back a response the
// page can use. Reading the body *before* answering is deliberate: a fading link delivers the
// headers and then stalls, and a response passed on at the headers would stall the page with it.
// Resolving only once the bytes are in is what lets the deadline fall back to the kept copy.
// It is the same lesson as the loader's deadline covering the body read (docs/data-flow.md).
async function fetchAndKeep(request) {
    let response;
    try {
        response = await fetch(request);
    } catch (e) {
        badLinkUntil = Date.now() + BAD_LINK_FOR;
        throw e;
    }
    badLinkUntil = 0;
    // Redirects (opaque for navigations), errors and anything already redirected pass on
    // unchanged, and nothing is kept of them.
    if (response.type !== 'basic' || !response.ok || response.redirected) {
        return response;
    }
    // Neither is anything the server says must not be stored. WordPress sends exactly that to a
    // logged-in user (wp_get_nocache_headers()), whose pages carry the admin bar and their name;
    // those stay out of a cache that outlives the login. An anonymous live page carries no
    // Cache-Control at all, so what a spectator sees is kept as before.
    if (/\bno-store\b/i.test(response.headers.get('Cache-Control') || '')) {
        return response;
    }
    const body = await response.blob();
    const headers = new Headers(response.headers);
    // The body is already decoded; the copies must not claim otherwise.
    headers.delete('content-encoding');
    headers.delete('content-length');
    const init = { status: response.status, statusText: response.statusText, headers };
    const cache = await caches.open(CACHE_NAME);
    const kept = await cache.match(request, { ignoreVary: true });
    if (!kept || !sameFile(kept, response)) {
        await cache.put(request, new Response(body, init));
    }
    return new Response(body, init);
}

async function findKept(request, isPage) {
    const cache = await caches.open(CACHE_NAME);
    const exact = await cache.match(request, { ignoreVary: true });
    if (exact || !isPage) {
        return exact;
    }
    // The start URL carries ?resume=1 and the link back to the selection page ?rm_race=; the
    // page behind either is the same page, so a copy kept under another query will do.
    return cache.match(request, { ignoreVary: true, ignoreSearch: true });
}

async function networkFirst(request, network, isPage) {
    const kept = await findKept(request, isPage);
    if (!kept) {
        return network.catch(() => (isPage ? offlinePage() : Response.error()));
    }
    if (skipTheNetwork(isPage)) {
        return kept;
    }
    return new Promise((resolve) => {
        let done = false;
        const answer = (response) => {
            if (!done) {
                done = true;
                resolve(response);
            }
        };
        network.then(
            // A server error during an event is no better than no connection; the kept copy is.
            (response) => answer(response.status >= 500 ? kept : response),
            () => answer(kept)
        );
        setTimeout(() => {
            if (!done) {
                badLinkUntil = Date.now() + BAD_LINK_FOR;
                answer(kept);
            }
        }, NETWORK_DEADLINE);
    });
}

// A page that was never opened on this device has no copy to fall back on. Saying so beats the
// browser's own error page, which reads as though the site were down.
function offlinePage() {
    const html =
        '<!doctype html><html lang="en"><head><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width, initial-scale=1">' +
        '<title>No connection</title></head>' +
        '<body style="font-family:system-ui,sans-serif;max-width:32rem;margin:0 auto;padding:2rem 1rem;line-height:1.5">' +
        '<h1 style="font-size:1.4rem">No connection</h1>' +
        '<p>This page has not been opened on this device before, so there is no copy of it to show.</p>' +
        '<p>Pages you have already opened are kept, and open without a connection.</p>' +
        '<p><button type="button" onclick="location.reload()" style="font:inherit;padding:.6rem 1.2rem">Try again</button></p>' +
        '</body></html>';
    return new Response(html, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin || isRaceData(url)) {
        return; // straight to the network, as if there were no worker
    }

    const isPage = request.mode === 'navigate';
    if (!isPage && !isStaticFile(url)) {
        return; // REST, admin-ajax and the like: not ours to keep
    }
    const network = fetchAndKeep(request);
    // Let the request finish and refresh the kept copy even when the copy has already answered.
    event.waitUntil(network.then(() => {}, () => {}));
    event.respondWith(networkFirst(request, network, isPage));
});

// A page's first visit happens before this worker controls it, so nothing that visit loaded went
// through the fetch handler. js/pwa-sw-register.js therefore sends the page's own URL and the URLs
// it loaded, and whatever is not kept yet is fetched and kept now. That makes the *first* visit
// survive a lost connection -- the one a spectator at the trackside is most likely to have had.
// Sent on every page view, so anything already kept costs a cache lookup and nothing else.
//
// The selection page is always added. The installed app starts there -- the manifest's start_url
// is [pwaStartPage], which js/rm-live-resume.js turns into the race last viewed -- and someone who
// installed the app from a race page has usually never opened it. Without a copy of it the app
// could not start offline at all, even with every race page it needs kept.
self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data.type !== 'rm-keep-page' || !Array.isArray(data.urls)) {
        return;
    }
    const startPage = new URL(SCOPE_PATH, self.location.origin).href;
    event.waitUntil(keepUrls(data.urls.slice(0, 200).concat(startPage)));
});

// On a first visit the page sends twice -- once when the worker is ready, once when it takes
// control -- so a URL already being fetched is joined rather than fetched again.
const keeping = new Map();

async function keepUrls(hrefs) {
    const cache = await caches.open(CACHE_NAME);
    await Promise.all(hrefs.map(async (href) => {
        let url;
        try {
            url = new URL(href, self.location.href);
        } catch (e) {
            return;
        }
        url.hash = '';
        if (url.origin !== self.location.origin || isRaceData(url)) {
            return;
        }
        if (!isStaticFile(url) && !url.pathname.startsWith(SCOPE_PATH)) {
            return;
        }
        if (keeping.has(url.href)) {
            return keeping.get(url.href);
        }
        const job = (async () => {
            if (await cache.match(url.href, { ignoreVary: true })) {
                return;
            }
            try {
                await fetchAndKeep(new Request(url.href, { credentials: 'same-origin' }));
            } catch (e) {
                // No connection again; there will be another page view.
            }
        })();
        keeping.set(url.href, job);
        try {
            await job;
        } finally {
            keeping.delete(url.href);
        }
    }));
}

self.addEventListener('install', () => {
    // Take over at once rather than waiting for every tab of the old worker to close.
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const names = await caches.keys();
            await Promise.all(
                names
                    .filter((name) => name.startsWith(CACHE_PREFIX) && name !== CACHE_NAME)
                    .map((name) => caches.delete(name))
            );
            // Control the pages that are already open, so their next request is handled here.
            await self.clients.claim();
        })()
    );
});

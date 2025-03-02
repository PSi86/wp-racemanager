// template-pwa-sw.js
// available placeholders: 
//      [siteUrl] URL of the main site
//      [livePagesUrl] URL of the live pages
//      [pwaStartPage] relative path to the PWA start page eg:'/wp/live/'
//      [iconFolderUrl] URL of the folder containing the icons (in the plugin folder /img)

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

/* self.addEventListener('install', function (event) {
  self.skipWaiting();
}); */

// Typical install event: can handle caching of static assets here
self.addEventListener('install', (event) => {
    // Usually you'd do event.waitUntil(caches.open('my-cache')... etc.)
    // Then call skipWaiting() if you want to automatically activate this SW 
    // even while older versions are running:
    self.skipWaiting();
});

// The activate event, where we claim clients
self.addEventListener('activate', (event) => {
    // Make the new service worker take control of clients (pages) as soon as possible
    event.waitUntil(
        (async () => {
            await self.clients.claim(); // TODO: test impact of this line
        })()
    );
});

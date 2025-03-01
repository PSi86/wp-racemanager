// pwa-sw.js

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
    body: data.body || 'Your selected pilot is in the lineup!',
    icon: data.icon || 'https://wherever-we-are.com/wp/wp-content/plugins/wp-racemanager/img/icon_192.png',
    data: data.url || '/wp/live/',
    badge: data.badge || 'https://wherever-we-are.com/wp/wp-content/plugins/wp-racemanager/img/icon_192.png',
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  /*event.notification.close();
    event.waitUntil(
    clients.openWindow(event.notification.data)
  ); */
  event.notification.close();

  // The path you open should be inside the PWA's scope, usually matching the "start_url" in your manifest.
  const pwaStartUrl = '/wp/live/';

  event.waitUntil(
    // Query all open windows/tabs controlled by this service worker.
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      // If there's already an open window for the PWA, focus it.
      for (const client of clientList) {
        // Adjust this check to match your actual path or domain
        if (client.url.includes(pwaStartUrl) && 'focus' in client) {
          return client.focus();
        }
      }

      // Otherwise, open a new window in the PWA scope.
      // If the user has the PWA installed, most modern browsers will launch it in standalone mode.
      return clients.openWindow(pwaStartUrl);
    })
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
      // Clears old caches if needed, then claims clients
      // For example:
      // const keys = await caches.keys();
      // for (const key of keys) {
      //   if (key !== 'my-cache') {
      //     await caches.delete(key);
      //   }
      // }
      await self.clients.claim();
    })()
  );
});

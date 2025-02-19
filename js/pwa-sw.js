// pwa-sw.js

self.addEventListener('push', function(event) {
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
      icon: data.icon || '/icon_192.png',
      data: data.url || '/wp/live/',
      badge: '/icon_192.png',
      vibrate: [200, 100, 200, 100, 200, 100, 200]
    };
    event.waitUntil(self.registration.showNotification(title, options));
  });
  
  self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    event.waitUntil(
      clients.openWindow(event.notification.data)
    );
  });
  
  self.addEventListener('install', function(event) {
    self.skipWaiting();
  });
  
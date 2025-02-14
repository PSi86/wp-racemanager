// pwa-sw.js

self.addEventListener('push', function(event) {
    let data = {};
    if (event.data) {
      data = event.data.json();
    }
    const title = data.title || 'Race Update';
    const options = {
      body: data.body || 'Your selected pilot is in the lineup!',
      icon: data.icon || '/icon-192.png',
      data: data.url || '/'
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
  
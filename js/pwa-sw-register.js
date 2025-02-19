// pwa-sw-register.js

if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      //navigator.serviceWorker.register(pluginsUrl + '/wp-racemanager/js/pwa-sw.js')
      navigator.serviceWorker.register('/wp/pwa-sw.js?ver=1.0.0', {
        scope: '/wp/live/'
      })
        .then(function(registration) {
          console.log('Service Worker registered with scope:', registration.scope);
        })
        .catch(function(error) {
          console.error('Service Worker registration failed:', error);
        });
    });
  }
  
  // Adjust pluginsUrl if necessary; assumes the plugin is under /wp-content/plugins/
  var pluginsUrl = '/wp/wp-content/plugins';
  
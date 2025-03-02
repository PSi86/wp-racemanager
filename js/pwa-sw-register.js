// pwa-sw-register.js

if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      //navigator.serviceWorker.register(pluginsUrl + '/wp-racemanager/js/pwa-sw.js?ver=1.0.3')
      //navigator.serviceWorker.register('/wp/pwa-sw.js?ver=1.0.3')
      navigator.serviceWorker.register('/wp/pwa-sw.js?ver=1.0.3', {
        scope: '/wp/live/' // works as "/wp/live/" or as full URL
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
  // TODO: write this file during plugin activation?
  // Alternative: use WP's wp_localize_script() to pass the URL to this script
  //var pluginsUrl = '/wp/wp-content/plugins';
  
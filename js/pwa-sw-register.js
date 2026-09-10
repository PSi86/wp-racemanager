// pwa-sw-register.js

if ('serviceWorker' in navigator) {
    // Tell the worker what this page is made of, so it can keep whatever it does not have yet.
    // A first visit happens before the worker controls the page, and right after an update the
    // new worker starts with an empty cache -- in both cases nothing of this page went through its
    // fetch handler. The worker skips what it already keeps, so sending on every view is cheap.
    var rmKeepPage = function (worker) {
        if (!worker) {
            return;
        }
        var urls = [location.href].concat(
            performance.getEntriesByType('resource').map(function (entry) { return entry.name; })
        );
        worker.postMessage({ type: 'rm-keep-page', urls: urls });
    };

    navigator.serviceWorker.addEventListener('controllerchange', function () {
        rmKeepPage(navigator.serviceWorker.controller);
    });

    window.addEventListener('load', function() {
      //navigator.serviceWorker.register(pluginsUrl + '/wp-racemanager/js/pwa-sw.js?ver=1.0.3')
      //navigator.serviceWorker.register('/wp/pwa-sw.js?ver=1.0.3')
      navigator.serviceWorker.register('/pwa-sw.js?ver=1.0.3', {
        scope: '/live/' // works as "/wp/live/" or as full URL
      })
        .then(function(registration) {
          console.log('Service Worker registered with scope:', registration.scope);
        })
        .catch(function(error) {
          console.error('Service Worker registration failed:', error);
        });

      navigator.serviceWorker.ready.then(function (registration) {
        rmKeepPage(registration.active);
      });
    });
  }

  // Adjust pluginsUrl if necessary; assumes the plugin is under /wp-content/plugins/
  // TODO: write this file during plugin activation?
  // Alternative: use WP's wp_localize_script() to pass the URL to this script
  //var pluginsUrl = '/wp/wp-content/plugins';

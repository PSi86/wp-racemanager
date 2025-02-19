<?php
// includes/livepage-handler.php
// Live Microsite Session & URL Rewrite
// Uses PHP sessions to persist a selected race post ID and rewrites all /live/ page URLs to include the race_id parameter.
// Starts a session (only for /live pages) and appends the race_id from the session to all links in the /live hierarchy.

// Reminder: if you need to check for permissions, you can use a callback like this:
//'permission_callback' => function() {
//    return current_user_can( 'edit_posts' );
//},

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Start a PHP session if one isn't already active.
 */
function rm_start_session() {
    if ( rm_is_live_page() && !session_id() ) {
        session_start();
    }
}
//add_action( 'init', 'rm_start_session', 1 );
add_action( 'template_redirect', 'rm_start_session', 1 ); // Load a bit later, so the "rm_is_live_page" function can be used

/**
 * Helper function to retrieve the current race post ID.
 * It checks the URL first and then the session.
 *
 * @return int|false The race ID, or false if not set.
 */
function rm_get_current_race_id() {
    if ( ! empty( $_GET['race_id'] ) ) {
        $race_id = absint( $_GET['race_id'] );
        $_SESSION['live_race_id'] = $race_id; // Store in session for future requests
        return absint( $race_id );
    } elseif ( ! empty( $_SESSION['live_race_id'] ) ) {
        return absint( $_SESSION['live_race_id'] );
    }
    return false;
}

/**
 * Rewrite all page accesses inside the /live/ hierarchy by appending the race_id parameter.
 * This ensures that the URL is shareable and always includes the race selection.
 */
function rm_rewrite_live_urls() {
    // Only run on the frontend.
    if ( is_admin() ) {
        return;
    }

    // Check if the current URL is part of the /live/ hierarchy.
    $request_uri = $_SERVER['REQUEST_URI'];
    $rm_live_page_id = get_option('rm_live_page_id');
    // We check if the URI starts with "/live" (covers /live, /live/pilots, etc.).
    //if ( preg_match( '#^/live(/|$)#', $request_uri ) ) {
    //if ( is_page( array( 'pilots', 'bracket', 'stats', 'next-up' ) ) ) {
    if ( rm_is_live_page() ) {
        // If the race_id parameter is missing...
            $race_id = rm_get_current_race_id();
            if ( $race_id ) {
                // Build the current full URL with the race_id parameter appended.
                $redirect_url = add_query_arg( 'race_id', $race_id, $request_uri );
                // Avoid potential redirect loops.
                if ( $request_uri !== $redirect_url ) {
                    wp_redirect( $redirect_url );
                    exit;
                }
            }
            elseif ( $rm_live_page_id && ! is_page( $rm_live_page_id ) ) {
                // no race_id found in the session and no race_id in the URL and not already on the landing page
                $redirect_url = home_url( '/live/' );
                // Avoid potential redirect loops.
                if ( $request_uri !== $redirect_url ) {
                    // Redirect to the landing page
                    wp_redirect( $redirect_url );
                    exit;
                }
                // Redirect to the landing page
                //wp_redirect( home_url( '/live/' ) );
                //exit;
            }
    }
}
add_action( 'template_redirect', 'rm_rewrite_live_urls', 2 );

/**
 * Shortcode to display pilots data.
 * Usage: [rm_pilots]
 */
function rm_sc_select_race( $atts ) {
    $race_id = rm_get_current_race_id();
    // TODO - this should not be necessary because of the rm_rewrite_live_urls function and its redirect to /live/
    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }
    // TODO - Race selection and optional pilot selection for notifications
    // Retrieve pilots data from race post meta (change 'pilots_data' as needed).
    $pilots = get_post_meta( $race_id, 'pilots_data', true );
    ob_start();
    if ( $pilots ) {
        echo '<div class="rm-pilots-content">' . esc_html( $pilots ) . '</div>';
    } else {
        //echo '<p>No pilots data available for this race.</p>';
        echo '<h1>Select your pilot for race notifications</h1>
            <form id="subscribe-form">
                <select id="pilot-select">
                <option value="">-- Select a pilot --</option>
                <option value="17">D3C4Y</option>
                <option value="pilot2">Pilot 2</option>
                <option value="pilot3">Pilot 3</option>
                <!-- Add more pilot options as needed -->
                </select>
                <button type="submit">Subscribe for notifications</button>
            </form>

            <script>
                // Replace with your actual public VAPID key (Base64 URL-safe encoded)
                const publicVapidKey = "BLtUYsLUAC8rx0_LlTs4SEIcOwKPv1N4ydICV_f3C3v4aGlh1wLs2Bg-XNwzTndptldsZB3gm4RuYVBTUAK1jGQ";

                // Check that service workers are supported
                if (\'serviceWorker\' in navigator) {
                window.addEventListener(\'load\', () => {
                    registerServiceWorkerAndSubscribe();
                });
                } else {
                console.error("Service workers are not supported by this browser.");
                }

                async function registerServiceWorkerAndSubscribe() {
                try {
                    // Register the service worker
                    const register = await navigator.serviceWorker.register(\'/wp/wp-content/plugins/wp-racemanager/js/pwa-sw.js\', {
                    scope: \'/\'
                    });
                    console.log("Service Worker registered.");

                    // Request permission for notifications
                    const permission = await Notification.requestPermission();
                    if (permission !== "granted") {
                    console.error("Notification permission was not granted.");
                    return;
                    }

                    // Subscribe for push notifications
                    const subscription = await register.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicVapidKey)
                    });
                    console.log("Push subscription:", subscription);

                    // Save subscription globally so the form can use it
                    window.pushSubscription = subscription;
                } catch (error) {
                    console.error("Error during service worker registration or push subscription:", error);
                }
                }

                // Utility to convert the public key to Uint8Array
                function urlBase64ToUint8Array(base64String) {
                const padding = \'=\'.repeat((4 - base64String.length % 4) % 4);
                const base64 = (base64String + padding)
                    .replace(/-/g, \'+\')
                    .replace(/_/g, \'/\');
                const rawData = window.atob(base64);
                const outputArray = new Uint8Array(rawData.length);
                for (let i = 0; i < rawData.length; ++i) {
                    outputArray[i] = rawData.charCodeAt(i);
                }
                return outputArray;
                }

                // Handle the form submission to send the pilot selection and subscription to the server
                document.getElementById(\'subscribe-form\').addEventListener(\'submit\', async function(e) {
                e.preventDefault();
                const pilot = document.getElementById(\'pilot-select\').value;
                if (!pilot) {
                    alert("Please select a pilot.");
                    return;
                }
                if (!window.pushSubscription) {
                    alert("Push subscription is not available.");
                    return;
                }

                // Send subscription info and the selected pilot to the PHP endpoint
                const response = await fetch(\'/subscribe.php\', {
                    method: \'POST\',
                    headers: {
                    \'Content-Type\': \'application/json\'
                    },
                    body: JSON.stringify({
                    pilot: pilot,
                    subscription: window.pushSubscription
                    })
                });
                if (response.ok) {
                    alert("Subscription saved successfully!");
                } else {
                    alert("Failed to save subscription.");
                }
                });
            </script>';
    }
    return ob_get_clean();
}
add_shortcode( 'rm_select_race', 'rm_sc_select_race' );

/**
 * Shortcode function for pilot selection and push notification registration.
 * Usage: [pilot_push]
 */
function my_pilot_push_shortcode() {
  add_action('wp_enqueue_scripts', function() {
    // Only enqueue if the shortcode is actually present on the page:
    // You can do an is_page() check or use a 'do_shortcode' detect. 
    // For simplicity, we’ll just always enqueue for front-end.

    // Register the script
    wp_register_script(
        'rm_pwa_subscribe',
        plugin_dir_url(__DIR__) . 'js/pwa-subscribe.js',
        [],   // dependencies if needed, e.g. ['wp-element'] 
        '1.0.0',
        true  // in footer
    );

    // Localize or pass in data if needed
    // For example, if you want to pass the VAPID public key from PHP:
    wp_localize_script('rm_pwa_subscribe', 'RmPushData', [
        'restUrl'     => home_url('/wp-json/rm/v1/subscription'),
        'publicVapid' => 'BLtUYsLUAC8rx0_LlTs4SEIcOwKPv1N4ydICV_f3C3v4aGlh1wLs2Bg-XNwzTndptldsZB3gm4RuYVBTUAK1jGQ',
    ]);

    // Finally enqueue
    wp_enqueue_script('rm_pwa_subscribe');
  });

  ob_start();
  ?>
  <div id="pilot-push-container">
    <h2>Select Your Pilot for Race Notifications</h2>
    <form id="pilot-push-form">
      <select id="pilot-select">
        <option value="">-- Select a pilot --</option>
        <!-- Suppose "D3C4Y" is a pilot callsign, and 17 is the race_id. 
             Or maybe you're just using the same ID for race_id and callsign? 
             We'll do callsign as the visible text. -->
        <option value="D3C4Y" data-race-id="396" data-pilot-id="17">D3C4Y</option>
        <option value="Kwadastrophe" data-race-id="396" data-pilot-id="24">Kwadastrophe</option>
        <option value="KidCe" data-race-id="396" data-pilot-id="12">KidCe</option>
        <option value="MaxDax" data-race-id="396" data-pilot-id="16">MaxDax</option>
        <!-- Add more pilot callsigns as needed -->
      </select>
      <button type="submit" id="subscribe-button">Subscribe</button>
    </form>
    <!-- 
        Status elements to show subscription status or messages to the user. We can manipulate these via JS.
    -->
    <div id="subscription-status"></div>
  </div>

  <?php
  return ob_get_clean();
}
add_shortcode('pilot_push', 'my_pilot_push_shortcode');

/**
 * Shortcode to display pilots data.
 * Usage: [rm_pilots]
 */
function rm_pilots_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();
    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }
    // Retrieve pilots data from race post meta (change 'pilots_data' as needed).
    $pilots = get_post_meta( $race_id, 'pilots_data', true );
    ob_start();
    if ( $pilots ) {
        echo '<div class="rm-pilots-content">' . esc_html( $pilots ) . '</div>';
    } else {
        echo '<p>No pilots data available for this race.</p>';
    }
    return ob_get_clean();
}
add_shortcode( 'rm_pilots', 'rm_pilots_shortcode' );

/**
 * Shortcode to display bracket data.
 * Usage: [rm_bracket]
 */
function rm_bracket_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();
    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }
    $bracket = get_post_meta( $race_id, 'bracket_data', true );
    ob_start();
    if ( $bracket ) {
        echo '<div class="rm-bracket-content">' . esc_html( $bracket ) . '</div>';
    } else {
        echo '<p>No bracket data available for this race.</p>';
    }
    return ob_get_clean();
}
add_shortcode( 'rm_bracket', 'rm_bracket_shortcode' );

/**
 * Shortcode to display stats data.
 * Usage: [rm_stats]
 */
function rm_stats_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();
    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }
    $stats = get_post_meta( $race_id, 'stats_data', true );
    ob_start();
    if ( $stats ) {
        echo '<div class="rm-stats-content">' . esc_html( $stats ) . '</div>';
    } else {
        echo '<p>No stats data available for this race_id:' . esc_html( $race_id ) . '</p>';
    }
    return ob_get_clean();
}
add_shortcode( 'rm_stats', 'rm_stats_shortcode' );

/**
 * Optional: Output a navigation menu for the microsite.
 * Place this in your custom header for /live/ pages.
 */
/* function rm_microsite_navigation() {
    ?>
    <nav class="rm-microsite-nav">
        <ul>
            <li><a href="<?php echo esc_url( home_url( '/live/' ) ); ?>">Race Selection</a></li>
            <li><a href="<?php echo esc_url( rm_get_microsite_url( 'pilots' ) ); ?>">Pilots</a></li>
            <li><a href="<?php echo esc_url( rm_get_microsite_url( 'bracket' ) ); ?>">Bracket</a></li>
            <li><a href="<?php echo esc_url( rm_get_microsite_url( 'stats' ) ); ?>">Stats</a></li>
        </ul>
    </nav>
    <?php
} */

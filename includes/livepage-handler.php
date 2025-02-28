<?php
// includes/livepage-handler.php
// Live Microsite Session & URL Rewrite
// Uses PHP sessions to persist a selected race post ID and rewrites all /live/ page URLs to include the race_id parameter.
// Starts a session (only for /live pages) and appends the race_id from the session to all links in the /live hierarchy.
//
// JS Documentation:
// - All JS Modules are named with the 'rm-m-' prefix, e.g. 'rm-m-pilotSelector'.
// - The script is a module script, so it is enqueued with 'wp_enqueue_script_module'.
// - The script uses a configuration object that is passed to the main module script.
// - The configuration object is printed as an inline script before the main module script is loaded. (localize_script is not supported for ES6 modules)
// - The configuration object is used to set up the module and its dependencies.
// dataLoader is a singleton module that loads data from the server and stores it in the browser's local storage.
//      - other modules can subscribe to data changes
//      - if the refreshInterval is set, the dataLoader will periodically check for updates
// pilotSelector is a module that handles the pilot selection dropdown and subscription button.
//      - it listens to dataLoader updates and updates the dropdown accordingly
//      - it populates the dropdown with the configured id (pilotSelectorId) with pilots from the dataLoader
//      - it saves the selected pilot in the browser's local storage
// pushSubscription is a module that handles the push notification subscription. // not yet implemented, using pwa-subscribe.js (not a module)
//      - it can work on the same data as the pilotSelector
// filterBracketData is a module that processes the data from the dataLoader. // not yet implemented
//      - it can be configured to process the data in different ways
// renderEngine is a module that renders the data. // not yet implemented
//      - it can be configured with a theme and to update on data changes


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
    $rm_live_page_id = get_option('rm_live_page_id'); // id of the live site landing page
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
 * Shortcode function for pilot selection and push notification registration.
 * Usage: [pilot_push]
 */
function rm_sc_select_race() {
  add_action('wp_enqueue_scripts', function() {
    // Only enqueue if the shortcode is actually present on the page:
    // You can do an is_page() check or use a 'do_shortcode' detect. 
    // For simplicity, we’ll just always enqueue for front-end.

    // Register the script
    wp_register_script(
        'rm_pwa_subscribe',
        plugin_dir_url(__DIR__) . 'js/pwa-subscribe.js',
        [],   // dependencies if needed, e.g. ['wp-element'] 
        '1.0.3',
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
    
    // Register the main module script.
/*     wp_register_script_module(
        'rm-main-script',
        plugin_dir_url( __DIR__ ) . 'js/rm-main.js',
        array(), // ['jquery'] // no dependency needed here, as dynamic imports are handled in main.js
        '1.0.3',
        true
    ); */

    wp_register_script_module(
        'rm-pilotSelector',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-pilotSelector.js',
        array(), // ['jquery'] // no dependency needed here, as dynamic imports are handled in main.js
        '1.0.3',
        true
    );
    
/*     // Prepare the configuration array.
    $config = array(
        'refreshInterval'=> 0, // no refresh here (in milliseconds)
        'timestampUrl'   => $file_timestamp_url,
        'dataUrl'        => $file_data_url,
        'storageKey'     => 'myBracketData',
        'timeout'        => 9000,  // in milliseconds
        'loadBracketEngine'=> false,
        'loadUiRenderer' => false
    );

    // Convert the configuration to JSON.
    $config_json = wp_json_encode($config);
    // Inject the configuration as an inline script before your module loads.
    $inline_script = "window.RmJsConfig = {$config_json};";
    wp_add_inline_script( 'rm-main-script', $inline_script, 'before' ); */

    function rm_print_js_config() {
        $race_id = rm_get_current_race_id();
        $upload_dir = wp_upload_dir();
        $upload_path_url = trailingslashit( $upload_dir['baseurl'] ) . 'races/';
    
        $filename_timestamp = $race_id . '-timestamp.json';
        $filename_data = $race_id . '-data.json';
    
        $file_timestamp_url = $upload_path_url . $filename_timestamp;
        $file_data_url = $upload_path_url . $filename_data;

        /* $config = array(
            'refreshInterval'=> 0, // no refresh here (in milliseconds)
            'timestampUrl'   => $file_timestamp_url,
            'dataUrl'        => $file_data_url,
            'storageKey'     => $race_id,
            'timeout'        => 9000,  // in milliseconds
            'loadBracketEngine'=> false,
            'loadUiRenderer' => false,
        ); */
        // TODO: create helper function to parameterize the configuration
        $config = array(
            'dataLoader' => [
                'refreshInterval'   => 0, // no refresh here (in milliseconds)
                'timestampUrl'      => $file_timestamp_url,
                'dataUrl'           => $file_data_url,
                'storageKey'        => $race_id,
                'timeout'           => 9000,  // in milliseconds (optional)
                'loadBracketEngine' => false,
                'loadUiRenderer'    => false,
            ],
            'pilotSelector' => [
                'pilotSelectorId'    => 'pilotSelector',
            ],
            'displayHeats' => [
                'filterCheckboxId'             => 'filterCheckbox',
            ],
            'renderEngine' => [
                'theme'             => 'dark',
                'updateOnDataChange'=> true,
            ],
            'pushSubscription' => [ // not yet a module - poc under rm-m-pwa-subscribe.js
                'restUrlSubscribe'  => home_url('/wp-json/rm/v1/subscription'),
                'publicVapid'       => 'BLtUYsLUAC8rx0_LlTs4SEIcOwKPv1N4ydICV_f3C3v4aGlh1wLs2Bg-XNwzTndptldsZB3gm4RuYVBTUAK1jGQ',
            ],
            'dataProcessor' => [
                'processingMode'    => 'fast',
                'enableLogging'     => true,
            ],
            'renderEngine' => [
                'theme'             => 'dark',
                'updateOnDataChange'=> true,
            ],
        );
        
        /* // Convert the configuration to JSON.
        $config_json = wp_json_encode($config);
        // Inject the configuration as an inline script before your module loads.
        $inline_script = "window.RmJsConfig = {$config_json};";
        wp_add_inline_script( 'rm-pilotSelector', $inline_script, 'before' ); //rm-config */
        
        echo '<script>window.RmJsConfig = ' . wp_json_encode($config) . ';</script>';
    }
    add_action( 'wp_head', 'rm_print_js_config' ); // necessary if using one of the js modules
    

    // Pass configuration to your main module.
    // does currently not work with ES6 modules (wp_register_script_module)
    /*  wp_localize_script( 'rm-main-script', 'RmJsConfig', array(
        'refreshInterval'=> 0, // no refresh here (in milliseconds)
        'timestampUrl'   => '$file_timestamp_url',
        'dataUrl'        => '$file_data_url',
        'storageKey'     => 'myBracketData',
        'timeout'        => 9000,  // in milliseconds
        'loadBracketEngine'=> false,
        'loadUiRenderer' => false
    ) ); */

    // Enqueue the main module.
    //wp_enqueue_script_module( 'rm-main-script' );
    wp_enqueue_script_module( 'rm-pilotSelector' );

    /* wp_localize_script('rm-bracketview', 'wp_vars', [
        'webmode' => 'live', // live mode -> data is asynchronously updated
        'timestampUrl' => $file_timestamp_url, // rest_url('rh/v1/latest-timestamp'), OR '/wp/wp-content/'.$results[0]->id.'-timestamp.json'
        'dataUrl' => $file_data_url, // rest_url('rh/v1/latest-data'), OR '/wp/wp-content/'.$results[0]->id.'-data.json'
        'refreshInterval' => 10000, // Polling interval in milliseconds (5 seconds)
    ]); */
  });

  ob_start();
  ?>
  <div id="pilot-push-container">
    <h2>Select Pilot for Race Notifications</h2>
    <form id="pilot-push-form">
      <select id="pilotSelector">
        <option value="0">-- Select a pilot --</option>
        <!-- Pilots are added by pilotSelector module -->
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
add_shortcode('pilot_push', 'rm_sc_select_race'); //rm_select_race

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
  add_action('wp_enqueue_scripts', function() {
    $race_id = rm_get_current_race_id();

    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }

    // Enqueue custom CSS and JS
    wp_enqueue_style(
        'rm-sc-viewer-css', 
        plugin_dir_url( __DIR__ ) . 'css/rm_viewer.css'
    );

    wp_enqueue_script(
        'rm-bracket-template', 
        plugin_dir_url( __DIR__ ) . 'js/class_templates_V1.js', 
        ['jquery'], 
        '1.0.3', 
        false
    );

    wp_register_script_module(
        'rm-displayHeats',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayHeats.js',
        array(), // ['jquery'] // no dependency needed here, as dynamic imports are handled in main.js
        '1.0.3',
        true
    );

    function rm_print_js_config() {
        $race_id = rm_get_current_race_id();
        $upload_dir = wp_upload_dir();
        $upload_path_url = trailingslashit( $upload_dir['baseurl'] ) . 'races/';
    
        $filename_timestamp = $race_id . '-timestamp.json';
        $filename_data = $race_id . '-data.json';
    
        $file_timestamp_url = $upload_path_url . $filename_timestamp;
        $file_data_url = $upload_path_url . $filename_data;

        // TODO: create helper function to parameterize the configuration
        $config = array(
            'dataLoader' => [
                'refreshInterval'   => 10000, // 10 sec (in milliseconds)
                'timestampUrl'      => $file_timestamp_url,
                'dataUrl'           => $file_data_url,
                'storageKey'        => $race_id,
                'timeout'           => 9000,  // in milliseconds (optional)
                'loadBracketEngine' => false,
                'loadUiRenderer'    => false,
            ],
            'pilotSelector' => [
                'pilotSelectorId'    => 'pilotSelector',
            ],
            'displayHeats' => [
                'filterCheckboxId'   => 'filterCheckbox',
            ],
        );
        
        /* // Convert the configuration to JSON.
        $config_json = wp_json_encode($config);
        // Inject the configuration as an inline script before your module loads.
        $inline_script = "window.RmJsConfig = {$config_json};";
        wp_add_inline_script( 'rm-pilotSelector', $inline_script, 'before' ); //rm-config */
        
        echo '<script>window.RmJsConfig = ' . wp_json_encode($config) . ';</script>';
    }
    add_action( 'wp_head', 'rm_print_js_config' ); // necessary if using one of the js modules

    // Enqueue the module.
    wp_enqueue_script_module( 'rm-displayHeats' );
  });

  ob_start();
  ?>
        <div class="web-controls">
            <label for="pilotSelector">Highlight Pilot: </label>
            <select id="pilotSelector">
                <option value="0">-- Select a Pilot --</option>
            </select>
            <label>
                <input type="checkbox" id="filterCheckbox"> Filter by Selected Pilot
            </label>
        </div>
        <!-- id needs to be *-display (eg. elimination-display) and class must be raceclass-container -->
        <div id="elimination-display" class="raceclass-container"></div>
        <div id="qualifying-display" class="raceclass-container"></div>
        <div id="training-display" class="raceclass-container"></div>
  <?php
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

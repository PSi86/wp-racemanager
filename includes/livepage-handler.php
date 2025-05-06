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
// pushSubscription is a module that handles the push notification subscription.
//      - it can work on the same data as the pilotSelector
// displayHeats is a module that displays the heats with the option to filter for or highlight a specific pilot.
//      - it listens to dataLoader updates and updates the display accordingly
//      - it uses the pilotSelector module
//      - filterCheckbox element is handled by this module
// displayStats is a module that displays the pilot stats with the option to filter for or highlight a specific pilot.
//      - it listens to dataLoader updates and updates the display accordingly
// displayNextUp is a module that displays the next up pilots and allows for push notification subscription.
//      - it listens to dataLoader updates and updates the display accordingly
// displayPilotStats is a module that displays the pilot stats as a sortable table.
//      - it listens to dataLoader updates and updates the display accordingly


// Reminder: if you need to check for permissions, you can use a callback like this:
//'permission_callback' => function() {
//    return current_user_can( 'edit_posts' );
//},

if (!defined('ABSPATH')) exit; // Exit if accessed directly

$rm_js_config = null; // Global variable to store the JS configuration object

/**
 * Start a PHP session if one isn't already active.
 */
function rm_start_session() {
    if ( !session_id() ) {
        session_start();
    }
}

//add_action( 'template_redirect', 'rm_start_session', 1 ); // Load a bit later, so the "rm_is_live_page" function can be used

/**
 * Helper function to retrieve the current race post ID.
 * It checks the URL first and then the session.
 *
 * @return int|false The race ID, or false if not set.
 */
function rm_get_current_race_id() {
    // TODO: make more universal by also checking current post type to be a cpt "race", then the post ID is the race_id
    // TODO: add a check for shortcode attributes to override the race_id
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
//add_action( 'template_redirect', 'rm_rewrite_live_urls', 2 );

/**
 * Shortcode to display pilots data.
 * Usage: [rm_pilots]
 */
function rm_pilots_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();

    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }

    wp_enqueue_style(
        'rm-sc-rotorhazard-css', 
        plugin_dir_url( __DIR__ ) . 'css/rotorhazard.css'
    );
    wp_enqueue_style(
        'rm-sc-viewer-css', 
        plugin_dir_url( __DIR__ ) . 'css/rm_viewer.css'
    );

    wp_register_script_module(
        'rm-pilot-stats',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayPilotStats.js',
        ['jquery'], // ['jquery'] // no dependency needed here, as dynamic imports are handled in main.js
        '1.0.3',
        true
    );

    // Load dataLoader, pilotSelector, pushSubscription
    global $rm_js_config;
    $rm_js_config = array(
        'dataLoader' => [], // Auto-filled by rm_print_js_module_config
        'pilotSelector' => [
            'pilotSelectorId'    => 'pilotSelector',
        ],
        'displayStats' => [
            'filterCheckboxId'   => 'filterCheckbox', // no filter checkbox here
        ],
    );
        
    add_action( 'wp_head', 'rm_print_js_module_config' ); // necessary if using one of the js modules

    // Enqueue the module.
    wp_enqueue_script_module( 'rm-pilot-stats' );

    ob_start();
    ?>
        <!-- <div class="web-controls">
            <label for="pilotSelector">Highlight Pilot: </label>
            <select id="pilotSelector">
                <option value="0">-- Select a Pilot --</option>
            </select>
            <label>
                <input type="checkbox" id="filterCheckbox"> Filter by Selected Pilot
            </label>
        </div> -->
        <div id="pilot-stats" class="responsive-wrap js-container"></div>
    <?php
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

    global $rm_js_config;
    $rm_js_config = array(
        'dataLoader' => [], // Auto-filled by rm_print_js_module_config
        'pilotSelector' => [
            'pilotSelectorId'    => 'pilotSelector',
        ],
        'displayHeats' => [
            'filterCheckboxId'   => 'filterCheckbox',
        ],
    );

    add_action( 'wp_head', 'rm_print_js_module_config' ); // necessary if using one of the js modules
    // Enqueue the module.
    wp_enqueue_script_module( 'rm-displayHeats' );

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

    wp_enqueue_style(
        'rm-sc-rotorhazard-css', 
        plugin_dir_url( __DIR__ ) . 'css/rotorhazard.css'
    );
    wp_register_script_module(
        'rm-stats',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayStats.js',
        ['jquery'], // ['jquery'] // no dependency needed here, as dynamic imports are handled in main.js
        '1.0.3',
        true
    );

    // Load dataLoader, pilotSelector, pushSubscription
    global $rm_js_config;
    $rm_js_config = array(
        'dataLoader' => [], // Auto-filled by rm_print_js_module_config
        'pilotSelector' => [
            'pilotSelectorId'    => 'pilotSelector',
        ],
        'displayStats' => [
            'filterCheckboxId'   => 'filterCheckbox', // no filter checkbox here
        ],
    );
        
    add_action( 'wp_head', 'rm_print_js_module_config' ); // necessary if using one of the js modules

    // Enqueue the module.
    wp_enqueue_script_module( 'rm-stats' );

    ob_start();
    ?>
        <!-- <div class="web-controls">
            <label for="pilotSelector">Highlight Pilot: </label>
            <select id="pilotSelector">
                <option value="0">-- Select a Pilot --</option>
            </select>
            <label>
                <input type="checkbox" id="filterCheckbox"> Filter by Selected Pilot
            </label>
        </div> -->
        <div id="results" class="js-container"></div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'rm_stats', 'rm_stats_shortcode' );

function rm_nextup_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();

    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( home_url( '/live/' ) ) . '">Race Selection</a> page.</p>';
    }

    // TODO: centralize the meta queries for live pages and make them available as global variables
    // TODO: show final results when race is locked
    $race_live = get_post_meta( $race_id, '_race_live', true );
    if ( ! $race_live ) {
        return '<p>This race is over.</p>';
    }
    
    wp_enqueue_style(
        'rm-sc-viewer-css', 
        plugin_dir_url( __DIR__ ) . 'css/rm_viewer.css'
    );

    wp_register_script_module(
        'rm-nextUp',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayNextUp.js',
        array(), // ['jquery'] // no dependency needed here, as dynamic imports are handled in main.js
        '1.0.3',
        true
    );

    // Generate a nonce using the wp_rest action (the default for REST API endpoints)
    $nonce = wp_create_nonce( 'rm_ajax_nonce' );

    // Load dataLoader, pilotSelector, pushSubscription
    global $rm_js_config;
    $rm_js_config = array(
        'dataLoader' => [],
        'pilotSelector' => [
            'pilotSelectorId'    => 'pilotSelector',
        ],
        'pushSubscription' => [
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'nonce'                => $nonce,
            'publicVapid'          => '', // Insert public VAPID key here
            'formId'               => 'pilot-push-form',
            'subscribeButtonId'    => 'subscribe-button',
            'subscriptionStatusId' => 'subscription-status',
        ],
        'displayHeats' => [
            'filterCheckboxId'   => 'none', // no filter checkbox here
        ],
        'displayLog' => [
            'containerId'     => 'log-container', // ID of the container for the log display
        ],
    );
        
    add_action( 'wp_head', 'rm_print_js_module_config' ); // necessary if using one of the js modules

    // Enqueue the module.
    wp_enqueue_script_module( 'rm-nextUp' );

    ob_start();
    ?>
    <div id="nextup-display" class="raceclass-container"></div>
    <div id="pilot-push-container">
      <h2>Select Pilot for Notifications</h2>
      <form id="pilot-push-form">
        <select id="pilotSelector">
          <option value="0">-- Select a pilot --</option>
          <!-- Pilots are added by pilotSelector module -->
        </select>
        <input style="margin-top: var(--wp--preset--spacing--x-small);" type="submit" id="subscribe-button"></input>
      </form>
      <div id="subscription-status"></div>
    </div>
    <!-- Race Log Container -->
    <h2>Race Log</h2>
    <div id="log-container"></div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'rm_nextup', 'rm_nextup_shortcode' );

function rm_print_js_module_config() {
    global $rm_js_config;
    if ( ! $rm_js_config ) {
        return;
    }

    $race_id = rm_get_current_race_id();
    $upload_dir = wp_upload_dir();
    $upload_path_url = trailingslashit( $upload_dir['baseurl'] ) . 'races/';

    $filename_timestamp = $race_id . '-timestamp.json';
    $filename_data = $race_id . '-data.json';

    $file_timestamp_url = $upload_path_url . $filename_timestamp;
    $file_data_url = $upload_path_url . $filename_data;

    $race_live = get_post_meta( $race_id, '_race_live', true ); // Using meta value here instead of time since last upload

    // Example configuration object for the js modules module.
    /* $config = array(
        'dataLoader' => [
            'refreshInterval'   => 10000, // no refresh here (in milliseconds)
            'timestampUrl'      => $file_timestamp_url,
            'dataUrl'           => $file_data_url,
            'storageKey'        => $race_id,
            'timeout'           => 9000,  // in milliseconds (optional)
        ],
        'pilotSelector' => [
            'pilotSelectorId'    => 'pilotSelector',
        ],
        'pushSubscription' => [
            'restUrlSubscribe'     => home_url('/wp-json/rm/v1/subscription'),
            'publicVapid'          => 'your-public-vapid-key',
            'formId'               => 'pilot-push-form',
            'subscribeButtonId'    => 'subscribe-button',
            'subscriptionStatusId' => 'subscription-status',
        ],
        'displayHeats' => [
            'filterCheckboxId'   => 'none', // no filter checkbox here
        ],
    ); */
    
    // Merge the provided configuration with the defaults.
    if ( isset($rm_js_config['dataLoader']) && is_array($rm_js_config['dataLoader']) ) {
        $dataloader_defaults = array(
            'refreshInterval' => $race_live ? 10000 : 0,
            'timestampUrl'    => $file_timestamp_url,
            'dataUrl'         => $file_data_url,
            'storageKey'      => $race_id,
            'timeout'         => 9000,
        );
        // Merge defaults with the provided dataLoader config.
        $rm_js_config['dataLoader'] = wp_parse_args($rm_js_config['dataLoader'], $dataloader_defaults);
    }
    
    echo '<script>window.RmJsConfig = ' . wp_json_encode($rm_js_config) . ';</script>';
}
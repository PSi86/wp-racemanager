<?php
// includes/livepage-handler.php
// Shortcodes for the live micro-site and the JS module configuration they need.
//
// The selected race comes from the URL, resolved by includes/live-routing.php. There is no
// server-side session: rm_get_current_race_id() reads the race segment of the path (or a
// legacy ?race_id parameter, which is redirected to the canonical URL).
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

require_once __DIR__ . '/race-files.php'; // rm_race_part_filename(), for the loader's configuration
require_once __DIR__ . '/countries.php'; // rm_flag_base_url(): where the views find the flags

$rm_js_config = null; // Global variable to store the JS configuration object

/**
 * Contribute one shortcode's configuration to the object printed for the JS modules.
 *
 * Every shortcode used to assign $rm_js_config outright. Two live shortcodes on one page --
 * say the pilot stats above the bracket -- meant the second assignment threw the first one's
 * settings away, and the module that ran first came up unconfigured. (The wp_head action was
 * never the problem: add_action() stores a string callback under its own name, so registering
 * it four times still fires it once.)
 *
 * Merging is per module, so two shortcodes that configure different modules both get what they
 * asked for. Where both configure the *same* module the later one wins, key by key; that is
 * unchanged, and configuring one module twice with different element IDs on a single page
 * would need per-instance configuration rather than one global object.
 *
 * @param array $config Module name => settings.
 * @return void
 */
function rm_add_js_module_config( array $config ) {
    global $rm_js_config;

    if ( ! is_array( $rm_js_config ) ) {
        $rm_js_config = array();
    }

    foreach ( $config as $module => $settings ) {
        if ( is_array( $settings ) && isset( $rm_js_config[ $module ] ) && is_array( $rm_js_config[ $module ] ) ) {
            $rm_js_config[ $module ] = array_merge( $rm_js_config[ $module ], $settings );
        } else {
            $rm_js_config[ $module ] = $settings;
        }
    }

    // Only works because block themes render the template before wp_head(); accepted by
    // decision, see docs/wordpress-update-audit.md (B3).
    add_action( 'wp_head', 'rm_print_js_module_config' );
}

/**
 * The freshness indicator the four live views share.
 *
 * Returns the element js/rm-m-updateStatus.js fills, and enqueues that module together with its
 * stylesheet. Markup, script and styles travel together on purpose: three of the four live
 * shortcodes load css/rm_viewer.css and rm_stats does not, so hanging the styles off an existing
 * sheet would have left one view unstyled.
 *
 * **Emitted at most once per request.** Two live shortcodes on one page is a case this file
 * already handles elsewhere (see rm_add_js_module_config), and here it matters twice over: a
 * second element would duplicate the id, and since the indicator is positioned fixed, the two
 * would sit on top of each other in the corner. The enqueues stay unconditional -- they are
 * idempotent, and every shortcode has to be able to ask without knowing who came first.
 *
 * A <button> rather than a <div>: the whole pill is the control, tapping it forces a check, and
 * "is it stuck?" deserves an answer the visitor can reach. It is emitted `hidden` and the module
 * reveals it. Without JavaScript nothing polls, so there is no honest status to report and no
 * check to force, and an empty pill would be worse than none.
 *
 * Both this module and the view's own module import js/rm-m-dataLoader.js relatively. The two
 * specifiers resolve to the same URL, so the browser instantiates the loader once and the
 * singleton stays a singleton -- which is what lets the indicator report on the very same loader
 * the tables are fed by.
 *
 * @return string Markup for the first caller in a request, an empty string for any after it.
 */
function rm_update_status_markup() {
    // The plugin version rather than a hand-written one. A literal here has to be remembered
    // every time the file changes, and it was already wrong: this stylesheet was rewritten twice
    // -- the pill redesign and the [hidden] fix -- while the string beside it stayed put. A
    // release bump now busts these on its own, which is the only version anybody actually keeps
    // in step.
    wp_enqueue_style(
        'rm-update-status-css',
        plugin_dir_url( __DIR__ ) . 'css/rm-update-status.css',
        array(),
        WP_RACEMANAGER_VERSION
    );

    wp_register_script_module(
        'rm-updateStatus',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-updateStatus.js',
        array(), // the loader is a relative import inside the module, as in every other view
        WP_RACEMANAGER_VERSION
    );
    wp_enqueue_script_module( 'rm-updateStatus' );

    rm_add_js_module_config( array(
        'updateStatus' => [
            'containerId' => 'rm-update-status',
        ],
    ) );

    // A global rather than a static, so the test suite can render each shortcode as what it
    // really is -- a separate request -- instead of four in one process sharing one flag.
    if ( ! empty( $GLOBALS['rm_update_status_emitted'] ) ) {
        return '';
    }
    $GLOBALS['rm_update_status_emitted'] = true;

    return '<button type="button" id="rm-update-status" class="rm-update-status" hidden></button>';
}

/**
 * The row of view tabs fixed to the foot of a phone's screen (L9 in
 * docs/live-webapp-improvements.md): one tap to another view of the same race.
 *
 * Measured on production before it existed: below 600 px the theme's navigation folds the view
 * links into its burger, so every switch took two taps, and nothing on the page said the other
 * views were there at all. The row lists the live page's child pages in their page order, links
 * each one to the current race with rm_live_url(), and marks the current one with aria-current.
 * Plain links, so it needs no JavaScript.
 *
 * Emitted once per request, for the same reason as the pill: it is fixed, so a second copy would
 * lie on top of the first. And its stylesheet is enqueued only here, which is what allows that
 * stylesheet to make room at the foot of the page and to lift the pill above the row -- wherever
 * the stylesheet is loaded, the row is on the page.
 *
 * @return string Markup for the first caller in a request that has a race, an empty string otherwise.
 */
function rm_view_tabs_markup() {
    if ( ! empty( $GLOBALS['rm_view_tabs_emitted'] ) ) {
        return '';
    }

    $race   = rm_get_current_race();
    $titles = rm_get_live_view_titles();
    // One view has nowhere to switch to.
    if ( ! $race || count( $titles ) < 2 ) {
        return '';
    }
    $GLOBALS['rm_view_tabs_emitted'] = true;

    wp_enqueue_style(
        'rm-view-tabs-css',
        plugin_dir_url( __DIR__ ) . 'css/rm-view-tabs.css',
        array(),
        WP_RACEMANAGER_VERSION
    );

    $current = rm_current_view_slug();
    $tabs    = '';
    foreach ( $titles as $view => $title ) {
        $tabs .= sprintf(
            '<a class="rm-view-tabs__tab" href="%s"%s>%s</a>',
            esc_url( rm_live_url( $race, $view ) ),
            $view === $current ? ' aria-current="page"' : '',
            esc_html( $title )
        );
    }

    return '<nav id="rm-view-tabs" class="rm-view-tabs" aria-label="Race views">' . $tabs . '</nav>';
}

/**
 * Shortcode to display pilots data.
 * Usage: [rm_pilots]
 */
function rm_pilots_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();

    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( rm_live_selection_url() ) . '">Race Selection</a> page.</p>';
    }

    wp_enqueue_style(
        'rm-sc-rotorhazard-css', 
        plugin_dir_url( __DIR__ ) . 'css/rotorhazard.css',
        array(),
        WP_RACEMANAGER_VERSION
    );
    wp_enqueue_style(
        'rm-sc-viewer-css', 
        plugin_dir_url( __DIR__ ) . 'css/rm_viewer.css',
        array(),
        WP_RACEMANAGER_VERSION
    );

    wp_register_script_module(
        'rm-pilot-stats',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayPilotStats.js',
        array(), // no dependency needed here, as dynamic imports are handled in the module itself
        WP_RACEMANAGER_VERSION
    );

    // Load dataLoader, pilotSelector, pushSubscription
    rm_add_js_module_config( array(
        'dataLoader' => [], // Auto-filled by rm_print_js_module_config
        'pilotSelector' => [
            'pilotSelectorId'    => 'pilotSelector',
        ],
        'displayStats' => [
            'filterCheckboxId'   => 'filterCheckbox', // no filter checkbox here
        ],
    ) );

    // Enqueue the module.
    wp_enqueue_script_module( 'rm-pilot-stats' );

    ob_start();
    ?>
        <?php echo rm_view_tabs_markup(); ?>
        <?php echo rm_update_status_markup(); ?>
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
        return '<p>No race selected. Please go back to the <a href="' . esc_url( rm_live_selection_url() ) . '">Race Selection</a> page.</p>';
    }

    // Enqueue custom CSS and JS
    wp_enqueue_style(
        'rm-sc-viewer-css',
        plugin_dir_url( __DIR__ ) . 'css/rm_viewer.css',
        array(),
        WP_RACEMANAGER_VERSION
    );
    // The filter's own styles, shared with the stats view, which cannot load rm_viewer.css --
    // see the note there.
    wp_enqueue_style(
        'rm-pilot-filter-css',
        plugin_dir_url( __DIR__ ) . 'css/rm-pilot-filter.css',
        array(),
        WP_RACEMANAGER_VERSION
    );

    // Since 1.10.0 the brackets are drawn from the heats' seeding (js/rm-m-bracketModel.js);
    // js/class_templates_V1.js is no longer needed here, only by the legacy [rm_viewer].
    wp_register_script_module(
        'rm-displayHeats',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayHeats.js',
        array(), // no dependency needed here, as dynamic imports are handled in the module itself
        WP_RACEMANAGER_VERSION
    );
    wp_register_script_module(
        'rm-displayStandings',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayStandings.js',
        array(),
        WP_RACEMANAGER_VERSION
    );

    rm_add_js_module_config( array(
        'dataLoader' => [], // Auto-filled by rm_print_js_module_config
        'pilotSelector' => [
            'pilotSelectorId'    => 'pilotSelector',
        ],
        'displayHeats' => [
            'filterCheckboxId'   => 'filterCheckbox',
            'flagBaseUrl'        => rm_flag_base_url(), // a pilot's flag next to the callsign (1.11.0)
        ],
        'displayStandings' => [
            'containerId'        => 'standings-display',
            'flagBaseUrl'        => rm_flag_base_url(),
        ],
    ) );
    // Enqueue the modules.
    wp_enqueue_script_module( 'rm-displayHeats' );
    wp_enqueue_script_module( 'rm-displayStandings' );

  ob_start();
  ?>
        <?php echo rm_view_tabs_markup(); ?>
        <?php echo rm_update_status_markup(); ?>
        <div class="web-controls">
            <label for="pilotSelector">Highlight Pilot: </label>
            <select id="pilotSelector">
                <option value="0">-- Select a Pilot --</option>
            </select>
            <label>
                <input type="checkbox" id="filterCheckbox"> Filter by Selected Pilot
            </label>
        </div>
        <!-- Every class of the race gets a section here, the newest on top, the heats without a class last (js/rm-m-displayHeats.js) -->
        <div id="raceclass-sections"></div>
        <div id="standings-display"></div>
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
        return '<p>No race selected. Please go back to the <a href="' . esc_url( rm_live_selection_url() ) . '">Race Selection</a> page.</p>';
    }

    wp_enqueue_style(
        'rm-sc-rotorhazard-css',
        plugin_dir_url( __DIR__ ) . 'css/rotorhazard.css',
        array(),
        WP_RACEMANAGER_VERSION
    );
    // The pilot filter only. Deliberately NOT rm_viewer.css, which is the bracket's stylesheet:
    // it redefines .node as a bracket race box, while here .node is RotorHazard's own class for
    // a lap-results column. Loading it in this view mangles the round detail under every heat.
    wp_enqueue_style(
        'rm-pilot-filter-css',
        plugin_dir_url( __DIR__ ) . 'css/rm-pilot-filter.css',
        array(),
        WP_RACEMANAGER_VERSION
    );
    wp_register_script_module(
        'rm-stats',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayStats.js',
        array(), // no dependency needed here, as dynamic imports are handled in the module itself
        WP_RACEMANAGER_VERSION
    );

    // Load dataLoader, pilotSelector, pushSubscription
    rm_add_js_module_config( array(
        'dataLoader' => [], // Auto-filled by rm_print_js_module_config
        'pilotSelector' => [
            'pilotSelectorId'    => 'pilotSelector',
        ],
        'displayStats' => [
            'filterCheckboxId'   => 'filterCheckbox', // no filter checkbox here
        ],
    ) );

    // Enqueue the module.
    wp_enqueue_script_module( 'rm-stats' );

    ob_start();
    ?>
        <?php echo rm_view_tabs_markup(); ?>
        <?php echo rm_update_status_markup(); ?>
        <div class="web-controls">
            <label for="pilotSelector">Highlight Pilot: </label>
            <select id="pilotSelector">
                <option value="0">-- Select a Pilot --</option>
            </select>
            <label>
                <input type="checkbox" id="filterCheckbox"> Filter by Selected Pilot
            </label>
        </div>
        <div id="results" class="js-container"></div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'rm_stats', 'rm_stats_shortcode' );

function rm_nextup_shortcode( $atts ) {
    $race_id = rm_get_current_race_id();

    if ( ! $race_id ) {
        return '<p>No race selected. Please go back to the <a href="' . esc_url( rm_live_selection_url() ) . '">Race Selection</a> page.</p>';
    }

    // TODO: centralize the meta queries for live pages and make them available as global variables
    // TODO: show final results when race is locked
    $race_live = get_post_meta( $race_id, '_race_live', true );
    if ( ! $race_live ) {
        // Nothing to come on a finished race, but the other views still have its results.
        return rm_view_tabs_markup() . '<p>This race is over.</p>';
    }
    
    wp_enqueue_style(
        'rm-sc-viewer-css', 
        plugin_dir_url( __DIR__ ) . 'css/rm_viewer.css',
        array(),
        WP_RACEMANAGER_VERSION
    );

    wp_register_script_module(
        'rm-nextUp',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayNextUp.js',
        array(), // no dependency needed here, as dynamic imports are handled in the module itself
        WP_RACEMANAGER_VERSION
    );

    // Generate a nonce using the wp_rest action (the default for REST API endpoints)
    $nonce = wp_create_nonce( 'rm_ajax_nonce' );

    // Load dataLoader, pilotSelector, pushSubscription
    rm_add_js_module_config( array(
        'dataLoader' => [],
        'pilotSelector' => [
            'pilotSelectorId'    => 'pilotSelector',
        ],
        'pushSubscription' => [
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'nonce'                => $nonce,
            'publicVapid'          => rm_get_vapid()['publicKey'], // From wp-config.php constants or the 'rm_vapid' option
            'formId'               => 'pilot-push-form',
            'subscribeButtonId'    => 'subscribe-button',
            'subscriptionStatusId' => 'subscription-status',
        ],
        'displayHeats' => [
            'filterCheckboxId'   => 'none', // no filter checkbox here
            'flagBaseUrl'        => rm_flag_base_url(), // a pilot's flag next to the callsign (1.11.0)
        ],
        'displayStandings' => [
            'containerId'        => 'ranking-container', // the "Final Ranking" under next up
            'flagBaseUrl'        => rm_flag_base_url(),
        ],
        'displayLog' => [
            'containerId'     => 'log-container', // ID of the container for the log display
        ],
    ) );

    // Enqueue the module.
    wp_enqueue_script_module( 'rm-nextUp' );

    ob_start();
    ?>
    <?php echo rm_view_tabs_markup(); ?>
    <?php echo rm_update_status_markup(); ?>
    <div id="nextup-display" class="raceclass-container"></div>
    <div id="ranking-container"></div>
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
    $upload_path_url = rm_get_race_data_url();

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
            // The index and the parts (L7). The loader turns to them only once the whole file has
            // said, with its rm_index, that the race has them: a race stored before 1.8.0 costs no
            // request for an index that is not there.
            'indexUrl'        => $upload_path_url . $race_id . '-index.json',
            'partUrl'         => $upload_path_url . rm_race_part_filename( $race_id, array( '%s' ) ),
            'storageKey'      => $race_id,
            'timeout'         => 9000,
        );
        // Merge defaults with the provided dataLoader config.
        $rm_js_config['dataLoader'] = wp_parse_args($rm_js_config['dataLoader'], $dataloader_defaults);
    }
    
    echo '<script>window.RmJsConfig = ' . wp_json_encode($rm_js_config) . ';</script>';
}
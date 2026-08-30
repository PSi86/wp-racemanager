<?php
/**
 * Plugin Name: WP RaceManager
 * Description: Provides REST API endpoints for RotorHazard: download pilot registrations, upload race results. The "Races" menu item will be populated with the latest races. For more information, see the plugin settings.
 * Version: 1.1.0
 * Author: Peter Simandl
 * Text Domain: wp-racemanager
 * Requires at least: 6.5
 * Requires PHP: 8.2
 */

// "Requires at least" is 6.5 because the live pages are built on the Script Modules API
// (wp_register_script_module()), which core added in that release.
//
// "Requires PHP" is 8.2 because minishlink/web-push pulls in web-token/jwt-library, which
// declares php >= 8.2. Without these two headers WordPress cannot warn about an incompatible
// update and would happily install the plugin into an environment where it dies.

// Define the namespace
namespace RaceManager;  // Use your preferred namespace if you have one.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define( 'WP_RACEMANAGER_VERSION', '1.1.0' ); // keep in sync with the plugin header and package.json
define( 'WP_RACEMANAGER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_RACEMANAGER_URL', plugin_dir_url( __FILE__ ) );
//define( 'WP_RACEMANAGER_ASSETS', WP_RACEMANAGER_URL . 'assets/build/' );


// Include required files


// Activation hook to create the database table

function rm_activate() {
    // Ensure Contact Form 7 is active.
    if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( 
            __( 'Contact Form 7 Plugin not installed or active', 'wp-racemanager' ), 
            __( 'Plugin Activation Error', 'wp-racemanager' ), 
            array( 'back_link' => true )
        );
    }
    // Create the database table for PWA subscriptions
    require_once plugin_dir_path(__FILE__) . 'includes/pwa-subscription-handler.php';
    \RaceManager\PWA_Subscription_Handler::create_db_table();

    // Generate the VAPID key pair for Web Push, unless keys are already configured
    // or subscriptions exist that new keys would invalidate.
    require_once plugin_dir_path(__FILE__) . 'includes/vapid-handler.php';
    rm_maybe_bootstrap_vapid_keys();

    // Create Registration Table
    require_once plugin_dir_path(__FILE__) . 'includes/admin-registrations.php';
    rm_create_registration_table();
    create_event_registration_cf7_form();

    // Create the service worker and manifest files
    require_once plugin_dir_path(__FILE__) . 'includes/pwa-handler.php';
    rm_create_file_from_template('template-pwa-sw.js', ABSPATH);
    rm_create_file_from_template('template-manifest.json', ABSPATH);

    // Register the /live/{race}/{view}/ rules and write them to the rewrite cache.
    require_once plugin_dir_path(__FILE__) . 'includes/live-routing.php';
    rm_live_rewrite_rules();
    rm_flush_live_rewrite_rules();

}
register_activation_hook(
    __FILE__,
    __NAMESPACE__ . '\\rm_activate'  // "RaceManager\\rm_activate"
);


final class WP_RaceManager {

    /**
     * Store a static instance of the plugin class.
     *
     * @var WP_RaceManager|null
     */
    private static $instance = null;

    public $pwa_subscription_handler;
    public $live_race_in_progress = false;

    /**
     * Instantiate or retrieve the existing instance of this class (Singleton).
     *
     * @return WP_RaceManager
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     *
     * We keep it private or protected if we want to force
     * usage of WP_RaceManager::instance() for the singleton pattern.
     */
    private function __construct() {
        // The following is a minimal approach.
        //add_action( 'plugins_loaded', [ $this, 'maybe_init_rest_handlers' ] );
        //require_once WP_RACEMANAGER_DIR . 'vendor/autoload.php'; // if you’re using Composer
        // First load helper functions or implement them here
        // Init global variables
        add_action( 'init', [ $this, 'is_a_race_live' ] ); // Check if a race has been updated in the last two hours
        
        // Load the REST API handling
        //require_once __DIR__ . '/../../../../vendor/autoload.php'; // Relative path to the vendor directory (currently in root of httpdocs)
        
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] ); // only called on REST API requests
        add_action( 'template_redirect', [ $this, 'handle_live_pages' ], 2 ); // called on every page load

        /* require_once plugin_dir_path( __FILE__ ) . 'includes/pwa-subscription-handler.php';
        $this->pwa_subscription_handler = new PWA_Subscription_Handler();
        require_once plugin_dir_path( __FILE__ ) . 'includes/race-data-functions.php';
        include_once plugin_dir_path(__FILE__) . 'includes/rest-handler.php'; */
        
        // END of REST API handling



        // TODO: Load only on admin pages
        include_once plugin_dir_path(__FILE__) . 'includes/settings-handler.php';

        // active on all pages
        require_once plugin_dir_path(__FILE__) . 'includes/vapid-handler.php'; // VAPID keys for Web Push (frontend needs the public key)
        require_once plugin_dir_path(__FILE__) . 'includes/race-data-functions.php'; // helpers for the per-race JSON files (path/URL), used by REST and the viewers
        require_once plugin_dir_path(__FILE__) . 'includes/race-dates.php'; // one canonical format for the event dates
        require_once plugin_dir_path(__FILE__) . 'includes/live-routing.php'; // resolves the selected race from the URL path
        require_once plugin_dir_path(__FILE__) . 'includes/pwa-handler.php'; // PWA meta/manifest; also refreshes the generated files in admin
        include_once plugin_dir_path(__FILE__) . 'includes/db-handler.php';
        include_once plugin_dir_path(__FILE__) . 'includes/ajax-subscription-handler.php'; // Handles all subscription-related AJAX requests for the RaceManager plugin.
        // active on every page
        require_once plugin_dir_path(__FILE__) . 'includes/seo-handler.php'; // SEO functions
        require_once plugin_dir_path(__FILE__) . 'includes/main-navigation-handler.php'; // filter function for main navigation to indicate live race in progress
        require_once plugin_dir_path(__FILE__) . 'includes/block-loader.php';
        include_once plugin_dir_path(__FILE__) . 'includes/cpt-handler.php'; //
        include_once plugin_dir_path(__FILE__) . 'includes/race-archive.php'; // Race archive page (ordering by event start date)
        require_once plugin_dir_path(__FILE__) . 'includes/admin-registrations.php'; // Admin functions for registrations
        include_once plugin_dir_path(__FILE__) . 'includes/sc-cf7-event-dropdown.php'; // SC for Contact Form 7
        include_once plugin_dir_path(__FILE__) . 'includes/cpt-meta-handler.php'; // cpt admin functions
        
        include_once plugin_dir_path(__FILE__) . 'includes/block-modifiers.php'; // filter modifiers for default wp blocks
        include_once plugin_dir_path(__FILE__) . 'includes/sc-gallery.php';
        
        include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_viewer.php'; // SC for heat viewer
        include_once plugin_dir_path(__FILE__) . 'includes/sc-race-log.php'; // SC for race log
        include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_registered.php'; // SC for registered pilots
        
        //include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_cards.php'; // SC for Shortcode
        //include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_tabs.php'; // SC for Shortcode
        
        // TODO: Include only on live pages request.

        
    }
    
    /**
     * Register the REST routes RotorHazard talks to.
     *
     * Runs on rest_api_init only, so none of this is loaded on ordinary page requests.
     */
    public function register_rest_routes() {
        // Load helper functions for RH JSON data
        require_once plugin_dir_path( __FILE__ ) . 'includes/race-data-functions.php';
        // Load instantiate PWA_Subscription_Handler.
        require_once plugin_dir_path( __FILE__ ) . 'includes/pwa-subscription-handler.php';
        // PWA class registers its rest routes in the constructor
        $this->pwa_subscription_handler = new PWA_Subscription_Handler(); 
        // Handle pilot download and results upload
        require_once plugin_dir_path(__FILE__) . 'includes/rest-handler.php';
        rm_register_rest_routes_rh();
    }

    public function handle_live_pages() {
        if ( $this->is_live_page() ) {
            // Routing and canonical redirects are handled by includes/live-routing.php,
            // which is loaded on every request and hooks template_redirect itself.
            include_once plugin_dir_path(__FILE__) . 'includes/livepage-handler.php';
            include_once plugin_dir_path(__FILE__) . 'includes/pwa-handler.php';
            rm_load_live_resources();
        }
    }

    public static function is_live_page() {
        // Only proceed on page requests.
        if ( ! is_page() ) {
            return false;
        }
    
        // Get the current page ID.
        $page_id = get_the_ID();
        
        // Retrieve the stored Live Races page ID.
        $rm_live_page_id = get_option('rm_live_page_id');
        if ( ! $rm_live_page_id ) {
            return false;
        }
        
        // Check if the current page is the Live Races page.
        if ( $page_id == $rm_live_page_id ) {
            return true;
        }
        
        // Check if the Live Races page is one of the ancestors of the current page.
        if ( in_array( $rm_live_page_id, get_post_ancestors( $page_id ) ) ) {
            return true;
        }
        
        return false;
    }

    public function is_a_race_live() {
        //
        // Generate the datetime string for two hours ago
        $two_hours_ago = date( 'Y-m-d H:i:s', strtotime( '-2 hours', current_time( 'timestamp' ) ) );

        $args = array(
            'post_type'      => 'race',
            'posts_per_page' => 1,              // Limit to one result
            'fields'         => 'ids',          // Only retrieve IDs for efficiency
            'meta_query'     => array(
                array(
                    'key'     => '_race_last_upload',
                    'value'   => $two_hours_ago,
                    'compare' => '>',
                    'type'    => 'DATETIME'
                ),
            ),
        );

        $query = new \WP_Query( $args );
        $this->live_race_in_progress = $query->have_posts();
        
        if($this->live_race_in_progress) {
            add_filter( 'render_block', 'rm_indicate_live_race', 10, 2 );
        }
        //return $query->have_posts();
    }
    
    public static function write_log($log) {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }
}

/**
 * Launch the plugin.
 */
function racemanager_run() {
    return WP_RaceManager::instance();
}
racemanager_run(); // Instead of hooking, we can just run it directly here.
// Optionally use the plugins_loaded or init hook to delay the launch.
//add_action( 'plugins_loaded', 'RaceManager\racemanager_run' );
<?php
/**
 * Plugin Name: WP RaceManager
 * Description: Provides REST API endpoints for RotorHazard: download pilot registrations, upload race results. The "Races" menu item will be populated with the latest races. For more information, see the plugin settings.
 * Version: 1.0
 * Author: Peter Simandl
 * Text Domain: wp-racemanager
 */

// Define the namespace
namespace RaceManager;  // Use your preferred namespace if you have one.

//if (!defined('ABSPATH')) exit; // Exit if accessed directly

define( 'WP_RACEMANAGER_VERSION', '1.0.0' );
define( 'WP_RACEMANAGER_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_RACEMANAGER_URL', plugin_dir_url( __FILE__ ) );
//define( 'WP_RACEMANAGER_ASSETS', WP_RACEMANAGER_URL . 'assets/build/' );


// Include required files


// Activation hook to create the database table

function rm_activate() {
    //require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    require_once plugin_dir_path(__FILE__) . 'includes/pwa-subscription-handler.php';
    \RaceManager\PWA_Subscription_Handler::create_db_table();
    
    require_once plugin_dir_path(__FILE__) . 'includes/pwa-handler.php';
    rm_create_file_from_template('template-pwa-sw.js', ABSPATH);
    rm_create_file_from_template('template-manifest.json', ABSPATH);

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

        // active on every page
        require_once plugin_dir_path(__FILE__) . 'includes/main-navigation-handler.php'; // filter function for main navigation to indicate live race in progress
        require_once plugin_dir_path(__FILE__) . 'includes/block-loader.php';

        // TODO: Load only on admin pages
        include_once plugin_dir_path(__FILE__) . 'includes/settings-handler.php';

        // active on all pages
        include_once plugin_dir_path(__FILE__) . 'includes/db-handler.php';
        include_once plugin_dir_path(__FILE__) . 'includes/cpt-handler.php'; // 
        include_once plugin_dir_path(__FILE__) . 'includes/cpt-meta-handler.php'; // cpt admin functions
        
        include_once plugin_dir_path(__FILE__) . 'includes/sc-gallery.php';
        
        include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_viewer.php';
        include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_registered.php'; // SC for Shortcode
        
        //include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_cards.php'; // SC for Shortcode
        //include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_tabs.php'; // SC for Shortcode
        
        // TODO: Include only on live pages request.

        
    }
    
/*     public static function activate() {
        require_once plugin_dir_path(__FILE__) . 'includes/pwa-subscription-handler.php';
        \RaceManager\PWA_Subscription_Handler::create_db_table();
        // ...
    }
    register_activation_hook(__FILE__, [ 'RaceManager\WP_RaceManager', 'activate' ]); */

    /**
     * Check if we need to load the PWA subscription code,
     * and then load it if necessary.
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
            include_once plugin_dir_path(__FILE__) . 'includes/livepage-handler.php';
            rm_start_session();
            rm_rewrite_live_urls();
            include_once plugin_dir_path(__FILE__) . 'includes/pwa-handler.php';
            rm_load_live_resources();
        }
    }

    /**
     * Detect whether this request is a WP REST API request for our "racemanager" namespace.
     */
    private function is_racemanager_rest_api_request() {
        return true;
        // Make sure it's a REST request at all.
        if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
            return false;
        }
        // Ensure rest_route param is present.
        if ( empty( $_GET['rest_route'] ) ) {
            return false;
        }
        // Check if it starts with "racemanager/v1" (adjust to your chosen namespace/version).
        //return ( 0 === strpos( ltrim( $_GET['rest_route'], '/' ), 'rm/v1' ) );
        return true;
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
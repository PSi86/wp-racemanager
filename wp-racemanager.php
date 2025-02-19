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
        // You can do general setup here or in a separate init method.
        // The following is a minimal approach.
        //add_action( 'plugins_loaded', [ $this, 'maybe_init_rest_handlers' ] );
        //require_once WP_RACEMANAGER_DIR . 'vendor/autoload.php'; // if you’re using Composer
        
        //TODO: load this only for the REST API requests
        //require_once __DIR__ . '/../../../../vendor/autoload.php'; // Relative path to the vendor directory (currently in root of httpdocs)
        require_once plugin_dir_path( __FILE__ ) . 'includes/pwa-subscription-handler.php';
        $this->pwa_subscription_handler = new PWA_Subscription_Handler();
        include_once plugin_dir_path(__FILE__) . 'includes/rest-handler.php';
        // END of REST API handling

        
        include_once plugin_dir_path(__FILE__) . 'includes/settings-handler.php';
        include_once plugin_dir_path(__FILE__) . 'includes/db-handler.php';
        include_once plugin_dir_path(__FILE__) . 'includes/cpt-handler.php';
        include_once plugin_dir_path(__FILE__) . 'includes/meta-handler.php';
        include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_viewer.php';
        include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_registered.php'; // SC for Shortcode
        
        //include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_cards.php'; // SC for Shortcode
        //include_once plugin_dir_path(__FILE__) . 'includes/sc-rm_tabs.php'; // SC for Shortcode
        
        include_once plugin_dir_path(__FILE__) . 'includes/submenu-block-handler.php';
        
        // TODO: Include only on live pages request.
        include_once plugin_dir_path(__FILE__) . 'includes/livepage-handler.php';
        include_once plugin_dir_path(__FILE__) . 'includes/pwa-handler.php';
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
    public function maybe_init_rest_handlers() {
        if ( $this->is_racemanager_rest_api_request() ) {
            // Load the file and instantiate PWA_Subscription_Handler.
            require_once plugin_dir_path( __FILE__ ) . 'includes/pwa-subscription-handler.php';
            $this->pwa_subscription_handler = new PWA_Subscription_Handler();
            // Handle pilot download and results upload
            include_once plugin_dir_path(__FILE__) . 'includes/rest-handler.php';
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
}

/**
 * Launch the plugin.
 */
function racemanager_run() {
    return WP_RaceManager::instance();
}
racemanager_run(); // Instead of hooking, we can just run it directly here.
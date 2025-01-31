<?php
/**
 * Plugin Name: WP RaceManager
 * Description: Provides REST API endpoints for RotorHazard: download pilot registrations, upload race results. The "Races" menu item will be populated with the latest races. For more information, see the plugin settings.
 * Version: 1.0
 * Author: Peter Simandl
 * Text Domain: wp-racemanager
 */

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Define the table name
//global $wpdb;
//define('RH_TABLE', $wpdb->prefix . 'race_data');

// Include required files
include_once plugin_dir_path(__FILE__) . 'includes/cpt-handler.php';
include_once plugin_dir_path(__FILE__) . 'includes/meta-handler.php';
//include_once plugin_dir_path(__FILE__) . 'includes/admin-interface.php';
//include_once plugin_dir_path(__FILE__) . 'includes/db-functions.php';
include_once plugin_dir_path(__FILE__) . 'includes/rest-handler.php';

//include_once plugin_dir_path(__FILE__) . 'includes/menu-handler.php';
//include_once plugin_dir_path(__FILE__) . 'includes/settings-page.php';
include_once plugin_dir_path(__FILE__) . 'includes/shortcodes.php';

// Activation hook to create the database table
register_activation_hook(__FILE__, 'rm_activate');

function rm_activate() {

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
}

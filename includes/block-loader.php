<?php
/**
 * block-loader.php
 * Loads the custom blocks for this plugin and registers them with WordPress.
 * When Wordpress functions are overridden or extended, the new functions are loaded here.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Include the server-side render callback.
require_once plugin_dir_path(__DIR__) . 'includes/block-render-nav-latest-races.php'; // block-render-submenu.php
require_once plugin_dir_path( __DIR__ ) . 'includes/block-render-race-select.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/block-render-race-title.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/block-render-race-date.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/block-render-race-buttons.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/block-render-gallery.php';
require_once plugin_dir_path( __DIR__ ) . 'includes/block-archive-toggle.php';

/**
 * Registers the custom block using the block.json in the block folder.
 */
function rm_register_blocks() {
     // for block-render-nav-latest-races.php
    register_block_type( plugin_dir_path( __DIR__ ) . 'blocks/nav-latest-races', array(
        'render_callback' => 'rm_render_nav_latest_races_block'
    ) );
    // for block-render-race-select.php
    register_block_type( plugin_dir_path( __DIR__ ) . 'blocks/race-select', array(
        'render_callback' => 'rm_render_race_select_block',
    ) );
    // for block-render-race-title.php
    register_block_type( plugin_dir_path( __DIR__ ) . 'blocks/race-title', array(
        'render_callback' => 'rm_render_race_title_block',
    ) );
    // for block-render-race-date.php
    register_block_type( plugin_dir_path( __DIR__ ) . 'blocks/race-date', array(
        'render_callback' => 'rm_render_race_date_block',
    ) );
    register_block_type( plugin_dir_path( __DIR__ ) . 'blocks/race-buttons', array(
        'render_callback' => 'rm_render_race_buttons_block',
    ) );
    register_block_type( plugin_dir_path( __DIR__ ) . 'blocks/race-archive-toggle', array(
        'render_callback' => 'rm_render_archive_toggle_block',
    ) );
    register_block_type( plugin_dir_path( __DIR__ ) . 'blocks/race-gallery', array(
        'render_callback' => 'rm_render_media_gallery',
    ) );
}
add_action( 'init', 'rm_register_blocks' );

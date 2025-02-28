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
require_once plugin_dir_path(__DIR__) . 'includes/main-navigation-handler.php'; // does its own init (add_filter)
require_once plugin_dir_path(__DIR__) . 'includes/block-render-submenu.php'; // block-render-submenu.php
require_once plugin_dir_path( __DIR__ ) . 'includes/block-render-select-race.php';

/**
 * Registers the custom block using the block.json in the block folder.
 */
function rm_register_blocks() {
    // for block-render-submenu.php
    wp_register_script(
        'wp-racemanager-nav-submenu-editor',
        plugin_dir_url( __DIR__ ) . 'js/nav-latest-races-submenu.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        '1.0.3'
    );
    // for block-render-submenu.php
    register_block_type( 'wp-racemanager/nav-latest-races-submenu', array(
        'editor_script'   => 'wp-racemanager-nav-submenu-editor',
        'render_callback' => 'rm_render_nav_latest_races_submenu',
        'supports'        => array(
            'align'           => false,
            'anchor'          => true,
            'customClassName' => true,
        ),
        // Restrict this block so it can only be added as a child of a Navigation Link.
        'parent'          => array( 'core/navigation-submenu' ),
    ) );
    // for block-render-select-race.php
    register_block_type( plugin_dir_path( __DIR__ ) . 'blocks/race-select', array(
        'render_callback' => 'rm_render_race_select_block',
    ) );
}
add_action( 'init', 'rm_register_blocks' );

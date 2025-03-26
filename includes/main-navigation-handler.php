<?php
// includes/main-navigation-handler.php
// All functions in this file are related to the main navigation (not the live pages navigation).
// Modify the navigation block output to include a blinking dot indicator when a race is live.

// Reminder: if you need to check for permissions, you can use a callback like this:
//'permission_callback' => function() {
//    return current_user_can( 'edit_posts' );
//},

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Modify the navigation block output to include a blinking dot indicator when a live race is happening
 */
function rm_indicate_live_race( $block_content, $block ) {
    if ( isset( $block['blockName'] ) && 'core/navigation' === $block['blockName'] ) {
        // Replace the custom class with one that includes your blinking dot indicator.
        $block_content = str_replace( 'rm-live-page-link', 'rm-live-page-link has-blinking-dot', $block_content );
        wp_enqueue_style(
            'rm-live-page-link-css', 
            plugin_dir_url( __DIR__ ) . 'css/rm_live_page_link.css'
        );
    }
    return $block_content;
}

//add_filter( 'render_block', 'rm_indicate_live_race', 10, 2 ); // Now called in wp-racemanager.php on init in is_a_race_live() if a race is live

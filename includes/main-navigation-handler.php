<?php
// includes/main-navigation-handler.php
// Live Microsite Session & URL Rewrite
// Uses PHP sessions to persist a selected race post ID and rewrites all /live/ page URLs to include the race_id parameter.
// Starts a session (only for /live pages) and appends the race_id from the session to all links in the /live hierarchy.

// Reminder: if you need to check for permissions, you can use a callback like this:
//'permission_callback' => function() {
//    return current_user_can( 'edit_posts' );
//},

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Modify the navigation block output to include a blinking dot indicator when a live race is happening
 */
function rm_replace_custom_navigation_class( $block_content, $block ) {
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
// TODO: make this conditional on a race being live
add_filter( 'render_block', 'rm_replace_custom_navigation_class', 10, 2 );


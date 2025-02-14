<?php
// includes/pwa-handler.php
// Handle the delivery of the pwa scripts .

// Reminder: if you need to check for permissions, you can use a callback like this:
//'permission_callback' => function() {
//    return current_user_can( 'edit_posts' );
//},

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Check if the current page is part of the /live hierarchy.
 *
 * This example uses the REQUEST_URI to decide if the URL begins with "/live".
 * Adjust this function as needed if your WordPress install is in a subdirectory.
 */
function pwa_is_live_page() {
    // Get the current request URI.
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    // Match /live or /live/... (e.g., /live/pilots)
    return preg_match('#^/live(?:/|$)#', $request_uri);
}

/**
 * Enqueue PWA-related scripts only on live pages.
 */
function pwa_live_enqueue_scripts() {
    if ( pwa_is_live_page() ) {
        // Enqueue the service worker registration script.
        wp_enqueue_script(
            'pwa-sw-register',
            plugin_dir_url( __DIR__ ) . 'js/pwa-sw-register.js', //plugins_url('pwa-sw-register.js', __FILE__),
            array(),
            null,
            true
        );
        // Enqueue the foreground polling script if needed.
        /* wp_enqueue_script(
            'pwa-foreground-poll',
            plugin_dir_url( __DIR__ ) . 'js/pwa-foreground-poll.js', //plugins_url('pwa-foreground-poll.js', __FILE__),
            array(),
            null,
            true
        ); */
    }
}
add_action('wp_enqueue_scripts', 'pwa_live_enqueue_scripts');

/**
 * Add the manifest link to the head only on live pages.
 */
function pwa_live_add_manifest_link() {
    if ( pwa_is_live_page() ) {
        echo '<link rel="manifest" href="' . esc_url( home_url('?pwa_manifest=true') ) . '">' . "\n";
    }
}
add_action('wp_head', 'pwa_live_add_manifest_link');

/**
 * Serve the manifest.json file when requested.
 */
function pwa_live_manifest() {
    if ( isset($_GET['pwa_manifest']) && $_GET['pwa_manifest'] === 'true' ) {
        header('Content-Type: application/json');
        echo json_encode(array(
            "name" => "Copterrace Live",
            "short_name" => "CR-Live",
            "start_url" => "/live",
            "display" => "standalone",
            "background_color" => "#ffffff",
            "theme_color" => "#000000",
            "icons" => array(
                array(
                    "src" => plugin_dir_url( __DIR__ ) . 'img/icon_192.png', //plugins_url('icon_192.png', __FILE__),
                    "sizes" => "192x192",
                    "type" => "image/png"
                ),
                array(
                    "src" => plugin_dir_url( __DIR__ ) . 'img/icon_512.png', //plugins_url('icon_512.png', __FILE__),
                    "sizes" => "512x512",
                    "type" => "image/png"
                )
            )
        ));
        exit;
    }
}
add_action('init', 'pwa_live_manifest');

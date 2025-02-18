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
function rm_is_live_page() {
    // Only proceed on page requests.
    if ( ! is_page() ) {
        return false;
    }

    // Get the current page ID.
    $page_id = get_the_ID();
    
    // Retrieve the stored Live Races page ID.
    $live_races_page_id = get_option('rm_live_page_id');
    if ( ! $live_races_page_id ) {
        return false;
    }
    
    // Check if the current page is the Live Races page.
    if ( $page_id == $live_races_page_id ) {
        return true;
    }
    
    // Check if the Live Races page is one of the ancestors of the current page.
    if ( in_array( $live_races_page_id, get_post_ancestors( $page_id ) ) ) {
        return true;
    }
    
    return false;
}

/**
 * Check if the current page is part of the /live hierarchy.
 *
 * This example uses the REQUEST_URI to decide if the URL begins with "/live".
 * Adjust this function as needed if your WordPress install is in a subdirectory.
 */
function add_custom_pwa_meta_tags() {
    // Replace 'pwa-page' with your specific page slug or use is_page( 123 ) for a page ID
    if ( rm_is_live_page() ) {
        $rm_iconfolder = plugin_dir_url( __DIR__ ) . 'img';
        // Add the PWA meta tags
        ?>
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Mobile web app title">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">

        <link rel="apple-touch-icon" sizes="180x180" href="<?php echo $rm_iconfolder; ?>/icon_180.png">
        
        <!-- ICONS -->
        <!-- iOS icons -->
        <link rel="apple-touch-icon" sizes="57x57" href="<?php echo $rm_iconfolder; ?>/icon_57.png">
        <link rel="apple-touch-icon" sizes="60x60" href="<?php echo $rm_iconfolder; ?>/icon_60.png">
        <link rel="apple-touch-icon" sizes="72x72" href="<?php echo $rm_iconfolder; ?>/icon_72.png">
        <link rel="apple-touch-icon" sizes="76x76" href="<?php echo $rm_iconfolder; ?>/icon_76.png">
        <link rel="apple-touch-icon" sizes="114x114" href="<?php echo $rm_iconfolder; ?>/icon_114.png">
        <link rel="apple-touch-icon" sizes="120x120" href="<?php echo $rm_iconfolder; ?>/icon_120.png">
        <link rel="apple-touch-icon" sizes="144x144" href="<?php echo $rm_iconfolder; ?>/icon_144.png">
        <link rel="apple-touch-icon" sizes="152x152" href="<?php echo $rm_iconfolder; ?>/icon_152.png">
        <link rel="apple-touch-icon" sizes="180x180" href="<?php echo $rm_iconfolder; ?>/icon_180.png">
        
        <!-- Android icons -->
        <link rel="icon" type="image/png" sizes="192x192" href="<?php echo $rm_iconfolder; ?>/icon_192.png">
        <link rel="icon" type="image/png" sizes="32x32" href="<?php echo $rm_iconfolder; ?>/icon_32.png">
        <link rel="icon" type="image/png" sizes="96x96" href="<?php echo $rm_iconfolder; ?>/icon_96.png">
        <link rel="icon" type="image/png" sizes="16x16" href="<?php echo $rm_iconfolder; ?>/icon_16.png">

        <!-- Windows icons -->
        <meta name="msapplication-TileImage" content="/icon_144.png">
        
        <!-- Windows dock color -->
        <meta name="msapplication-TileColor" content="#fff">
        
        <!-- Android dock color -->
        <meta name="theme-color" content="#fff">
        <?php
    }
}
add_action('wp_head', 'add_custom_pwa_meta_tags');

/**
 * Enqueue PWA-related scripts only on live pages.
 */
function rm_pwa_enqueue_scripts() {
    if ( rm_is_live_page() ) {
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
add_action('wp_enqueue_scripts', 'rm_pwa_enqueue_scripts');

/**
 * Add the manifest link to the head only on live pages.
 */
function rm_pwa_add_manifest_link() {
    if ( rm_is_live_page() ) {
        echo '<link rel="manifest" href="' . esc_url( home_url('?pwa_manifest=true') ) . '">' . "\n";
    }
}
add_action('wp_head', 'rm_pwa_add_manifest_link');

/**
 * Serve the manifest.json file when requested.
 */
function rm_pwa_manifest() {
    if ( isset($_GET['pwa_manifest']) && $_GET['pwa_manifest'] === 'true' ) {
        $rm_iconfolder = plugin_dir_url( __DIR__ ) . 'img';
        header('Content-Type: application/json');
        echo json_encode(array(
            "name" => "Copterrace Live",
            "short_name" => "CR-Live",
            "start_url" => "wp/live/",
            "display" => "standalone",
            "background_color" => "#ffffff",
            "theme_color" => "#000000",
            "icons" => array(
                array(
                    "src" => $rm_iconfolder . '/icon_32.png',
                    "sizes" => "32x32",
                    "type" => "image/png"
                ),
                array(
                    "src" => $rm_iconfolder . '/icon_96.png',
                    "sizes" => "96x96",
                    "type" => "image/png"
                ),
                array(
                    "src" => $rm_iconfolder . '/icon_192.png',
                    "sizes" => "192x192",
                    "type" => "image/png"
                ),
                array(
                    "src" => $rm_iconfolder . '/icon_512.png', //plugins_url('icon_512.png', __FILE__),
                    "sizes" => "512x512",
                    "type" => "image/png"
                )
            )
        ));
        exit;
    }
}
add_action('init', 'rm_pwa_manifest');

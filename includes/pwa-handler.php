<?php
// includes/pwa-handler.php
// Handle the delivery of the pwa scripts, meta-tags, manifest link.
// On live pages, the PWA resources are loaded.
// The PWA resources include the service worker registration script, the manifest link, and the meta tags.

// INIT: rm_load_live_resources() is called from wp-racemanager.php
// Plugin activation: rm_create_file_from_template() is called from pwa-subscription-handler.php

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Live Page resouce loader
 */
function rm_load_live_resources() {
    add_action('wp_head', 'add_custom_pwa_meta_tags');
    add_action('wp_enqueue_scripts', 'rm_pwa_enqueue_scripts');
    add_action('wp_head', 'rm_pwa_add_manifest_link');
}

/**
 * Check if the current page is part of the /live hierarchy.
 *
 * This example uses the REQUEST_URI to decide if the URL begins with "/live".
 * Adjust this function as needed if your WordPress install is in a subdirectory.
 */
function add_custom_pwa_meta_tags() {
    // Replace 'pwa-page' with your specific page slug or use is_page( 123 ) for a page ID
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

/**
 * Enqueue PWA-related scripts only on live pages.
 */
function rm_pwa_enqueue_scripts() {
    // Enqueue the service worker registration script.
    wp_enqueue_script(
        'pwa-sw-register',
        plugin_dir_url( __DIR__ ) . 'js/pwa-sw-register.js', //plugins_url('pwa-sw-register.js', __FILE__),
        array(),
        '1.0.3',
        false
    );
}

/**
 * Add the manifest link to the head only on live pages.
 */
function rm_pwa_add_manifest_link() {
    //$manifest_file_url = plugin_dir_url(__DIR__) . 'manifest.json';
    $manifest_file_url = get_site_url() . '/manifest.json';
    echo '<link rel="manifest" href="' . esc_url( $manifest_file_url ) . '">' . "\n";
}

/**
 * Create a file from a template.
 * Template folder: wp-content/plugins/racemanager/templates/
 * Each template file should have placeholders in the format [placeholderName]
 * The function replaces the placeholders with actual values and writes the content to a new file.
 * The new filename is the same as the template filename without the "template-" prefix.
 * The target file path has to be provided as second argument.
 * 
 * Example: rm_create_file_from_template('template-pwa-sw.js', ABSPATH);
 * 
 * @param string $template_filename
 * @param string $output_file_path
 * @return void
 */
function rm_create_file_from_template($template_filename, $output_file_path) {
    $rm_iconfolder = plugin_dir_url(__DIR__) . 'img';
    $rm_wp_root_url = get_site_url();
    // TODO: allowing editing the values in the admin panel?
    $replace_pattern = array(
        "[siteUrl]" => esc_js("https://wherever-we-are.com/wp/"), // wp start page url
        "[livePagesUrl]" => esc_js("https://wherever-we-are.com/wp/live/"), // also used for pwa_id
        "[pwaScope]" => esc_js("/wp/live/"),   // relative path to the live pages
        "[pwaStartUrl]" => esc_js("https://wherever-we-are.com/wp/live/bracket/"), // when the PWA is started
        "[pwaStartPage]" => esc_js("/wp/live/bracket/"), // '/wp/live/' relative path to the pwaScope start page
        "[iconFolderUrl]" => esc_js($rm_iconfolder),
    );
    
    // construct the full path to the template file
    $template_file = plugin_dir_path(__DIR__) . '/templates/' . $template_filename;
    // construct the full path to the output file
    $output_file = $output_file_path . str_replace('template-', '', $template_filename);

    // replace placeholders in the service worker template with actual values using the $replace_pattern array
    $template_content = file_get_contents($template_file);
    $template_content = str_replace(array_keys($replace_pattern), array_values($replace_pattern), $template_content);

    // Write the service worker content to the file
    file_put_contents($output_file, $template_content);
}

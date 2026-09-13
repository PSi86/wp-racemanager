<?php
/**
 * The site's top bar on a phone: out of the way while the visitor scrolls down, back as soon as
 * they scroll up (1.17.0), as droneracingslovakia.com does it. On every page of the site, since the
 * header is the same on every page; js/rm-top-bar.js decides on the page whether there is anything
 * to do - a header the theme pins, and its navigation showing the burger.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Enqueue the top bar's script and its stylesheet on the front end.
 *
 * @return void
 */
function rm_enqueue_top_bar() {
    wp_enqueue_style(
        'rm-top-bar',
        plugin_dir_url( __DIR__ ) . 'css/rm-top-bar.css',
        array(),
        WP_RACEMANAGER_VERSION
    );
    wp_enqueue_script(
        'rm-top-bar',
        plugin_dir_url( __DIR__ ) . 'js/rm-top-bar.js',
        array(),
        WP_RACEMANAGER_VERSION,
        array(
            'in_footer' => true,
            'strategy'  => 'defer',
        )
    );
}
add_action( 'wp_enqueue_scripts', 'rm_enqueue_top_bar' );

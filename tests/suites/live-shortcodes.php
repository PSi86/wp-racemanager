<?php
/**
 * The four live-page shortcodes, run against the verbatim WordPress 7.1 signatures of the
 * script module API.
 *
 * This is the regression guard for the WordPress 6.9 breakage: wp_register_script_module()
 * gained a fifth `array $args` parameter, and passing anything else there is a TypeError.
 */

require_once __DIR__ . '/../bootstrap.php';

// Verbatim from wp-includes/script-modules.php @ 7.1 -- the point of this suite.
function wp_register_script_module( string $id, string $src, array $deps = array(), $version = false, array $args = array() ) {
    $GLOBALS['rm_modules_registered'][ $id ] = compact( 'src', 'deps', 'version', 'args' );
}
function wp_enqueue_script_module( string $id, string $src = '', array $deps = array(), $version = false, array $args = array() ) {
    $GLOBALS['rm_modules_enqueued'][] = $id;
}

function get_post_meta( $id, $key, $single = false ) { return '_race_live' === $key ? '1' : ''; }
function wp_upload_dir() {
    return array(
        'basedir' => sys_get_temp_dir() . '/rm-tests',
        'baseurl' => 'https://example.test/wp-content/uploads',
        'error'   => '',
    );
}
function rm_get_vapid() { return array( 'publicKey' => 'TESTPUBKEY', 'privateKey' => '', 'subject' => '' ); }

$GLOBALS['rm_modules_registered'] = array();
$GLOBALS['rm_modules_enqueued']   = array();
$GLOBALS['rm_options'] = array( 'rm_live_page_id' => 7, 'admin_email' => 'race@example.test' );

require_once RM_TEST_DIR . '/stubs/wordpress.php';

rm_test_post( 7,  'page', 'live' );
rm_test_post( 11, 'page', 'bracket', 'publish', 7 );
rm_test_post( 12, 'page', 'stats',   'publish', 7 );
rm_test_post( 13, 'page', 'nextup',  'publish', 7 );
rm_test_post( 182, 'race', 'spring-cup-2026', 'publish', 0, 'Spring Cup 2026' );

require_once RM_PLUGIN_DIR . '/includes/race-data-functions.php';
require_once RM_PLUGIN_DIR . '/includes/live-routing.php';
require_once RM_PLUGIN_DIR . '/includes/livepage-handler.php';

// The race arrives exactly as the rewrite rule delivers it.
$GLOBALS['rm_query_vars']['rm_race'] = 'spring-cup-2026';

rm_test_section( 'Every shortcode renders without a fatal' );
foreach ( array( 'rm_pilots_shortcode', 'rm_bracket_shortcode', 'rm_stats_shortcode', 'rm_nextup_shortcode' ) as $fn ) {
    $html  = '';
    $error = '';
    try {
        $html = $fn( array() );
    } catch ( \Throwable $e ) {
        $error = get_class( $e ) . ': ' . $e->getMessage();
    }
    rm_test_check(
        $fn,
        '' === $error && is_string( $html ) && '' !== $html && ! str_contains( $html, 'No race selected' ),
        '' !== $error ? $error : 'empty output or no race resolved'
    );
}

rm_test_section( 'Modules registered and enqueued' );
$expected = array( 'rm-pilot-stats', 'rm-displayHeats', 'rm-stats', 'rm-nextUp' );
rm_test_check( 'all four registered',
    $expected === array_keys( $GLOBALS['rm_modules_registered'] ),
    implode( ', ', array_keys( $GLOBALS['rm_modules_registered'] ) ) );
rm_test_check( 'all four enqueued', $expected === $GLOBALS['rm_modules_enqueued'] );
foreach ( $GLOBALS['rm_modules_registered'] as $id => $module ) {
    rm_test_check( "$id passes no classic script handles as module deps", array() === $module['deps'],
        implode( ',', array_map( 'strval', $module['deps'] ) ) );
}

rm_test_section( 'JS configuration reaches the head' );
ob_start();
foreach ( array_unique( $GLOBALS['rm_head_actions'], SORT_REGULAR ) as $callback ) {
    $callback();
}
$head = ob_get_clean();
rm_test_check( 'window.RmJsConfig printed', str_contains( $head, 'window.RmJsConfig = {' ) && str_contains( $head, '"dataUrl"' ) );
rm_test_check( 'storageKey is the race post ID', str_contains( $head, '"storageKey":182' ), $head );
rm_test_check( 'no session-era race_id link left', ! str_contains( $head, 'race_id=' ) );
rm_test_check( 'public VAPID key handed to the client', str_contains( $head, 'TESTPUBKEY' ) );

rm_test_finish();

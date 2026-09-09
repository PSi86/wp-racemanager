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
// Recording rather than the no-op in stubs/wordpress.php: the status line's markup and its
// stylesheet have to travel together, and this suite is where that is checked. Defined here on
// purpose -- the stubs guard every definition with function_exists(), so this one wins.
function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
    $GLOBALS['rm_styles_enqueued'][ $handle ] = $src;
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
$GLOBALS['rm_styles_enqueued']    = array();
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
$rendered = array();
foreach ( array( 'rm_pilots_shortcode', 'rm_bracket_shortcode', 'rm_stats_shortcode', 'rm_nextup_shortcode' ) as $fn ) {
    $html  = '';
    $error = '';
    try {
        $html = $fn( array() );
    } catch ( \Throwable $e ) {
        $error = get_class( $e ) . ': ' . $e->getMessage();
    }
    $rendered[ $fn ] = $html;
    rm_test_check(
        $fn,
        '' === $error && is_string( $html ) && '' !== $html && ! str_contains( $html, 'No race selected' ),
        '' !== $error ? $error : 'empty output or no race resolved'
    );
}

rm_test_section( 'Modules registered and enqueued' );
// One view module per shortcode, plus rm-updateStatus, which every shortcode asks for and which
// is therefore registered once, when the first of them renders. The list is compared in order so
// that a module quietly appearing or disappearing shows up here rather than in a browser.
$expected = array( 'rm-pilot-stats', 'rm-updateStatus', 'rm-displayHeats', 'rm-stats', 'rm-nextUp' );
rm_test_check( 'the five view modules are registered, in order',
    $expected === array_keys( $GLOBALS['rm_modules_registered'] ),
    implode( ', ', array_keys( $GLOBALS['rm_modules_registered'] ) ) );
// Enqueued once per shortcode that wants it: the four view modules once each, rm-updateStatus
// four times. WordPress deduplicates that; what matters here is that no shortcode skips it.
rm_test_check( 'and every one of them is enqueued',
    $expected === array_values( array_unique( $GLOBALS['rm_modules_enqueued'] ) ),
    implode( ', ', $GLOBALS['rm_modules_enqueued'] ) );
rm_test_check( 'all four shortcodes enqueue the status module',
    4 === count( array_keys( $GLOBALS['rm_modules_enqueued'], 'rm-updateStatus', true ) ),
    implode( ', ', $GLOBALS['rm_modules_enqueued'] ) );
foreach ( $GLOBALS['rm_modules_registered'] as $id => $module ) {
    rm_test_check( "$id passes no classic script handles as module deps", array() === $module['deps'],
        implode( ',', array_map( 'strval', $module['deps'] ) ) );
}

rm_test_section( 'The freshness line is wired into every live view' );
// The container, the module and the stylesheet come from one helper precisely so that they
// cannot drift apart. A view that emits the container without the stylesheet would show an
// unstyled bar; one that emits it without the module would show an empty one forever.
foreach ( $rendered as $fn => $html ) {
    rm_test_check( "$fn emits the status container",
        str_contains( $html, 'id="rm-update-status"' ), substr( $html, 0, 120 ) );
}
rm_test_check( 'the container starts hidden, for the no-JavaScript case',
    str_contains( $rendered['rm_bracket_shortcode'], 'id="rm-update-status"' ) &&
    preg_match( '/<div id="rm-update-status"[^>]*\shidden\b/', $rendered['rm_bracket_shortcode'] ) === 1,
    $rendered['rm_bracket_shortcode'] );
rm_test_check( 'the stylesheet travels with it',
    isset( $GLOBALS['rm_styles_enqueued']['rm-update-status-css'] ) &&
    str_contains( $GLOBALS['rm_styles_enqueued']['rm-update-status-css'], 'css/rm-update-status.css' ),
    implode( ', ', array_keys( $GLOBALS['rm_styles_enqueued'] ) ) );

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

rm_test_section( 'Two shortcodes on one page do not overwrite each other (B3)' );
// All four shortcodes ran above. Every one of them used to assign $rm_js_config outright, so
// only the last one's modules survived and everything before it came up unconfigured.
preg_match( '/window\.RmJsConfig = (\{.*?\});/s', $head, $m );
$config = isset( $m[1] ) ? json_decode( $m[1], true ) : array();

rm_test_check( 'the config parses', is_array( $config ) && $config, $head );
rm_test_check( 'displayStats survived, from the first shortcode', isset( $config['displayStats'] ),
    'keys: ' . implode( ', ', array_keys( (array) $config ) ) );
rm_test_check( 'displayLog is there, from the last one', isset( $config['displayLog'] ) );
rm_test_check( 'so are displayHeats and pushSubscription',
    isset( $config['displayHeats'] ) && isset( $config['pushSubscription'] ) );
rm_test_check( 'dataLoader is still filled in, not emptied by a later []',
    isset( $config['dataLoader']['dataUrl'] ) && '' !== $config['dataLoader']['dataUrl'] );
rm_test_check( 'the config object is printed once', 1 === substr_count( $head, 'window.RmJsConfig' ), $head );
// Core stores a string callback under its own name, so repeated registration fires once anyway.
rm_test_check( 'the head hook holds one callback',
    array( 'rm_print_js_module_config' ) === array_values( array_unique( $GLOBALS['rm_head_actions'], SORT_REGULAR ) ),
    implode( ', ', array_map( 'strval', $GLOBALS['rm_head_actions'] ) ) );

rm_test_finish();

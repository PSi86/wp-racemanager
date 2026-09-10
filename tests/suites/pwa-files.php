<?php
/**
 * manifest.json and pwa-sw.js, as the plugin writes them into the WordPress root.
 *
 * Two ways these files go wrong without anything reporting it:
 *
 * - A placeholder the template uses but rm_get_pwa_template_values() does not fill reaches the
 *   output raw -- a service worker comparing URLs against the literal string "[pwaScope]".
 * - A changed template is not written out. rm_maybe_refresh_pwa_files() rewrites only when its
 *   signature moves, and the signature used to cover the substituted values and the plugin
 *   version but not the templates. Changing the service worker without a version bump therefore
 *   left the old worker on disk, and the site kept serving it. The offline cache (L6) made that
 *   worse than before: its name has to change with the worker, or a new worker inherits an old
 *   worker's cache.
 *
 * The suite works on a copy of templates/, so it can change a template and watch what happens.
 */

$GLOBALS['rm_test_tmp'] = sys_get_temp_dir() . '/rm-pwa-files-' . getmypid() . '/';
@mkdir( $GLOBALS['rm_test_tmp'] . 'root', 0777, true );
@mkdir( $GLOBALS['rm_test_tmp'] . 'plugin/templates', 0777, true );

// The WordPress root the files are written to.
define( 'ABSPATH', $GLOBALS['rm_test_tmp'] . 'root/' );

require_once __DIR__ . '/../bootstrap.php';

foreach ( array( 'template-pwa-sw.js', 'template-manifest.json' ) as $template ) {
    copy( RM_PLUGIN_DIR . '/templates/' . $template, $GLOBALS['rm_test_tmp'] . 'plugin/templates/' . $template );
}

// The plugin directory, as far as the template code is concerned, is the copy.
function plugin_dir_path( $file ) { return $GLOBALS['rm_test_tmp'] . 'plugin/'; }

// Core's esc_js() without its filter and its UTF-8 check, the rest as core has it: the service
// worker template is where its output ends up.
function esc_js( $text ) {
    $safe_text = htmlspecialchars( (string) $text, ENT_COMPAT, 'UTF-8' );
    $safe_text = preg_replace( '/&#(x)?0*(?(1)27|39);?/i', "'", stripslashes( $safe_text ) );
    $safe_text = str_replace( "\r", '', $safe_text );
    return str_replace( "\n", '\\n', addslashes( $safe_text ) );
}

function rm_live_path() { return 'live'; }

$GLOBALS['rm_options'] = array();

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/pwa-handler.php';

$root   = ABSPATH;
$values = rm_get_pwa_template_values();

rm_test_section( 'Every placeholder a template uses has a value' );
// Placeholders are [camelCase]. JavaScript indexing with a bare identifier would look the same;
// neither template does that, and a false alarm here is cheaper than a raw placeholder in a
// live service worker.
foreach ( array( 'template-pwa-sw.js', 'template-manifest.json' ) as $template ) {
    preg_match_all( '/\[([a-z][A-Za-z]+)\]/', file_get_contents( RM_PLUGIN_DIR . '/templates/' . $template ), $m );
    $used    = array_unique( $m[0] );
    $missing = array_diff( $used, array_keys( $values ) );
    rm_test_check( "$template: " . count( $used ) . ' placeholders, all filled', array() === $missing,
        'no value for ' . implode( ', ', $missing ) );
}

rm_test_section( 'What is written' );
rm_maybe_refresh_pwa_files();
$sw       = is_file( $root . 'pwa-sw.js' ) ? file_get_contents( $root . 'pwa-sw.js' ) : '';
$manifest = is_file( $root . 'manifest.json' ) ? json_decode( file_get_contents( $root . 'manifest.json' ), true ) : null;
rm_test_check( 'pwa-sw.js is written', '' !== $sw );
rm_test_check( 'and nothing in it is left as a placeholder',
    ! preg_match( '/\[[a-z][A-Za-z]+\]/', $sw ), preg_match( '/\[[a-z][A-Za-z]+\]/', $sw, $left ) ? $left[0] : '' );
rm_test_check( 'the worker knows its scope', str_contains( $sw, "const SCOPE_PATH = '/live/';" ) );
$version_pattern = "/const CACHE_NAME = CACHE_PREFIX \\+ '" . preg_quote( WP_RACEMANAGER_VERSION, '/' ) . "-([0-9a-f]{8})';/";
rm_test_check( 'its cache is named for the plugin version and the template',
    (bool) preg_match( $version_pattern, $sw, $first_name ), 'no CACHE_NAME line of that shape' );
rm_test_check( 'manifest.json is written, as valid JSON', is_array( $manifest ) );
rm_test_check( 'with the live area as its scope and ?resume=1 as its start',
    is_array( $manifest ) && '/live/' === $manifest['scope'] && 'https://example.test/live/?resume=1' === $manifest['start_url'],
    is_array( $manifest ) ? $manifest['scope'] . ' ' . $manifest['start_url'] : '' );

rm_test_section( 'They are rewritten when, and only when, something changed' );
unlink( $root . 'pwa-sw.js' );
unlink( $root . 'manifest.json' );
rm_maybe_refresh_pwa_files();
rm_test_check( 'nothing changed: not rewritten on every admin page',
    ! is_file( $root . 'pwa-sw.js' ) && ! is_file( $root . 'manifest.json' ) );

// The case that used to fail: a new worker, same plugin version.
file_put_contents( $GLOBALS['rm_test_tmp'] . 'plugin/templates/template-pwa-sw.js', "\n// changed\n", FILE_APPEND );
rm_maybe_refresh_pwa_files();
$sw_after = is_file( $root . 'pwa-sw.js' ) ? file_get_contents( $root . 'pwa-sw.js' ) : '';
rm_test_check( 'a changed worker template is written out without a version bump',
    str_contains( $sw_after, '// changed' ) );
rm_test_check( 'under a new cache name, so the new worker starts with an empty cache',
    preg_match( $version_pattern, $sw_after, $second_name ) && isset( $first_name[1] ) && $second_name[1] !== $first_name[1],
    ( $first_name[1] ?? '?' ) . ' -> ' . ( $second_name[1] ?? '?' ) );

unlink( $root . 'pwa-sw.js' );
unlink( $root . 'manifest.json' );
file_put_contents( $GLOBALS['rm_test_tmp'] . 'plugin/templates/template-manifest.json', "\n", FILE_APPEND );
rm_maybe_refresh_pwa_files();
rm_test_check( 'so is a changed manifest template', is_file( $root . 'manifest.json' ) );

// Leave nothing behind in the temp directory.
foreach ( array( 'root/pwa-sw.js', 'root/manifest.json', 'plugin/templates/template-pwa-sw.js', 'plugin/templates/template-manifest.json' ) as $f ) {
    @unlink( $GLOBALS['rm_test_tmp'] . $f );
}
foreach ( array( 'plugin/templates', 'plugin', 'root', '' ) as $d ) {
    @rmdir( $GLOBALS['rm_test_tmp'] . $d );
}

rm_test_finish();

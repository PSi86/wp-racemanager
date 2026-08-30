<?php
/**
 * What the SEO handler puts into <head>.
 *
 * E9: it echoed its own <title> from wp_head at priority 1 -- the same hook and priority core
 * uses for _wp_render_title_tag() -- so every singular page carried two of them. And the
 * per-post overrides were read into variables that only exist on singular views, so every
 * archive, search result and 404 logged three "undefined variable" warnings.
 */

require_once __DIR__ . '/../bootstrap.php';

/* --------------------------------------------------------------------------
 * A page WordPress can be asked about
 * ----------------------------------------------------------------------- */

$GLOBALS['rm_is_singular']    = true;
$GLOBALS['rm_is_home']        = false;
$GLOBALS['rm_is_front']       = false;
$GLOBALS['rm_queried_id']     = 182;
$GLOBALS['rm_meta']           = array();
$GLOBALS['rm_title_support']  = true;
$GLOBALS['rm_filters_added']  = array();

function is_singular( $types = '' ) { return (bool) $GLOBALS['rm_is_singular']; }
function is_home() { return (bool) $GLOBALS['rm_is_home']; }
function is_front_page() { return (bool) $GLOBALS['rm_is_front']; }
function get_queried_object_id() { return (int) $GLOBALS['rm_queried_id']; }
function get_post_meta( $id, $key, $single = false ) {
    return isset( $GLOBALS['rm_meta'][ $key ] ) ? $GLOBALS['rm_meta'][ $key ] : '';
}
function get_bloginfo( $what = '' ) { return 'name' === $what ? 'Copterrace' : 'FPV racing'; }
function current_theme_supports( $feature ) { return 'title-tag' === $feature ? (bool) $GLOBALS['rm_title_support'] : true; }
function get_permalink( $id = 0 ) { return 'https://example.test/race/spring-cup/'; }
function has_post_thumbnail() { return false; }

// Core's own document title pipeline, reduced to the part that matters here.
function wp_get_document_title() {
    return apply_filters( 'pre_get_document_title', 'Spring Cup - Copterrace' );
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
    $GLOBALS['rm_filters'][ $hook ][] = $callback;
    $GLOBALS['rm_filters_added'][]    = $hook;
    return true;
}
function apply_filters( $hook, $value, ...$args ) {
    foreach ( (array) ( $GLOBALS['rm_filters'][ $hook ] ?? array() ) as $callback ) {
        $value = $callback( $value, ...$args );
    }
    return $value;
}

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/seo-handler.php';

/**
 * Render the head the way WordPress would: core's title tag first, then our action.
 */
function rm_test_head( $with_core_title = true ) {
    ob_start();
    if ( $with_core_title && current_theme_supports( 'title-tag' ) ) {
        echo '<title>' . wp_get_document_title() . '</title>'; // _wp_render_title_tag()
    }
    rm_seo_output_meta();
    return ob_get_clean();
}

/* --------------------------------------------------------------------------
 * One title, not two
 * ----------------------------------------------------------------------- */

rm_test_section( 'A singular page' );

$head = rm_test_head();
rm_test_check( 'exactly one <title>', 1 === substr_count( $head, '<title>' ), $head );
rm_test_check( 'og:title is still there', str_contains( $head, 'property="og:title"' ) );
rm_test_check( 'canonical points at the permalink', str_contains( $head, 'rel="canonical" href="https://example.test/race/spring-cup/"' ) );

rm_test_section( 'With a per-post title override' );

$GLOBALS['rm_meta'] = array( '_seo_title' => 'Spring Cup 2026 - live results' );
$head = rm_test_head();
rm_test_check( 'still exactly one <title>', 1 === substr_count( $head, '<title>' ), $head );
rm_test_check( 'and it carries the override',
    str_contains( $head, '<title>Spring Cup 2026 - live results</title>' ),
    'the override has to reach core\'s title tag, not a second one' );
rm_test_check( 'og:title agrees with it', str_contains( $head, 'content="Spring Cup 2026 - live results"' ) );

rm_test_section( 'A theme that opts out of the title tag' );

$GLOBALS['rm_title_support'] = false;
$head = rm_test_head();
rm_test_check( 'the page is not left without a title', 1 === substr_count( $head, '<title>' ), $head );
$GLOBALS['rm_title_support'] = true;

/* --------------------------------------------------------------------------
 * Nothing undefined off a singular view
 * ----------------------------------------------------------------------- */

rm_test_section( 'Archives, search and 404' );

$GLOBALS['rm_meta']        = array();
$GLOBALS['rm_is_singular'] = false;
$GLOBALS['rm_queried_id']  = 0;

$warnings = array();
set_error_handler( static function ( $no, $str ) use ( &$warnings ) {
    $warnings[] = $str;
    return true;
}, E_ALL );

$head = rm_test_head();

restore_error_handler();

rm_test_check( 'no PHP notices or warnings', array() === $warnings, implode( ' | ', $warnings ) );
rm_test_check( 'still one <title>', 1 === substr_count( $head, '<title>' ) );
rm_test_check( 'no canonical is invented for an archive', ! str_contains( $head, 'rel="canonical"' ) );
rm_test_check( 'the site description is still advertised', str_contains( $head, 'name="description"' ) );

rm_test_section( 'The blog home' );

$GLOBALS['rm_is_home'] = true;
$head = rm_test_head();
rm_test_check( 'og:title falls back to the site name', str_contains( $head, 'content="Copterrace"' ), $head );
rm_test_check( 'canonical is the home URL', str_contains( $head, 'rel="canonical" href="https://example.test/"' ), $head );

rm_test_section( 'The filter is what carries the override' );
rm_test_check( 'pre_get_document_title is filtered', in_array( 'pre_get_document_title', $GLOBALS['rm_filters_added'], true ),
    'without this the override would need a second title tag again' );

rm_test_finish();

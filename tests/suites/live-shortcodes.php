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
    $GLOBALS['rm_style_versions'][ $handle ]  = $ver;
}

function get_post_meta( $id, $key, $single = false ) {
    return '_race_live' === $key ? ( $GLOBALS['rm_test_race_live'] ?? '1' ) : '';
}
// The page being rendered, which is how rm_current_view_slug() knows which view tab is current.
function get_queried_object() { return $GLOBALS['rm_test_queried'] ?? null; }
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
$GLOBALS['rm_style_versions']     = array();
$GLOBALS['rm_options'] = array( 'rm_live_page_id' => 7, 'admin_email' => 'race@example.test' );

require_once RM_TEST_DIR . '/stubs/wordpress.php';

rm_test_post( 7,  'page', 'live' );
rm_test_post( 11, 'page', 'bracket', 'publish', 7 );
// An ampersand in a title, so the view tabs have something to escape.
rm_test_post( 12, 'page', 'stats',   'publish', 7, 'Stats & Laps' );
rm_test_post( 13, 'page', 'nextup',  'publish', 7, 'Next up' );
rm_test_post( 14, 'page', 'pilots',  'publish', 7 );
rm_test_post( 182, 'race', 'spring-cup-2026', 'publish', 0, 'Spring Cup 2026' );

// Which page each shortcode lives on, for the current-view marking.
$view_page = array(
    'rm_pilots_shortcode'  => 14,
    'rm_bracket_shortcode' => 11,
    'rm_stats_shortcode'   => 12,
    'rm_nextup_shortcode'  => 13,
);

require_once RM_PLUGIN_DIR . '/includes/race-data-functions.php';
require_once RM_PLUGIN_DIR . '/includes/live-routing.php';
require_once RM_PLUGIN_DIR . '/includes/livepage-handler.php';

// The race arrives exactly as the rewrite rule delivers it.
$GLOBALS['rm_query_vars']['rm_race'] = 'spring-cup-2026';

rm_test_section( 'Every shortcode renders without a fatal' );
$rendered = array();
$styles   = array();
foreach ( array( 'rm_pilots_shortcode', 'rm_bracket_shortcode', 'rm_stats_shortcode', 'rm_nextup_shortcode' ) as $fn ) {
    $html  = '';
    $error = '';
    // Each shortcode here stands for its own page request, so the once-per-request flag on the
    // status indicator is reset between them. Rendering four in one process is an artefact of
    // this suite; on a real site each of these is a separate load.
    $GLOBALS['rm_update_status_emitted'] = false;
    $GLOBALS['rm_view_tabs_emitted']     = false;
    $GLOBALS['rm_test_queried']          = get_post( $view_page[ $fn ] );
    // Which sheets *this* shortcode asked for, and only this one. Reading the shared list would
    // let one view's enqueue answer for another's -- which is exactly how a check that the stats
    // view loads the right stylesheet passed while it was loading the wrong one. Emptied rather
    // than diffed, because two views legitimately enqueue the same handle and a diff would credit
    // it only to whichever ran first.
    $GLOBALS['rm_styles_enqueued'] = array();
    try {
        $html = $fn( array() );
    } catch ( \Throwable $e ) {
        $error = get_class( $e ) . ': ' . $e->getMessage();
    }
    $rendered[ $fn ] = $html;
    $styles[ $fn ] = $GLOBALS['rm_styles_enqueued'];
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

rm_test_section( 'Every asset is versioned by the plugin, not by hand' );
// A literal version string beside an enqueue has to be remembered every time the file changes,
// and it never is: css/rm-update-status.css was rewritten twice while the '1.1.0' next to it
// stayed put, and js/rm-m-displayStats.js grew a whole pilot filter while its module still said
// '1.0.3'. A returning visitor would have kept the cached copy and seen the previous release --
// which looks like nothing is wrong, because the old file still works.
//
// Note what this cannot cover: js/rm-m-dataLoader.js is reached through a relative import, and
// WordPress versions only what it enqueues. That one's freshness rests on the host's cache
// headers, which docs/deployment.md says to check once per host.
$stale = array();
foreach ( $GLOBALS['rm_modules_registered'] as $id => $module ) {
    if ( WP_RACEMANAGER_VERSION !== $module['version'] ) {
        $stale[] = "$id=" . var_export( $module['version'], true );
    }
}
foreach ( $GLOBALS['rm_style_versions'] as $handle => $version ) {
    if ( WP_RACEMANAGER_VERSION !== $version ) {
        $stale[] = "$handle=" . var_export( $version, true );
    }
}
rm_test_check( 'every registered module and stylesheet carries WP_RACEMANAGER_VERSION',
    array() === $stale, implode( ', ', $stale ) );

rm_test_section( 'The freshness indicator is wired into every live view' );
// The element, the module and the stylesheet come from one helper precisely so that they cannot
// drift apart. A view that emits the element without the stylesheet would show an unstyled
// button in the middle of the page; one that emits it without the module would show an empty
// pill forever.
foreach ( $rendered as $fn => $html ) {
    rm_test_check( "$fn emits the indicator",
        str_contains( $html, 'id="rm-update-status"' ), substr( $html, 0, 120 ) );
}
rm_test_check( 'it is a button, so the whole pill can force a check',
    preg_match( '/<button[^>]+id="rm-update-status"/', $rendered['rm_bracket_shortcode'] ) === 1,
    $rendered['rm_bracket_shortcode'] );
rm_test_check( 'and it starts hidden, for the no-JavaScript case',
    preg_match( '/<button[^>]+id="rm-update-status"[^>]*\shidden\b/', $rendered['rm_bracket_shortcode'] ) === 1,
    $rendered['rm_bracket_shortcode'] );
$without_status_css = array_keys( array_filter( $styles,
    static fn( $sheets ) => ! isset( $sheets['rm-update-status-css'] ) ) );
rm_test_check( 'the stylesheet travels with it, in every view',
    array() === $without_status_css, implode( ', ', $without_status_css ) );

rm_test_section( 'The view tabs come with every view (L9)' );
// A row of plain links fixed to the foot of a phone's screen: one per child page of the live
// page, in page order, each pointing at this race, the page's own view marked as current.
$expected_hrefs = array();
foreach ( array( 'bracket', 'stats', 'nextup', 'pilots' ) as $view ) {
    $expected_hrefs[ $view ] = 'https://example.test/live/spring-cup-2026/' . $view . '/';
}
foreach ( $rendered as $fn => $html ) {
    rm_test_check( "$fn emits the tabs, once", 1 === substr_count( $html, 'id="rm-view-tabs"' ),
        substr_count( $html, 'id="rm-view-tabs"' ) . ' found' );
    preg_match_all( '#<a class="rm-view-tabs__tab" href="([^"]+)"( aria-current="page")?>#', $html, $tabs, PREG_SET_ORDER );
    rm_test_check( "$fn: a tab per view, in page order, each for this race",
        array_values( $expected_hrefs ) === array_column( $tabs, 1 ), implode( ' ', array_column( $tabs, 1 ) ) );
    $current = array_values( array_filter( $tabs, static fn( $tab ) => ! empty( $tab[2] ) ) );
    $own     = $expected_hrefs[ get_post( $view_page[ $fn ] )->post_name ];
    rm_test_check( "$fn: its own tab is the current one, and no other",
        1 === count( $current ) && $own === $current[0][1], wp_json_encode( array_column( $current, 1 ) ) );
    rm_test_check( "$fn: the tabs' stylesheet travels with them", isset( $styles[ $fn ]['rm-view-tabs-css'] ),
        implode( ', ', array_keys( $styles[ $fn ] ) ) );
}
rm_test_check( 'the labels are the page titles, escaped',
    str_contains( $rendered['rm_bracket_shortcode'], '>Stats &amp; Laps</a>' )
    && str_contains( $rendered['rm_bracket_shortcode'], '>Next up</a>' ), $rendered['rm_bracket_shortcode'] );
rm_test_check( 'the row is a named navigation landmark',
    1 === preg_match( '#<nav id="rm-view-tabs" class="rm-view-tabs" aria-label="[^"]+">#', $rendered['rm_bracket_shortcode'] ) );

rm_test_section( 'No race, no tabs; a finished race keeps them' );
$GLOBALS['rm_query_vars']['rm_race'] = '';
rm_reset_current_race();
$GLOBALS['rm_view_tabs_emitted'] = false;
$no_race = rm_bracket_shortcode( array() );
rm_test_check( 'without a race there is nothing for them to link to',
    str_contains( $no_race, 'No race selected' ) && ! str_contains( $no_race, 'rm-view-tabs' ), $no_race );
$GLOBALS['rm_query_vars']['rm_race'] = 'spring-cup-2026';
rm_reset_current_race();
// Next up has nothing to show once a race is over, but the other views still have its results.
$GLOBALS['rm_test_race_live']    = '';
$GLOBALS['rm_view_tabs_emitted'] = false;
$GLOBALS['rm_test_queried']      = get_post( 13 );
$over = rm_nextup_shortcode( array() );
rm_test_check( "a finished race's next-up view still offers the other views",
    str_contains( $over, 'This race is over.' ) && 1 === substr_count( $over, 'id="rm-view-tabs"' ), $over );
unset( $GLOBALS['rm_test_race_live'] );

rm_test_section( 'A routing cache from before the tabs is rebuilt once' );
// Production's rm_live_routing option was written without titles. Read as it is, every tab would
// be labelled with its slug until someone happened to edit a live page.
$GLOBALS['rm_options']['rm_live_routing'] = array( 'page_id' => 7, 'path' => 'live', 'views' => array( 'bracket', 'stats', 'nextup', 'pilots' ) );
$titles = rm_get_live_view_titles();
rm_test_check( 'it answers with the titles',
    array( 'bracket' => 'Bracket', 'stats' => 'Stats & Laps', 'nextup' => 'Next up', 'pilots' => 'Pilots' ) === $titles,
    wp_json_encode( $titles ) );
rm_test_check( 'and keeps them for the next request', isset( $GLOBALS['rm_options']['rm_live_routing']['titles'] ) );

rm_test_section( 'The pilot filter, on the bracket view and now on the stats view' );
// Both halves have to be present for either to be useful: the dropdown marks a pilot, the
// checkbox drops the rest. js/rm-m-displayStats.js reads both by the ids below.
foreach ( array( 'rm_bracket_shortcode', 'rm_stats_shortcode' ) as $fn ) {
    rm_test_check( "$fn offers the pilot dropdown",
        str_contains( $rendered[ $fn ], 'id="pilotSelector"' ), substr( $rendered[ $fn ], 0, 200 ) );
    rm_test_check( "$fn offers the filter checkbox",
        str_contains( $rendered[ $fn ], 'id="filterCheckbox"' ), substr( $rendered[ $fn ], 0, 200 ) );
}
// The controls sit in .web-controls, styled in css/rm-pilot-filter.css together with the marking
// of the selected row. Both views load that file.
foreach ( array( 'rm_bracket_shortcode', 'rm_stats_shortcode' ) as $fn ) {
    rm_test_check( "$fn loads the stylesheet those controls need",
        isset( $styles[ $fn ]['rm-pilot-filter-css'] ) &&
        str_contains( $styles[ $fn ]['rm-pilot-filter-css'], 'css/rm-pilot-filter.css' ),
        implode( ', ', array_keys( $styles[ $fn ] ) ) );
}
// The negative half, and the one that matters. rm_viewer.css is the *bracket's* stylesheet: it
// redefines .node as a bracket race box, while in the stats view .node is RotorHazard's own class
// for a lap-results column -- a callsign stacked above a table of laps. Loading it there laid the
// two on top of each other and mangled the round detail under every heat. That is how the filter
// was first built, and nothing caught it, because the controls did look right.
rm_test_check( 'and the stats view does NOT load the bracket stylesheet with them',
    ! isset( $styles['rm_stats_shortcode']['rm-sc-viewer-css'] ),
    implode( ', ', array_keys( $styles['rm_stats_shortcode'] ) ) );
// The pilots view deliberately has neither: displayPilotStats never imported pilotSelector, and
// its own table is already one row per pilot. Its markup for the controls is still there but
// commented out, so the comments have to come off before asking -- checking the raw string finds
// the ids inside the comment and passes for the wrong reason.
$pilots_live = preg_replace( '/<!--.*?-->/s', '', $rendered['rm_pilots_shortcode'] );
rm_test_check( 'the pilots view still has no active filter, which is deliberate',
    ! str_contains( $pilots_live, 'id="filterCheckbox"' ) &&
    ! str_contains( $pilots_live, 'id="pilotSelector"' ),
    $pilots_live );

// Two live shortcodes on one page is a real configuration -- the pilot stats above the bracket --
// and it is the same case rm_add_js_module_config() exists for. A second element would duplicate
// the id and, because the indicator is positioned fixed, stack a second pill on the first.
$GLOBALS['rm_update_status_emitted'] = false;
$GLOBALS['rm_view_tabs_emitted']     = false;
$one_page = rm_pilots_shortcode( array() ) . rm_bracket_shortcode( array() );
rm_test_check( 'two shortcodes on one page emit exactly one indicator',
    1 === substr_count( $one_page, 'id="rm-update-status"' ),
    substr_count( $one_page, 'id="rm-update-status"' ) . ' found' );
rm_test_check( 'and exactly one row of view tabs', 1 === substr_count( $one_page, 'id="rm-view-tabs"' ),
    substr_count( $one_page, 'id="rm-view-tabs"' ) . ' found' );
rm_test_check( 'and the second shortcode still asks for the module',
    in_array( 'rm-updateStatus', $GLOBALS['rm_modules_enqueued'], true ),
    implode( ', ', $GLOBALS['rm_modules_enqueued'] ) );

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

rm_test_section( 'The loader is told where the index and the parts are (L7)' );
$loader_config = $config['dataLoader'] ?? array();
$data_url      = (string) ( $loader_config['dataUrl'] ?? '' );
rm_test_check( 'the index beside the data file',
    '' !== $data_url && str_replace( '-data.json', '-index.json', $data_url ) === ( $loader_config['indexUrl'] ?? null ),
    json_encode( $loader_config ) );
rm_test_check( 'a part named as the writer names it, "%s" for its path',
    '' !== $data_url && dirname( $data_url ) . '/' . rm_race_part_filename( 182, array( '%s' ) ) === ( $loader_config['partUrl'] ?? null ),
    json_encode( $loader_config ) );

rm_test_finish();

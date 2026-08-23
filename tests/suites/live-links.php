<?php
/**
 * Link rewriting in the live navigation: rm_rewrite_live_links()
 *
 * Runs against the real WP_HTML_Tag_Processor rather than a stand-in, because the whole
 * point of using the HTML API is that it parses markup instead of pattern-matching it.
 * Skips itself when no WordPress checkout can be found.
 */

require_once __DIR__ . '/../bootstrap.php';

if ( ! rm_test_load_html_api() ) {
    rm_test_skip( 'no WordPress checkout found -- set WP_CORE_DIR (see tests/README.md)' );
}

$GLOBALS['rm_options'] = array( 'rm_live_page_id' => 7, 'admin_email' => 'race@example.test' );

require_once RM_TEST_DIR . '/stubs/wordpress.php';

rm_test_post( 7,  'page', 'live' );
rm_test_post( 11, 'page', 'bracket', 'publish', 7 );
rm_test_post( 12, 'page', 'stats',   'publish', 7 );
rm_test_post( 13, 'page', 'nextup',  'publish', 7 );
rm_test_post( 182, 'race', 'spring-cup-2026', 'publish', 0, 'Spring Cup 2026' );
rm_test_post( 183, 'race', 'winter-jam',      'publish', 0, 'Winter Jam' );
rm_test_post( 66,  'race', 'sommer-cup-66',   'publish', 0, 'Sommer Cup 66' );

require_once RM_PLUGIN_DIR . '/includes/live-routing.php';

$GLOBALS['rm_query_vars']['rm_race'] = 'spring-cup-2026';

rm_test_section( 'View links pick up the current race' );
$nav = '<ul class="wp-block-navigation">'
     . '<li><a class="x" href="/live/bracket/">Bracket</a></li>'
     . '<li><a href="https://example.test/live/stats/">Stats</a></li>'
     . '<li><a href="/live/nextup/">Next Up</a></li>'
     . '</ul>';
$out = rm_rewrite_live_links( $nav );
rm_test_check( 'bracket rewritten', str_contains( $out, 'href="https://example.test/live/spring-cup-2026/bracket/"' ), $out );
rm_test_check( 'stats rewritten',   str_contains( $out, 'href="https://example.test/live/spring-cup-2026/stats/"' ) );
rm_test_check( 'nextup rewritten',  str_contains( $out, 'href="https://example.test/live/spring-cup-2026/nextup/"' ) );
rm_test_check( 'other attributes survive', str_contains( $out, 'class="x"' ) );
rm_test_check( 'link text untouched', str_contains( $out, '>Bracket<' ) && str_contains( $out, '>Next Up<' ) );

rm_test_section( 'What must not be touched' );
$mixed = '<div>'
       . '<a href="/live/">Selection</a>'
       . '<a href="/live/winter-jam/stats/">another race</a>'
       . '<a href="/races/spring-cup-2026/">race page</a>'
       . '<a href="https://evil.test/live/stats/">foreign</a>'
       . '<a href="#top">anchor</a>'
       . '<a>no href</a>'
       . '</div>';
$out2 = rm_rewrite_live_links( $mixed );
rm_test_check( 'selection gets rm_race as a marker, never race_id',
    str_contains( $out2, 'href="https://example.test/live/?rm_race=spring-cup-2026"' ) && ! str_contains( $out2, 'race_id=' ),
    $out2 );
rm_test_check( 'another race untouched', str_contains( $out2, 'href="/live/winter-jam/stats/"' ) );
rm_test_check( 'race page untouched', str_contains( $out2, 'href="/races/spring-cup-2026/"' ) );
rm_test_check( 'foreign host untouched', str_contains( $out2, 'href="https://evil.test/live/stats/"' ) );
rm_test_check( 'anchor untouched', str_contains( $out2, 'href="#top"' ) );
rm_test_check( 'anchor without href survives', str_contains( $out2, '<a>no href</a>' ) );

rm_test_section( 'No race in context means no change' );
$GLOBALS['rm_query_vars']['rm_race'] = '';
rm_reset_current_race();
rm_test_check( 'returned unchanged', rm_rewrite_live_links( $nav ) === $nav );
$GLOBALS['rm_query_vars']['rm_race'] = 'spring-cup-2026';
rm_reset_current_race();

rm_test_section( 'Markup robustness' );
rm_test_check( 'empty string', '' === rm_rewrite_live_links( '' ) );
rm_test_check( 'text without links', 'just text' === rm_rewrite_live_links( 'just text' ) );
$quoted = '<a href=\'/live/stats/\' data-x="a>b">S</a>';
$outq   = rm_rewrite_live_links( $quoted );
rm_test_check( 'single quotes and > inside an attribute',
    str_contains( $outq, '/live/spring-cup-2026/stats/' ) && str_contains( $outq, 'data-x="a>b"' ), $outq );
$nested = '<div><p>Text <a href="/live/stats/">S</a> more</p></div>';
rm_test_check( 'nested markup stays intact',
    str_contains( rm_rewrite_live_links( $nested ), '<div><p>Text <a href="https://example.test/live/spring-cup-2026/stats/">S</a> more</p></div>' ),
    rm_rewrite_live_links( $nested ) );

rm_test_section( 'Full scenario: a visitor on race 66, bracket view' );
$GLOBALS['rm_query_vars']['rm_race'] = 'sommer-cup-66';
rm_reset_current_race();

$live_nav = '<nav class="wp-block-navigation"><ul class="wp-block-navigation__container">'
  . '<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="/live/">Selection</a></li>'
  . '<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="/live/bracket/">Bracket</a></li>'
  . '<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="/live/stats/">Stats</a></li>'
  . '<li class="wp-block-navigation-item"><a class="wp-block-navigation-item__content" href="/live/nextup/">Next Up</a></li>'
  . '</ul></nav>';
$nav66 = rm_rewrite_live_links( $live_nav );

foreach ( array( 'bracket', 'stats', 'nextup' ) as $view ) {
    rm_test_check( "navigation item '$view' points at race 66",
        str_contains( $nav66, 'href="https://example.test/live/sommer-cup-66/' . $view . '/"' ), $nav66 );
}
rm_test_check( 'selection link carries the race',
    str_contains( $nav66, 'href="https://example.test/live/?rm_race=sommer-cup-66"' ), $nav66 );
rm_test_check( 'no link left pointing at a race-less view',
    ! preg_match( '#href="[^"]*/live/(bracket|stats|nextup)/"#', $nav66 ), $nav66 );
rm_test_check( 'labels untouched', str_contains( $nav66, '>Selection<' ) && str_contains( $nav66, '>Next Up<' ) );
rm_test_check( 'block classes untouched', 4 === substr_count( $nav66, 'wp-block-navigation-item__content' ) );

rm_test_section( 'Rewriting is idempotent' );
rm_test_check( 'a second pass changes nothing', rm_rewrite_live_links( $nav66 ) === $nav66 );

rm_test_section( 'Coverage of both menu mechanisms' );
rm_test_check( 'render_block filter registered', in_array( 'render_block', $GLOBALS['rm_filters'], true ) );
rm_test_check( 'wp_nav_menu filter registered (classic themes)', in_array( 'wp_nav_menu', $GLOBALS['rm_filters'], true ),
    implode( ',', array_unique( $GLOBALS['rm_filters'] ) ) );

rm_test_section( 'The selection marker can be switched off' );
$GLOBALS['rm_filter_overrides']['rm_selection_link_carries_race'] = false;
$off = rm_rewrite_live_links( $live_nav );
rm_test_check( 'selection stays clean', str_contains( $off, 'href="/live/"' ), $off );
rm_test_check( 'views still rewritten', str_contains( $off, '/live/sommer-cup-66/stats/' ) );
unset( $GLOBALS['rm_filter_overrides']['rm_selection_link_carries_race'] );

rm_test_finish();

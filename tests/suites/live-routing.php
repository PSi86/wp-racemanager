<?php
/**
 * Routing for the live micro-site: includes/live-routing.php
 *
 * Covers the rewrite rule's shape and -- importantly -- what it must NOT match, URL
 * building, race resolution, link matching and the canonical redirects.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_options'] = array( 'rm_live_page_id' => 7, 'admin_email' => 'race@example.test' );

require_once RM_TEST_DIR . '/stubs/wordpress.php';

// Fixtures: a live page with three view children, plus three races.
rm_test_post( 7,  'page', 'live' );
rm_test_post( 11, 'page', 'bracket', 'publish', 7 );
rm_test_post( 12, 'page', 'stats',   'publish', 7 );
rm_test_post( 13, 'page', 'nextup',  'publish', 7 );
rm_test_post( 182, 'race', 'spring-cup-2026', 'publish', 0, 'Spring Cup 2026' );
rm_test_post( 183, 'race', 'winter-jam',      'publish', 0, 'Winter Jam' );
rm_test_post( 184, 'race', 'secret-draft',    'draft',   0, 'Secret Draft' );

require_once RM_PLUGIN_DIR . '/includes/live-routing.php';

rm_test_section( 'Routing facts are derived from the pages' );
rm_test_check( 'live path', 'live' === rm_live_path(), rm_live_path() );
rm_test_check( 'views come from the child pages',
    array( 'bracket', 'stats', 'nextup' ) === rm_get_live_view_slugs(),
    implode( ',', rm_get_live_view_slugs() ) );
rm_test_check( 'default view is bracket', 'bracket' === rm_get_default_live_view() );
rm_test_check( 'bracket is a view', rm_is_live_view( 'bracket' ) );
rm_test_check( 'a race slug is not a view', ! rm_is_live_view( 'spring-cup-2026' ) );

rm_test_section( 'Rewrite rule' );
rm_live_rewrite_rules();
rm_test_check( 'exactly one rule', 1 === count( $GLOBALS['rm_rewrite'] ), count( $GLOBALS['rm_rewrite'] ) . ' rules' );
list( $regex, $target, $where ) = $GLOBALS['rm_rewrite'][0];
rm_test_check( 'registered at the top', 'top' === $where );
rm_test_check( 'target sets pagename and rm_race',
    'index.php?pagename=live/$matches[2]&rm_race=$matches[1]' === $target, $target );
rm_test_check( 'no leading ^ of its own (WP adds it)', ! str_starts_with( $regex, '^' ), $regex );

$matches = static function ( $path ) use ( $regex ) {
    return (bool) preg_match( "#^$regex#", $path );
};

rm_test_check( 'matches /live/{race}/bracket/', $matches( 'live/spring-cup-2026/bracket/' ) );
rm_test_check( 'matches without a trailing slash', $matches( 'live/spring-cup-2026/bracket' ) );
rm_test_check( 'does not match /live/bracket/', ! $matches( 'live/bracket/' ), 'would collide with the view page' );
rm_test_check( 'does not match /live/', ! $matches( 'live/' ) );
rm_test_check( 'does not match /liveblog/a/b/', ! $matches( 'liveblog/a/b/' ) );
// A generic [^/]+ for the second segment swallowed all of these:
rm_test_check( 'does not match /live/page/2/', ! $matches( 'live/page/2/' ), 'race list pagination would 404' );
rm_test_check( 'does not match /live/page/17/', ! $matches( 'live/page/17/' ) );
rm_test_check( 'does not match /live/{race}/feed/', ! $matches( 'live/spring-cup-2026/feed/' ), 'feeds would break' );
rm_test_check( 'does not match an unknown view', ! $matches( 'live/spring-cup-2026/nope/' ) );
rm_test_check( 'does not match /live/bracket/page/2/', ! $matches( 'live/bracket/page/2/' ) );

preg_match( "#^$regex#", 'live/spring-cup-2026/stats/', $m );
rm_test_check( 'group 1 is the race, group 2 the view', 'spring-cup-2026' === $m[1] && 'stats' === $m[2] );

rm_test_section( 'Building URLs' );
rm_test_check( 'default view',
    'https://example.test/live/spring-cup-2026/bracket/' === rm_live_url( get_post( 182 ) ),
    rm_live_url( get_post( 182 ) ) );
rm_test_check( 'explicit view',
    'https://example.test/live/spring-cup-2026/stats/' === rm_live_url( get_post( 182 ), 'stats' ) );
rm_test_check( 'unknown view falls back to the default',
    'https://example.test/live/spring-cup-2026/bracket/' === rm_live_url( get_post( 182 ), 'nope' ) );
rm_test_check( 'by slug', 'https://example.test/live/winter-jam/bracket/' === rm_live_url( 'winter-jam' ) );
rm_test_check( 'by id', 'https://example.test/live/winter-jam/bracket/' === rm_live_url( 183 ) );
rm_test_check( 'unknown race yields an empty string', '' === rm_live_url( 'no-such-race' ) );
rm_test_check( 'selection page', 'https://example.test/live/' === rm_live_selection_url() );

rm_test_section( 'Resolving a race' );
rm_test_check( 'by slug', 182 === rm_resolve_race( 'spring-cup-2026' )->ID );
rm_test_check( 'by id', 182 === rm_resolve_race( 182 )->ID );
rm_test_check( 'a page is not a race', null === rm_resolve_race( 11 ) );
rm_test_check( 'unknown identifier', null === rm_resolve_race( 'nope' ) );
$GLOBALS['rm_can_edit'] = false;
rm_test_check( 'a draft is not guessable by slug', null === rm_resolve_race( 'secret-draft' ) );
$GLOBALS['rm_can_edit'] = true;
rm_test_check( 'an editor can see the draft', null !== rm_resolve_race( 'secret-draft' ) );
$GLOBALS['rm_can_edit'] = false;

rm_test_section( 'Recognising links into the live area' );
rm_test_check( '/live/stats/ is the stats view', 'stats' === rm_match_live_view( '/live/stats/' ) );
rm_test_check( 'absolute URL works too', 'stats' === rm_match_live_view( 'https://example.test/live/stats/' ) );
rm_test_check( '/live/ is the landing page', '' === rm_match_live_view( '/live/' ) );
rm_test_check( 'a link that already carries a race is left alone', null === rm_match_live_view( '/live/spring-cup-2026/stats/' ) );
rm_test_check( 'outside the live area', null === rm_match_live_view( '/races/spring-cup-2026/' ) );
rm_test_check( 'another host', null === rm_match_live_view( 'https://evil.test/live/stats/' ) );
rm_test_check( 'an anchor', null === rm_match_live_view( '#top' ) );
rm_test_check( 'a mailto link', null === rm_match_live_view( 'mailto:a@b.test' ) );
rm_test_check( 'an empty href', null === rm_match_live_view( '' ) );
rm_test_check( 'an unknown subpage', null === rm_match_live_view( '/live/imprint/' ) );

rm_test_section( 'Current race from the path' );
$GLOBALS['rm_query_vars']['rm_race'] = 'spring-cup-2026';
rm_test_check( 'resolved from rm_race', 182 === rm_get_current_race_id() );

rm_test_section( 'The legacy redirect stays inside the live area' );
$GLOBALS['rm_query_vars']['rm_race'] = '';
rm_reset_current_race();
$_GET = array( 'race_id' => 182 );

RaceManager\WP_RaceManager::$live = true;
$hit = rm_test_redirect_from( 'rm_live_canonical_redirect' );
rm_test_check( 'inside: 301 to the canonical URL',
    $hit && 'https://example.test/live/spring-cup-2026/bracket/' === $hit[0] && 301 === $hit[1],
    $hit ? $hit[0] . ' (' . $hit[1] . ')' : 'no redirect' );

RaceManager\WP_RaceManager::$live = false;
rm_reset_current_race();
rm_test_check( 'outside (e.g. /register/): no redirect',
    null === rm_test_redirect_from( 'rm_live_canonical_redirect' ),
    'the registration link would be hijacked' );

RaceManager\WP_RaceManager::$live = true;
$_GET = array( 'race_id' => 999999 );
rm_reset_current_race();
rm_test_check( 'unknown race: no redirect', null === rm_test_redirect_from( 'rm_live_canonical_redirect' ) );
$_GET = array();

rm_test_finish();

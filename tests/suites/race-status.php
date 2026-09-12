<?php
/**
 * A race's two states, live and archived, and what follows from each: includes/race-status.php.
 *
 * What an archived race keeps on disk is race-writes' to check. This suite is about the states
 * themselves (1.9.0): what tells a change from a repeat, and what a change sets off.
 *
 *   - The page cache is emptied when a race's state changes, and only then. The live area is
 *     cached, and the state is in its markup; measured on production on 2026-09-12, the home page,
 *     the race list and a race's bracket all came from LiteSpeed's page cache.
 *   - The dot on the live link asks for a race that is live, not for an upload in the last two
 *     hours, which a cached page kept showing after the race was over.
 *
 * The hooks run as core runs them: update_post_meta() below compares the stored string with the
 * new value strictly, fires update_post_meta before a value changes and updated_ or added_post_meta
 * after -- so Quick Edit's number for an archived race is a change to WordPress, and a repeat here.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_upload_base'] = sys_get_temp_dir() . '/rm-race-status-' . getmypid();
$GLOBALS['rm_meta']        = array();
$GLOBALS['rm_hooks']       = array();
$GLOBALS['rm_fired']       = array();
$GLOBALS['rm_queries']     = array();
$GLOBALS['rm_options']     = array();
$GLOBALS['rm_now']         = '2026-09-12 10:00:00';

function wp_upload_dir() {
    return array( 'basedir' => $GLOBALS['rm_upload_base'], 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => '' );
}
function current_time( $type ) {
    return $GLOBALS['rm_now'];
}
function get_post_meta( $id, $key = '', $single = false ) {
    return $GLOBALS['rm_meta'][ $id ][ $key ] ?? '';
}
function delete_post_meta( $id, $key, $value = '' ) {
    unset( $GLOBALS['rm_meta'][ $id ][ $key ] );
    return true;
}
function wp_doing_ajax() {
    return false;
}

// Hooks as core runs them, as far as these go: a callback gets as many arguments as it asked for,
// and every action fired is recorded.
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
    $GLOBALS['rm_hooks'][ $hook ][] = array( $callback, $args );
}
function do_action( $hook, ...$args ) {
    $GLOBALS['rm_fired'][] = array( $hook, $args );
    foreach ( $GLOBALS['rm_hooks'][ $hook ] ?? array() as $registered ) {
        call_user_func_array( $registered[0], array_slice( $args, 0, $registered[1] ) );
    }
}
// update_metadata() as far as the hooks go.
function update_post_meta( $id, $key, $value ) {
    $exists = isset( $GLOBALS['rm_meta'][ $id ] ) && array_key_exists( $key, $GLOBALS['rm_meta'][ $id ] );
    if ( $exists && $GLOBALS['rm_meta'][ $id ][ $key ] === $value ) {
        return false;
    }
    if ( $exists ) {
        do_action( 'update_post_meta', 1, $id, $key, $value );
    }
    $GLOBALS['rm_meta'][ $id ][ $key ] = is_bool( $value ) ? ( $value ? '1' : '' ) : ( is_scalar( $value ) ? (string) $value : $value );
    do_action( $exists ? 'updated_post_meta' : 'added_post_meta', 1, $id, $key, $value );
    return true;
}

// Core's reading of what the one-time archiving asks for: 'any' is every status but the bin, and
// 'ids' gives IDs.
function get_posts( $args = array() ) {
    $out = array();
    foreach ( $GLOBALS['rm_posts'] ?? array() as $post ) {
        if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
            continue;
        }
        $status = $args['post_status'] ?? 'publish';
        if ( 'any' === $status ? 'trash' === $post->post_status : $post->post_status !== $status ) {
            continue;
        }
        $out[] = 'ids' === ( $args['fields'] ?? '' ) ? $post->ID : $post;
    }
    return $out;
}
// db-handler.php's, which deletes a race's rows from the subscriptions table.
function rm_delete_all_race_subscriptions( $race_id ) {
    $GLOBALS['rm_forgotten'][] = (int) $race_id;
    return 1;
}

class WP_Query {
    public $posts = array();
    public function __construct( $args = array() ) {
        $GLOBALS['rm_queries'][] = $args;
        $this->posts = $GLOBALS['rm_query_result'] ?? array();
    }
    public function have_posts() {
        return ! empty( $this->posts );
    }
}

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/race-data-functions.php';
require_once RM_PLUGIN_DIR . '/includes/race-files.php';

function rm_rs_purges() {
    $purges = array_filter( $GLOBALS['rm_fired'], fn( $fired ) => 'litespeed_purge' === $fired[0] && array( '*' ) === $fired[1] );
    $GLOBALS['rm_fired'] = array();
    return count( $purges );
}

rm_test_post( 42, 'race', 'autumn-cup', 'publish', 0, 'Autumn Cup' );
rm_test_post( 43, 'post', 'news', 'publish', 0, 'News' );
rm_test_post( 44, 'race', 'spring-cup', 'draft', 0, 'Spring Cup' );

/* --------------------------------------------------------------------------
 * The page cache
 * ----------------------------------------------------------------------- */

rm_test_section( 'A change of state empties the page cache, and only a change' );

rm_test_check( 'hooked before and after the flag is stored',
    isset( $GLOBALS['rm_hooks']['update_post_meta'], $GLOBALS['rm_hooks']['updated_post_meta'], $GLOBALS['rm_hooks']['added_post_meta'] ) );

$GLOBALS['rm_fired'] = array();
update_post_meta( 42, '_race_live', 1 );
rm_test_check( 'a race created live: emptied', 1 === rm_rs_purges() );
update_post_meta( 42, '_race_last_upload', '2026-09-12 10:00:00' );
rm_test_check( 'its first results, which put it on the race list: emptied', 1 === rm_rs_purges() );
update_post_meta( 42, '_race_last_upload', '2026-09-12 10:05:00' );
rm_test_check( 'a later upload, which changes no page: left alone', 0 === rm_rs_purges() );

update_post_meta( 42, '_race_live', '0' );
rm_test_check( 'set to archive in the meta box: emptied', 1 === rm_rs_purges() );
update_post_meta( 42, '_race_live', 0 );
rm_test_check( 'Quick Edit\'s 0 for it, a change to WordPress: left alone', 0 === rm_rs_purges() );
update_post_meta( 42, '_race_live', '0' );
rm_test_check( 'the meta box\'s same value, no change to anyone: left alone', 0 === rm_rs_purges() );
update_post_meta( 42, '_race_live', '1' );
rm_test_check( 'live again: emptied', 1 === rm_rs_purges() );

update_post_meta( 44, '_race_live', '0' );
rm_test_check( 'a race created in the admin, which the meta box saves as archived: left alone', 0 === rm_rs_purges() );
update_post_meta( 43, '_race_live', '1' );
update_post_meta( 43, '_race_last_upload', '2026-09-12 10:00:00' );
rm_test_check( 'a post that is no race: left alone', 0 === rm_rs_purges() );

/* --------------------------------------------------------------------------
 * Push subscriptions
 * ----------------------------------------------------------------------- */

rm_test_section( 'An archived race\'s push subscriptions go' );

// Nothing reaches a follower of an archived race any more, and a subscription holds a browser's
// endpoint and keys and the pilot it followed. rm_delete_all_race_subscriptions() existed, and
// nothing called it.
$GLOBALS['rm_forgotten'] = array();
update_post_meta( 42, '_race_live', '0' );
rm_test_check( 'set to archive: its subscriptions are deleted', array( 42 ) === $GLOBALS['rm_forgotten'], json_encode( $GLOBALS['rm_forgotten'] ) );
$GLOBALS['rm_forgotten'] = array();
update_post_meta( 42, '_race_live', '1' );
rm_test_check( 'set live: nobody\'s', array() === $GLOBALS['rm_forgotten'] );
do_action( 'before_delete_post', 42 );
rm_test_check( 'deleted for good: its subscriptions go with it', array( 42 ) === $GLOBALS['rm_forgotten'] );
$GLOBALS['rm_forgotten'] = array();
do_action( 'before_delete_post', 43 );
rm_test_check( 'a post that is no race: nobody\'s', array() === $GLOBALS['rm_forgotten'] );

rm_test_section( 'The races archived before, once more for their subscriptions' );

// 1.8.1 cleared their logs once and recorded it as rm_archived_races_cleared. A site that ran that
// has to run it again, for the subscriptions.
$GLOBALS['rm_posts'] = array();
rm_test_post( 50, 'race', 'autumn-cup', 'publish', 0, 'Autumn Cup' );
rm_test_post( 51, 'race', 'winter-cup', 'publish', 0, 'Winter Cup' );
rm_test_post( 52, 'race', 'old-cup', 'trash', 0, 'Old Cup' );
$GLOBALS['rm_meta'][50]['_race_live'] = '0';
$GLOBALS['rm_meta'][51]['_race_live'] = '1';
$GLOBALS['rm_meta'][52]['_race_live'] = '0';
$GLOBALS['rm_options']   = array( 'rm_archived_races_cleared' => '2026-09-12 11:55:12' );
$GLOBALS['rm_forgotten'] = array();
rm_maybe_clear_archived_races();
rm_test_check( 'run on a site that ran 1.8.1\'s: the archived race\'s subscriptions go', array( 50 ) === $GLOBALS['rm_forgotten'], json_encode( $GLOBALS['rm_forgotten'] ) );
rm_test_check( 'recorded as schema 2, and 1.8.1\'s record removed',
    RM_ARCHIVE_SCHEMA === get_option( 'rm_archive_schema' ) && 2 === RM_ARCHIVE_SCHEMA && false === get_option( 'rm_archived_races_cleared' ) );
$GLOBALS['rm_forgotten'] = array();
rm_maybe_clear_archived_races();
rm_test_check( 'and not run again', array() === $GLOBALS['rm_forgotten'] );

/* --------------------------------------------------------------------------
 * The dot on the live link
 * ----------------------------------------------------------------------- */

rm_test_section( 'The dot on the live link follows the live flag' );

$GLOBALS['rm_queries']      = array();
$GLOBALS['rm_query_result'] = array( 42 );
rm_test_check( 'a live race with results: the dot', true === rm_live_race_exists() );
$args  = $GLOBALS['rm_queries'][0] ?? array();
$query = $args['meta_query'] ?? array();
rm_test_check( 'asked for a race that is live', 'race' === ( $args['post_type'] ?? '' )
    && in_array( array( 'key' => '_race_live', 'value' => '1' ), $query, true ), json_encode( $args ) );
rm_test_check( 'and has results', in_array( array( 'key' => '_race_last_upload', 'compare' => 'EXISTS' ), $query, true ) );
rm_test_check( 'not for an upload in a time window, which no event ends',
    ! array_filter( $query, fn( $clause ) => isset( $clause['type'] ) || isset( $clause['value'] ) && '_race_last_upload' === $clause['key'] ) );
$GLOBALS['rm_query_result'] = array();
rm_test_check( 'none: no dot', false === rm_live_race_exists() );

// The plugin's main class asks for it on init; that file is not loaded here.
$main = file_get_contents( RM_PLUGIN_DIR . '/wp-racemanager.php' );
rm_test_check( 'the main class asks rm_live_race_exists(), not for the last two hours',
    str_contains( $main, '$this->live_race_in_progress = rm_live_race_exists();' ) && ! str_contains( $main, "'-2 hours'" ) );
$list = file_get_contents( RM_PLUGIN_DIR . '/includes/block-render-race-select.php' );
rm_test_check( 'and so does the race list\'s "Live:"',
    str_contains( $list, '$is_live = rm_race_is_live( $race_id );' ) && ! str_contains( $list, 'HOUR_IN_SECONDS' ) );

// Clean up.
@rmdir( $GLOBALS['rm_upload_base'] . '/races' );
@rmdir( $GLOBALS['rm_upload_base'] );

rm_test_finish();

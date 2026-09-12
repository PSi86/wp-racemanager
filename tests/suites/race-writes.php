<?php
/**
 * What an upload leaves in uploads/races/: rm_write_files().
 *
 * Browsers poll a race's timestamp file and download what changed when the timestamp did, so the
 * timestamp is a promise that what it announces is there. rm_write_files() wrote it first: a
 * browser polling between the two writes took the new timestamp with the old data, and so did every
 * browser after a data write that failed -- each then held the old standing under the new timestamp
 * until the next upload. The same mistake the loader made before 2026, made on the server
 * (docs/data-flow.md, "Two orderings that are load-bearing"). And a file was rewritten in place,
 * so a browser reading it during the write got half of it.
 *
 * Since 1.8.0 the payload is stored in parts as well, with an index naming each part's hash, so
 * that a browser downloads only the parts that changed (L7 in docs/live-webapp-improvements.md).
 * What matters about them: put back together, they are the payload; an update changes the hash of
 * what changed and of nothing else; and they are written before the index that names them.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_upload_base'] = sys_get_temp_dir() . '/rm-race-writes-' . getmypid();
$GLOBALS['rm_data_dir']    = $GLOBALS['rm_upload_base'] . '/races/';
$GLOBALS['rm_meta']        = array();
$GLOBALS['rm_now']         = '2026-09-12 10:00:00';

// A write that fails is logged; read here instead of printed among the checks.
$GLOBALS['rm_log'] = sys_get_temp_dir() . '/rm-race-writes-' . getmypid() . '.log';
ini_set( 'error_log', $GLOBALS['rm_log'] );

function wp_upload_dir() {
    return array( 'basedir' => $GLOBALS['rm_upload_base'], 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => '' );
}
function current_time( $type ) {
    return $GLOBALS['rm_now'];
}
// Every race is live unless a case says otherwise, with '_race_live' as the meta box stores it.
function get_post_meta( $id, $key = '', $single = false ) {
    if ( '_race_live' === $key && ! isset( $GLOBALS['rm_meta'][ $id ][ $key ] ) ) {
        return '1';
    }
    return $GLOBALS['rm_meta'][ $id ][ $key ] ?? '';
}
function delete_post_meta( $id, $key, $value = '' ) {
    unset( $GLOBALS['rm_meta'][ $id ][ $key ] );
    return true;
}
// Core's reading of what the one-time clearing asks for: 'any' is every status but the bin, and
// 'ids' gives IDs. The shared stub knows neither.
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
function wp_doing_ajax() {
    return ! empty( $GLOBALS['rm_doing_ajax'] );
}
$GLOBALS['rm_options'] = array();
// Hooks by name: race-files.php registers them as it is loaded.
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
    $GLOBALS['rm_actions'][ $hook ][] = $callback;
}

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/race-data-functions.php';
require_once RM_PLUGIN_DIR . '/includes/race-files.php';

/* --------------------------------------------------------------------------
 * Helpers
 * ----------------------------------------------------------------------- */

function rm_rw_file( $race_id, $name ) {
    return $GLOBALS['rm_data_dir'] . $race_id . '-' . $name . '.json';
}

function rm_rw_read( $race_id, $name ) {
    $file = rm_rw_file( $race_id, $name );
    return is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
}

// Everything in the directory, sub-directories left out: what a visitor could be served.
function rm_rw_listing( $race_id = null ) {
    $names = array();
    foreach ( scandir( $GLOBALS['rm_data_dir'] ) as $name ) {
        if ( '.' === $name || '..' === $name || is_dir( $GLOBALS['rm_data_dir'] . $name ) ) {
            continue;
        }
        if ( null === $race_id || 0 === strpos( $name, $race_id . '-' ) ) {
            $names[] = $name;
        }
    }
    sort( $names );
    return $names;
}

function rm_rw_reset() {
    $dir = $GLOBALS['rm_data_dir'];
    if ( is_dir( $dir ) ) {
        foreach ( scandir( $dir ) as $name ) {
            if ( '.' === $name || '..' === $name ) {
                continue;
            }
            is_dir( $dir . $name ) ? rmdir( $dir . $name ) : unlink( $dir . $name );
        }
    }
    $GLOBALS['rm_meta'] = array();
    $GLOBALS['rm_now']  = '2026-09-12 10:00:00';
}

// An event shaped like the connector's upload, results for $flown heats.
function rm_rw_event( $heat_count = 2, $flown = array() ) {
    $heats   = array();
    $results = array();
    for ( $id = 1; $id <= $heat_count; $id++ ) {
        $heats[] = array( 'id' => $id, 'displayname' => 'Heat ' . $id, 'class_id' => 1 );
    }
    foreach ( $flown as $id => $laps ) {
        $results[ $id ] = array( 'heat_id' => $id, 'rounds' => array( array( 'laps' => $laps ) ) );
    }
    return array(
        'race_name'    => 'Autumn Cup',
        'current_heat' => array( 'current_heat' => 1 ),
        'pilot_data'   => array( 'pilots' => array( array( 'pilot_id' => 7, 'callsign' => 'Whoop/7' ) ) ),
        'heat_data'    => array( 'heats' => $heats ),
        'result_data'  => array(
            'heats'             => $results,
            'heats_by_class'    => array( array(), array( 1, 2 ) ),
            // The class's standing follows from the laps of its heats, as RotorHazard's does.
            'classes'           => array( 1 => array( 'id' => 1, 'ranking' => array_sum( $flown ) ) ),
            'event_leaderboard' => array( 'by_race_time' => array() ),
        ),
    );
}

function rm_rw_paths( $index ) {
    return array_map( fn( $part ) => implode( '.', $part['path'] ), $index['parts'] ?? array() );
}

function rm_rw_hashes( $index ) {
    $hashes = array();
    foreach ( $index['parts'] ?? array() as $part ) {
        $hashes[ implode( '.', $part['path'] ) ] = $part['hash'];
    }
    return $hashes;
}

// The payload as a browser puts it back together: every part the index names, set at its path.
function rm_rw_assemble( $race_id, $index ) {
    $data = array();
    foreach ( $index['parts'] as $part ) {
        $wrapper = json_decode( file_get_contents( $GLOBALS['rm_data_dir'] . rm_race_part_filename( $race_id, $part['path'] ) ), true );
        $ref     = &$data;
        foreach ( $part['path'] as $key ) {
            if ( ! isset( $ref[ $key ] ) ) {
                $ref[ $key ] = array();
            }
            $ref = &$ref[ $key ];
        }
        $ref = $wrapper['data'];
        unset( $ref );
    }
    return $data;
}

function rm_rw_without_index( $data ) {
    unset( $data['rm_index'] );
    return $data;
}

/* --------------------------------------------------------------------------
 * What is written
 * ----------------------------------------------------------------------- */

rm_test_section( 'An upload is written' );

rm_rw_reset();
$GLOBALS['rm_meta'][42]['_race_notification_log'] = array( array( 'msg_title' => 'Lunch' ) );
$event  = rm_rw_event( 2, array( 1 => 5, 2 => 4 ) );
$result = rm_write_files( 42, $event );
rm_test_check( 'no WP_Error', ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
rm_test_check( 'the timestamp says when', array( 'time' => '2026-09-12 10:00:00' ) === rm_rw_read( 42, 'timestamp' ) );
$data = rm_rw_read( 42, 'data' );
rm_test_check( 'the data is the upload', is_array( $data ) && 'Autumn Cup' === $data['race_name'] && 2 === count( $data['heat_data']['heats'] ) );
rm_test_check( 'with the race log added', is_array( $data ) && array( array( 'msg_title' => 'Lunch' ) ) === $data['notifications'] );

/* --------------------------------------------------------------------------
 * The parts
 * ----------------------------------------------------------------------- */

rm_test_section( 'The payload is stored in parts, per heat and per class' );

$index = rm_rw_read( 42, 'index' );
rm_test_check( 'an index is written', is_array( $index ) && RM_RACE_INDEX_FORMAT === $index['format'] );
rm_test_check( 'dated like the timestamp', is_array( $index ) && '2026-09-12 10:00:00' === $index['time'] );
$expected = array(
    'race_name', 'current_heat', 'pilot_data', 'heat_data',
    'result_data.heats.1', 'result_data.heats.2', 'result_data.heats_by_class',
    'result_data.classes.1', 'result_data.event_leaderboard', 'notifications',
);
rm_test_check( 'a part per top-level key, per heat and per class, in order', $expected === rm_rw_paths( $index ), implode( ', ', rm_rw_paths( $index ) ) );

$files_ok = true;
foreach ( $index['parts'] ?? array() as $part ) {
    $file    = $GLOBALS['rm_data_dir'] . rm_race_part_filename( 42, $part['path'] );
    $wrapper = is_file( $file ) ? json_decode( file_get_contents( $file ), true ) : null;
    if ( ! is_array( $wrapper ) || $wrapper['hash'] !== $part['hash'] || strlen( json_encode( $wrapper['data'] ) ) !== $part['bytes'] ) {
        $files_ok = false;
    }
}
rm_test_check( 'each part has its file, with the hash the index names', $files_ok );
rm_test_check( 'named after its path', is_file( rm_rw_file( 42, 'part-result_data-heats-2' ) ) && is_file( rm_rw_file( 42, 'part-pilot_data' ) ) );
rm_test_check( 'put back together, the parts are the payload',
    rm_rw_without_index( $data ) === rm_rw_assemble( 42, $index ) );
rm_test_check( 'the whole file carries the index too', is_array( $data ) && $index === $data['rm_index'] );
rm_test_check( 'last, after the payload', 'rm_index' === array_key_last( $data ) );

rm_test_section( 'An update changes the hash of what changed, and of nothing else' );

$before = rm_rw_hashes( $index );
$GLOBALS['rm_now'] = '2026-09-12 10:05:00';
rm_write_files( 42, rm_rw_event( 2, array( 1 => 5, 2 => 6 ) ) );
$after   = rm_rw_hashes( rm_rw_read( 42, 'index' ) );
$changed = array_keys( array_diff_assoc( $after, $before ) );
rm_test_check( 'heat 2 flown again: its part and its class change', array( 'result_data.heats.2', 'result_data.classes.1' ) === $changed, implode( ', ', $changed ) );
rm_test_check( 'the same upload twice changes no hash', $after === rm_rw_hashes( ( function () {
    $GLOBALS['rm_now'] = '2026-09-12 10:06:00';
    rm_write_files( 42, rm_rw_event( 2, array( 1 => 5, 2 => 6 ) ) );
    return rm_rw_read( 42, 'index' );
} )() ) );
rm_test_check( 'but the index is dated anew', '2026-09-12 10:06:00' === rm_rw_read( 42, 'index' )['time'] );

$GLOBALS['rm_now'] = '2026-09-12 10:10:00';
rm_write_files( 42, rm_rw_event( 1, array( 1 => 5 ) ) );
rm_test_check( 'a heat deleted on the timer: its part file is removed', ! is_file( rm_rw_file( 42, 'part-result_data-heats-2' ) ) );
rm_test_check( 'and the index no longer names it', ! in_array( 'result_data.heats.2', rm_rw_paths( rm_rw_read( 42, 'index' ) ), true ) );

rm_write_files( 4, rm_rw_event( 1, array( 1 => 3 ) ) );
rm_write_files( 42, rm_rw_event( 1 ) );
rm_test_check( 'writing race 42 leaves race 4\'s parts alone', is_file( rm_rw_file( 4, 'part-result_data-heats-1' ) ) );

rm_test_section( 'What is not split' );

rm_rw_reset();
$event = rm_rw_event( 2 );
$event['result_data']['heats']   = array( 0 => array( 'heat_id' => 0 ), 1 => array( 'heat_id' => 1 ) );
$event['result_data']['classes'] = array();
rm_write_files( 42, $event );
$index = rm_rw_read( 42, 'index' );
rm_test_check( 'heats keyed 0, 1, ...: a list, one part', in_array( 'result_data.heats', rm_rw_paths( $index ), true ) );
rm_test_check( 'an empty object: one part', in_array( 'result_data.classes', rm_rw_paths( $index ), true ) );
rm_test_check( 'and both come back as they went in', rm_rw_without_index( rm_rw_read( 42, 'data' ) ) === rm_rw_assemble( 42, $index ) );

$event = rm_rw_event( 2, array( 1 => 5 ) );
$event['result_data']['heats']['heat-2'] = array( 'heat_id' => 2 );
rm_write_files( 42, $event );
$index = rm_rw_read( 42, 'index' );
rm_test_check( 'a key that cannot name a file keeps its object whole', in_array( 'result_data.heats', rm_rw_paths( $index ), true ) );
rm_test_check( 'and the parts of it written before are removed', ! is_file( rm_rw_file( 42, 'part-result_data-heats-1' ) ) );
rm_test_check( 'the payload still comes back whole', rm_rw_without_index( rm_rw_read( 42, 'data' ) ) === rm_rw_assemble( 42, $index ) );

rm_write_files( 42, array( 'race name' => 'Autumn Cup', 'heat_data' => array() ) );
rm_test_check( 'a top level that cannot be split: no index', null === rm_rw_read( 42, 'index' ) );
rm_test_check( 'no parts either, the earlier ones removed', array( '42-data.json', '42-timestamp.json' ) === rm_rw_listing( 42 ), implode( ', ', rm_rw_listing( 42 ) ) );
rm_test_check( 'and no index in the whole file', ! array_key_exists( 'rm_index', rm_rw_read( 42, 'data' ) ) );

rm_test_section( 'A notification writes the stored file again' );

// handle_notification_request() reads the whole file back, index included, and hands it on.
rm_rw_reset();
rm_write_files( 42, rm_rw_event( 2, array( 1 => 5 ) ) );
$before = rm_rw_read( 42, 'index' );
$GLOBALS['rm_meta'][42]['_race_notification_log'] = array( array( 'msg_title' => 'Break' ) );
$GLOBALS['rm_now'] = '2026-09-12 10:15:00';
rm_write_files( 42, rm_rw_read( 42, 'data' ) );
$after = rm_rw_read( 42, 'index' );
rm_test_check( 'the index does not end up inside itself', ! in_array( 'rm_index', rm_rw_paths( $after ), true ) && ! isset( rm_rw_read( 42, 'data' )['rm_index']['rm_index'] ) );
rm_test_check( 'only the race log\'s hash changes', array( 'notifications' ) === array_keys( array_diff_assoc( rm_rw_hashes( $after ), rm_rw_hashes( $before ) ) ) );

rm_test_section( 'A race deleted for good takes its index and parts with it' );

// The whole file and the timestamp are attachments of a race an upload created, and go with them;
// the index and the parts are not, and would be left behind.
rm_rw_reset();
rm_test_post( 42, 'race', 'autumn-cup', 'publish', 0, 'Autumn Cup' );
rm_test_post( 43, 'post', 'news', 'publish', 0, 'News' );
rm_write_files( 42, rm_rw_event( 2, array( 1 => 5 ) ) );
rm_write_files( 43, rm_rw_event( 2, array( 1 => 5 ) ) );
rm_test_check( 'hooked to before_delete_post as the file is loaded',
    in_array( 'rm_delete_race_parts_of_post', $GLOBALS['rm_actions']['before_delete_post'] ?? array(), true ) );
rm_delete_race_parts_of_post( 42 );
rm_test_check( 'the index and every part are gone, the whole file and the timestamp left to the attachments',
    array( '42-data.json', '42-timestamp.json' ) === rm_rw_listing( 42 ), implode( ', ', rm_rw_listing( 42 ) ) );
rm_delete_race_parts_of_post( 43 );
rm_test_check( 'a post that is no race: nothing removed', in_array( '43-index.json', rm_rw_listing( 43 ), true ) );

/* --------------------------------------------------------------------------
 * An archived race (1.8.1)
 * ----------------------------------------------------------------------- */

// Decided on 2026-09-12: the parts and the race log belong to a race while it is live. An
// archived race keeps its results, whole; "order lunch now", with the link to the order, has no
// place on a finished race's pages or in its public JSON.

rm_test_section( 'A race that is not live keeps its results whole, and no race log' );

rm_rw_reset();
$GLOBALS['rm_meta'][42]['_race_live']              = '0';
$GLOBALS['rm_meta'][42]['_race_notification_log'] = array( array( 'msg_title' => 'Lunch' ) );
rm_write_files( 42, rm_rw_event( 2, array( 1 => 5 ) ) );
rm_test_check( 'the whole file and the timestamp, nothing else',
    array( '42-data.json', '42-timestamp.json' ) === rm_rw_listing( 42 ), implode( ', ', rm_rw_listing( 42 ) ) );
$data = rm_rw_read( 42, 'data' );
rm_test_check( 'no race log in it, though the database holds one', array() === ( $data['notifications'] ?? null ) );
rm_test_check( 'and no index', is_array( $data ) && ! array_key_exists( 'rm_index', $data ) );

rm_test_section( 'Set to archive: the parts, the index and the race log go' );

rm_test_check( 'hooked to added_post_meta and updated_post_meta',
    in_array( 'rm_on_race_live_changed', $GLOBALS['rm_actions']['added_post_meta'] ?? array(), true )
    && in_array( 'rm_on_race_live_changed', $GLOBALS['rm_actions']['updated_post_meta'] ?? array(), true ) );
// deleted_post_meta fires for every meta of a race being deleted, after its attachments took the
// whole file and the timestamp: archiving then would write them again.
rm_test_check( 'not to deleted_post_meta', ! in_array( 'rm_on_race_live_changed', $GLOBALS['rm_actions']['deleted_post_meta'] ?? array(), true ) );

rm_rw_reset();
rm_test_post( 42, 'race', 'autumn-cup', 'publish', 0, 'Autumn Cup' );
$GLOBALS['rm_meta'][42]['_race_notification_log'] = array( array( 'msg_title' => 'Lunch', 'msg_url' => 'https://example.test/order' ) );
$event = rm_rw_event( 2, array( 1 => 5 ) );
rm_write_files( 42, $event );
rm_test_check( 'live: parts, an index and the race log',
    is_file( rm_rw_file( 42, 'index' ) ) && 1 === count( rm_rw_read( 42, 'data' )['notifications'] ?? array() ) );

// WordPress fires the hook after it stored the value.
$GLOBALS['rm_meta'][42]['_race_live'] = '0';
$GLOBALS['rm_now'] = '2026-09-12 18:00:00';
rm_on_race_live_changed( 1, 42, '_race_live', '0' );
rm_test_check( 'archived: the whole file and the timestamp are all that is left',
    array( '42-data.json', '42-timestamp.json' ) === rm_rw_listing( 42 ), implode( ', ', rm_rw_listing( 42 ) ) );
$data = rm_rw_read( 42, 'data' );
rm_test_check( 'the results as they were',
    is_array( $data ) && $event['result_data'] === $data['result_data'] && $event['heat_data'] === $data['heat_data'] );
rm_test_check( 'the race log empty, and no index', array() === ( $data['notifications'] ?? null ) && ! array_key_exists( 'rm_index', $data ) );
rm_test_check( 'the timestamp moved, since the data changed', array( 'time' => '2026-09-12 18:00:00' ) === rm_rw_read( 42, 'timestamp' ) );
rm_test_check( 'and the race log is gone from the database', ! isset( $GLOBALS['rm_meta'][42]['_race_notification_log'] ) );

// Quick Edit passes 0 where the database holds '0', and WordPress compares strictly: every save of
// an archived race there fires the hook again.
$GLOBALS['rm_now'] = '2026-09-12 18:30:00';
rm_on_race_live_changed( 1, 42, '_race_live', 0 );
rm_test_check( 'stored again: nothing to do, nothing done', array( 'time' => '2026-09-12 18:00:00' ) === rm_rw_read( 42, 'timestamp' ) );

rm_on_race_live_changed( 1, 42, '_race_reg_closed', '0' );
$GLOBALS['rm_meta'][42]['_race_live'] = '1';
rm_on_race_live_changed( 1, 42, '_race_live', '1' );
rm_test_check( 'another key, or live again: nothing happens',
    array( '42-data.json', '42-timestamp.json' ) === rm_rw_listing( 42 ) && array( 'time' => '2026-09-12 18:00:00' ) === rm_rw_read( 42, 'timestamp' ) );
rm_write_files( 42, rm_rw_event( 2, array( 1 => 5, 2 => 4 ) ) );
rm_test_check( 'until the next upload brings the parts back, with a race log begun anew',
    is_file( rm_rw_file( 42, 'index' ) ) && array() === ( rm_rw_read( 42, 'data' )['notifications'] ?? null ) );

rm_test_post( 43, 'post', 'news', 'publish', 0, 'News' );
rm_write_files( 43, rm_rw_event( 2, array( 1 => 5 ) ) );
$GLOBALS['rm_meta'][43]['_race_live'] = '0';
rm_on_race_live_changed( 1, 43, '_race_live', '0' );
rm_test_check( 'a post that is no race is left alone', is_file( rm_rw_file( 43, 'index' ) ) );

rm_test_section( 'Archiving that cannot write keeps the race log' );

rm_rw_reset();
$GLOBALS['rm_meta'][42]['_race_notification_log'] = array( array( 'msg_title' => 'Lunch' ) );
rm_write_files( 42, rm_rw_event( 2, array( 1 => 5 ) ) );
$GLOBALS['rm_meta'][42]['_race_live'] = '0';
unlink( rm_rw_file( 42, 'timestamp' ) );
mkdir( rm_rw_file( 42, 'timestamp' ) );
$result = rm_archive_race( 42 );
rm_test_check( 'a file that cannot be written: WP_Error', is_wp_error( $result ) );
rm_test_check( 'and the race log is still in the database, to go the next time',
    array( array( 'msg_title' => 'Lunch' ) ) === ( $GLOBALS['rm_meta'][42]['_race_notification_log'] ?? null ) );
rmdir( rm_rw_file( 42, 'timestamp' ) );
rm_test_check( 'the next time it goes', true === rm_archive_race( 42 ) && ! isset( $GLOBALS['rm_meta'][42]['_race_notification_log'] )
    && ! is_file( rm_rw_file( 42, 'index' ) ) );

rm_rw_reset();
rm_write_files( 42, rm_rw_event( 2, array( 1 => 5 ) ) );
$result = rm_archive_race( 42 );
rm_test_check( 'a live race is not archived', is_wp_error( $result ) && 'race_live' === $result->get_error_code() && is_file( rm_rw_file( 42, 'index' ) ) );

rm_test_section( 'The races archived before 1.8.1, once' );

// Race 42 archived under 1.8.0, with its parts and its log; 43 live; 44 archived with nothing to
// clear. Archived without the hook, as an older version did.
rm_rw_reset();
$GLOBALS['rm_posts']   = array();
$GLOBALS['rm_options'] = array();
rm_test_post( 42, 'race', 'autumn-cup', 'publish', 0, 'Autumn Cup' );
rm_test_post( 43, 'race', 'winter-cup', 'publish', 0, 'Winter Cup' );
rm_test_post( 44, 'race', 'spring-cup', 'draft', 0, 'Spring Cup' );
foreach ( array( 42, 43 ) as $race_id ) {
    $GLOBALS['rm_meta'][ $race_id ]['_race_notification_log'] = array( array( 'msg_title' => 'Lunch' ) );
    rm_write_files( $race_id, rm_rw_event( 2, array( 1 => 5 ) ) );
}
$GLOBALS['rm_meta'][42]['_race_live'] = '0';
$GLOBALS['rm_meta'][44]['_race_live'] = '0';

$GLOBALS['rm_doing_ajax'] = true;
rm_maybe_clear_archived_races();
rm_test_check( 'not in an AJAX request, which the live pages make', is_file( rm_rw_file( 42, 'index' ) ) && false === get_option( 'rm_archived_races_cleared' ) );
$GLOBALS['rm_doing_ajax'] = false;

rm_maybe_clear_archived_races();
rm_test_check( 'an archived race: whole file and timestamp only, no race log',
    array( '42-data.json', '42-timestamp.json' ) === rm_rw_listing( 42 ) && array() === ( rm_rw_read( 42, 'data' )['notifications'] ?? null )
    && ! isset( $GLOBALS['rm_meta'][42]['_race_notification_log'] ), implode( ', ', rm_rw_listing( 42 ) ) );
rm_test_check( 'a live race untouched', is_file( rm_rw_file( 43, 'index' ) ) && isset( $GLOBALS['rm_meta'][43]['_race_notification_log'] ) );
rm_test_check( 'recorded as done', false !== get_option( 'rm_archived_races_cleared' ) );
$GLOBALS['rm_meta'][42]['_race_notification_log'] = array( array( 'msg_title' => 'Break' ) );
rm_maybe_clear_archived_races();
rm_test_check( 'and not run again', isset( $GLOBALS['rm_meta'][42]['_race_notification_log'] ) );

/* --------------------------------------------------------------------------
 * The order
 * ----------------------------------------------------------------------- */

rm_test_section( 'The timestamp is written last, the index after the parts' );

// A file that cannot be written: a directory of its name, which neither writing the file nor
// renaming another onto it gets past.
rm_rw_reset();
rm_write_files( 42, rm_rw_event() );
unlink( rm_rw_file( 42, 'data' ) );
mkdir( rm_rw_file( 42, 'data' ) );
$GLOBALS['rm_now'] = '2026-09-12 10:05:00';
$result = rm_write_files( 42, rm_rw_event( 3 ) );
rm_test_check( 'a data file that cannot be written: WP_Error', is_wp_error( $result ) );
rm_test_check( 'with status 500', is_wp_error( $result ) && 500 === ( $result->get_error_data()['status'] ?? null ) );
rm_test_check( 'and the timestamp still announces what is there',
    array( 'time' => '2026-09-12 10:00:00' ) === rm_rw_read( 42, 'timestamp' ),
    'timestamp: ' . json_encode( rm_rw_read( 42, 'timestamp' ) ) );
rm_test_check( 'the log names the file and why',
    is_file( $GLOBALS['rm_log'] ) && str_contains( file_get_contents( $GLOBALS['rm_log'] ), '42-data.json' )
    && str_contains( file_get_contents( $GLOBALS['rm_log'] ), 'Is a directory' ),
    is_file( $GLOBALS['rm_log'] ) ? file_get_contents( $GLOBALS['rm_log'] ) : 'nothing logged' );
rmdir( rm_rw_file( 42, 'data' ) );

rm_rw_reset();
rm_write_files( 42, rm_rw_event() );
unlink( rm_rw_file( 42, 'index' ) );
mkdir( rm_rw_file( 42, 'index' ) );
$GLOBALS['rm_now'] = '2026-09-12 10:05:00';
rm_test_check( 'an index that cannot be written: WP_Error', is_wp_error( rm_write_files( 42, rm_rw_event( 3 ) ) ) );
rm_test_check( 'and the timestamp left as it was', array( 'time' => '2026-09-12 10:00:00' ) === rm_rw_read( 42, 'timestamp' ) );
rmdir( rm_rw_file( 42, 'index' ) );

rm_rw_reset();
rm_write_files( 42, rm_rw_event( 2, array( 1 => 5 ) ) );
unlink( rm_rw_file( 42, 'part-heat_data' ) );
mkdir( rm_rw_file( 42, 'part-heat_data' ) );
$GLOBALS['rm_now'] = '2026-09-12 10:05:00';
rm_test_check( 'a part that cannot be written: WP_Error', is_wp_error( rm_write_files( 42, rm_rw_event( 3 ) ) ) );
rm_test_check( 'the index left as it was', '2026-09-12 10:00:00' === rm_rw_read( 42, 'index' )['time'] );
rm_test_check( 'and the timestamp too', array( 'time' => '2026-09-12 10:00:00' ) === rm_rw_read( 42, 'timestamp' ) );
rmdir( rm_rw_file( 42, 'part-heat_data' ) );

/* --------------------------------------------------------------------------
 * Whole files
 * ----------------------------------------------------------------------- */

rm_test_section( 'Every file is replaced whole, never rewritten in place' );

// A file rewritten in place keeps its inode, and a reader in the middle of it gets part of the old
// and part of the new, or a file cut short. One written beside it and renamed over it is a new
// inode: a reader has either the old file, whole, or the new one.
rm_rw_reset();
rm_write_files( 42, rm_rw_event() );
clearstatcache();
$inode_data      = fileinode( rm_rw_file( 42, 'data' ) );
$inode_timestamp = fileinode( rm_rw_file( 42, 'timestamp' ) );
$GLOBALS['rm_now'] = '2026-09-12 10:05:00';
rm_write_files( 42, rm_rw_event( 3 ) );
clearstatcache();
rm_test_check( 'the data file is a new file', fileinode( rm_rw_file( 42, 'data' ) ) !== $inode_data );
rm_test_check( 'the timestamp file is a new file', fileinode( rm_rw_file( 42, 'timestamp' ) ) !== $inode_timestamp );
rm_test_check( 'and holds the new upload', 3 === count( rm_rw_read( 42, 'data' )['heat_data']['heats'] ?? array() ) );
$expected = array(
    '42-data.json', '42-index.json',
    '42-part-current_heat.json', '42-part-heat_data.json', '42-part-notifications.json', '42-part-pilot_data.json',
    '42-part-race_name.json', '42-part-result_data-classes-1.json', '42-part-result_data-event_leaderboard.json',
    '42-part-result_data-heats.json', '42-part-result_data-heats_by_class.json', '42-timestamp.json',
);
rm_test_check( 'nothing else is left in the directory', $expected === rm_rw_listing(), implode( ', ', rm_rw_listing() ) );

rm_rw_reset();
rm_write_files( 42, rm_rw_event() );
unlink( rm_rw_file( 42, 'data' ) );
mkdir( rm_rw_file( 42, 'data' ) );
rm_write_files( 42, rm_rw_event( 3 ) );
rm_test_check( 'a failed write leaves nothing half-written behind',
    ! array_filter( rm_rw_listing(), fn( $name ) => str_ends_with( $name, '.tmp' ) ), implode( ', ', rm_rw_listing() ) );
rmdir( rm_rw_file( 42, 'data' ) );

/* --------------------------------------------------------------------------
 * The real payloads
 * ----------------------------------------------------------------------- */

rm_test_section( 'The races from production, where the local site has them' );

// Three real events are on the local site (docs/development-setup.md). Their shapes are what the
// split has to survive -- heats_by_class a list in one and an object in another, a top-level key
// the connector no longer sends -- and they are what the saving was measured on.
$core  = rm_test_wp_core_dir();
$found = $core ? glob( $core . '/wp-content/uploads/races/*-data.json' ) : array();
if ( ! $found ) {
    echo "    (none found; the checks on real payloads did not run)\n";
}
foreach ( $found as $n => $file ) {
    rm_rw_reset();
    $real = json_decode( file_get_contents( $file ), true );
    unset( $real['rm_index'], $real['notifications'] );
    $race_id = 900 + $n;
    rm_write_files( $race_id, $real );
    $index  = rm_rw_read( $race_id, 'index' );
    $heats  = count( $real['result_data']['heats'] ?? array() );
    $name   = basename( $file );
    rm_test_check( $name . ': a part per result heat', $heats > 0 && $heats === count( array_filter( rm_rw_paths( $index ), fn( $p ) => str_starts_with( $p, 'result_data.heats.' ) ) ) );
    rm_test_check( $name . ': put back together, the payload', rm_rw_without_index( rm_rw_read( $race_id, 'data' ) ) === rm_rw_assemble( $race_id, $index ) );
}

// Clean up.
rm_rw_reset();
@rmdir( $GLOBALS['rm_data_dir'] );
@rmdir( $GLOBALS['rm_upload_base'] );
@unlink( $GLOBALS['rm_log'] );

rm_test_finish();

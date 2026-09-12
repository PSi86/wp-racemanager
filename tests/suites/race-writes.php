<?php
/**
 * What an upload leaves in uploads/races/: rm_write_files().
 *
 * Browsers poll a race's timestamp file and download its data when the timestamp changed, so the
 * timestamp is a promise that the data it announces is there. rm_write_files() wrote it first: a
 * browser polling between the two writes took the new timestamp with the old data, and so did every
 * browser after a data write that failed -- each then held the old standing under the new timestamp
 * until the next upload. The same mistake the loader made before 2026, made on the server
 * (docs/data-flow.md, "Two orderings that are load-bearing").
 *
 * And a file was rewritten in place, so a browser reading it during the write got half of it.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_data_dir'] = sys_get_temp_dir() . '/rm-race-writes-' . getmypid() . '/';
$GLOBALS['rm_meta']     = array();
$GLOBALS['rm_now']      = '2026-09-12 10:00:00';

// A write that fails is logged; read here instead of printed among the checks.
$GLOBALS['rm_log'] = sys_get_temp_dir() . '/rm-race-writes-' . getmypid() . '.log';
ini_set( 'error_log', $GLOBALS['rm_log'] );

function rm_get_race_data_dir( $create = true ) {
    if ( $create && ! is_dir( $GLOBALS['rm_data_dir'] ) ) {
        mkdir( $GLOBALS['rm_data_dir'], 0777, true );
    }
    return $GLOBALS['rm_data_dir'];
}
function current_time( $type ) {
    return $GLOBALS['rm_now'];
}
function get_post_meta( $id, $key = '', $single = false ) {
    return $GLOBALS['rm_meta'][ $id ][ $key ] ?? '';
}

require_once RM_TEST_DIR . '/stubs/wordpress.php';
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
function rm_rw_listing() {
    $names = array();
    foreach ( scandir( $GLOBALS['rm_data_dir'] ) as $name ) {
        if ( '.' !== $name && '..' !== $name && ! is_dir( $GLOBALS['rm_data_dir'] . $name ) ) {
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

function rm_rw_event( $heat_count = 2 ) {
    $heats = array();
    for ( $id = 1; $id <= $heat_count; $id++ ) {
        $heats[] = array( 'id' => $id, 'displayname' => 'Heat ' . $id );
    }
    return array(
        'race_name'   => 'Autumn Cup',
        'heat_data'   => array( 'heats' => $heats ),
        'result_data' => array( 'heats' => array() ),
    );
}

/* --------------------------------------------------------------------------
 * What is written
 * ----------------------------------------------------------------------- */

rm_test_section( 'An upload is written' );

rm_rw_reset();
$GLOBALS['rm_meta'][42]['_race_notification_log'] = array( array( 'msg_title' => 'Lunch' ) );
$result = rm_write_files( 42, rm_rw_event() );
rm_test_check( 'no WP_Error', ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );
rm_test_check( 'the timestamp says when', array( 'time' => '2026-09-12 10:00:00' ) === rm_rw_read( 42, 'timestamp' ) );
$data = rm_rw_read( 42, 'data' );
rm_test_check( 'the data is the upload', is_array( $data ) && 'Autumn Cup' === $data['race_name'] && 2 === count( $data['heat_data']['heats'] ) );
rm_test_check( 'with the race log added', is_array( $data ) && array( array( 'msg_title' => 'Lunch' ) ) === $data['notifications'] );

/* --------------------------------------------------------------------------
 * The timestamp last
 * ----------------------------------------------------------------------- */

rm_test_section( 'The timestamp is written last' );

// A data file that cannot be written: a directory of its name, which neither writing the file nor
// renaming another onto it gets past.
rm_rw_reset();
rm_write_files( 42, rm_rw_event() );
unlink( rm_rw_file( 42, 'data' ) );
mkdir( rm_rw_file( 42, 'data' ) );
$GLOBALS['rm_now'] = '2026-09-12 10:05:00';
$result = @rm_write_files( 42, rm_rw_event( 3 ) );
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
rm_test_check( 'nothing else is left in the directory',
    array( '42-data.json', '42-timestamp.json' ) === rm_rw_listing(), implode( ', ', rm_rw_listing() ) );

rm_rw_reset();
rm_write_files( 42, rm_rw_event() );
unlink( rm_rw_file( 42, 'data' ) );
mkdir( rm_rw_file( 42, 'data' ) );
@rm_write_files( 42, rm_rw_event( 3 ) );
rm_test_check( 'a failed write leaves nothing half-written behind',
    array( '42-timestamp.json' ) === rm_rw_listing(), implode( ', ', rm_rw_listing() ) );
rmdir( rm_rw_file( 42, 'data' ) );

// Clean up.
rm_rw_reset();
@rmdir( $GLOBALS['rm_data_dir'] );
@unlink( $GLOBALS['rm_log'] );

rm_test_finish();

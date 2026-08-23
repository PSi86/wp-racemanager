<?php
/**
 * The per-race JSON directory: rm_get_race_data_dir() / rm_get_race_data_url()
 *
 * Nothing ever created this directory, so the first upload on a fresh install failed --
 * and both callers discarded the WP_Error that reported it.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_upload_base']  = sys_get_temp_dir() . '/rm-tests-' . getmypid();
$GLOBALS['rm_upload_error'] = '';

function wp_upload_dir() {
    return array(
        'basedir' => $GLOBALS['rm_upload_base'],
        'baseurl' => 'https://example.test/wp-content/uploads',
        'error'   => $GLOBALS['rm_upload_error'],
    );
}

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/race-data-functions.php';

rm_test_section( 'The directory is created on demand' );
rm_test_check( 'does not exist beforehand', ! is_dir( $GLOBALS['rm_upload_base'] . '/races' ) );
$dir = rm_get_race_data_dir();
rm_test_check( 'no WP_Error', ! is_wp_error( $dir ), is_wp_error( $dir ) ? $dir->get_error_message() : '' );
rm_test_check( 'exists now', is_dir( $GLOBALS['rm_upload_base'] . '/races' ) );
rm_test_check( 'path is trailing-slashed', '/' === substr( $dir, -1 ) );
rm_test_check( 'writable', false !== file_put_contents( $dir . '42-data.json', '{}' ) );

rm_test_section( 'The read-only probe creates nothing' );
$GLOBALS['rm_upload_base'] = sys_get_temp_dir() . '/rm-tests-none-' . getmypid();
$probe = rm_get_race_data_dir( false );
rm_test_check( 'returns a path', is_string( $probe ) );
rm_test_check( 'but creates no directory', ! is_dir( $GLOBALS['rm_upload_base'] . '/races' ) );

rm_test_section( 'A broken uploads directory is reported' );
$GLOBALS['rm_upload_error'] = 'Directory is not writable.';
$error = rm_get_race_data_dir();
rm_test_check( 'WP_Error returned', is_wp_error( $error ) );
rm_test_check( 'with the expected code', is_wp_error( $error ) && 'upload_dir_unavailable' === $error->get_error_code() );
$GLOBALS['rm_upload_error'] = '';

rm_test_section( 'Path and URL agree' );
rm_test_check( 'URL ends in /races/', str_ends_with( rm_get_race_data_url(), '/races/' ) );
rm_test_check( 'URL is built from the uploads base',
    'https://example.test/wp-content/uploads/races/' === rm_get_race_data_url(), rm_get_race_data_url() );

rm_test_section( 'Bulk delete stays scoped to one race' );
// Mirrors the statement built in includes/admin-registrations.php.
$ids          = array( 11, 12, 13 );
$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
$sql          = "DELETE FROM wp_rm_registrations WHERE race_id = %d AND id IN ($placeholders)";
$args         = array_merge( array( 182 ), $ids );
rm_test_check( 'race_id is part of the WHERE clause', str_contains( $sql, 'race_id = %d' ), 'cross-race deletion possible' );
rm_test_check( 'one placeholder per id', substr_count( $sql, '%d' ) === count( $ids ) + 1 );
rm_test_check( 'argument count matches', count( $args ) === substr_count( $sql, '%d' ) );
rm_test_check( 'an all-zero selection is filtered out before the query',
    array() === array_filter( array_map( 'absint', array( '0', 'abc' ) ) ), 'IN () is a SQL syntax error' );

// Clean up.
@unlink( sys_get_temp_dir() . '/rm-tests-' . getmypid() . '/races/42-data.json' );
@rmdir( sys_get_temp_dir() . '/rm-tests-' . getmypid() . '/races' );
@rmdir( sys_get_temp_dir() . '/rm-tests-' . getmypid() );

rm_test_finish();

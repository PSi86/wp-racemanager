<?php
/**
 * One canonical format for the event dates: includes/race-dates.php
 *
 * The interesting case is the Unix integer. WordPress runs PHP in UTC, so the integers that
 * strtotime('today 8:00') produced reverse exactly with gmdate() -- giving back the wall clock
 * the caller meant. Reading them through the site timezone instead would shift an intended
 * 8am start to 10am on a UTC+2 site, so that choice is asserted here rather than assumed.
 */

require_once __DIR__ . '/../bootstrap.php';

// WordPress does this in wp-settings.php; the suite must match, or the assertions are meaningless.
date_default_timezone_set( 'UTC' );

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/race-dates.php';

rm_test_section( 'Unix integers, as written by the REST upload' );
$eight_am = strtotime( '2026-08-22 08:00:00' );
rm_test_check( 'integer becomes the intended wall clock',
    '2026-08-22 08:00:00' === rm_normalize_event_datetime( $eight_am ),
    rm_normalize_event_datetime( $eight_am ) );
rm_test_check( 'numeric string too',
    '2026-08-22 08:00:00' === rm_normalize_event_datetime( (string) $eight_am ) );
rm_test_check( 'round trip is lossless',
    $eight_am === strtotime( rm_normalize_event_datetime( $eight_am ) ) );
rm_test_check( 'zero is not a date', '' === rm_normalize_event_datetime( 0 ) );
rm_test_check( 'negative is not a date', '' === rm_normalize_event_datetime( -5 ) );

rm_test_section( 'datetime-local strings, as written by the meta box' );
rm_test_check( 'T separator and missing seconds',
    '2026-08-22 10:00:00' === rm_normalize_event_datetime( '2026-08-22T10:00' ),
    rm_normalize_event_datetime( '2026-08-22T10:00' ) );
rm_test_check( 'with seconds', '2026-08-22 10:00:30' === rm_normalize_event_datetime( '2026-08-22T10:00:30' ) );
rm_test_check( 'surrounding whitespace', '2026-08-22 10:00:00' === rm_normalize_event_datetime( "  2026-08-22T10:00\n" ) );

rm_test_section( 'Already canonical values are left alone' );
rm_test_check( 'unchanged', '2026-08-22 10:00:00' === rm_normalize_event_datetime( '2026-08-22 10:00:00' ) );
rm_test_check( 'idempotent',
    rm_normalize_event_datetime( rm_normalize_event_datetime( '2026-08-22T10:00' ) )
        === rm_normalize_event_datetime( '2026-08-22T10:00' ) );

rm_test_section( 'Date without a time' );
rm_test_check( 'midnight assumed', '2026-08-22 00:00:00' === rm_normalize_event_datetime( '2026-08-22' ) );

rm_test_section( 'Values that must be refused rather than guessed at' );
foreach ( array( '', null, false, array(), 'tomorrow', '22.08.2026', '2026-13-01T10:00', '2026-02-30T10:00', '2026-08-22T25:00', '2026-08-22T10:61' ) as $bad ) {
    rm_test_check( 'refused: ' . var_export( $bad, true ), '' === rm_normalize_event_datetime( $bad ) );
}

rm_test_section( 'Formatting back for the datetime-local input' );
// A space instead of the T makes browsers render an empty field, so this conversion matters.
rm_test_check( 'canonical to input', '2026-08-22T10:00' === rm_event_datetime_for_input( '2026-08-22 10:00:00' ) );
rm_test_check( 'integer to input', '2026-08-22T08:00' === rm_event_datetime_for_input( $eight_am ) );
rm_test_check( 'already in input form', '2026-08-22T10:00' === rm_event_datetime_for_input( '2026-08-22T10:00' ) );
rm_test_check( 'nothing to show', '' === rm_event_datetime_for_input( '' ) );
rm_test_check( 'unreadable shows nothing', '' === rm_event_datetime_for_input( 'tomorrow' ) );

rm_test_section( 'Classification drives the dry-run report' );
$cases = array(
    '2026-08-22 10:00:00' => 'canonical',
    '1755849600'          => 'timestamp',
    '2026-08-22T10:00'    => 'iso-t',
    '2026-08-22'          => 'date-only',
    ''                    => 'empty',
    'tomorrow'            => 'unreadable',
);
foreach ( $cases as $value => $expected ) {
    rm_test_check( "'" . $value . "' is $expected", $expected === rm_classify_event_datetime( $value ),
        rm_classify_event_datetime( $value ) );
}

rm_test_section( 'The integer variant is what breaks the queries today' );
// CAST('1755849600' AS DATETIME) is NULL in MySQL -- which is why those races vanish.
rm_test_check( 'timestamps are classified as needing migration',
    'timestamp' === rm_classify_event_datetime( (string) $eight_am ) );
rm_test_check( 'and normalising them produces something MySQL can cast',
    1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', rm_normalize_event_datetime( $eight_am ) ) );

rm_test_finish();

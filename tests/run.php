<?php
/**
 * tests/run.php
 *
 * Runs every suite in tests/suites/ and prints a summary.
 *
 *   php tests/run.php              all suites
 *   php tests/run.php live         only suites whose name contains "live"
 *   php tests/run.php -v           show each suite's full output
 *
 * Each suite runs in its own PHP process, so a suite is free to define its own version of a
 * WordPress stub without colliding with the others.
 *
 * Exit code is 0 only when every suite either passed or skipped for a documented reason.
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( 'Run this from the command line.' );
}

$argv    = $_SERVER['argv'];
$verbose = in_array( '-v', $argv, true ) || in_array( '--verbose', $argv, true );
$filter  = '';
foreach ( array_slice( $argv, 1 ) as $arg ) {
    if ( ! str_starts_with( $arg, '-' ) ) {
        $filter = $arg;
    }
}

$suites = glob( __DIR__ . '/suites/*.php' );
sort( $suites );

if ( '' !== $filter ) {
    $suites = array_values( array_filter( $suites, static function ( $file ) use ( $filter ) {
        return false !== stripos( basename( $file, '.php' ), $filter );
    } ) );
}

if ( ! $suites ) {
    echo "No suites matched.\n";
    exit( 1 );
}

echo "\nWP RaceManager test suites\n";
echo str_repeat( '=', 62 ) . "\n";

$passed  = 0;
$failed  = 0;
$skipped = 0;
$details = array();

foreach ( $suites as $suite ) {
    $name = basename( $suite, '.php' );

    $output = array();
    $status = 0;
    exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $suite ) . ' 2>&1', $output, $status );
    $text = implode( "\n", $output );

    // Suites report their own tally on the last non-empty line.
    $lines   = array_values( array_filter( array_map( 'trim', $output ), static function ( $l ) { return '' !== $l; } ) );
    $summary = $lines ? end( $lines ) : '';

    if ( 2 === $status ) {
        ++$skipped;
        $label = 'SKIP';
    } elseif ( 0 === $status ) {
        ++$passed;
        $label = 'PASS';
    } else {
        ++$failed;
        $label = 'FAIL';
        $details[ $name ] = $text;
    }

    printf( "  %-4s  %-22s  %s\n", $label, $name, $summary );

    if ( $verbose ) {
        echo $text . "\n";
    }
}

echo str_repeat( '-', 62 ) . "\n";
printf( "  %d passed, %d failed, %d skipped\n\n", $passed, $failed, $skipped );

foreach ( $details as $name => $text ) {
    echo "--- $name " . str_repeat( '-', max( 0, 54 - strlen( $name ) ) ) . "\n";
    echo $text . "\n\n";
}

exit( $failed > 0 ? 1 : 0 );

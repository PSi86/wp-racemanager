<?php
/**
 * The site's half of tests/e2e/race-parts.cjs: writes a race's files the way an upload does, so
 * that the browser test can watch what a browser downloads afterwards.
 *
 *   ddev wp eval-file tests/e2e/race-parts-site.php <op> <race id> [heat] [mark]
 *
 *   begin  keeps a copy of the race's files, then writes them anew with the plugin's own writer,
 *          which gives the race its index and parts. A copy a run that did not finish left behind
 *          is put back first. Prints the result heats and the class each belongs to.
 *   fly    marks one result heat, its class and the event leaderboard, and moves current_heat on,
 *          as the upload after that heat would change them. Prints the parts whose hash changed.
 *   all    marks every result heat: more than half of the payload changes.
 *   end    puts the files back as begin found them, byte for byte, and removes the rest.
 *
 * Only through WP-CLI: the plugin directory is served on the development site, and this file
 * must do nothing when requested.
 */

if ( ! defined( 'WP_CLI' ) ) {
    exit;
}

require_once __DIR__ . '/../../includes/race-files.php';

$op      = $args[0] ?? '';
$race_id = (int) ( $args[1] ?? 0 );
$dir     = rm_get_race_data_dir();
$backup  = sys_get_temp_dir() . '/rm-e2e-race-parts-' . $race_id . '/';

if ( $race_id <= 0 || is_wp_error( $dir ) ) {
    WP_CLI::error( 'no race ID, or no race data directory' );
}

// The race's files: {id}-data.json, -timestamp.json, -index.json, -part-*.json.
$own = function ( $in ) use ( $race_id ) {
    return array_values( array_filter(
        scandir( $in ) ?: array(),
        fn( $name ) => 0 === strpos( $name, $race_id . '-' ) && str_ends_with( $name, '.json' )
    ) );
};

$restore = function () use ( $own, $dir, $backup ) {
    foreach ( $own( $dir ) as $name ) {
        unlink( $dir . $name );
    }
    foreach ( $own( $backup ) as $name ) {
        copy( $backup . $name, $dir . $name );
        unlink( $backup . $name );
    }
    rmdir( $backup );
};

$read = function () use ( $dir, $race_id ) {
    $data = json_decode( file_get_contents( $dir . $race_id . '-data.json' ), true );
    if ( ! is_array( $data ) ) {
        WP_CLI::error( 'the race has no readable data file' );
    }
    // Both are made anew by the writer, as on every upload.
    unset( $data['rm_index'], $data['notifications'] );
    return $data;
};

$write = function ( $data ) use ( $race_id ) {
    $result = rm_write_files( $race_id, $data );
    if ( is_wp_error( $result ) ) {
        WP_CLI::error( $result->get_error_message() );
    }
};

$hashes = function () use ( $dir, $race_id ) {
    $index  = json_decode( (string) @file_get_contents( $dir . $race_id . '-index.json' ), true );
    $hashes = array();
    foreach ( $index['parts'] ?? array() as $part ) {
        $hashes[ implode( '-', $part['path'] ) ] = $part['hash'];
    }
    return $hashes;
};

// The class a result heat belongs to, out of heats_by_class: a list in one payload, an object in
// another.
$class_of = function ( $data, $heat ) {
    foreach ( $data['result_data']['heats_by_class'] ?? array() as $class_id => $heats ) {
        if ( in_array( (int) $heat, array_map( 'intval', (array) $heats ), true ) ) {
            return (string) $class_id;
        }
    }
    return null;
};

switch ( $op ) {
    case 'begin':
        if ( is_dir( $backup ) ) {
            $restore();
        }
        mkdir( $backup, 0777, true );
        foreach ( $own( $dir ) as $name ) {
            copy( $dir . $name, $backup . $name );
        }
        $data = $read();
        $write( $data );
        $heats = array();
        foreach ( array_keys( $data['result_data']['heats'] ?? array() ) as $heat ) {
            $heats[ (string) $heat ] = $class_of( $data, $heat );
        }
        echo wp_json_encode( array( 'parts' => count( $hashes() ), 'heats' => $heats ) ), "\n";
        break;

    case 'fly':
    case 'all':
        $data   = $read();
        $before = $hashes();
        $mark   = (string) ( 'fly' === $op ? ( $args[3] ?? 'x' ) : ( $args[2] ?? 'x' ) );
        $heats  = 'fly' === $op ? array( (string) ( $args[2] ?? '' ) ) : array_map( 'strval', array_keys( $data['result_data']['heats'] ) );
        foreach ( $heats as $heat ) {
            if ( ! isset( $data['result_data']['heats'][ $heat ] ) ) {
                WP_CLI::error( 'no result heat ' . $heat );
            }
            $data['result_data']['heats'][ $heat ]['rm_e2e'] = $mark;
            $class = $class_of( $data, $heat );
            if ( null !== $class && isset( $data['result_data']['classes'][ $class ] ) ) {
                $data['result_data']['classes'][ $class ]['rm_e2e'] = $mark;
            }
        }
        $data['result_data']['event_leaderboard']['rm_e2e'] = $mark;
        $data['current_heat']['current_heat'] = (int) end( $heats ) + 1;
        $write( $data );
        echo wp_json_encode( array( 'changed' => array_keys( array_diff_assoc( $hashes(), $before ) ) ) ), "\n";
        break;

    case 'end':
        if ( is_dir( $backup ) ) {
            $restore();
        }
        echo wp_json_encode( array( 'restored' => true ) ), "\n";
        break;

    default:
        WP_CLI::error( 'unknown operation: ' . $op );
}

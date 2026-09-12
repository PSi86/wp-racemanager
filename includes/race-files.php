<?php
// includes/race-files.php
// The files a race is stored in under uploads/races/, as the upload and the notifications write them:
//
//   {race}-part-{path}.json   one part of the payload each, with its hash (since 1.8.0)
//   {race}-data.json          the whole payload, the index included as rm_index
//   {race}-index.json         which parts there are, and the hash of each (since 1.8.0)
//   {race}-timestamp.json     when the data was produced; what browsers poll
//
// Why the parts, and what they cost and save, is L7 in docs/live-webapp-improvements.md.

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * The index format this plugin writes. The loader uses an index only in a format it knows, and
 * downloads the whole payload otherwise.
 */
const RM_RACE_INDEX_FORMAT = 1;

/** The key data.json carries the index under. */
const RM_RACE_INDEX_KEY = 'rm_index';

/**
 * Where the payload is split below its top level, whose every key is a part of its own: a key
 * listed here is split into its keys in turn, as deep as the nesting goes.
 *
 * result_data is 96-97 % of a real payload and changes with every upload, since every upload
 * comes after a heat was flown; the other sections together are 3-7 KB compressed. Split at the
 * top level alone, an update would cost 98 % of the whole file. Most of result_data is its heats,
 * one entry per heat, and a heat that was not flown since the last upload does not change: split
 * per heat and per class, an update costs 13-18 % (measured on the three races from production,
 * see L7 in docs/live-webapp-improvements.md).
 */
const RM_RACE_SPLIT = array(
    'result_data' => array(
        'heats'   => array(),
        'classes' => array(),
    ),
);

/**
 * Store a race's data: its parts, the whole of it, the index, and the timestamp that announces
 * them.
 *
 * Browsers poll the timestamp and download what changed when it did (docs/data-flow.md), so the
 * timestamp is a promise that what it announces is there, and it is written last. It used to be
 * written first: a browser polling between the two writes took the new timestamp with the old
 * data, and so did every browser after a data write that failed -- each held the old standing
 * under the new timestamp until the next upload. The loader made the same mistake until 2026, the
 * other way round. For the same reason the parts go before the index that names them.
 *
 * @param int   $race_id              The race.
 * @param array $json_data            The upload, decoded.
 * @param int   $create_wp_attachment Whether to register the files as attachments of the race.
 * @return true|WP_Error WP_Error with status 500 when a file cannot be written; the timestamp is
 *                       then left as it was.
 */
function rm_write_files( $race_id, $json_data, $create_wp_attachment = 0 ) {
    $timestamp = current_time('mysql');

    // Resolves through wp_upload_dir() and creates the directory if it is missing.
    $upload_path = rm_get_race_data_dir();
    if ( is_wp_error( $upload_path ) ) {
        return $upload_path;
    }

    $filename_timestamp = $upload_path . $race_id . '-timestamp.json';
    $filename_data = $upload_path . $race_id . '-data.json';
    $filename_index = $upload_path . $race_id . '-index.json';

    // A notification writes the stored file again, index and all: the index is made anew below.
    if ( is_array( $json_data ) ) {
        unset( $json_data[ RM_RACE_INDEX_KEY ] );
    }

    // add the notifications data to the JSON
    $json_data = add_notifications_to_race_json( $json_data, $race_id );

    $files = array();
    $parts = rm_race_parts( $json_data );
    $index = null;
    if ( null !== $parts ) {
        $index = array( 'format' => RM_RACE_INDEX_FORMAT, 'time' => $timestamp, 'parts' => array() );
        foreach ( $parts as $part ) {
            $hash = hash( 'xxh64', $part['json'] );
            $index['parts'][] = array( 'path' => $part['path'], 'hash' => $hash, 'bytes' => strlen( $part['json'] ) );
            // The hash goes into the part as well: a browser that read the index and then gets a
            // part a newer upload has already replaced can tell, and read the index again.
            $files[ $upload_path . rm_race_part_filename( $race_id, $part['path'] ) ] =
                '{"hash":"' . $hash . '","data":' . $part['json'] . '}';
        }
        // A first visit downloads the whole file, and learns from it which parts it holds.
        $json_data[ RM_RACE_INDEX_KEY ] = $index;
    }
    $files[ $filename_data ] = wp_json_encode( $json_data );
    if ( null !== $index ) {
        $files[ $filename_index ] = wp_json_encode( $index );
    }
    $files[ $filename_timestamp ] = wp_json_encode( array( 'time' => $timestamp ) );

    foreach ( $files as $filename => $contents ) {
        if ( ! rm_write_file_whole( $filename, $contents ) ) {
            return new WP_Error(
                'file_write_error',
                'Failed to write JSON file to uploads:'.$filename,
                array('status' => 500)
            );
        }
    }

    // Parts the payload no longer has -- a heat deleted on the timer -- and, for a payload that
    // cannot be split, the index with every part. Only now: the index that named them is gone.
    rm_remove_race_parts( $upload_path, $race_id, array_keys( $files ) );
    if ( null === $index && is_file( $filename_index ) ) {
        @unlink( $filename_index );
    }

    // if no errors occured, create the wp attachment if requested
    if($create_wp_attachment) {
        rm_create_wp_attachment( $race_id, $filename_timestamp );
        rm_create_wp_attachment( $race_id, $filename_data );
    }
    return true;
}

/**
 * Split a payload into the parts it is stored in, following RM_RACE_SPLIT.
 *
 * Only a JSON object is split, and only when each of its keys can go into a file name: a list is
 * one part, and so is an empty object, which PHP cannot tell from an empty list anyway. So the
 * parts put back together are the payload, whatever shape it came in.
 *
 * @param mixed $data The payload, decoded.
 * @return array|null [ [ 'path' => string[], 'json' => string ], ... ] in the payload's order, or
 *                    null when the payload is no object that can be split.
 */
function rm_race_parts( $data ) {
    if ( ! rm_race_splittable( $data ) ) {
        return null;
    }
    $parts = array();
    rm_race_split( $data, array(), RM_RACE_SPLIT, $parts );
    foreach ( $parts as $part ) {
        if ( ! is_string( $part['json'] ) ) {
            return null; // wp_json_encode() gave up on it; the whole file is all there is then
        }
    }
    return $parts;
}

/**
 * Add the parts of $value, which is split into its keys here, to $parts.
 *
 * @param array    $value The object being split.
 * @param string[] $path  Where it sits in the payload.
 * @param array    $below Which of its keys are split further, as in RM_RACE_SPLIT.
 * @param array    $parts The parts so far.
 * @return void
 */
function rm_race_split( $value, $path, $below, &$parts ) {
    foreach ( $value as $key => $child ) {
        $child_path = array_merge( $path, array( (string) $key ) );
        if ( isset( $below[ $key ] ) && rm_race_splittable( $child ) ) {
            rm_race_split( $child, $child_path, $below[ $key ], $parts );
        } else {
            $parts[] = array( 'path' => $child_path, 'json' => wp_json_encode( $child ) );
        }
    }
}

/**
 * Whether a value is an object whose keys can each name a part's file.
 *
 * @param mixed $value
 * @return bool
 */
function rm_race_splittable( $value ) {
    if ( ! is_array( $value ) || array() === $value || array_is_list( $value ) ) {
        return false;
    }
    foreach ( array_keys( $value ) as $key ) {
        if ( 1 !== preg_match( '/^[A-Za-z0-9_]{1,64}$/', (string) $key ) ) {
            return false;
        }
    }
    return true;
}

/**
 * The file name of one part of a race's payload: {race}-part-{the path's keys, joined by "-"}.json.
 *
 * The keys hold no "-" (rm_race_splittable()), so the name is unambiguous. rm_write_files() writes
 * the parts under it, and the live pages hand the loader the same name with "%s" for the path
 * (rm_print_js_module_config()), so that writer and reader cannot disagree about it.
 *
 * @param int      $race_id
 * @param string[] $path
 * @return string
 */
function rm_race_part_filename( $race_id, $path ) {
    return $race_id . '-part-' . implode( '-', $path ) . '.json';
}

/**
 * Remove a race's part files that are not among $keep.
 *
 * @param string   $upload_path The race data directory, trailing-slashed.
 * @param int      $race_id
 * @param string[] $keep        Paths of the files that stay.
 * @return void
 */
function rm_remove_race_parts( $upload_path, $race_id, $keep ) {
    $prefix = $race_id . '-part-';
    $keep   = array_flip( $keep );
    foreach ( scandir( $upload_path ) ?: array() as $name ) {
        if ( 0 === strpos( $name, $prefix ) && str_ends_with( $name, '.json' ) && ! isset( $keep[ $upload_path . $name ] ) ) {
            @unlink( $upload_path . $name );
        }
    }
}

/**
 * Replace a file whole, or leave it as it was.
 *
 * Written beside it and renamed over it: a browser reading the file meanwhile gets the old one or
 * the new one, whole. Written in place, it could get a file cut short, and a payload of 1.6 MB takes
 * long enough to write for that to happen. The name starts with a dot and ends in .tmp, so nothing
 * that looks for a race's files takes it for one.
 *
 * @param string $filename Path of the file.
 * @param string $contents What it is to hold.
 * @return bool Whether the file holds $contents now.
 */
function rm_write_file_whole( $filename, $contents ) {
    $temp = dirname( $filename ) . '/.' . basename( $filename ) . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
    if ( false === @file_put_contents( $temp, $contents ) || ! @rename( $temp, $filename ) ) {
        $error = error_get_last();
        error_log( 'rm_write_file_whole: ' . $filename . ': ' . ( $error ? $error['message'] : 'not written' ) );
        @unlink( $temp );
        return false;
    }
    return true;
}

function rm_create_wp_attachment( $race_id, $filepath ) {
        // Turn the saved file into a WordPress attachment
    // TODO: check the file names and variables full_path
    $upload_dir  = wp_upload_dir(); 
    $filetype = wp_check_filetype( $filepath, null );
    $attachment = array(
        'guid'           => $upload_dir['baseurl'] . '/races/' . basename( $filepath ),
        'post_mime_type' => $filetype['type'] ?: 'application/json',
        'post_title'     => basename( $filepath ) . ' JSON',
        'post_content'   => '',
        'post_status'    => 'inherit',
    );

    $attach_id = wp_insert_attachment( $attachment, $filepath, $race_id );
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
    wp_update_attachment_metadata( $attach_id, $attach_data );
}

/**
 * Injects WP “race” notifications into a race JSON blob.
 *
 * @param string $race_json     Decoded JSON string for one race.
 * @param int    $race_id       The post ID of the race CPT.
 * @return string               The modified JSON, now including a "notifications" array.
 */
function add_notifications_to_race_json( $race_data, $race_id ) {
    // Fetch the notifications log from post meta
    $meta_key       = '_race_notification_log';
    $notifications  = get_post_meta( $race_id, $meta_key, true );
    
    if ( ! is_array( $notifications ) ) {
        // If not an array, initialize it as empty array
        $notifications = array();
    }

    // Inject into the race data
    $race_data['notifications'] = $notifications;

    return $race_data; // Return the modified array
}

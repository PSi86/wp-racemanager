<?php
// includes/race-files.php
// The files a race is stored in under uploads/races/, as the upload and the notifications write them

if (!defined('ABSPATH')) exit; // Exit if accessed directly

/**
 * Store a race's data: the whole of it, and the timestamp that announces it.
 *
 * Browsers poll the timestamp and download the data when it changed (docs/data-flow.md), so the
 * timestamp is a promise that the data it announces is there, and it is written last. It used to
 * be written first: a browser polling between the two writes took the new timestamp with the old
 * data, and so did every browser after a data write that failed -- each held the old standing
 * under the new timestamp until the next upload. The loader made the same mistake until 2026, the
 * other way round.
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

    // add the notifications data to the JSON
    $json_data = add_notifications_to_race_json( $json_data, $race_id );

    $files = array(
        $filename_data      => wp_json_encode( $json_data ),
        $filename_timestamp => wp_json_encode( array( 'time' => $timestamp ) ),
    );
    foreach ( $files as $filename => $contents ) {
        if ( ! rm_write_file_whole( $filename, $contents ) ) {
            return new WP_Error(
                'file_write_error',
                'Failed to write JSON file to uploads:'.$filename,
                array('status' => 500)
            );
        }
    }

    // if no errors occured, create the wp attachment if requested
    if($create_wp_attachment) {
        rm_create_wp_attachment( $race_id, $filename_timestamp );
        rm_create_wp_attachment( $race_id, $filename_data );
    }
    return true;
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

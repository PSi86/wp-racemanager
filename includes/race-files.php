<?php
// includes/race-files.php
// The files a race is stored in under uploads/races/, as the upload and the notifications write them

if (!defined('ABSPATH')) exit; // Exit if accessed directly

function rm_write_files( $race_id, $json_data, $create_wp_attachment = 0 ) {
    // Write the timestamp and data to a file
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

    $file_saved = file_put_contents( $filename_timestamp, wp_json_encode(['time' => $timestamp]));
    if ( $file_saved === false ) {
        // Cleanup if needed
        //wp_delete_post( $race_id, true );
        return new WP_Error(
            'file_write_error',
            'Failed to write JSON file to uploads:'.$filename_timestamp,
            array('status' => 500)
        );
    }

    // Encode the race json data for writing to file
    $encoded_json_data = wp_json_encode( $json_data );
    $file_saved = file_put_contents( $filename_data, $encoded_json_data );
    if ( $file_saved === false ) {
        // Cleanup if needed
        //wp_delete_post( $race_id, true );
        return new WP_Error(
            'file_write_error',
            'Failed to write JSON file to uploads:'.$filename_data,
            array('status' => 500)
        );
    }
    // if no errors occured, create the wp attachment if requested
    if($create_wp_attachment) {
        rm_create_wp_attachment( $race_id, $filename_timestamp );
        rm_create_wp_attachment( $race_id, $filename_data );
    }
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

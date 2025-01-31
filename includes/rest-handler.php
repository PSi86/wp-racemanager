<?php
// inc/rest.php
// Register the REST API routes

// Reminder: if you need to check for permissions, you can use a callback like this:
//'permission_callback' => function() {
//    return current_user_can( 'edit_posts' );
//},

if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_action('rest_api_init', function () {
    // Endpoint for uploading JSON data
    register_rest_route('rm/v1', '/upload', [
        'methods' => 'POST',
        'callback' => 'rm_handle_upload',
        'permission_callback' => '__return_true',
    ]);

    // Endpoint for retrieving the latest upload timestamp
    register_rest_route('rm/v1', '/latest-timestamp', [
        'methods' => 'GET',
        'callback' => 'rm_get_latest_timestamp',
        'permission_callback' => '__return_true',
    ]);

    // Endpoint for retrieving the latest uploaded JSON data
    register_rest_route('rm/v1', '/latest-racedata', [
        'methods' => 'GET',
        'callback' => 'rm_get_latest_racedata',
        'permission_callback' => '__return_true',
    ]);
    
    // Endpoint for retrieving the latest pilot registrations
    register_rest_route('rm/v1', '/get-pilots', [
        'methods'  => 'GET',
        'callback' => 'rm_get_registration_data',
        'permission_callback' => '__return_true', // Adjust permission as needed
        'args' => [
            'form_title' => [
                'required' => true,
                'validate_callback' => function ($param) {
                    return is_string($param);
                },
            ],
        ],
    ]);
});

/**
 * Callback for POST /wp-json/wp-racemanager/v1/races
 * Expects JSON with { race_name, race_description, ...anything else... }
 */
function rm_handle_upload( $request ) {
    // Check for API key
    $api_key = get_option('rh_api_key');
    $provided_key = $request->get_header('api_key');

    if ($provided_key !== $api_key) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Invalid API Key',
        ], 401);
        //return new WP_Error('unauthorized', 'Invalid API Key', ['status' => 401]);
    }

    // Validate size without storing raw data
    if (strlen($request->get_body()) > 10 * 1024 * 1024) { // 10 MB in bytes
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'JSON size exceeds the maximum allowed limit of 10 MB.',
        ], 400);
        //return new WP_Error('invalid_data', 'JSON size exceeds the maximum allowed limit of 10 MB.', ['status' => 400]);
    }

    // Decode JSON directly without keeping the raw string
    $data = $request->get_json_params();

    if (!$data || !isset($data['heat_data'])) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'JSON must contain heat_data.',
        ], 400);
        //return new WP_Error('invalid_data', 'JSON must contain "heat_data".', ['status' => 400]);
    }

    //$json_data = $data['json_data'];

    if (!isset($data['race_name'])) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'JSON data must contain race_name.',
        ], 400);
        //return new WP_Error('invalid_data', 'JSON data must contain race_name.', ['status' => 400]);
    }
    else {
        // if we get here the input is valid
        $race_name = sanitize_text_field($data['race_name']);
    }

    if (strlen($data['race_name']) < 2 || strlen($data['race_name']) > 255) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Length of race_name must be between 2 and 255 characters.',
        ], 400);
    }
    
    // less checks for race_description
    if (isset($data['race_description'])) {
        $race_description = sanitize_textarea_field($data['race_description']);
        //$race_description = wp_kses_post($data['race_description']);
    }
    else {
        $race_description = '';
    }
    
    $encoded_json_data = wp_json_encode($data);
    
    $timestamp = current_time('mysql'); // Get the current timestamp
    
    // Parse the request body as JSON
    //$body       = $request->get_body();
    //$json_array = json_decode( $body, true );

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error(
            'invalid_json',
            'The JSON provided is invalid.',
            array('status' => 400)
        );
    }

    // Use WP_Query to find an existing Race post with this exact title
    // Check if a Race post with the same title already exists
    // Useb the first matching post (if multiple). 
    $args = array(
        'post_type'      => 'race',
        'post_status'    => 'any',
        'title'          => $race_name,
        'posts_per_page' => 1,
        'fields'         => 'ids', // return just the post IDs for efficiency
    );

    $existing_query = new WP_Query( $args );

    if ( $existing_query->have_posts() ) {
        // Existing Race found -> get its id, update its post meta (_race_last_upload) and update the JSON files

        // Grab the first matched post ID
        $post_id = $existing_query->posts[0];

        // Check if the post is already locked
        $post_live = get_post_meta( $post_id, '_race_live', true );
        if ( $post_live !== "1" ) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'Race is not live and cannot be overwritten',
                'id' => $post_id,
            ], 403);
        }

        rm_write_files( $post_id, $encoded_json_data );

        // 2) Update meta key with the current timestamp
        update_post_meta(
            $post_id,
            '_race_last_upload',
            $timestamp
        );
        
        return new WP_REST_Response([
            'status' => 'success',
            'message' => 'Event updated successfully', 
            'id' => $post_id,
        ], 200);
    }

    // No existing race found, create a new one
    // Create the Race CPT post
    $post_content = "<!-- wp:paragraph -->\n<p>{$race_description}</p>\n<!-- /wp:paragraph -->\n\n" .
                    "<!-- wp:shortcode -->\n[rm_viewer]\n<!-- /wp:shortcode -->\n";
    
    $post_id = wp_insert_post( array(
        'post_type'    => 'race',
        'post_title'   => $race_name,
        'post_content' => $post_content,
        'post_status'  => 'publish',
    ));

    if ( is_wp_error( $post_id ) ) {
        return new WP_Error(
            'post_creation_failed',
            'Could not create Race CPT post.',
            array('status' => 500)
        );
    }

    // Store data and timestamp JSON file in uploads/races
    // 1: also crate WP attachments for the files
    rm_write_files( $post_id, $encoded_json_data, 1 );

    // Link attachment in post meta
    //update_post_meta( $post_id, '_race_json_attachment_id', $attach_id );
    // Save the _race_live in post meta
    update_post_meta( $post_id, '_race_live', 1 ); // 1 = live, 0 = locked
    // Save the _race_last_upload in post meta
    update_post_meta( $post_id, '_race_last_upload', $timestamp);

    // TODO: set older race to locked status!
    rm_meta_set_last_race_inactive( $post_id );

    // Return success response
    return new WP_REST_Response([
        'status' => 'success',
        'message' => 'Event created successfully', 
        'id' => $post_id
    ], 201); // TODO - test this
}

function rm_write_files( $post_id, $encoded_json_data, $create_wp_attachment = 0 ) {
    // Write the timestamp and data to a file
    $timestamp = current_time('mysql');

    //$upload_dir  = wp_upload_dir(); 
    //$upload_path = $upload_dir['path']; // e.g. wp-content/uploads/2025/01
    //$upload_path = $upload_dir['basedir'] . '/races'; // e.g. /var/www/html/wp-content/uploads/races
    $upload_path = WP_CONTENT_DIR . '/uploads/races/';
    //$filename_timestamp = trailingslashit( $upload_path ) . $post_id . '-timestamp.json';
    $filename_timestamp = $upload_path . $post_id . '-timestamp.json';
    $filename_data = $upload_path . $post_id . '-data.json';

    $file_saved = file_put_contents( $filename_timestamp, wp_json_encode(['time' => $timestamp]));
    $file_saved = file_put_contents( $filename_data, $encoded_json_data );

    if ( $file_saved === false ) {
        // Cleanup if needed
        //wp_delete_post( $post_id, true );
        return new WP_Error(
            'file_write_error',
            'Failed to write JSON file to uploads:'.$filename_timestamp,
            array('status' => 500)
        );
    }
    // if no errors occured, create the wp attachment if requested
    if($create_wp_attachment) {
        rm_create_wp_attachment( $post_id, $filename_timestamp );
        rm_create_wp_attachment( $post_id, $filename_data );
    }
}

function rm_create_wp_attachment( $post_id, $filepath ) {
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

    $attach_id = wp_insert_attachment( $attachment, $filepath, $post_id );
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $attach_data = wp_generate_attachment_metadata( $attach_id, $filepath );
    wp_update_attachment_metadata( $attach_id, $attach_data );
}
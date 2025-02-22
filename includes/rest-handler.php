<?php
// includes/rest-handler.php
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
/**
 * Main REST API callback for uploading race result data.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function rm_handle_upload( WP_REST_Request $request ) {
    // 1. Validate API Key
    $maybe_error = rm_validate_api_key( $request );
    if ( is_wp_error( $maybe_error ) ) {
        return new WP_REST_Response([
            'status'  => 'error',
            'message' => $maybe_error->get_error_message(),
        ], $maybe_error->get_error_data() ?: 401);
    }

    // 2. Validate request size & decode JSON
    $data = rm_validate_and_decode_json( $request );
    if ( is_wp_error( $data ) ) {
        return new WP_REST_Response([
            'status'  => 'error',
            'message' => $data->get_error_message(),
        ], $data->get_error_data() ?: 400);
    }

    // 3. Validate required fields (race_name, heat_data)
    $maybe_error = rm_validate_required_fields( $data );
    if ( is_wp_error( $maybe_error ) ) {
        return new WP_REST_Response([
            'status'  => 'error',
            'message' => $maybe_error->get_error_message(),
        ], 400);
    }

    // 4. Process the race (either update existing or create new)
    $race_result = rm_find_or_create_race( $data );
    if ( is_wp_error( $race_result ) ) {
        return new WP_REST_Response([
            'status'  => 'error',
            'message' => $race_result->get_error_message(),
            'id'      => $race_result->get_error_data() ?: 0,
        ], 400);
    }

    // 5. If we get here, $race_result is an array with:
    //    ['status' => 'success'|'updated', 'id' => (race_id), 'message' => ...]
    $race_id   = $race_result['id'];
    $is_update = ( 'updated' === $race_result['status'] );

    // 6. Notify subscribers about the new or updated race
    //    (Only do this if it’s actually published/live, etc.)
    // TODO TEST New Notification logic
    // Call the function to get the upcoming race pilots (in race-data-functions.php) and feed the output to send_next_up_notifications(race_id, upcomingPilots)
    $upcomingPilots = rm_getUpcomingRacePilots($data);
    if ($upcomingPilots === null) {
        return new WP_REST_Response([
            'status'  => 'error',
            'message' => 'Could not extract upcoming pilots from data.',
            'id'      => 0,
        ], 400);
    }
    rm_notify_race_subscribers($race_id, $upcomingPilots);
    //rm_notify_race_subscribers_bak( $race_id, $is_update );

    // 7. Return final success response
    return new WP_REST_Response([
        'status'  => 'success',
        'message' => $race_result['message'],
        'id'      => $race_id,
    ], $is_update ? 200 : 201);
}

/**
 * Validate API key header.
 */
function rm_validate_api_key( WP_REST_Request $request ) {
    $api_key      = get_option('rm_api_key');
    $provided_key = $request->get_header('api_key');

    if ( $provided_key !== $api_key ) {
        return new WP_Error(
            'invalid_api_key',
            'Invalid API Key',
            401
        );
    }
    return true; // All good
}

/**
 * Validate request size & decode JSON.
 */
function rm_validate_and_decode_json( WP_REST_Request $request ) {
    // 10 MB limit
    if ( strlen( $request->get_body() ) > 10 * 1024 * 1024 ) {
        return new WP_Error(
            'invalid_data',
            'JSON size exceeds the maximum allowed limit of 10 MB.',
            400
        );
    }

    $data = $request->get_json_params();
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error(
            'invalid_json',
            'The JSON provided is invalid.',
            400
        );
    }

    return $data; // Return the decoded array
}

/**
 * Validate existence of required fields (race_name, heat_data).
 */
function rm_validate_required_fields( $data ) {
    if ( ! is_array( $data ) ) {
        return new WP_Error( 'invalid_data', 'Expected an array of data.' );
    }

    if ( ! isset( $data['heat_data'] ) ) {
        return new WP_Error( 'invalid_data', 'JSON must contain "heat_data".' );
    }
    if ( ! isset( $data['race_name'] ) ) {
        return new WP_Error( 'invalid_data', 'JSON must contain "race_name".' );
    }

    $race_name = trim( $data['race_name'] );
    if ( strlen( $race_name ) < 2 || strlen( $race_name ) > 255 ) {
        return new WP_Error( 'invalid_data', 'Length of race_name must be between 2 and 255 characters.' );
    }

    // Optional field 'race_description' can be handled similarly if needed
    return true;
}

/**
 * Finds an existing Race CPT (by title=race_name) or creates a new one.
 * Returns array on success: ['status' => 'updated'|'success', 'id' => (race_id), 'message' => '...']
 * Returns WP_Error on failure.
 */
function rm_find_or_create_race( $data ) {
    $race_name        = sanitize_text_field( $data['race_name'] );
    $race_description = isset( $data['race_description'] )
        ? sanitize_textarea_field( $data['race_description'] )
        : '';

    // Search for an existing Race with this exact title
    $args = [
        'post_type'      => 'race',
        'post_status'    => 'any',
        'title'          => $race_name,
        'posts_per_page' => 1,
        'fields'         => 'ids', // return only IDs
    ];
    $existing_query = new WP_Query( $args );

    // Encode the data for writing to file
    $encoded_json_data = wp_json_encode( $data );
    $timestamp         = current_time( 'mysql' );

    if ( $existing_query->have_posts() ) {
        // Existing Race found
        $race_id  = $existing_query->posts[0];
        $post_live = get_post_meta( $race_id, '_race_live', true );
        if ( '1' !== $post_live ) {
            return new WP_Error(
                'race_locked',
                'Race is not live and cannot be overwritten',
                $race_id
            );
        }

        // We can now write new data to files, etc.
        rm_write_files( $race_id, $encoded_json_data );
        update_post_meta( $race_id, '_race_last_upload', $timestamp );

        return [
            'status'  => 'updated',
            'id'      => $race_id,
            'message' => 'Event updated successfully',
        ];
    }

    // Otherwise, no existing race found -> create a new CPT post
    $post_content = "<!-- wp:paragraph -->\n<p>{$race_description}</p>\n<!-- /wp:paragraph -->\n\n" .
                    "<!-- wp:shortcode -->\n[rm_viewer]\n<!-- /wp:shortcode -->\n";
    
    $race_id = wp_insert_post([
        'post_type'    => 'race',
        'post_title'   => $race_name,
        'post_content' => $post_content,
        'post_status'  => 'publish',
    ]);

    if ( is_wp_error( $race_id ) ) {
        return new WP_Error(
            'post_creation_failed',
            'Could not create Race CPT post.',
            500
        );
    }

    rm_write_files( $race_id, $encoded_json_data, 1 );
    update_post_meta( $race_id, '_race_live', 1 );
    update_post_meta( $race_id, '_race_last_upload', $timestamp );

    // Optionally set older races inactive
    rm_meta_set_last_race_inactive( $race_id );

    return [
        'status'  => 'success',
        'id'      => $race_id,
        'message' => 'Event created successfully',
    ];
}

/**
 * Calls the PWA_Subscription_Handler's send_next_up_notifications() method
 * after a race is updated or created.
 *
 * @param int  $race_id   The Race CPT post ID
 * @param bool $is_update True if the race was updated; false if newly created
 */
function rm_notify_race_subscribers( $race_id, $upcomingPilots ) {
    // If you have direct access to $this->pwa_subscription_handler in scope, use it.
    // Otherwise, retrieve from your plugin instance:
    $manager = \RaceManager\WP_RaceManager::instance();

    // Ensure the subscription handler is available.
    if ( empty( $manager->pwa_subscription_handler ) ) {
        // Maybe just bail out silently if there's no subscription system loaded
        return;
    }
    $pwa = $manager->pwa_subscription_handler;

    // Now call the method (public in pwa-subscription-handler.php).
    // If your PWA_Subscription_Handler uses the CPT post ID as `race_id`,
    // pass $race_id as the "race_id" parameter:
    
    //error_log('race_id: ' . $race_id);
    //error_log(print_r($upcomingPilots, true));

    $pwa->send_next_up_notifications( $race_id, $upcomingPilots );
}
function rm_notify_race_subscribers_bak( $race_id, $is_update = false ) {
    // If you have direct access to $this->pwa_subscription_handler in scope, use it.
    // Otherwise, retrieve from your plugin instance:
    $manager = \RaceManager\WP_RaceManager::instance();

    // Ensure the subscription handler is available.
    if ( empty( $manager->pwa_subscription_handler ) ) {
        // Maybe just bail out silently if there's no subscription system loaded
        return;
    }
    $pwa = $manager->pwa_subscription_handler;

    // Example title/message
    $title   = $is_update ? 'Race Data Updated' : 'New Race Created';
    $race    = get_post( $race_id );
    $message = ( $race ) 
        ? sprintf( 'The race "%s" has been %s.', $race->post_title, $is_update ? 'updated' : 'created' )
        : ( $is_update ? 'A race was updated.' : 'A new race was created.' );

    // Now call the method (public in pwa-subscription-handler.php).
    // If your PWA_Subscription_Handler uses the CPT post ID as `race_id`,
    // pass $race_id as the "race_id" parameter:
    $pwa->send_notification_to_all( $race_id, $title, $message );
}

function rm_write_files( $race_id, $encoded_json_data, $create_wp_attachment = 0 ) {
    // Write the timestamp and data to a file
    $timestamp = current_time('mysql');

    //$upload_dir  = wp_upload_dir(); 
    //$upload_path = $upload_dir['path']; // e.g. wp-content/uploads/2025/01
    //$upload_path = $upload_dir['basedir'] . '/races'; // e.g. /var/www/html/wp-content/uploads/races
    $upload_path = WP_CONTENT_DIR . '/uploads/races/';
    //$filename_timestamp = trailingslashit( $upload_path ) . $race_id . '-timestamp.json';
    $filename_timestamp = $upload_path . $race_id . '-timestamp.json';
    $filename_data = $upload_path . $race_id . '-data.json';

    $file_saved = file_put_contents( $filename_timestamp, wp_json_encode(['time' => $timestamp]));
    $file_saved = file_put_contents( $filename_data, $encoded_json_data );

    if ( $file_saved === false ) {
        // Cleanup if needed
        //wp_delete_post( $race_id, true );
        return new WP_Error(
            'file_write_error',
            'Failed to write JSON file to uploads:'.$filename_timestamp,
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

// Callback function to fetch and return pilot registration data
// Options: 'latest' or a specific form title
// requires 'form_title' parameter and 'api_key' header to be set
function rm_get_registration_data($request) {

    // API key authentication
    $api_key = get_option('rm_api_key');
    $provided_key = $request->get_header('api_key');

    if ($provided_key !== $api_key) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Invalid API Key.',
        ], 401);
        //return new WP_Error('unauthorized', 'Invalid API Key', ['status' => 401]);
    }

    global $wpdb;

    $form_title = $request['form_title']; // Get the form_title from the request
    $form_title = sanitize_text_field($form_title);
    $cfdb7_table = $wpdb->prefix . 'db7_forms'; // cfdb7 table name holds all form replies
    $posts_table = $wpdb->prefix . 'posts'; // WordPress posts table. The 'wpcf7_contact_form' post type holds the form definitions

    $form_post_id = null;

    if ($form_title === 'latest') {
        // Get the highest form_post_id directly from the cfdb7 table
        //$form_post_id = $wpdb->get_var("SELECT MAX(form_post_id) FROM $cfdb7_table");

        // Find the latest published form in the posts table otherwise there is the risk of reading registrations from a old, deleted or unpublished form
        $form_post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(ID) FROM $posts_table 
                WHERE post_status = 'publish' AND post_type = 'wpcf7_contact_form'"
            )
        );
        
        if (!$form_post_id) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'No registration form found in the database.',
            ], 404);
            //return new WP_Error('no_latest_form', 'No form data found in the database.', ['status' => 404]);
        }
    } elseif (strlen($form_title) > 1) {
        // Find the highest post_id for the given form_title in the posts table
        $form_post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(ID) FROM $posts_table 
                WHERE post_title = %s AND post_type = 'wpcf7_contact_form'",
                $form_title
            )
        );

        if (!$form_post_id) {
            return new WP_REST_Response([
                'status' => 'error',
                'message' => 'No form found with the specified form_title.',
            ], 404);
            //return new WP_Error('no_form_found', 'No form found with the specified form_title.', ['status' => 404]);
        }
    } else {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Invalid form_title parameter.',
        ], 404);
        //return new WP_Error('invalid_form_title', 'Invalid form_title parameter.', ['status' => 404]);
    }

    // Query the cfdb7 table for entries matching the form_post_id
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT form_value, form_date FROM $cfdb7_table WHERE form_post_id = %d",
            $form_post_id
        ),
        ARRAY_A
    );

    if (empty($results)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'No registration data found for the form_post_id:' .$form_post_id. '.',
        ], 404);
        //return new WP_Error('no_form_data', 'No data found for the matching form.', ['status' => 404]);
    }

    // Process and format results
    $formatted_data = [];
    foreach ($results as $row) {
        $formatted_data[] = [
            'form_values' => maybe_unserialize($row['form_value']),
            'submission_date' => $row['form_date'],
        ];
    }

    return rest_ensure_response($formatted_data);
}
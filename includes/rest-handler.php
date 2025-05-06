<?php
// includes/rest-handler.php
// Register the REST API routes

// Reminder: if you need to check for permissions, you can use a callback like this:
//'permission_callback' => function() {
//    return current_user_can( 'edit_posts' );
//},

if (!defined('ABSPATH')) exit; // Exit if accessed directly

//add_action('rest_api_init', function () {
function rm_register_rest_routes_rh() {
    // Endpoint for uploading JSON data
    register_rest_route(
        'rm/v1', 
        '/upload', 
        [
            'methods' => 'POST',
            'callback' => 'rm_handle_upload',
            'permission_callback' => 'permission_check_user',
        ]
    );

    // Endpoint for retrieving the latest pilot registrations
    register_rest_route(
        'rm/v1',
        '/get-pilots', 
        [
            'methods'  => 'GET',
            'callback' => 'rm_get_registration_data',
            'permission_callback' => 'permission_check_user',
            'args' => [
                'race_id' => [
                    'required' => true,
                    'validate_callback' => 'permission_check_race_id',
                ],
            ],
        ]
    );

    // Send notifications to all in a race
    register_rest_route(
        'rm/v1',
        '/notify-racers',
        [
            'methods'  => 'POST',
            'callback' => 'handle_notification_request',
            'permission_callback' => 'permission_check_user',
        ]
    );
}
//);

function permission_check_user( \WP_REST_Request $request ) {
    return is_user_logged_in(); 
}

function permission_check_race_id( $param, \WP_REST_Request $request, $key ) {
    if ( rest_is_integer($param) ) {
        $race_id = intval($param);
        if( current_user_can( 'edit_post', $race_id ) ) {
            return true;
        } else {
            // User is not allowed to edit this post
            return new WP_Error( 
                'forbidden',
                __( 'Wrong user. You do not have permission to access this race.', 'wp-racemanager' ), 
                array( 'status' => 403 ) 
            );
        }
    }
    else {
        return new WP_Error( 
            'forbidden',
            __( 'Wrong prarameter format [race_id]', 'wp-racemanager' ), 
            array( 'status' => 400 ) 
        );
    }
}

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
    /* $maybe_error = rm_validate_api_key( $request );
    if ( is_wp_error( $maybe_error ) ) {
        return new WP_REST_Response([
            'status'  => 'error',
            'message' => $maybe_error->get_error_message(),
        ], $maybe_error->get_error_data() ?: 401);
    } */

    // Validate request size & decode JSON
    $data = rm_validate_and_decode_json( $request );
    if ( is_wp_error( $data ) ) {
        return new WP_REST_Response([
            'status'  => 'error',
            'message' => $data->get_error_message(),
        ], $data->get_error_data() ?: 400);
    }

    // Validate required fields (race_name, heat_data)
    $maybe_error = rm_validate_required_fields( $data );
    if ( is_wp_error( $maybe_error ) ) {
        return new WP_REST_Response([
            'status'  => 'error',
            'message' => $maybe_error->get_error_message(),
        ], 400);
    }

    // Process the race (either update existing or create new)
    //  User rights are checked within rm_find_or_create_race()
    $race_result = rm_find_or_create_race( $data );
    if ( is_wp_error( $race_result ) ) {
        return new WP_REST_Response([
            'status'  => 'error',
            'message' => $race_result->get_error_message(),
            'id'      => $race_result->get_error_data() ?: 0,
        ], 400);
    }

    // If we get here, $race_result is an array with:
    //  ['status' => 'success'|'updated', 'id' => (race_id), 'message' => ...]
    $race_id   = $race_result['id'];
    $is_update = ( 'updated' === $race_result['status'] );

    // Notify subscribers about the new or updated race
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
    $notified = rm_notify_nextup($race_id, $upcomingPilots);
    //rm_notify_nextup_bak( $race_id, $is_update );

    // Return final success response
    return new WP_REST_Response([
        'status'  => 'success',
        'message' => $race_result['message'],
        'id'      => $race_id,
        'nextup' => $upcomingPilots,
        'notifiedIds' => $notified,
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
 * Finds an existing Race CPT (by title=race_name) or creates a new one, if the current user is allowed to do so.
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

    $timestamp         = current_time( 'mysql' );

    if ( $existing_query->have_posts() ) {
        // Existing Race found
        $race_id  = $existing_query->posts[0];

        // Check if the current user is allowed to edit this post.
        // This check respects the default capabilities, allowing higher-level users
        // (e.g. editors, administrators) to update any post.
        if ( ! current_user_can( 'edit_post', $race_id ) ) {
            return new WP_Error( 
                'forbidden',
                __( 'Wrong user. You do not have permission to update this race.', 'wp-racemanager' ), 
                array( 'status' => 403 ) 
            );
        }
                
        $post_live = get_post_meta( $race_id, '_race_live', true );
        if ( '1' !== $post_live ) {
            return new WP_Error(
                'race_locked',
                'Race is locked and cannot be overwritten',
                $race_id
            );
        }
        
        rm_write_files( $race_id, $data );
        update_post_meta( $race_id, '_race_last_upload', $timestamp );

        return [
            'status'  => 'updated',
            'id'      => $race_id,
            'message' => 'Event updated successfully',
        ];
    }
    else {
        // Create new race post
        // Check if the current user is allowed to publish posts.
        if ( ! current_user_can( 'publish_posts', $race_id ) ) {
            return new WP_Error( 
                'forbidden',
                __( 'Wrong user. You do not have permission to update this race.', 'wp-racemanager' ), 
                array( 'status' => 403 ) 
            );
        }
        // Otherwise, no existing race found -> create a new CPT post
        /* $post_content = "<!-- wp:paragraph -->\n<p>{$race_description}</p>\n<!-- /wp:paragraph -->\n\n" .
                        "<!-- wp:shortcode -->\n[rm_viewer]\n<!-- /wp:shortcode -->\n"; */
        $post_content = '<!-- wp:group {"metadata":{"name":"Link Row"},"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between"}} -->
            <div class="wp-block-group">
            <!-- wp:wp-racemanager/race-buttons /-->

            <!-- wp:social-links {"iconColor":"base","iconColorValue":"#ffffff","iconBackgroundColor":"contrast","iconBackgroundColorValue":"#000000","openInNewTab":true,"metadata":{"name":"Social Links"},"className":"is-style-default","layout":{"type":"flex","justifyContent":"right","orientation":"horizontal"}} -->
            <ul class="wp-block-social-links has-icon-color has-icon-background-color is-style-default">
<!-- wp:social-link {"url":"https://www.youtube.com/channel/00000","service":"youtube"} /-->
            <!-- wp:social-link {"url":"https://www.instagram.com/00000/","service":"instagram"} /-->
            <!-- wp:social-link {"url":"https://discord.gg/00000","service":"discord"} /--></ul>
            <!-- /wp:social-links --></div>
            <!-- /wp:group -->

            <!-- wp:columns {"className":"is-style-columns-reverse","style":{"spacing":{"margin":{"top":"var:preset|spacing|x-small","bottom":"var:preset|spacing|x-small"}}}} -->
            <div class="wp-block-columns is-style-columns-reverse" style="margin-top:var(--wp--preset--spacing--x-small);margin-bottom:var(--wp--preset--spacing--x-small)"><!-- wp:column {"width":"66.66%","layout":{"type":"default"}} -->
            <div class="wp-block-column" style="flex-basis:66.66%"><!-- wp:paragraph {"align":"left","placeholder":"Enter race description here...","style":{"layout":{"selfStretch":"fit","flexSize":null}}} -->
            <p class="has-text-align-left">' . $race_description . '</p>
            <!-- /wp:paragraph --></div>
            <!-- /wp:column -->

            <!-- wp:column {"width":"","layout":{"type":"default"}} -->
            <div class="wp-block-column"><!-- wp:post-featured-image {"width":"","height":"","scale":"contain"} /--></div>
            <!-- /wp:column --></div>
            <!-- /wp:columns -->

            <!-- wp:details -->
            <details class="wp-block-details"><summary><strong>Details: </strong></summary><!-- wp:paragraph {"placeholder":"Timetable, Location, Food, Rules, etc."} -->
            <p>08:30 Doors open <br>09:00 Training <br>10:00 Qualification <br>13:00 Lunch <br>17:00 Finals <br>18:00 End</p>
            <!-- /wp:paragraph -->

            <!-- wp:gmap/gmap-block {"address":"Martin-Luther-Straße 28, 70825 Korntal-Münchingen","zoom":11,"uniqueId":"gmap-block-gaoc5rx2","blockStyle":"\n        \n        \n    \n        @media (max-width: 1024px) and (min-width: 768px) {\n            \n         \n    \n        }\n        @media (max-width: 767px) {\n            \n         \n    \n        }\n    "} -->
            <div class="wp-block-gmap-gmap-block gmap-block-gaoc5rx2"><iframe src="https://maps.google.com/maps?q=Martin-Luther-Stra%C3%9Fe+28%2C+70825+Korntal-M%C3%BCnchingen&amp;z=11&amp;t=roadmap&amp;output=embed" class="embd-map" title="Martin-Luther-Straße 28, 70825 Korntal-Münchingen"></iframe></div>
            <!-- /wp:gmap/gmap-block --></details>
            <!-- /wp:details -->

            <!-- wp:wp-racemanager/race-gallery /-->

            <!-- wp:shortcode {"metadata":{"name":"Registered Pilots"}} -->
            [rm_registered]
            <!-- /wp:shortcode -->';
        
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

        rm_write_files( $race_id, $data, 1 );

        update_post_meta( $race_id, '_race_live', 1 );
        update_post_meta( $race_id, '_race_last_upload', $timestamp );
        update_post_meta( $race_id, '_race_reg_closed', true );

        $date_start = strtotime('today 8:00');
        update_post_meta( $race_id, '_race_event_start', $date_start );

        $date_end = strtotime('today 19:00');
        update_post_meta( $race_id, '_race_event_end', $date_end );

        return [
            'status'  => 'success',
            'id'      => $race_id,
            'message' => 'Event created successfully',
        ];
    }
}

/**
 * Calls the PWA_Subscription_Handler's send_next_up_notifications() method
 * after a race is updated or created.
 *
 * @param int  $race_id   The Race CPT post ID
 * @param bool $is_update True if the race was updated; false if newly created
 */
function rm_notify_nextup( $race_id, $upcomingPilots ) {
    // If you have direct access to $this->pwa_subscription_handler in scope, use it.
    // Otherwise, retrieve from your plugin instance:
    $manager = \RaceManager\WP_RaceManager::instance();

    // Ensure the subscription handler is available.
    if ( empty( $manager->pwa_subscription_handler ) ) {
        // Maybe just bail out silently if there's no subscription system loaded
        return;
    }
    $pwa = $manager->pwa_subscription_handler;
    //$pwa = $this->pwa_subscription_handler; // TODO: Test this

    // Now call the method (public in pwa-subscription-handler.php).
    // If your PWA_Subscription_Handler uses the CPT post ID as `race_id`,
    // pass $race_id as the "race_id" parameter:
    
    //error_log('race_id: ' . $race_id);
    //error_log(print_r($upcomingPilots, true));

    $notified = $pwa->send_next_up_notifications( $race_id, $upcomingPilots );

    return $notified;
}

function rm_write_files( $race_id, $json_data, $create_wp_attachment = 0 ) {
    // Write the timestamp and data to a file
    $timestamp = current_time('mysql');

    //$upload_dir  = wp_upload_dir(); 
    //$upload_path = $upload_dir['path']; // e.g. wp-content/uploads/2025/01
    //$upload_path = $upload_dir['basedir'] . '/races'; // e.g. /var/www/html/wp-content/uploads/races
    $upload_path = WP_CONTENT_DIR . '/uploads/races/';
    //$filename_timestamp = trailingslashit( $upload_path ) . $race_id . '-timestamp.json';
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

// Callback function to fetch and return pilot registration data
// Options: 'latest' or a specific form title
// requires 'race_id' parameter and 'api_key' header to be set
function rm_get_registration_data( WP_REST_Request $request) {

    global $wpdb;

    $race_id = $request['race_id']; // Get the race_id from the request
    $race_id = intval(sanitize_text_field($race_id)); // Sanitize the input
    $registrations_table = $wpdb->prefix . 'rm_registrations'; // cfdb7 table name holds all form replies

    if ("race" != get_post_type($race_id)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'Invalid race_id parameter.',
        ], 404);
    }

    // Query the registrations table for entries matching the race_id
    $results = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $registrations_table WHERE race_id = %d",
            $race_id
        ),
        ARRAY_A
    );

    if (empty($results)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'No registration data found for the race_id:' .$race_id. '.',
        ], 404);
        //return new WP_Error('no_form_data', 'No data found for the matching form.', ['status' => 404]);
    }

    // Process the results: unserialize the form data and add extra fields.
    // TODO: Move this to a separate function to avoid code duplication.
    // Define the allowed keys for display
    global $rm_gui_columns;
    $rows = array();

    if ( $results ) {
        foreach ( $results as $row ) {
            $data = maybe_unserialize($row['form_value']);
            if (!is_array($data)) {
                $data = array();
            }
            // Filter the array so only allowed keys remain
            $filtered_data = array_intersect_key($data, array_flip($rm_gui_columns));
            // Add extra fields from the record.
            $filtered_data['user_id']   = $row['user_id'];
            $filtered_data['form_date'] = $row['form_date'];
            $filtered_data['id']        = $row['id']; // required for checkboxes.
            $rows[] = $filtered_data;
        }
    }

    return rest_ensure_response($rows);
}

/**
 * Handle notification requests from RotorHazard
 * Sends notifications to all subscribers in a race.
 * Expects JSON with:
 * {   
 *  "race_id": 123,
 * }
 */
function handle_notification_request( \WP_REST_Request $request ) {
    $body = json_decode( $request->get_body(), true );

    if ( empty( $body['race_id'] ) || empty( $body['msg_title'] ) || empty( $body['msg_body'] ) ) {
        return new \WP_REST_Response(
            [ 'error' => 'Missing required field.' ],
            400
        );
    }
    
    $race_id    = absint( $body['race_id'] );
    
    if ( ! current_user_can( 'edit_post', $race_id ) ) {
        return new \WP_REST_Response(
            [ 'error' => 'Unauthorized. The current user cannot access this post.' ],
            403
        );
    }

    // Authenticated, let's proceed with the notification

    // Build notification data for storing in post meta
    $notification = array(
        'msg_title'   => isset( $body['msg_title'] ) ? sanitize_text_field( $body['msg_title'] ) : '',
        'msg_body'    => isset( $body['msg_body'] ) ? sanitize_textarea_field( $body['msg_body'] ) : '',
        'msg_url'     => isset( $body['msg_url'] ) ? esc_url_raw( $body['msg_url'] ) : '',
        'msg_icon'    => isset( $body['msg_icon'] ) ? esc_url_raw( $body['msg_icon'] ) : '',
        'msg_time'    => current_time( 'mysql' ),
    );

    // Meta key.
    $meta_key = '_race_notification_log';

    // Get existing notifications
    $race_log = get_post_meta( $race_id, $meta_key, true );
    if ( ! is_array( $race_log ) ) {
        $race_log = array();
    }

    // Prepend new notification
    array_unshift( $race_log, $notification );

    // Update post meta
    update_post_meta( $race_id, $meta_key, $race_log );

    // load the existing json file
    $upload_path = WP_CONTENT_DIR . '/uploads/races/';
    //$filename_timestamp = trailingslashit( $upload_path ) . $race_id . '-timestamp.json';
    $filename = $upload_path . $race_id . '-data.json';

    if ( file_exists( $filename ) ) {
        // Read the existing JSON data
        $race_json = file_get_contents( $filename );
        $race_data = json_decode( $race_json, true );

        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $race_data ) ) {
            return new \WP_REST_Response(
                [ 'error' => 'Error writing notification to race JSON file.' ],
                500
            );
        }

        // automatically adds the notifications to the JSON and updates the timestamp file, too
        rm_write_files( $race_id, $race_data, 0 );
    }
    /*  for now we ignore the file writing if the file does not exist    
    else {
        // File does not exist, return an error response
        return new \WP_REST_Response(
            [ 'error' => 'Error reading notification to race JSON file.' ],
            500
        );
    } */

    // Send the notification to all subscribers
    $msg_title  = sanitize_text_field( $body['msg_title'] );
    $msg_body   = sanitize_text_field( $body['msg_body'] );

    $manager = \RaceManager\WP_RaceManager::instance();

    // Ensure the subscription handler is available
    if ( empty( $manager->pwa_subscription_handler ) ) {
        // Maybe just bail out silently if there's no subscription system loaded
        return;
    }
    $pwa = $manager->pwa_subscription_handler;

    //$notified = $pwa->send_next_up_notifications( $race_id, $upcomingPilots );
    $pwa->send_notification_to_all_in_race( $race_id, $msg_title, $msg_body );

    return new \WP_REST_Response(
        [ 'success' => true, 'message' => 'Notification sent successfully' ],
        200
    );
}
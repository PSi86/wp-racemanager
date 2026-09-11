<?php
// includes/rest-handler.php
// Register the REST API routes

if (!defined('ABSPATH')) exit; // Exit if accessed directly

//add_action('rest_api_init', function () {
function rm_register_rest_routes_rh() {
    // Endpoint for uploading JSON data. With ?race_id= it updates exactly that race; without,
    // it goes by title as before -- kept for one release, for timers with an older plugin.
    // Whether the race exists and the user may edit it is rm_update_race()'s to say: it answers
    // 404 and 403 in that order, where a check here could only say "not allowed" to both.
    register_rest_route(
        'rm/v1',
        '/upload',
        [
            'methods' => 'POST',
            'callback' => 'rm_handle_upload',
            'permission_callback' => 'permission_check_user',
            'args' => [
                'race_id' => [
                    'required' => false,
                    'validate_callback' => 'rm_validate_race_id',
                ],
            ],
        ]
    );

    // The races a timer can upload to, and creating one on purpose from the event
    register_rest_route(
        'rm/v1',
        '/races',
        [
            [
                'methods'  => 'GET',
                'callback' => 'rm_list_races',
                'permission_callback' => 'permission_check_user',
            ],
            [
                'methods'  => 'POST',
                'callback' => 'rm_handle_create_race',
                'permission_callback' => 'permission_check_user',
            ],
        ]
    );

    // Endpoint for retrieving the latest pilot registrations
    register_rest_route(
        'rm/v1',
        '/get-pilots', 
        [
            'methods'  => 'GET',
            'callback' => 'rm_get_registration_data',
            'permission_callback' => 'permission_check_user_and_race',
            'args' => [
                'race_id' => [
                    'required' => true,
                    'validate_callback' => 'rm_validate_race_id',
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

/**
 * Gate for the endpoints RotorHazard talks to.
 *
 * "Logged in" was too wide: on a site with registered pilots every one of them could reach the
 * upload endpoint and have a body of arbitrary size decoded and validated before the per-race
 * capability check further down said no. edit_posts is the smallest capability that every
 * client which can currently succeed already holds -- updating a race needs edit_post on it,
 * creating one needs publish_posts, and both imply edit_posts -- so this rejects earlier
 * without turning away a timer that works today.
 *
 * @param \WP_REST_Request $request
 * @return true|\WP_Error
 */
function permission_check_user( \WP_REST_Request $request ) {
    if ( current_user_can( 'edit_posts' ) ) {
        return true;
    }

    return new \WP_Error(
        'rest_forbidden',
        __( 'You do not have permission to use the RaceManager endpoints.', 'wp-racemanager' ),
        array( 'status' => is_user_logged_in() ? 403 : 401 )
    );
}

/**
 * validate_callback for race_id: its form, and nothing else.
 *
 * WordPress validates a route's parameters before it calls its permission callback, so a check
 * of the user's rights here ran ahead of the login check: a request without a valid login -- a
 * revoked application password, say -- was answered 400 "Invalid parameter(s): race_id" instead
 * of 401. Rights belong in the permission callback, or in the handler.
 *
 * @return true|\WP_Error
 */
function rm_validate_race_id( $param, \WP_REST_Request $request, $key ) {
    if ( rest_is_integer( $param ) && (int) $param > 0 ) {
        return true;
    }
    return new WP_Error(
        'invalid_race_id',
        __( 'race_id has to be a race\'s ID, a whole number.', 'wp-racemanager' ),
        array( 'status' => 400 )
    );
}

/**
 * permission_callback for get-pilots: the login first, then the right to the race it names.
 *
 * The registrations handler does not check the race itself, so this has to happen before it.
 * An ID that is no race goes on to the handler, which answers 404 for it; a race the user may
 * not edit is refused here with 403.
 *
 * @return true|\WP_Error
 */
function permission_check_user_and_race( \WP_REST_Request $request ) {
    $allowed = permission_check_user( $request );
    if ( true !== $allowed ) {
        return $allowed;
    }
    $race_id = (int) $request->get_param( 'race_id' );
    if ( 'race' === get_post_type( $race_id ) && ! current_user_can( 'edit_post', $race_id ) ) {
        return new WP_Error(
            'forbidden',
            __( 'Wrong user. You do not have permission to access this race.', 'wp-racemanager' ),
            array( 'status' => 403 )
        );
    }
    return true;
}

/**
 * Main REST API callback for uploading race result data.
 *
 * Expects the event as JSON: { race_name, race_description, heat_data, ... }.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function rm_handle_upload( WP_REST_Request $request ) {
    // Authentication happens in permission_check_user(); the per-race capability is checked in
    // rm_update_race() / rm_create_race().

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

    // A timer that names its race gets exactly that one, and never a new race on the way.
    // Without race_id: the lookup by title, which may create one -- kept for one release, for
    // timers with an older plugin.
    $race_id = $request->get_param( 'race_id' );
    if ( null !== $race_id && '' !== $race_id ) {
        $race_result = rm_update_race( absint( $race_id ), $data );
    } else {
        $race_result = rm_find_or_create_race( $data );
    }
    if ( is_wp_error( $race_result ) ) {
        return rm_race_error_response( $race_result );
    }

    // If we get here, $race_result is an array with:
    //  ['status' => 'success'|'updated', 'id' => (race_id), 'message' => ...]
    $race_id   = $race_result['id'];
    $is_update = ( 'updated' === $race_result['status'] );

    // Tell the viewers who follow the pilots flying next. The race is saved by now, so nothing
    // from here on turns the upload into a failure: that answered 400 when the data lacked a
    // section rm_getUpcomingRacePilots() needs, and the timer reported a saved upload as failed
    // (D8 in the RotorHazard plugin's roadmap). The push library throws too -- on keys it cannot
    // use, among others. Either way nobody is notified, and the answer says so.
    $notice         = null;
    $notified       = false;
    $upcomingPilots = rm_getUpcomingRacePilots( $data );
    if ( null === $upcomingPilots ) {
        $upcomingPilots = array();
        $notice         = 'Saved. Who flies next could not be worked out from the data, so nobody was notified.';
    } else {
        try {
            $notified = rm_notify_nextup( $race_id, $upcomingPilots );
        } catch ( \Throwable $e ) {
            error_log( 'rm_handle_upload: next-up notifications failed: ' . $e->getMessage() );
            $notice = 'Saved. Sending the next-up notifications failed, so nobody was notified.';
        }
    }

    $answer = [
        'status'      => 'success',
        'message'     => $race_result['message'],
        'id'          => $race_id,
        'nextup'      => $upcomingPilots,
        'notifiedIds' => $notified,
    ];
    if ( null !== $notice ) {
        $answer['notice'] = $notice;
    }
    return new WP_REST_Response( $answer, $is_update ? 200 : 201 );
}

/**
 * The answer for a race that could not be updated or created.
 *
 * The error's data carries the HTTP status -- 403, 404, 409, 500 -- and, for a locked race or a
 * taken title, the race's ID; for a taken title also whether the user may edit that race.
 * Anything without a status is a 400.
 *
 * @param WP_Error $error
 * @return WP_REST_Response
 */
function rm_race_error_response( $error ) {
    $data = $error->get_error_data();
    $body = [
        'status'  => 'error',
        'message' => $error->get_error_message(),
        'id'      => ( is_array( $data ) && isset( $data['id'] ) ) ? (int) $data['id'] : 0,
    ];
    if ( is_array( $data ) && isset( $data['editable'] ) ) {
        $body['editable'] = (bool) $data['editable'];
    }
    return new WP_REST_Response( $body, ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 400 );
}

/**
 * How many races GET /races lists, counted among those the user may edit: the timer needs the
 * next events', not the archive. Decided on 2026-09-11.
 */
const RM_TIMER_RACE_LIST_LENGTH = 15;

/** How many race IDs one query of GET /races fetches while it looks for editable ones. */
const RM_TIMER_RACE_LIST_PAGE = 50;

/**
 * Callback for GET /rm/v1/races: the newest races the current user may edit.
 *
 * Ordered by event start like the live race selection, so a race needs its start date to
 * appear -- every race an upload creates has one. Titles, dates and the live flag only.
 *
 * The limit counts races the user may edit, and edit_post decides that per race, so the list is
 * read page by page until it has them: an account that may edit only its own races would
 * otherwise get whichever of them happened to be among the newest few overall.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function rm_list_races( WP_REST_Request $request ) {
    $races = [];
    $page  = 1;
    do {
        $query = new WP_Query( [
            'post_type'      => 'race',
            'post_status'    => 'any',
            'posts_per_page' => RM_TIMER_RACE_LIST_PAGE,
            'paged'          => $page++,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_key'       => '_race_event_start',
            'meta_type'      => 'DATETIME',
            // Races created the same day share their placeholder start; without a second key
            // the database may order those differently from one page to the next.
            'orderby'        => [ 'meta_value' => 'DESC', 'ID' => 'DESC' ],
        ] );

        foreach ( $query->posts as $race_id ) {
            if ( ! current_user_can( 'edit_post', $race_id ) ) {
                continue;
            }
            $races[] = [
                'id'    => (int) $race_id,
                'title' => (string) get_post_field( 'post_title', $race_id ),
                'start' => (string) get_post_meta( $race_id, '_race_event_start', true ),
                'end'   => (string) get_post_meta( $race_id, '_race_event_end', true ),
                'live'  => '1' === (string) get_post_meta( $race_id, '_race_live', true ),
            ];
            if ( count( $races ) === RM_TIMER_RACE_LIST_LENGTH ) {
                return new WP_REST_Response( $races, 200 );
            }
        }
    } while ( count( $query->posts ) === RM_TIMER_RACE_LIST_PAGE );

    return new WP_REST_Response( $races, 200 );
}

/**
 * Callback for POST /rm/v1/races: creates a race on purpose, from the event.
 *
 * The body is the event the upload sends. A new race gets its files right away, because a race
 * without them shows up empty in every listing. A title another race has is refused with 409 and
 * that race's ID: two races of one name are what D3 in the RotorHazard plugin's roadmap was about,
 * and the organiser can choose the one that exists.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function rm_handle_create_race( WP_REST_Request $request ) {
    $data = rm_validate_and_decode_json( $request );
    if ( is_wp_error( $data ) ) {
        return new WP_REST_Response( [
            'status'  => 'error',
            'message' => $data->get_error_message(),
        ], $data->get_error_data() ?: 400 );
    }

    $maybe_error = rm_validate_required_fields( $data );
    if ( is_wp_error( $maybe_error ) ) {
        return new WP_REST_Response( [
            'status'  => 'error',
            'message' => $maybe_error->get_error_message(),
        ], 400 );
    }

    // Races may be created by other accounts, and one the user may not edit is left out of
    // GET /races: the timer cannot choose it. So the answer says which kind of race it is.
    $existing = rm_find_race_by_title( sanitize_text_field( $data['race_name'] ) );
    if ( $existing ) {
        $editable = current_user_can( 'edit_post', $existing );
        return rm_race_error_response( new WP_Error(
            'race_exists',
            $editable
                ? __( 'There is a race with this title already.', 'wp-racemanager' )
                : __( 'There is a race with this title already, and you may not edit it.', 'wp-racemanager' ),
            array( 'status' => 409, 'id' => $existing, 'editable' => $editable )
        ) );
    }

    $race_result = rm_create_race( $data );
    if ( is_wp_error( $race_result ) ) {
        return rm_race_error_response( $race_result );
    }

    return new WP_REST_Response( [
        'status'  => 'success',
        'message' => $race_result['message'],
        'id'      => $race_result['id'],
    ], 201 );
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
 * Finds an existing Race CPT (by title=race_name) or creates a new one -- what an upload without
 * race_id does. Kept for one release, for timers with an older plugin; a timer that names its
 * race goes through rm_update_race() alone. See race-selection.md in the RotorHazard plugin's
 * repository.
 * Returns array on success: ['status' => 'updated'|'success', 'id' => (race_id), 'message' => '...']
 * Returns WP_Error on failure.
 */
function rm_find_or_create_race( $data ) {
    $existing = rm_find_race_by_title( sanitize_text_field( $data['race_name'] ) );
    if ( $existing ) {
        return rm_update_race( $existing, $data );
    }
    return rm_create_race( $data );
}

/**
 * The race titled $title, in any status but the bin: its ID, or 0. What an upload by title
 * updates, and what makes POST /races refuse -- one lookup, so both agree on what "the same
 * title" is. WP_Query compares post_title in SQL, so the column's collation decides about case.
 */
function rm_find_race_by_title( $title ) {
    $query = new WP_Query( [
        'post_type'      => 'race',
        'post_status'    => 'any',
        'title'          => $title,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );
    return $query->have_posts() ? (int) $query->posts[0] : 0;
}

/**
 * Writes an upload into an existing race, if the current user may edit it and it is flagged live.
 * Never creates one. Returns ['status' => 'updated', 'id', 'message'], or a WP_Error that carries
 * the HTTP status in its data, and for a locked race the race's ID.
 */
function rm_update_race( $race_id, $data ) {
    if ( 'race' !== get_post_type( $race_id ) || 'trash' === get_post_status( $race_id ) ) {
        return new WP_Error(
            'not_found',
            __( 'There is no race with this ID.', 'wp-racemanager' ),
            array( 'status' => 404 )
        );
    }

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
            array( 'status' => 400, 'id' => $race_id )
        );
    }

    $written = rm_write_files( $race_id, $data );
    if ( is_wp_error( $written ) ) {
        return $written;
    }
    update_post_meta( $race_id, '_race_last_upload', current_time( 'mysql' ) );

    return [
        'status'  => 'updated',
        'id'      => $race_id,
        'message' => 'Event updated successfully',
    ];
}

/**
 * Creates a race from an upload -- the post, its files, flagged live, with placeholder times --
 * if the current user may publish. What an upload without race_id does for an unknown title, and
 * what POST /races does on purpose. Returns ['status' => 'success', 'id', 'message'], or a
 * WP_Error that carries the HTTP status in its data.
 */
function rm_create_race( $data ) {
    $race_name        = sanitize_text_field( $data['race_name'] );
    $race_description = isset( $data['race_description'] )
        ? sanitize_textarea_field( $data['race_description'] )
        : '';
    $timestamp        = current_time( 'mysql' );

    if ( ! current_user_can( 'publish_posts' ) ) {
        return new WP_Error(
            'forbidden',
            __( 'Wrong user. You do not have permission to create a race.', 'wp-racemanager' ),
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
            <!-- wp:social-link {"url":"https://www.youtube.com/channel/UClUCsP1HXndOxwIshO17RNA","service":"youtube"} /-->
            <!-- wp:social-link {"url":"https://www.instagram.com/rotormaniacs/","service":"instagram"} /-->
            <!-- wp:social-link {"url":"https://discord.gg/NCKQwhw62e","service":"discord"} /--></ul>
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
            array( 'status' => 500 )
        );
    }

    $written = rm_write_files( $race_id, $data, 1 );
    if ( is_wp_error( $written ) ) {
        // The post exists but carries no data, so it would show up empty in every
        // listing. Remove it again and report the failure.
        wp_delete_post( $race_id, true );
        return $written;
    }

    update_post_meta( $race_id, '_race_live', 1 );
    update_post_meta( $race_id, '_race_last_upload', $timestamp );
    update_post_meta( $race_id, '_race_reg_closed', true );

    // Placeholder times the organiser is expected to correct in the backend. Stored in
    // the canonical format so they do not cast to NULL in the date queries.
    update_post_meta( $race_id, '_race_event_start', rm_normalize_event_datetime( strtotime( 'today 8:00' ) ) );
    update_post_meta( $race_id, '_race_event_end', rm_normalize_event_datetime( strtotime( 'today 19:00' ) ) );

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

    // The same rows the admin list shows, pilot_key included: the identity RotorHazard matches
    // a returning pilot by (docs/pilot-identity.md).
    return rest_ensure_response( rm_registration_rows( $results ) );
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
    $upload_path = rm_get_race_data_dir( false );
    if ( is_wp_error( $upload_path ) ) {
        return new \WP_REST_Response( [ 'error' => $upload_path->get_error_message() ], 500 );
    }
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
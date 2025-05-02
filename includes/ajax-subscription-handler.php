<?php
// includes/ajax-subscription-handler.php
// Handles all subscription-related AJAX requests for the RaceManager plugin.
// This file is included from the main plugin file (wp-racemanager.php).

add_action( 'init', 'register_ajax_handlers' );
/**
 * Register AJAX handlers instead of REST routes.
 */
function register_ajax_handlers() {
        add_action( 'wp_ajax_get_subscription', 'ajax_get_subscription' );
        add_action( 'wp_ajax_nopriv_get_subscription', 'ajax_get_subscription' );

        add_action( 'wp_ajax_update_subscription', 'ajax_update_subscription' );
        add_action( 'wp_ajax_nopriv_update_subscription', 'ajax_update_subscription');

        add_action( 'wp_ajax_unsubscribe', 'ajax_unsubscribe' );
        add_action( 'wp_ajax_nopriv_unsubscribe', 'ajax_unsubscribe' );
}

/**
 * AJAX callback: Get subscription status.
 * Expects a POST parameter "endpoint"
 */
function ajax_get_subscription() {
    // This will automatically verify the _ajax_nonce in $_POST.
    check_ajax_referer( 'rm_ajax_nonce' );

    // Validate input.
    if ( empty( $_POST['endpoint'] ) ) {
        wp_send_json_error( [ 'message' => 'Missing required field: endpoint.' ], 400 );
        wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
    }

    $endpoint = sanitize_text_field( wp_unslash( $_POST['endpoint'] ) );

    // Retrieve the subscription from the database
    $subscription = rm_get_subscription_by_endpoint( $endpoint );
    if ( $subscription ) {
        wp_send_json_success( [
            'subscribed' => true,
            'race_id'    => $subscription->race_id,
            'race_title' => get_the_title( $subscription->race_id ),
            'pilot_id'   => $subscription->pilot_id,
            'pilot_callsign' => $subscription->pilot_callsign,
        ], 200 );
    } else {
        wp_send_json_success( [ 
            'subscribed' => false,
            'race_id'    => 0,
            'race_title' => '',
            'pilot_id'   => 0,
            'pilot_callsign' => '',
        ], 200 );
    }

    wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
}

/**
 * AJAX callback: Insert or update a subscription.
 * Expects POST parameters: "race_id", "pilot_id", "endpoint", and (optionally) "keys"
 */
function ajax_update_subscription() {
    check_ajax_referer( 'rm_ajax_nonce' );

    if ( empty( $_POST['race_id'] ) || empty( $_POST['endpoint'] ) ) {
        wp_send_json_error( [ 'error' => 'Missing required fields: race_id and endpoint.' ], 400 );
        wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
    }

    if ( empty( $_POST['pilot_id'] ) || empty( $_POST['pilot_callsign'] ) ) {
        wp_send_json_error( [ 'error' => 'Missing required field: pilot_id or pilot_callsign.' ], 400 );
        wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
    }

    $race_id   = absint( wp_unslash( $_POST['race_id'] ) );
    $endpoint  = sanitize_text_field( wp_unslash( $_POST['endpoint'] ) );
    $pilot_id  = sanitize_text_field( wp_unslash( $_POST['pilot_id'] ) );
    $pilot_callsign = sanitize_text_field( wp_unslash( $_POST['pilot_callsign'] ) );
    $race_title = get_the_title( $race_id );

    // Optional keys.
    $keys    = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? wp_unslash( $_POST['keys'] ) : [];
    $p256dh  = isset( $keys['p256dh'] ) ? sanitize_text_field( $keys['p256dh'] ) : '';
    $auth    = isset( $keys['auth'] )   ? sanitize_text_field( $keys['auth'] )   : '';

    if ( empty( $p256dh ) || empty( $auth ) ) {
        wp_send_json_error( [ 'error' => 'Missing required keys: p256dh or auth.' ], 400 );
        wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
    }

    // Insert or update subscription in the DB.
    $result = rm_upsert_subscription( $race_id, $pilot_id, $pilot_callsign, $endpoint, $p256dh, $auth );
    if ( false === $result ) {
        wp_send_json_error( [ 'success' => false, 'message' => 'Failed to insert/update subscription.' ], 500 );
        wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
    }

    $manager = \RaceManager\WP_RaceManager::instance();

    // Ensure the subscription handler is available.
    if ( empty( $manager->pwa_subscription_handler ) ) {
        // Maybe just bail out silently if there's no subscription system loaded
        // Load instantiate PWA_Subscription_Handler.
        require_once plugin_dir_path( __DIR__ ) . 'includes/pwa-subscription-handler.php';
        // PWA class registers its rest routes in the constructor
        $manager->pwa_subscription_handler = new \RaceManager\PWA_Subscription_Handler();
        //return;
    }
    $pwa = $manager->pwa_subscription_handler;

    // Optional: send a notification for debugging.
    //$pwa->send_notification_to_all_in_race( $race_id, 'Subscription', 'Welcome to Rotormaniacs RaceManager!' );
    $pwa->send_notification_to_subscriber( $endpoint, $p256dh, $auth, 'Subscription', 'You have successfully subscribed to: ' . $race_title );

    //wp_send_json_success( [ 'message' => 'Subscription inserted/updated successfully.' ], 200 );
    wp_send_json_success( [
        'subscribed' => true,
        'race_id'    => $race_id,
        'race_title' => $race_title,
        'pilot_id'   => $pilot_id,
        'pilot_callsign' => $pilot_callsign,
    ], 200 );

    wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
}

/**
 * AJAX callback: Delete a subscription (unsubscribe).
 * Expects POST parameter "endpoint"
 */
function ajax_unsubscribe() {
    check_ajax_referer( 'rm_ajax_nonce' );

    if ( empty( $_POST['endpoint'] ) ) {
        wp_send_json_error( [ 'error' => 'Missing required field: endpoint.' ], 400 );
        wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
    }

    $endpoint = sanitize_text_field( wp_unslash( $_POST['endpoint'] ) );

    $deleted = rm_delete_subscription( $endpoint );
    if ( false === $deleted ) {
        wp_send_json_error( [ 'success' => false, 'message' => 'Failed to remove subscription.' ], 500 );
        wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
    }

    wp_send_json_success( [
        'subscribed' => false,
        'race_id'    => 0,
        'race_title' => '',
        'pilot_id'   => 0,
        'pilot_callsign' => '',
    ], 200 );
    
    wp_die(); // Always call wp_die() at the end of an AJAX request to prevent further output.
}
<?php
// includes/db-handler.php
// Query registered pilots from the registrations_table
if (!defined('ABSPATH')) exit; // Exit if accessed directly

function rm_get_registered_callsigns( $race_id ) {
    // API key authentication

    global $wpdb;

    //$race_id = sanitize_text_field($race_id);
    $race_id=intval($race_id);
    if(get_post_type($race_id) != 'race') {
        return 'Invalid Race ID';
    }

    $registrations_table = $wpdb->prefix . 'rm_registrations'; // cfdb7 table name holds all form replies

    // Query the cfdb7 table for entries matching the race_id
    $query = $wpdb->prepare(
        "SELECT form_value, form_date FROM $registrations_table WHERE race_id = %d",
        $race_id
    );
    $results = $wpdb->get_results( $query );
    
    $nicknames = null;
    $callsign_field = get_option('rm_callsign_field', 'pilot_callsign');

    // Process each submission and extract the 'pilot_nickname_1' field.
    if ( $results ) {
        $nicknames = array();
        foreach ( $results as $row ) {
            // Unserialize the data (it’s stored as a serialized array)
            $data = maybe_unserialize( $row->form_value );
            // TODO: make name of the field configurable in settings
            if ( isset( $data[$callsign_field] ) ) {
                $nicknames[] = $data[$callsign_field];
            }
        }
        return $nicknames;
    }
    else {
        // If no results are found, return an empty array or a message.
        return 'No pilots registered yet.';
    }    
}

/**
 * Retrieve all subscriptions for a given race_id.
 */
function rm_get_subscriptions( $race_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'rm_subscriptions';

    $sql = $wpdb->prepare(
        "SELECT * FROM $table WHERE race_id = %d",
        $race_id
    );

    return $wpdb->get_results( $sql, ARRAY_A );
}

/**
 * Retrieve a subscription record by its endpoint.
 *
 * @param string $endpoint The push subscription endpoint.
 * @return object|null The subscription record object if found, or null if not.
 */
function rm_get_subscription_by_endpoint( $endpoint ) {
    global $wpdb;
    $table = $wpdb->prefix . 'rm_subscriptions';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM $table WHERE endpoint = %s LIMIT 1",
            $endpoint
        )
    );
}

function rm_upsert_subscription( $race_id, $pilot_id, $pilot_callsign, $endpoint, $p256dh, $auth ) {
    global $wpdb;
    $table = $wpdb->prefix . 'rm_subscriptions';

    // Check if subscription already exists for (race_id, endpoint).
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id FROM $table WHERE endpoint = %s LIMIT 1",
            $endpoint
        )
    );

    $data = [
        'race_id'        => $race_id,
        'pilot_id'       => $pilot_id,
        'pilot_callsign' => $pilot_callsign,
        'endpoint'       => $endpoint,
        'p256dh_key'     => $p256dh,
        'auth_key'       => $auth,
        'updated_at'     => current_time( 'mysql' ),
    ];

    if ( $existing ) {
        // Update existing
        return $wpdb->update( $table, $data, [ 'id' => $existing->id ] );
    }

    // Insert new
    $data['created_at'] = current_time( 'mysql' );
    return $wpdb->insert( $table, $data );
}    

function rm_delete_subscription( $endpoint ) {
    // remove individual subscription
    global $wpdb;
    $table = $wpdb->prefix . 'rm_subscriptions';

    return $wpdb->delete(
        $table,
        [ 'endpoint' => $endpoint ],
        [ '%s' ]
    );
}

function rm_delete_all_race_subscriptions( $race_id) {
    // remove all subscriptions for a race_id
    global $wpdb;
    $table = $wpdb->prefix . 'rm_subscriptions';

    return $wpdb->delete(
        $table,
        [ 'race_id' => $race_id ],
        [ '%d' ]
    );
}
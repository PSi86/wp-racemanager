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

    // Query the cfdb7 table for entries matching the race_id, in the order they came
    $query = $wpdb->prepare(
        "SELECT form_value, form_date FROM $registrations_table WHERE race_id = %d ORDER BY id",
        $race_id
    );
    $results = $wpdb->get_results( $query );

    $nicknames = null;
    $callsign_field = get_option('rm_callsign_field', 'pilot_callsign');

    // Process each submission and extract the 'pilot_nickname_1' field.
    if ( $results ) {
        $nicknames = array();
        // One line per pilot, as RotorHazard imports them: the registrations of one address are one
        // pilot, at the place of the first, under the callsign sent last. The form refuses a second
        // one since 1.19.1; a race registered twice before keeps both rows in the admin list.
        $places = array();
        foreach ( $results as $row ) {
            // Unserialize the data (it’s stored as a serialized array)
            $data = maybe_unserialize( $row->form_value );
            if ( ! is_array( $data ) || ! isset( $data[$callsign_field] ) ) {
                continue;
            }
            $address = rm_pilot_address( $data['pilot_mail_1'] ?? '' );
            if ( '' !== $address && isset( $places[ $address ] ) ) {
                $nicknames[ $places[ $address ] ] = $data[$callsign_field];
                continue;
            }
            if ( '' !== $address ) {
                $places[ $address ] = count( $nicknames );
            }
            $nicknames[] = $data[$callsign_field];
        }
        return $nicknames;
    }
    else {
        // If no results are found, return an empty array or a message.
        return 'No pilots registered yet.';
    }    
}

/**
 * The subscriptions table's schema: 2 has pilot_key (1.7.0), 3 has channel, the channel a
 * subscription was told last (1.13.0).
 */
const RM_SUBSCRIPTIONS_SCHEMA = 3;

/**
 * Bring the subscriptions table up to date after an update.
 *
 * The activation hook creates the table, but a ZIP replace does not run that hook
 * (docs/deployment.md, section 6): a column added in a release would be missing until the plugin
 * was deactivated and activated again, and every subscription failed to save meanwhile. So the
 * first request after an update runs dbDelta() once, on plugins_loaded, the way WordPress's plugin
 * handbook does it for a plugin's tables. Every other request reads one autoloaded option.
 *
 * @return void
 */
function rm_maybe_upgrade_subscriptions_table() {
    if ( (int) get_option( 'rm_subscriptions_schema', 0 ) >= RM_SUBSCRIPTIONS_SCHEMA ) {
        return;
    }
    require_once __DIR__ . '/pwa-subscription-handler.php';
    \RaceManager\PWA_Subscription_Handler::create_db_table();

    // Recorded only once the newest column is there, so a failed ALTER is tried again. Until then
    // the push handler writes no channel (includes/pwa-subscription-handler.php).
    global $wpdb;
    $table = $wpdb->prefix . 'rm_subscriptions';
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table LIKE %s", 'channel' ) ) ) {
        update_option( 'rm_subscriptions_schema', RM_SUBSCRIPTIONS_SCHEMA );
    }
}
add_action( 'plugins_loaded', 'rm_maybe_upgrade_subscriptions_table' );

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

/**
 * Store a browser's subscription to a pilot of a race, or move it to another.
 *
 * A browser has one subscription, found by its endpoint, whatever it follows. Moved to another
 * pilot or race - the live pages' "Update Subscription" - it starts its schedule over (1.13.1): the
 * heat, slot and channel it stored are what the pilot before was told, and the next upload compared
 * the new pilot's heats with them. Measured on the local site with 1.13.0: the new pilot's follower
 * was told "Reassigned to Heat 7" instead of "Next race is Heat 7", "Channel changed" for a heat
 * never announced, or "You have been removed from your scheduled heat" of the pilot before.
 *
 * @param string $pilot_key The pilot's key where the race data has one (rm_valid_pilot_key()): the
 *                          subscription follows it when the timer re-creates its pilots.
 */
function rm_upsert_subscription( $race_id, $pilot_id, $pilot_callsign, $endpoint, $p256dh, $auth, $pilot_key = '' ) {
    global $wpdb;
    $table = $wpdb->prefix . 'rm_subscriptions';

    // The browser's subscription, whatever race and pilot it follows.
    $existing = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT id, race_id, pilot_id, pilot_key FROM $table WHERE endpoint = %s LIMIT 1",
            $endpoint
        )
    );

    $data = [
        'race_id'        => $race_id,
        'pilot_id'       => $pilot_id,
        'pilot_callsign' => $pilot_callsign,
        'pilot_key'      => $pilot_key,
        'endpoint'       => $endpoint,
        'p256dh_key'     => $p256dh,
        'auth_key'       => $auth,
        'updated_at'     => current_time( 'mysql' ),
    ];

    if ( $existing ) {
        if ( ! rm_subscription_follows( $existing, $race_id, $pilot_id, $pilot_key ) ) {
            // Another pilot or race: nothing told yet, as for a new subscription. The next upload
            // then says "Next race is ..." for the pilot followed now.
            $data['heat_id']          = 0;
            $data['slot_id']          = 0;
            $data['heat_displayname'] = '';
            if ( (int) get_option( 'rm_subscriptions_schema', 0 ) >= 3 ) {
                $data['channel'] = null; // the column exists from schema 3 on
            }
        }
        return $wpdb->update( $table, $data, [ 'id' => $existing->id ] );
    }

    // Insert new
    $data['created_at'] = current_time( 'mysql' );
    return $wpdb->insert( $table, $data );
}

/**
 * Whether a stored subscription follows this pilot of this race already: the same race, and the
 * same pilot - by the pilot key when both have one, since the timer gives re-created pilots new
 * IDs, else by ID. js/rm-m-pwa-subscribe.js's isSamePilot() decides it alike for its button.
 *
 * @param object     $row       The subscription's race_id, pilot_id and pilot_key.
 * @param int|string $race_id   The race.
 * @param int|string $pilot_id  The pilot's ID on the timer.
 * @param string     $pilot_key The pilot's key, or ''.
 * @return bool
 */
function rm_subscription_follows( $row, $race_id, $pilot_id, $pilot_key ) {
    if ( (int) $row->race_id !== (int) $race_id ) {
        return false;
    }
    // Lower case and trimmed, as the push handler reads a stored key: older rows may hold capitals.
    $stored = strtolower( trim( (string) ( $row->pilot_key ?? '' ) ) );
    $given  = strtolower( trim( (string) $pilot_key ) );
    if ( '' !== $stored && '' !== $given ) {
        return $stored === $given;
    }
    return (int) $row->pilot_id === (int) $pilot_id;
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
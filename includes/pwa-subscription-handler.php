<?php
namespace RaceManager;
require_once __DIR__ . '/../../../../../vendor/autoload.php'; // Relative path to the vendor directory (currently in root of httpdocs)
//require_once dirname(ABSPATH) . '/../vendor/autoload.php';
//require_once '/var/www/vhosts/wherever-we-are.com/httpdocs/vendor/autoload.php'; // Relative path to the vendor directory (currently in root of httpdocs)

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

defined( 'ABSPATH' ) || exit;

class PWA_Subscription_Handler {

    // VAPID authentication details – replace these with your own keys and contact
    private $vapid = [
        'subject' => 'mailto:',  // Can be a mailto: or your website address
        'publicKey' => '',  // Replace with your public key
        'privateKey' => '' // Replace with your private key
    ];

    public function __construct() {
        //add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        $this->register_rest_routes();
    }

    /**
     * Creates/updates the custom DB table for storing subscriptions.
     * Call on plugin activation.
     */
    public static function create_db_table() {
        global $wpdb;
    
        $table_name      = $wpdb->prefix . 'race_subscriptions';
        $charset_collate = $wpdb->get_charset_collate();
    
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        race_id bigint(20) unsigned NOT NULL,
        pilot_id int(20) unsigned NOT NULL,
        heat_id int(20) unsigned NOT NULL DEFAULT 0,
        slot_id int(20) unsigned NOT NULL DEFAULT 0,
        endpoint text NOT NULL,
        p256dh_key text DEFAULT '' NOT NULL,
        auth_key text DEFAULT '' NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY race_endpoint_unique (race_id, endpoint(191))
    ) $charset_collate;";
    
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
    

    /**
     * Registers only the subscription and notify-racers endpoints.
     */
    public function register_rest_routes() {
        // Insert or update a subscription
        register_rest_route(
            'rm/v1',
            '/subscription',
            [
                'methods'  => 'POST',
                'callback' => [ $this, 'handle_subscription' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ]
        );

        // Update a subscription
        register_rest_route(
            'rm/v1',
            '/subscription',
            [
                'methods'  => 'PUT',
                'callback' => [ $this, 'handle_subscription' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ]
        );

        // Delete a subscription
        register_rest_route(
            'rm/v1',
            '/subscription',
            [
                'methods'  => 'DELETE',
                'callback' => [ $this, 'remove_subscription' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ]
        );

        // Send notifications to all in a race
        register_rest_route(
            'rm/v1',
            '/notify-racers',
            [
                'methods'  => 'POST',
                'callback' => [ $this, 'handle_notification_request' ],
                'permission_callback' => [ $this, 'permission_check' ],
            ]
        );
    }

    /**
     * Basic permission check. Adjust if you want admin-only, etc.
     */
    public function permission_check( \WP_REST_Request $request ) {
        return true;
    }

    /**
     * Handle creation or update of a subscription (POST, PUT).
     * Expects JSON with:
     * {
     *   "race_id": 123,
     *   "pilot_id": "...",
     *   "endpoint": "...",
     *   "keys": { "p256dh": "...", "auth": "..." }
     * }
     */
    public function handle_subscription( \WP_REST_Request $request ) {
        $body = json_decode( $request->get_body(), true );
    
        if ( empty( $body['race_id'] ) || empty( $body['endpoint'] ) ) {
            return new \WP_REST_Response(
                [ 'error' => 'Missing required fields: race_id and endpoint.' ],
                400
            );
        }
    
        if ( empty( $body['pilot_id'] ) ) {
            return new \WP_REST_Response(
                [ 'error' => 'Missing required field: pilot_id.' ],
                400
            );
        }
    
        $race_id        = absint( $body['race_id'] );
        $endpoint       = sanitize_text_field( $body['endpoint'] );
        $pilot_id = sanitize_text_field( $body['pilot_id'] );
    
        // keys may be optional
        $keys     = ( isset( $body['keys'] ) && is_array( $body['keys'] ) ) ? $body['keys'] : [];
        $p256dh   = isset( $keys['p256dh'] ) ? sanitize_text_field( $keys['p256dh'] ) : '';
        $auth     = isset( $keys['auth'] )   ? sanitize_text_field( $keys['auth'] )   : '';
    
        // Insert or update subscription in DB
        $result = $this->upsert_subscription( $race_id, $pilot_id, $endpoint, $p256dh, $auth );
        if ( false === $result ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'message' => 'Failed to insert/update subscription.' ],
                500
            );
        }
        $this->send_notification_to_all_in_race( $race_id, 'Race Update', 'Welcome to Rotormaniacs RaceManager!' ); // For debugging
        return new \WP_REST_Response(
            [ 'success' => true, 'message' => 'Subscription inserted/updated successfully.' ],
            200
        );
    }
    

    /**
     * Handle deletion of a subscription (DELETE).
     * Expects JSON with:
     * {
     *   "race_id": 123,
     *   "endpoint": "..."
     * }
     */
    public function remove_subscription( \WP_REST_Request $request ) {
        $body = json_decode( $request->get_body(), true );
        //if ( empty( $body['race_id'] ) || empty( $body['endpoint'] ) ) {
        if ( empty( $body['endpoint'] ) ) {
            return new \WP_REST_Response(
                [ 'error' => 'Missing required fields: endpoint.' ],
                400
            );
        }

        //$race_id  = absint( $body['race_id'] );
        $endpoint = sanitize_text_field( $body['endpoint'] );

        $deleted = $this->delete_subscription( $endpoint );
        if ( false === $deleted ) {
            return new \WP_REST_Response(
                [ 'success' => false, 'message' => 'Failed to remove subscription.' ],
                500
            );
        }

        return new \WP_REST_Response(
            [ 'success' => true, 'message' => 'Subscription removed successfully.' ],
            200
        );
    }

    /**
     * Handle notification requests from RotorHazard
     * Sends notifications to all subscribers in a race.
     * Expects JSON with:
     * {   
     *  "race_id": 123,
     * }
     */
    public function handle_notification_request( \WP_REST_Request $request ) {
        // 1. Validate API Key
        $maybe_error = rm_validate_api_key( $request );
        if ( is_wp_error( $maybe_error ) ) {
            return new \WP_REST_Response([
                'status'  => 'error',
                'message' => $maybe_error->get_error_message(),
            ], $maybe_error->get_error_data() ?: 401);
        }
        
        $body = json_decode( $request->get_body(), true );
    
        if ( empty( $body['race_id'] ) || empty( $body['msg_title'] ) || empty( $body['msg_body'] ) ) {
            return new \WP_REST_Response(
                [ 'error' => 'Missing required field.' ],
                400
            );
        }
        
        $race_id    = absint( $body['race_id'] );
        $msg_title  = sanitize_text_field( $body['msg_title'] );
        $msg_body   = sanitize_text_field( $body['msg_body'] );
    
        $this->send_notification_to_all_in_race( $race_id, $msg_title, $msg_body );

        return new \WP_REST_Response(
            [ 'success' => true, 'message' => 'Subscription inserted/updated successfully.' ],
            200
        );
    }
    /**
     * Public method to send notifications for a given race_id.
     * Called internally from your plugin's other code (not via REST).
     * TODO: could be called from rotorhazard to send notifications to all pilots in the event
     */
    public function send_notification_to_all_in_race( $race_id, $title = 'Race Update', $message = 'Hello from WP RaceManager!' ) {
        $race_id = absint( $race_id );
        if ( ! $race_id ) {
            // For safety, do a no-op or throw an error
            return false;
        }

        $subscriptions = $this->get_subscriptions( $race_id );
        if ( empty( $subscriptions ) ) {
            return false;
        }

        // Holy shit! This cost a whole day. was: $webPush = new WebPush($vapid);
        $webPush = new WebPush(['VAPID' => $this->vapid]); // $auth is your server/VAPID keys

        foreach ( $subscriptions as $sub ) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh_key'],
                'authToken' => $sub['auth_key'],
            ]);
            $payload = json_encode([
                'title' => $title,
                'body'  => $message,
            ]);
            //$webPush->sendOneNotification($subscription, $payload);
            $webPush->queueNotification($subscription, $payload);
        }
        $report = $webPush->flush();
        // handle eventual errors here, and remove the subscription from your server if it is expired
        foreach ($report as $result) {
            $endpoint = $result->getRequest()->getUri()->__toString();
            if ($result->isSuccess()) {
                WP_RaceManager::write_log('Notification sent successfully to: ' . $endpoint);
            } else {
                if(strpos($result->getReason(), '410') !== false) {
                    $this->delete_subscription($endpoint);
                    WP_RaceManager::write_log('Subscription expired and removed: ' . $endpoint);
                }
                else {
                    WP_RaceManager::write_log('Notification failed to send to: ' . $endpoint . ' with reason: ' . $result->getReason());
                }
            }
        }

        return true; // Indicate success
    }

    /**
     * Send notifications to push subscribers when a pilot’s upcoming race schedule changes.
     *
     * This function takes the current race_id and the upcoming pilots list (each element contains:
     * heat_id, heat_displayname, pilot_id, callsign, slot_id, and channel). It retrieves all subscriptions for
     * the given race_id from the race_subscriptions table, compares the stored heat_id and slot_id for
     * each pilot with the new data, and sends a push notification with a precise message if needed.
     *
     * The notification messages are:
     *   - For a new schedule: "[callsign]: Your next race is [heat_displayname]. Your channel is [channel]"
     *   - For a change in channel or race: "[callsign]: Your channel in race [heat_displayname] has changed to [channel]"
     *   - For removal: "You have been removed from your scheduled heat."
     *
     * After sending a notification, the subscriber’s record is updated accordingly.
     *
     * TODO: Reduce data overhead: if multiple clients subscribe to the same pilot, the heat and slot data is redundantly stored in each subscription.
     * 
     * @param int   $race_id         The current race (or heat) ID.
     * @param array $upcomingPilots  Array of upcoming pilot entries from getUpcomingRacePilots().
     * @return array List of pilot_ids for which notifications were sent.
     */
    public function send_next_up_notifications($race_id, $upcomingPilots) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'race_subscriptions';

        // Build a mapping of upcoming pilots keyed by pilot_id.
        // If a pilot appears more than once, use the entry with the highest heat_id.
        $upcomingMapping = array();
        foreach ($upcomingPilots as $entry) {
            $pilotId = $entry['pilot_id'];
            if (!isset($upcomingMapping[$pilotId]) || $entry['heat_id'] > $upcomingMapping[$pilotId]['heat_id']) {
                $upcomingMapping[$pilotId] = $entry;
            }
        }

        // Retrieve all subscriber records for the given race_id with a valid pilot_id.
        //$query = $wpdb->prepare("SELECT * FROM $table_name WHERE race_id = %d AND pilot_id != 0", $race_id);
        //$subscribers = $wpdb->get_results($query, ARRAY_A);
        $subscribers = $this->get_subscriptions( $race_id );
        if ( empty( $subscribers ) ) {
            return false; // return true? TODO: test this
        }

        $webPush = new WebPush(['VAPID' => $this->vapid]); // $auth is your server/VAPID keys

        $notifiedPilotIds = array();

        foreach ($subscribers as $subscriber) {
            $pilotId    = $subscriber['pilot_id'];
            $storedHeat = isset($subscriber['heat_id']) ? (int)$subscriber['heat_id'] : 0;
            $storedSlot = isset($subscriber['slot_id']) ? (int)$subscriber['slot_id'] : 0;

            if (isset($upcomingMapping[$pilotId])) {
                // Pilot appears in the upcoming list.
                $newEntry = $upcomingMapping[$pilotId];
                $newHeat   = (int)$newEntry['heat_id'];
                $newSlot   = (int)$newEntry['slot_id'];
                $channel   = $newEntry['channel'];
                $callsign  = $newEntry['callsign'];
                $heatDisplay = $newEntry['heat_displayname'];

                if ($storedHeat === 0 && $storedSlot === 0) {
                    // New schedule.
                    $message = "{$callsign}: Your next race is {$heatDisplay}. Your channel is {$channel}";
                } elseif ($storedHeat === $newHeat && $storedSlot !== $newSlot) {
                    // Slot changed.
                    $message = "{$callsign}: Your channel in race {$heatDisplay} has changed to {$channel}";
                } elseif ($storedHeat !== $newHeat && $storedSlot === $newSlot) {
                    // Heat changed.
                    $message = "{$callsign}: You have been reassigned to {$heatDisplay} your channel remains {$channel}";
                } elseif ($storedHeat !== $newHeat && $storedSlot !== $newSlot) {
                    // Heat and slot changed.
                    $message = "{$callsign}: You have been reassigned to {$heatDisplay} your new channel is {$channel}";
                } else {
                    // No change; no notification needed.
                    continue;
                }
                
                $subscription = Subscription::create([
                    'endpoint' => $subscriber['endpoint'],
                    'publicKey' => $subscriber['p256dh_key'],
                    'authToken' => $subscriber['auth_key'],
                ]);

                $title = 'Race Update';

                $payload = json_encode([
                    'title' => $title,
                    'body'  => $message,
                ]);
                $webPush->queueNotification($subscription, $payload);
                
                $this->sendPushNotificationForSubscriber($subscriber, $message); // Mock function with log output
                
                $notifiedPilotIds[] = $pilotId;

                // Update the subscriber record with the new heat and slot.
                $wpdb->update(
                    $table_name,
                    array(
                        'heat_id' => $newHeat,
                        'slot_id' => $newSlot
                    ),
                    array('id' => $subscriber['id']),
                    array('%d', '%d'),
                    array('%d')
                );
            } else {
                // Pilot no longer appears in the upcoming list.
                // Check if his previously scheduled heat still exists in upcomingPilots.
                $heatStillExists = false;
                foreach ($upcomingPilots as $entry) {
                    if ((int)$entry['heat_id'] === $storedHeat) {
                        $heatStillExists = true;
                        break;
                    }
                }
                if ($heatStillExists && $storedHeat != 0) {
                    $message = "You have been removed from your scheduled heat.";
                    
                    $subscription = Subscription::create([
                        'endpoint' => $subscriber['endpoint'],
                        'publicKey' => $subscriber['p256dh_key'],
                        'authToken' => $subscriber['auth_key'],
                    ]);
                    
                    $title = 'Race Update';
    
                    $payload = json_encode([
                        'title' => $title,
                        'body'  => $message,
                    ]);
                    //$webPush->sendOneNotification($subscription, $payload);
                    $webPush->queueNotification($subscription, $payload);

                    $this->sendPushNotificationForSubscriber($subscriber, $message); // Mock function with log output
                    
                    $notifiedPilotIds[] = $pilotId;

                    // Clear out the stored heat and slot.
                    $wpdb->update(
                        $table_name,
                        array(
                            'heat_id' => 0,
                            'slot_id' => 0
                        ),
                        array('id' => $subscriber['id']),
                        array('%d', '%d'),
                        array('%d')
                    );
                }
            }
        }
        // Send all queued notifications.
        $report = $webPush->flush();
        // handle eventual errors here, and remove the subscription from your server if it is expired
        foreach ($report as $result) {
            $endpoint = $result->getRequest()->getUri()->__toString();
            if ($result->isSuccess()) {
                WP_RaceManager::write_log('Notification sent successfully to: ' . $endpoint);
            } else {
                if(strpos($result->getReason(), '410') !== false) {
                    $this->delete_subscription($endpoint);
                    WP_RaceManager::write_log('Subscription expired and removed: ' . $endpoint);
                }
                else {
                    WP_RaceManager::write_log('Notification failed to send to: ' . $endpoint . ' with reason: ' . $result->getReason());
                }
            }
        }

        return $notifiedPilotIds;
    }

    /**
     * Helper function to send a push notification for a given subscriber.
     *
     * @param array  $subscriber The subscriber record from the database.
     * @param string $message    The notification message.
     */
    private function sendPushNotificationForSubscriber($subscriber, $message) {
        // For demonstration, we log the notification.
        WP_RaceManager::write_log("Push notification for pilot {$subscriber['pilot_id']}: $message");
    }


    /**
     * Retrieve all subscriptions for a given race_id.
     */
    public function get_subscriptions( $race_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'race_subscriptions';

        $sql = $wpdb->prepare(
            "SELECT * FROM $table WHERE race_id = %d",
            $race_id
        );

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    // -------------------------------------------------------------------------
    // Internal DB Helpers
    // -------------------------------------------------------------------------

    private function upsert_subscription( $race_id, $pilot_id, $endpoint, $p256dh, $auth ) {
        global $wpdb;
        $table = $wpdb->prefix . 'race_subscriptions';
    
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

    private function delete_subscription( $endpoint ) {
        // remove individual subscription
        global $wpdb;
        $table = $wpdb->prefix . 'race_subscriptions';

        return $wpdb->delete(
            $table,
            [ 'endpoint' => $endpoint ],
            [ '%s' ]
        );
    }

    private function delete_all_race_subscriptions( $race_id) {
        // remove all subscriptions for a race_id
        global $wpdb;
        $table = $wpdb->prefix . 'race_subscriptions';

        return $wpdb->delete(
            $table,
            [ 'race_id' => $race_id ],
            [ '%d' ]
        );
    }
}

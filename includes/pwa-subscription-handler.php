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
        //$this->register_ajax_handlers();
    }

    /**
     * Creates/updates the custom DB table for storing subscriptions.
     * Call on plugin activation.
     */
    public static function create_db_table() {
        global $wpdb;
    
        $table_name      = $wpdb->prefix . 'rm_subscriptions';
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

        $subscriptions = rm_get_subscriptions( $race_id );
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
                    rm_delete_subscription($endpoint);
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
     * the given race_id from the rm_subscriptions table, compares the stored heat_id and slot_id for
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
        $table_name = $wpdb->prefix . 'rm_subscriptions';

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
        $subscribers = rm_get_subscriptions( $race_id );
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
                    rm_delete_subscription($endpoint);
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
}

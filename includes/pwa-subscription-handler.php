<?php
namespace RaceManager;

defined( 'ABSPATH' ) || exit;

// The Composer autoloader is located and required by rm_push_library_available(),
// which probes several known vendor locations instead of assuming a fixed relative
// path. Loading it here unconditionally used to fatal whenever the installation
// layout differed.
require_once __DIR__ . '/vapid-handler.php';
rm_push_library_available();
require_once __DIR__ . '/after-response.php';
require_once __DIR__ . '/pilot-key.php'; // rm_valid_pilot_key(), for which pilot a subscription follows

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

class PWA_Subscription_Handler {

    /**
     * Seconds one push may take in all, and to connect. Measured from a home line on 2026-09-11,
     * the push services answered in 0.04-0.57 s, and Apple's twice not within 20 s; without a
     * limit, one that hangs holds up every push after it.
     */
    const PUSH_TIMEOUT         = 10;
    const PUSH_CONNECT_TIMEOUT = 5;

    /**
     * How many pushes go out at once, when they can go out at once at all. Enough that a hall
     * full of followers is served in a few rounds; few enough that the web server does not open
     * hundreds of connections in one go.
     */
    const PUSH_BATCH = 50;

    /**
     * The channel told to a subscription from before 1.13.0 when its slot moved: one was told, and
     * which one is not known. No channel's name, so it never equals the channel of an upload.
     */
    const CHANNEL_NOT_KNOWN = '?';

    /**
     * How the column channel marks a channel told as likely (1.15.0): "R3?". A channel's name never
     * ends in it, and the mark alone is CHANNEL_NOT_KNOWN.
     */
    const LIKELY = '?';

    public function __construct() {
        //add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        //$this->register_ajax_handlers();
    }

    /**
     * VAPID credentials for the WebPush client.
     *
     * Read at call time from the constants or the 'rm_vapid' option, so rotating the
     * key pair never requires a code change. See includes/vapid-handler.php.
     *
     * @return array{subject: string, publicKey: string, privateKey: string}
     */
    private function get_vapid() {
        $vapid = rm_get_vapid();

        return [
            'subject'    => $vapid['subject'],
            'publicKey'  => $vapid['publicKey'],
            'privateKey' => $vapid['privateKey'],
        ];
    }

    /**
     * Creates/updates the custom DB table for storing subscriptions.
     * Call on plugin activation.
     *
     * The statement must say "CREATE TABLE", not "CREATE TABLE IF NOT EXISTS": dbDelta() reads
     * the table name with preg_match('|CREATE TABLE ([^ ]*)|') and would take it to be "IF",
     * then compare the wanted schema against a table of that name, find nothing, and never
     * issue a single ALTER. The table would still be created the first time -- MySQL handles
     * that -- but every later schema change would silently not apply.
     */
    public static function create_db_table() {
        global $wpdb;
    
        $table_name      = $wpdb->prefix . 'rm_subscriptions';
        $charset_collate = $wpdb->get_charset_collate();
    
        $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        race_id bigint(20) unsigned NOT NULL,
        pilot_id int(20) unsigned NOT NULL,
        pilot_callsign varchar(40) DEFAULT '' NOT NULL,
        pilot_key varchar(36) DEFAULT '' NOT NULL,
        heat_id int(20) unsigned NOT NULL DEFAULT 0,
        heat_displayname varchar(60) DEFAULT '' NOT NULL,
        slot_id int(20) unsigned NOT NULL DEFAULT 0,
        channel varchar(12) DEFAULT NULL,
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

        if ( ! rm_push_available() ) {
            WP_RaceManager::write_log( 'Push notification skipped: no VAPID keys or web-push library missing.' );
            return false;
        }

        list( $webPush, $pooled ) = $this->web_push();

        foreach ( $subscriptions as $sub ) {
            $subscription = Subscription::create([
                'endpoint' => $sub['endpoint'],
                'publicKey' => $sub['p256dh_key'],
                'authToken' => $sub['auth_key'],
            ]);
            $payload = json_encode([
                'title' => $title,
                'body'  => 'Hi '. $sub['pilot_callsign'] . ', ' . $message,
            ]);
            $webPush->queueNotification($subscription, $payload);
        }
        // The timer that sent the message has its answer before the push services are asked.
        rm_after_response( function () use ( $webPush, $pooled ) {
            $this->deliver( $webPush, $pooled );
        } );

        return true; // Queued; they go out after the answer
    }

    /**
     * Send a push notification to a single subscriber.
     *
     * @param string $endpoint The subscription endpoint URL.
     * @param string $p256dh   The user's public key.
     * @param string $auth     The user's auth token.
     * @param string $title    Notification title.
     * @param string $message  Notification body.
     * @return bool            True on success, false on failure.
     */
    public function send_notification_to_subscriber( $endpoint, $p256dh, $auth, $title = 'Subscription', $message = 'Subscription updated.' ) {
        if ( empty( $endpoint ) || empty( $p256dh ) || empty( $auth ) ) {
            return false;
        }

        if ( ! rm_push_available() ) {
            WP_RaceManager::write_log( 'Push notification skipped: no VAPID keys or web-push library missing.' );
            return false;
        }

        list( $webPush ) = $this->web_push();
        $subscription = Subscription::create( [
            'endpoint'  => $endpoint,
            'publicKey' => $p256dh,
            'authToken' => $auth,
        ] );

        $payload = json_encode( [
            'title' => $title,
            'body'  => $message,
        ] );

        $webPush->queueNotification( $subscription, $payload );
        // One push, to the browser that just subscribed: sent right away.
        $this->deliver( $webPush, false );

        return true;
    }

    /**
     * Send notifications to push subscribers when a pilot’s upcoming race schedule changes.
     *
     * This function takes the current race_id and the upcoming pilots list (each element contains:
     * heat_id, heat_displayname, pilot_id, callsign, slot_id, channel and likely). It retrieves all
     * subscriptions for the given race_id from the rm_subscriptions table, compares the stored heat
     * and channel told for each pilot with the new data, and sends a push notification with a precise
     * message if needed.
     *
     * The notification messages are:
     *   - For a new schedule: "[callsign]: Next race is [heat]. Channel is [channel]"
     *   - For a change of channel in the same heat: "[callsign]: Channel changed to [channel] for race [heat]"
     *   - For a change of heat: "[callsign]: Reassigned to [heat]. Channel is (remains) [channel]"
     *   - For removal: "[callsign]: You have been removed from your scheduled heat [heat]."
     *
     * A channel is named as fixed only once the heat's seats are (1.13.0); until then an entry's
     * channel is ''. Before 1.13.0 a heat from RotorHazard's generator was announced with the channel
     * of its slot's place in the plan, and corrected when the race director called it; 1.13 left it
     * out ("Next race is [heat].") and told it when the heat was called - just before the race,
     * since the timer uploads when a race is scheduled.
     *
     * So from 1.15.0 the channel RotorHazard will likely give the pilot (the entry's likely) goes out
     * with the schedule, marked as such ("Channel likely [channel]"), and again when it changes
     * ("Channel for race [heat] likely [channel]"). When the heat is called, a channel that came true
     * is not told again; another one is told as a change. A likely channel that can no longer be
     * told - the pilots of the heat left it open again - stands as said.
     *
     * The channel told is kept in the column channel - the likely one with LIKELY after it - and
     * compared as text, so a new frequency on the same seat is told as well; NULL there is a
     * subscription stored before 1.13.0, compared by heat and slot as it was then.
     *
     * After sending a notification, the subscriber’s record is updated accordingly.
     *
     * TODO: Reduce data overhead: if multiple clients subscribe to the same pilot, the heat and slot data is redundantly stored in each subscription.
     *
     * Which pilot a subscription follows: the one with its pilot key, where the upload names its
     * pilots by key (the RotorHazard connector sends pilot_key from the version that came with
     * 1.7.0 on). The timer gives its pilots new IDs when it re-creates them, and by ID a
     * subscription would then follow whoever has the number now. Where the upload has no keys, by
     * ID as before. A subscription found by its key gets the pilot's new ID; one from before keys,
     * found by ID, gets the key of that pilot, and follows the key from then on.
     *
     * @param int   $race_id         The current race (or heat) ID.
     * @param array $upcomingPilots  Array of upcoming pilot entries from getUpcomingRacePilots().
     * @param array $pilotKeys       pilot_id => pilot key for every pilot of the upload that has
     *                               one (rm_pilot_keys_by_id()); empty for an upload without keys.
     * @return array List of pilot_ids for which notifications were sent.
     */
    public function send_next_up_notifications($race_id, $upcomingPilots, $pilotKeys = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rm_subscriptions';
        $pilotIdByKey = array_flip( $pilotKeys );

        // Build a mapping of upcoming pilots keyed by pilot_id.
        // If a pilot appears more than once, use the entry with the highest heat_id.
        $upcomingMapping = array();
        foreach ($upcomingPilots as $upcomingPilot) {
            $pilotId = $upcomingPilot['pilot_id'];
            if (!isset($upcomingMapping[$pilotId]) || $upcomingPilot['heat_id'] > $upcomingMapping[$pilotId]['heat_id']) {
                $upcomingMapping[$pilotId] = $upcomingPilot;
            }
        }

        // Retrieve all subscriber records for the given race_id with a valid pilot_id.
        //$query = $wpdb->prepare("SELECT * FROM $table_name WHERE race_id = %d AND pilot_id != 0", $race_id);
        //$subscribers = $wpdb->get_results($query, ARRAY_A);
        $subscribers = rm_get_subscriptions( $race_id );
        if ( empty( $subscribers ) ) {
            return false; // return true? TODO: test this
        }

        if ( ! rm_push_available() ) {
            WP_RaceManager::write_log( 'Push notification skipped: no VAPID keys or web-push library missing.' );
            return false;
        }

        list( $webPush, $pooled ) = $this->web_push();

        $notifiedPilotIds = array();
        // The column that keeps the channel told comes with schema 3 (db-handler.php); until the
        // update has added it, nothing is written there and every subscription reads as from before.
        $keepsChannel = (int) get_option( 'rm_subscriptions_schema', 0 ) >= 3;

        foreach ($subscribers as $subscriber) {
            $pilotId    = (int) $subscriber['pilot_id'];
            $pilotKey   = rm_valid_pilot_key( $subscriber['pilot_key'] ?? '' );
            $pilotCallsign  = $subscriber['pilot_callsign'];

            if ( $pilotKeys && '' !== $pilotKey ) {
                // The pilot with this key, under whatever ID the timer gives them now; 0 when the
                // upload has nobody with it.
                $pilotId = $pilotIdByKey[ $pilotKey ] ?? 0;
                if ( $pilotId && $pilotId !== (int) $subscriber['pilot_id'] ) {
                    $wpdb->update( $table_name, array( 'pilot_id' => $pilotId ), array( 'id' => $subscriber['id'] ), array( '%d' ), array( '%d' ) );
                }
            } elseif ( '' === $pilotKey && isset( $pilotKeys[ $pilotId ] ) ) {
                // From before keys: the key of the pilot it follows, and by that from now on.
                $wpdb->update( $table_name, array( 'pilot_key' => $pilotKeys[ $pilotId ] ), array( 'id' => $subscriber['id'] ), array( '%s' ), array( '%d' ) );
            }
            $subscriber['pilot_id'] = $pilotId; // for the log line below
            $storedHeat = isset($subscriber['heat_id']) ? (int)$subscriber['heat_id'] : 0;
            $storedHeatDisplayname = isset($subscriber['heat_displayname']) ? $subscriber['heat_displayname'] : '';
            $storedSlot = isset($subscriber['slot_id']) ? (int)$subscriber['slot_id'] : 0;

            if (isset($upcomingMapping[$pilotId])) {
                // Pilot appears in the upcoming list.
                $newEntry = $upcomingMapping[$pilotId];
                $newHeat   = (int)$newEntry['heat_id'];
                $newSlot   = (int)$newEntry['slot_id'];
                $channel   = (string)$newEntry['channel']; // '' while the seats are not fixed
                $likely    = '' === $channel ? (string)($newEntry['likely'] ?? '') : '';
                $callsign  = $newEntry['callsign'];
                $heatDisplay = $newEntry['heat_displayname'];
                // What there is to tell, as the column keeps it: the channel, the likely one, or none.
                $now = '' !== $channel ? $channel : ('' !== $likely ? $likely . self::LIKELY : '');

                // The channel this subscriber was told last. A subscription stored before 1.13.0 -
                // or while the column is missing - has none: that version told every schedule with
                // the channel of the slot's place, so the same place means that channel still, and
                // another place one we do not know.
                $told = $subscriber['channel'] ?? null;
                if (null === $told) {
                    $told = $storedSlot === $newSlot ? $now : self::CHANNEL_NOT_KNOWN;
                }
                $told       = (string)$told;
                $toldLikely = strlen($told) > strlen(self::LIKELY) && self::LIKELY === substr($told, -strlen(self::LIKELY));
                $toldFixed  = '' !== $told && self::CHANNEL_NOT_KNOWN !== $told && !$toldLikely;

                if ($storedHeat === 0) {
                    // New schedule.
                    $message = "{$pilotCallsign}: Next race is {$heatDisplay}.";
                    if ('' !== $channel) {
                        $message .= " Channel is {$channel}";
                    } elseif ('' !== $likely) {
                        $message .= " Channel likely {$likely}";
                    }
                } elseif ($storedHeat === $newHeat) {
                    if ($told === $now || ('' === $now && $toldLikely)) {
                        // No change, or a likely channel that can no longer be told: what was said stands.
                        continue;
                    }
                    if ('' !== $channel) {
                        if ($told === $channel . self::LIKELY) {
                            // The seats are fixed as told: kept, not told again.
                            $message = null;
                        } elseif ('' === $told) {
                            // The seats are fixed now.
                            $message = "{$pilotCallsign}: Channel for race {$heatDisplay} is {$channel}";
                        } else {
                            $message = "{$pilotCallsign}: Channel changed to {$channel} for race {$heatDisplay}";
                        }
                    } elseif ($toldFixed) {
                        // The seats are given out again: the race director reset the heat's plan.
                        $message = "{$pilotCallsign}: Channel for race {$heatDisplay} is being reassigned"
                            . ('' === $likely ? '' : ", likely {$likely}");
                    } elseif ('' !== $likely) {
                        $message = "{$pilotCallsign}: Channel for race {$heatDisplay} likely {$likely}";
                    } else {
                        // Told by a subscription from before 1.13.0, and not known which.
                        $message = "{$pilotCallsign}: Channel for race {$heatDisplay} is being reassigned";
                    }
                } else {
                    // Heat changed.
                    $message = "{$pilotCallsign}: Reassigned to {$heatDisplay}.";
                    if ('' !== $channel) {
                        $message .= $told === $channel ? " Channel remains {$channel}" : " Channel is {$channel}";
                    } elseif ('' !== $likely) {
                        $message .= " Channel likely {$likely}";
                    }
                }

                if (null !== $message) {
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
                }

                // Update the subscriber record with the new heat and slot, and the channel told.
                $set    = array( 'heat_id' => $newHeat, 'slot_id' => $newSlot, 'heat_displayname' => $heatDisplay );
                $format = array( '%d', '%d', '%s' );
                if ( $keepsChannel ) {
                    $set['channel'] = $now;
                    $format[]       = '%s';
                }
                $wpdb->update( $table_name, $set, array( 'id' => $subscriber['id'] ), $format, array( '%d' ) );
            } else {
                // Pilot no longer appears in the upcoming list.
                // Check if his previously scheduled heat still exists in upcomingPilots.
                $heatStillExists = false;
                foreach ($upcomingPilots as $upcomingPilot) {
                    if ((int)$upcomingPilot['heat_id'] === $storedHeat) {
                        $heatStillExists = true;
                        break;
                    }
                }
                if ($heatStillExists && $storedHeat != 0) {
                    $message = "{$pilotCallsign}: You have been removed from your scheduled heat {$storedHeatDisplayname}.";
                    
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

                    // Clear out the stored heat and slot, and the channel told.
                    $set    = array( 'heat_id' => 0, 'slot_id' => 0, 'heat_displayname' => '' );
                    $format = array( '%d', '%d', '%s' );
                    if ( $keepsChannel ) {
                        $set['channel'] = '';
                        $format[]       = '%s';
                    }
                    $wpdb->update( $table_name, $set, array( 'id' => $subscriber['id'] ), $format, array( '%d' ) );
                }
            }
        }
        // Sent once the timer has its answer: the upload no longer waits for the push services
        // (includes/after-response.php). The subscribers' heat and slot are updated above
        // already, so the pilots listed here are the ones queued, not the ones reached.
        rm_after_response( function () use ( $webPush, $pooled ) {
            $this->deliver( $webPush, $pooled );
        } );

        return $notifiedPilotIds;
    }

    /**
     * A push client, and whether it can send a flush's pushes all at once.
     *
     * Every push gets PUSH_TIMEOUT. At once needs php-http/guzzle7-adapter, the asynchronous
     * client web-push's flushPooled() asks for: measured with the library alone in the local
     * container, 100 pushes to a push service that answers in 100 ms took 0.17 s that way and
     * 10.1 s one after another. Where the adapter is missing - a vendor/ from before it, or one
     * outside the plugin - they go out one after another, with the same limits.
     *
     * The VAPID keys are checked here, so unusable ones still throw inside the request that
     * asked for the pushes, and the upload says nobody was notified (D8 in the connector's
     * roadmap). A subscription's own keys are used only when its push goes out, after the
     * answer; one that cannot be used there shows in the log, not in the answer.
     *
     * @return array{0: WebPush, 1: bool}
     */
    protected function web_push() {
        $config = array(
            'timeout'         => self::PUSH_TIMEOUT,
            'connect_timeout' => self::PUSH_CONNECT_TIMEOUT,
        );
        $client = class_exists( '\GuzzleHttp\Client' ) ? new \GuzzleHttp\Client( $config ) : null;
        $async  = class_exists( '\Http\Adapter\Guzzle7\Client' )
            ? \Http\Adapter\Guzzle7\Client::createWithConfig( $config )
            : null;

        $webPush = new WebPush(
            array( 'VAPID' => $this->get_vapid() ),
            array( 'batchSize' => self::PUSH_BATCH ),
            $client,
            null,
            null,
            $async
        );

        return array( $webPush, null !== $async );
    }

    /**
     * Send what is queued, and forget the subscriptions a push service says are gone.
     *
     * @param WebPush $webPush The client with the queued pushes.
     * @param bool    $pooled  Whether it can send them all at once (see web_push()).
     */
    protected function deliver( $webPush, $pooled ) {
        $handle = function ( $result ) {
            $endpoint = $result->getRequest()->getUri()->__toString();
            if ( $result->isSuccess() ) {
                WP_RaceManager::write_log( 'Notification sent successfully to: ' . $endpoint );
            } elseif ( false !== strpos( $result->getReason(), '410' ) ) {
                rm_delete_subscription( $endpoint );
                WP_RaceManager::write_log( 'Subscription expired and removed: ' . $endpoint );
            } else {
                WP_RaceManager::write_log( 'Notification failed to send to: ' . $endpoint . ' with reason: ' . $result->getReason() );
            }
        };

        if ( $pooled ) {
            $webPush->flushPooled( $handle );
            return;
        }
        foreach ( $webPush->flush() as $result ) {
            $handle( $result );
        }
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

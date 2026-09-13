<?php
/**
 * A browser's subscription moved to another pilot or race (1.13.1): rm_upsert_subscription() in
 * includes/db-handler.php, and what the next upload's push says then.
 *
 * A browser has one subscription, found by its endpoint. The live pages' "Update Subscription"
 * moves it to the pilot selected, and until 1.13.1 it kept the heat, slot and channel the pilot
 * before had been told; the next upload compared the new pilot's heats with those. What has to hold:
 *
 *   - moved to another pilot or race, the schedule starts over: the next upload says "Next race is
 *     ...", not "Reassigned" or "Channel changed", and nothing about the heat of the pilot before -
 *     1.13.0 told the new pilot's follower they had been removed from it;
 *   - the same pilot of the same race keeps it: new keys of the browser, or the pilot re-created on
 *     the timer under a new ID and found by the key; by ID where either has no key;
 *   - before the update has added the channel column, the schedule starts over without it.
 *
 * Runs the real upsert, rm_get_subscriptions() and push handler against one subscription row kept
 * in memory, with the real minishlink/web-push for what the handler builds and a stand-in where it
 * sends; skips itself without the library.
 */

namespace RaceManager {
    class WP_RaceManager {
        public static $log = array();

        public static function write_log( $message ) {
            self::$log[] = $message;
        }
    }
}

namespace {

require_once __DIR__ . '/../bootstrap.php';

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
function current_time( $type ) { return '2026-09-13 12:00:00'; }

/** Fake $wpdb: the subscriptions table as one row, which inserts and updates change. */
class RM_Test_Wpdb {
    public $prefix  = 'wp_';
    public $row     = null;
    public $updates = array();

    public function prepare( $query, ...$args ) { return $query; }
    public function get_var( $query ) { return 0; }
    public function get_row( $query ) { return $this->row ? (object) $this->row : null; }
    public function get_results( $query, $output = null ) { return $this->row ? array( $this->row ) : array(); }
    public function insert( $table, $data ) {
        $this->row = array_merge( array( 'id' => 1, 'heat_id' => 0, 'slot_id' => 0, 'heat_displayname' => '', 'channel' => null ), $data );
        return 1;
    }
    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $this->updates[] = $data;
        $this->row       = array_merge( $this->row, $data );
        return 1;
    }
    public function delete( $table, $where, $format = null ) { $this->row = null; return 1; }
}
$GLOBALS['wpdb']       = new RM_Test_Wpdb();
$GLOBALS['rm_options'] = array( 'admin_email' => 'race@example.test', 'rm_subscriptions_schema' => 3 );

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/vapid-handler.php';

if ( ! rm_push_library_available() ) {
    rm_test_skip( 'minishlink/web-push not installed -- run "composer install" in the plugin directory' );
}

$rm_keys = \Minishlink\WebPush\VAPID::createVapidKeys();
define( 'RM_VAPID_PUBLIC_KEY', $rm_keys['publicKey'] );
define( 'RM_VAPID_PRIVATE_KEY', $rm_keys['privateKey'] );
define( 'RM_VAPID_SUBJECT', 'mailto:race@example.test' );

require_once RM_PLUGIN_DIR . '/includes/db-handler.php';
require_once RM_PLUGIN_DIR . '/includes/pwa-subscription-handler.php';

/** Stands in for WebPush where it sends: keeps what is queued. */
class RM_Test_Push {
    public $queued = array();

    public function queueNotification( $subscription, $payload ) {
        $this->queued[] = json_decode( $payload, true )['body'];
    }
    public function flushPooled( callable $callback ) {}
    public function flush() { return array(); }
}

class RM_Test_Handler extends \RaceManager\PWA_Subscription_Handler {
    public $push;

    protected function web_push() {
        $this->push = new RM_Test_Push();
        return array( $this->push, true );
    }
}

const EP     = 'https://push.example.test/1';
const KEY_A  = 'a7a7a7a7-bbbb-5ccc-8ddd-eeeeeeeeee07';
const KEY_B  = 'b8b8b8b8-bbbb-5ccc-8ddd-eeeeeeeeee08';

/** The browser selects a pilot and presses Subscribe or Update Subscription. */
function rm_ss_follow( $race_id, $pilot_id, $callsign, $key = '' ) {
    rm_upsert_subscription( $race_id, $pilot_id, $callsign, EP, 'p256dh', 'auth', $key );
}

/** One upload: [pilot_id, callsign, heat, slot, channel] per pilot up; what was pushed. */
function rm_ss_upload( $pilots ) {
    $upcoming = array();
    foreach ( $pilots as list( $pilot_id, $callsign, $heat, $slot, $channel ) ) {
        $upcoming[] = array( 'heat_id' => $heat, 'heat_displayname' => 'Heat ' . $heat, 'pilot_id' => $pilot_id, 'callsign' => $callsign, 'slot_id' => $slot, 'channel' => $channel );
    }
    $handler = new RM_Test_Handler();
    $handler->send_next_up_notifications( 2578, $upcoming, array() );
    return $handler->push->queued;
}

/** The stored schedule, as "heat/slot/name/channel". */
function rm_ss_stored() {
    $row = $GLOBALS['wpdb']->row;
    return $row['heat_id'] . '/' . $row['slot_id'] . '/' . $row['heat_displayname'] . '/' . var_export( $row['channel'], true );
}

$ALPHA_IN_5 = array( 7, 'Alpha', 5, 0, 'R1' );

rm_test_section( 'Moved to another pilot of the race' );

$GLOBALS['wpdb']->row = null;
rm_ss_follow( 2578, 7, 'Alpha' );
$pushed = rm_ss_upload( array( $ALPHA_IN_5 ) );
rm_test_check( 'following Alpha: told Heat 5 on R1', array( 'Alpha: Next race is Heat 5. Channel is R1' ) === $pushed, var_export( $pushed, true ) );

rm_ss_follow( 2578, 12, 'Bravo' );
rm_test_check( 'moved to Bravo: nothing told yet', '0/0//NULL' === rm_ss_stored(), rm_ss_stored() );

$pushed = rm_ss_upload( array( $ALPHA_IN_5 ) );
rm_test_check( 'Bravo not up yet, Alpha\'s heat still is: nothing, no "removed from Heat 5"', array() === $pushed, var_export( $pushed, true ) );

$pushed = rm_ss_upload( array( $ALPHA_IN_5, array( 12, 'Bravo', 7, 2, 'F2' ) ) );
rm_test_check( 'Bravo up in Heat 7: the schedule, as for a new subscription', array( 'Bravo: Next race is Heat 7. Channel is F2' ) === $pushed, var_export( $pushed, true ) );

rm_test_section( 'In the heat of the pilot before' );

$GLOBALS['wpdb']->row = null;
rm_ss_follow( 2578, 7, 'Alpha' );
rm_ss_upload( array( $ALPHA_IN_5 ) );
rm_ss_follow( 2578, 12, 'Bravo' );
$pushed = rm_ss_upload( array( $ALPHA_IN_5, array( 12, 'Bravo', 5, 2, 'R3' ) ) );
rm_test_check( 'Bravo in Heat 5 too: announced, not "Channel changed"', array( 'Bravo: Next race is Heat 5. Channel is R3' ) === $pushed, var_export( $pushed, true ) );

$GLOBALS['wpdb']->row = null;
rm_ss_follow( 2578, 7, 'Alpha' );
rm_ss_upload( array( $ALPHA_IN_5 ) );
rm_ss_follow( 2578, 12, 'Bravo' );
$pushed = rm_ss_upload( array( $ALPHA_IN_5, array( 12, 'Bravo', 5, 0, '' ) ) );
rm_test_check( '  and with its seats not fixed yet: announced without a channel', array( 'Bravo: Next race is Heat 5.' ) === $pushed, var_export( $pushed, true ) );

rm_test_section( 'Moved to a pilot of another race' );

$GLOBALS['wpdb']->row = null;
rm_ss_follow( 2578, 7, 'Alpha' );
rm_ss_upload( array( $ALPHA_IN_5 ) );
rm_ss_follow( 2400, 7, 'Charlie' );
rm_test_check( 'the same ID in another race: nothing told yet', '0/0//NULL' === rm_ss_stored(), rm_ss_stored() );

rm_test_section( 'The same pilot of the same race' );

$GLOBALS['wpdb']->row = null;
rm_ss_follow( 2578, 7, 'Alpha' );
rm_ss_upload( array( $ALPHA_IN_5 ) );
rm_ss_follow( 2578, 7, 'Alpha' );
rm_test_check( 'subscribed again, by ID: the schedule kept', '5/0/Heat 5/\'R1\'' === rm_ss_stored(), rm_ss_stored() );
rm_test_check( '  and the next upload has no news', array() === rm_ss_upload( array( $ALPHA_IN_5 ) ) );

$GLOBALS['wpdb']->row = null;
rm_ss_follow( 2578, 7, 'Alpha', KEY_A );
rm_ss_upload( array( $ALPHA_IN_5 ) );
rm_ss_follow( 2578, 21, 'Alpha', strtoupper( KEY_A ) );
rm_test_check( 're-created on the timer, found by the key under a new ID: kept', '5/0/Heat 5/\'R1\'' === rm_ss_stored() && 21 === $GLOBALS['wpdb']->row['pilot_id'], rm_ss_stored() );

$GLOBALS['wpdb']->row = null;
rm_ss_follow( 2578, 7, 'Alpha', KEY_A );
rm_ss_upload( array( $ALPHA_IN_5 ) );
rm_ss_follow( 2578, 7, 'Bravo', KEY_B );
rm_test_check( 'the same ID with another key - someone else under it now: started over', '0/0//NULL' === rm_ss_stored(), rm_ss_stored() );

$GLOBALS['wpdb']->row = null;
rm_ss_follow( 2578, 7, 'Alpha' );
rm_ss_upload( array( $ALPHA_IN_5 ) );
rm_ss_follow( 2578, 7, 'Alpha', KEY_A );
rm_test_check( 'a subscription from before keys, now with one, by ID: kept', '5/0/Heat 5/\'R1\'' === rm_ss_stored(), rm_ss_stored() );

rm_test_section( 'Before the update has added the channel column' );

$GLOBALS['rm_options']['rm_subscriptions_schema'] = 2;
$GLOBALS['wpdb']->row = null;
rm_ss_follow( 2578, 7, 'Alpha' );
$GLOBALS['wpdb']->row = array_merge( $GLOBALS['wpdb']->row, array( 'heat_id' => 5, 'heat_displayname' => 'Heat 5' ) );
unset( $GLOBALS['wpdb']->row['channel'] );
$GLOBALS['wpdb']->updates = array();
rm_ss_follow( 2578, 12, 'Bravo' );
$update = end( $GLOBALS['wpdb']->updates );
rm_test_check( 'started over, without writing the column', 0 === $update['heat_id'] && '' === $update['heat_displayname'] && ! array_key_exists( 'channel', $update ), var_export( $update, true ) );

rm_test_finish();

}

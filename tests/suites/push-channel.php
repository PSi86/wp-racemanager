<?php
/**
 * What the "your next race" push says about the channel: send_next_up_notifications() in
 * includes/pwa-subscription-handler.php, from 1.13.0 on.
 *
 * A channel is named only once the heat's seats are fixed; until then rm_getUpcomingRacePilots()
 * hands over '' (nextup-schedule covers when that is). What has to hold:
 *
 *   - a schedule without a channel says so by leaving it out, and the channel follows once the
 *     seats are fixed, told as such - not as a change;
 *   - the channel told is kept and compared as text: the same channel again is no news, another
 *     one on the same seat - a new frequency profile - is;
 *   - seats given out again (the race director reset the plan) are told, and a new heat without a
 *     channel yet says nothing about one;
 *   - a subscription stored before 1.13.0 (channel NULL) is compared by heat and slot, as 1.12 did,
 *     so updating the plugin does not repeat the pushes of a race under way;
 *   - until the update has added the column, nothing is written to it.
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

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}

$GLOBALS['rm_subscriptions'] = array();
function rm_get_subscriptions( $race_id ) {
    return $GLOBALS['rm_subscriptions'];
}
function rm_delete_subscription( $endpoint ) {}

/** Fake $wpdb: keeps every update the handler makes, in order. */
class RM_Test_Wpdb {
    public $prefix  = 'wp_';
    public $updates = array();

    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $this->updates[] = array( $where['id'], $data );
        return 1;
    }
    public function prepare( $query, ...$args ) { return $query; }
    public function get_var( $query ) { return 0; }
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

/**
 * A subscription to TP7 (pilot 7) as the table holds it; $channel null is one from before 1.13.0.
 */
function rm_pc_subscriber( $heat_id, $slot_id, $channel ) {
    $row = array(
        'id'               => 1,
        'pilot_id'         => '7', // as $wpdb hands it out
        'pilot_callsign'   => 'TP7',
        'pilot_key'        => '',
        'heat_id'          => $heat_id,
        'slot_id'          => $slot_id,
        'heat_displayname' => $heat_id ? 'Heat ' . $heat_id : '',
        'channel'          => $channel,
        'endpoint'         => 'https://push.example.test/1',
        'p256dh_key'       => 'key-1',
        'auth_key'         => 'auth-1',
    );
    return $row;
}

/** Pilot 7's entry of rm_getUpcomingRacePilots(); $channel '' while the seats are not fixed. */
function rm_pc_upcoming( $heat_id, $slot_id, $channel ) {
    return array(
        'heat_id'          => $heat_id,
        'heat_displayname' => 'Heat ' . $heat_id,
        'pilot_id'         => 7,
        'callsign'         => 'TP7',
        'slot_id'          => $slot_id,
        'channel'          => $channel,
    );
}

/** One upload's notifications: what was pushed, and the channel stored (absent: none written). */
function rm_pc_run( $subscriber, $upcoming ) {
    $GLOBALS['rm_subscriptions'] = array( $subscriber );
    $GLOBALS['wpdb']->updates    = array();
    $handler                     = new RM_Test_Handler();
    $handler->send_next_up_notifications( 2578, array( $upcoming ), array() );
    $stored = null;
    foreach ( $GLOBALS['wpdb']->updates as $update ) {
        if ( array_key_exists( 'channel', $update[1] ) ) {
            $stored = $update[1]['channel'];
        }
    }
    return array( $handler->push->queued, $stored, $GLOBALS['wpdb']->updates );
}

rm_test_section( 'A heat from the generator, until the race director calls it' );

list( $pushed, $stored ) = rm_pc_run( rm_pc_subscriber( 0, 0, null ), rm_pc_upcoming( 9, 0, '' ) );
rm_test_check( 'scheduled, no seat yet: the heat, no channel', array( 'TP7: Next race is Heat 9.' ) === $pushed, var_export( $pushed, true ) );
rm_test_check( '  and "no channel told" is stored', '' === $stored, var_export( $stored, true ) );

list( $pushed ) = rm_pc_run( rm_pc_subscriber( 9, 0, '' ), rm_pc_upcoming( 9, 0, '' ) );
rm_test_check( 'the next upload, still no seat: nothing', array() === $pushed, var_export( $pushed, true ) );

// Seat 0 - the number an unknown seat is stored under as well: the channel still goes out.
list( $pushed, $stored ) = rm_pc_run( rm_pc_subscriber( 9, 0, '' ), rm_pc_upcoming( 9, 0, 'R1' ) );
rm_test_check( 'called, on seat 0: the channel, told as given now', array( 'TP7: Channel for race Heat 9 is R1' ) === $pushed, var_export( $pushed, true ) );
rm_test_check( '  and R1 stored', 'R1' === $stored, var_export( $stored, true ) );

list( $pushed ) = rm_pc_run( rm_pc_subscriber( 9, 0, 'R1' ), rm_pc_upcoming( 9, 0, 'R1' ) );
rm_test_check( 'the same channel again: nothing', array() === $pushed, var_export( $pushed, true ) );

rm_test_section( 'A channel that changes' );

list( $pushed, $stored ) = rm_pc_run( rm_pc_subscriber( 9, 0, 'R1' ), rm_pc_upcoming( 9, 2, 'F2' ) );
rm_test_check( 'another seat: the change', array( 'TP7: Channel changed to F2 for race Heat 9' ) === $pushed, var_export( $pushed, true ) );
rm_test_check( '  and F2 stored', 'F2' === $stored, var_export( $stored, true ) );

list( $pushed ) = rm_pc_run( rm_pc_subscriber( 9, 2, 'F2' ), rm_pc_upcoming( 9, 2, 'E2' ) );
rm_test_check( 'the same seat on another frequency: the change too', array( 'TP7: Channel changed to E2 for race Heat 9' ) === $pushed, var_export( $pushed, true ) );

list( $pushed, $stored ) = rm_pc_run( rm_pc_subscriber( 9, 2, 'F2' ), rm_pc_upcoming( 9, 0, '' ) );
rm_test_check( 'the plan reset, the seats given out again: said so', array( 'TP7: Channel for race Heat 9 is being reassigned' ) === $pushed, var_export( $pushed, true ) );
rm_test_check( '  and "no channel told" stored', '' === $stored, var_export( $stored, true ) );

rm_test_section( 'Another heat' );

list( $pushed ) = rm_pc_run( rm_pc_subscriber( 9, 2, 'F2' ), rm_pc_upcoming( 10, 0, '' ) );
rm_test_check( 'no seat there yet: the heat alone', array( 'TP7: Reassigned to Heat 10.' ) === $pushed, var_export( $pushed, true ) );
list( $pushed ) = rm_pc_run( rm_pc_subscriber( 9, 2, 'F2' ), rm_pc_upcoming( 10, 2, 'F2' ) );
rm_test_check( 'the same channel there', array( 'TP7: Reassigned to Heat 10. Channel remains F2' ) === $pushed, var_export( $pushed, true ) );
list( $pushed ) = rm_pc_run( rm_pc_subscriber( 9, 2, 'F2' ), rm_pc_upcoming( 10, 1, 'R2' ) );
rm_test_check( 'another channel there', array( 'TP7: Reassigned to Heat 10. Channel is R2' ) === $pushed, var_export( $pushed, true ) );

rm_test_section( 'A subscription stored before 1.13.0 (channel NULL)' );

// 1.12 told "Next race is Heat 3. Channel is R1" for slot 0 and stored heat 3, slot 0.
list( $pushed ) = rm_pc_run( rm_pc_subscriber( 3, 0, null ), rm_pc_upcoming( 3, 0, 'R1' ) );
rm_test_check( 'the same heat and slot: nothing, as 1.12 compared it', array() === $pushed, var_export( $pushed, true ) );
list( $pushed ) = rm_pc_run( rm_pc_subscriber( 3, 0, null ), rm_pc_upcoming( 3, 2, 'F2' ) );
rm_test_check( 'another slot: the change, as 1.12 said it', array( 'TP7: Channel changed to F2 for race Heat 3' ) === $pushed, var_export( $pushed, true ) );
list( $pushed ) = rm_pc_run( rm_pc_subscriber( 3, 0, null ), rm_pc_upcoming( 5, 0, 'R1' ) );
rm_test_check( 'another heat, the same slot: "remains", as 1.12 said it', array( 'TP7: Reassigned to Heat 5. Channel remains R1' ) === $pushed, var_export( $pushed, true ) );

rm_test_section( 'Before the update has added the column' );

$GLOBALS['rm_options']['rm_subscriptions_schema'] = 2;
list( $pushed, $stored, $updates ) = rm_pc_run( array_diff_key( rm_pc_subscriber( 0, 0, null ), array( 'channel' => 1 ) ), rm_pc_upcoming( 9, 1, 'R2' ) );
rm_test_check( 'the push goes out', array( 'TP7: Next race is Heat 9. Channel is R2' ) === $pushed, var_export( $pushed, true ) );
rm_test_check( '  and no channel is written', null === $stored && 1 === count( $updates ) && array( 'heat_id' => 9, 'slot_id' => 1, 'heat_displayname' => 'Heat 9' ) === $updates[0][1],
    var_export( $updates, true ) );

rm_test_finish();

}

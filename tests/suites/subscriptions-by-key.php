<?php
/**
 * Push subscriptions follow the pilot key: send_next_up_notifications() in
 * includes/pwa-subscription-handler.php, and rm_pilot_keys_by_id() in includes/pilot-key.php.
 *
 * RotorHazard gives its pilots new IDs when the timer re-creates them (the connector's "Clear
 * pilots before download"), and a subscription kept by ID then followed whoever had the number:
 * pushes about another person's heat, and none about one's own. From the connector's pilot_key in
 * the upload on, a subscription follows the key. Runs against the real minishlink/web-push for what
 * the handler builds, with a stand-in where it sends; skips itself without the library.
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
$GLOBALS['rm_options'] = array( 'admin_email' => 'race@example.test' );

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
        $this->queued[] = array( $subscription->getEndpoint(), json_decode( $payload, true )['body'] );
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

const K7  = 'a7a7a7a7-bbbb-5ccc-8ddd-eeeeeeeeee07';
const K8  = 'a8a8a8a8-bbbb-5ccc-8ddd-eeeeeeeeee08';
const K99 = 'a9a9a9a9-bbbb-5ccc-8ddd-eeeeeeeeee99';

function rm_sk_subscriber( $id, $pilot_id, $key, $heat_id = 0, $slot_id = 0 ) {
    return array(
        'id'               => $id,
        'pilot_id'         => (string) $pilot_id, // as $wpdb hands it out
        'pilot_callsign'   => 'TP7',
        'pilot_key'        => $key,
        'heat_id'          => $heat_id,
        'slot_id'          => $slot_id,
        'heat_displayname' => $heat_id ? 'Heat ' . $heat_id : '',
        'endpoint'         => 'https://push.example.test/' . $id,
        'p256dh_key'       => 'key-' . $id,
        'auth_key'         => 'auth-' . $id,
    );
}

function rm_sk_upcoming( $pilot_id, $heat_id, $slot_id ) {
    return array(
        'heat_id'          => $heat_id,
        'heat_displayname' => 'Heat ' . $heat_id,
        'pilot_id'         => $pilot_id,
        'callsign'         => 'P' . $pilot_id,
        'slot_id'          => $slot_id,
        'channel'          => 'R' . ( $slot_id + 1 ),
    );
}

/** Run one upload's notifications; return what was pushed and what was stored. */
function rm_sk_run( $subscribers, $upcoming, $pilot_keys ) {
    $GLOBALS['rm_subscriptions'] = $subscribers;
    $GLOBALS['wpdb']->updates    = array();
    $handler                     = new RM_Test_Handler();
    $notified                    = $handler->send_next_up_notifications( 2578, $upcoming, $pilot_keys );
    return array( $handler->push->queued, $GLOBALS['wpdb']->updates, $notified );
}

rm_test_section( 'The pilots re-created on the timer, under new IDs' );

// Subscribed to TP7 (key K7) while TP7 was pilot 7, told "next race Heat 3, R1". The timer then
// re-created its pilots: TP7 is pilot 12 now, still in Heat 3 on R1, and pilot 7 is someone else
// (K99), in Heat 4 on R2.
list( $pushed, $stored ) = rm_sk_run(
    array( rm_sk_subscriber( 1, 7, K7, 3, 0 ) ),
    array( rm_sk_upcoming( 12, 3, 0 ), rm_sk_upcoming( 7, 4, 1 ) ),
    array( 12 => K7, 7 => K99 )
);
rm_test_check( 'no push about pilot 7\'s heat: TP7\'s own is unchanged', array() === $pushed, var_export( $pushed, true ) );
rm_test_check( 'the subscription now has TP7\'s new ID', in_array( array( 1, array( 'pilot_id' => 12 ) ), $stored, true ), var_export( $stored, true ) );

// TP7 moves to Heat 5 on R3.
list( $pushed, $stored, $notified ) = rm_sk_run(
    array( rm_sk_subscriber( 1, 12, K7, 3, 0 ) ),
    array( rm_sk_upcoming( 12, 5, 2 ), rm_sk_upcoming( 7, 4, 1 ) ),
    array( 12 => K7, 7 => K99 )
);
rm_test_check( 'TP7 moved on: the push says where to', array( array( 'https://push.example.test/1', 'TP7: Reassigned to Heat 5. Channel is R3' ) ) === $pushed, var_export( $pushed, true ) );
rm_test_check( '  and names pilot 12 as notified', array( 12 ) === $notified, var_export( $notified, true ) );

rm_test_section( 'Someone the upload no longer has' );

// TP7 left the field; pilot 7 is someone else, in TP7's old Heat 3.
list( $pushed ) = rm_sk_run(
    array( rm_sk_subscriber( 1, 7, K7, 3, 0 ) ),
    array( rm_sk_upcoming( 7, 3, 1 ) ),
    array( 7 => K99 )
);
rm_test_check( 'told of leaving Heat 3, not of pilot 7\'s channel',
    array( array( 'https://push.example.test/1', 'TP7: You have been removed from your scheduled heat Heat 3.' ) ) === $pushed,
    var_export( $pushed, true ) );

rm_test_section( 'Subscriptions from before keys' );

list( $pushed, $stored ) = rm_sk_run(
    array( rm_sk_subscriber( 2, 8, '' ) ),
    array( rm_sk_upcoming( 8, 2, 1 ) ),
    array( 8 => K8 )
);
rm_test_check( 'found by ID, as they were made', array( array( 'https://push.example.test/2', 'TP7: Next race is Heat 2. Channel is R2' ) ) === $pushed, var_export( $pushed, true ) );
rm_test_check( '  and given the key of that pilot, to follow from now on', in_array( array( 2, array( 'pilot_key' => K8 ) ), $stored, true ), var_export( $stored, true ) );

rm_test_section( 'An upload without keys, from an older connector' );

list( $pushed, $stored ) = rm_sk_run(
    array( rm_sk_subscriber( 1, 7, K7 ) ),
    array( rm_sk_upcoming( 7, 3, 0 ) ),
    array()
);
rm_test_check( 'a subscription with a key is found by its ID, as before', array( array( 'https://push.example.test/1', 'TP7: Next race is Heat 3. Channel is R1' ) ) === $pushed, var_export( $pushed, true ) );
rm_test_check( '  and nothing about its pilot is rewritten', array() === array_filter( $stored, function ( $u ) { return isset( $u[1]['pilot_id'] ) || isset( $u[1]['pilot_key'] ); } ), var_export( $stored, true ) );

rm_test_section( 'A key as the subscription stored it' );

list( $pushed ) = rm_sk_run(
    array( rm_sk_subscriber( 1, 7, strtoupper( K7 ) ) ),
    array( rm_sk_upcoming( 12, 3, 0 ) ),
    array( 12 => K7 )
);
rm_test_check( 'in capitals: the same key', array( array( 'https://push.example.test/1', 'TP7: Next race is Heat 3. Channel is R1' ) ) === $pushed, var_export( $pushed, true ) );

rm_test_section( 'The keys of an upload: rm_pilot_keys_by_id()' );

$keys = rm_pilot_keys_by_id( array( 'pilot_data' => array( 'pilots' => array(
    array( 'pilot_id' => 7, 'callsign' => 'TP7', 'pilot_key' => '  ' . strtoupper( K7 ) . ' ' ),
    array( 'pilot_id' => '8', 'callsign' => 'TP8', 'pilot_key' => K8 ),
    array( 'pilot_id' => 9, 'callsign' => 'TP9', 'pilot_key' => '' ),
    array( 'pilot_id' => 10, 'callsign' => 'TP10', 'pilot_key' => 'typed over on the timer' ),
    array( 'pilot_id' => 11, 'callsign' => 'TP11' ),
    array( 'callsign' => 'no ID', 'pilot_key' => K99 ),
    'not a pilot',
) ) ) );
rm_test_check( 'by pilot_id, in lower case, only the valid ones', array( 7 => K7, 8 => K8 ) === $keys, var_export( $keys, true ) );
rm_test_check( 'an upload without pilot_data has none', array() === rm_pilot_keys_by_id( array() ) );
rm_test_check( 'nor one whose pilots are no list', array() === rm_pilot_keys_by_id( array( 'pilot_data' => array( 'pilots' => 'x' ) ) ) );

rm_test_finish();

}

<?php
/**
 * Pushes after the answer, with time limits, all at once where they can be: includes/after-response.php
 * and the sending in includes/pwa-subscription-handler.php.
 *
 * The upload and notify-racers used to answer only after every push, sent one after another: 100
 * pushes to a push service that answers in 100 ms held the upload's answer 10.2 s, measured on the
 * local site on 2026-09-11. Runs against the real minishlink/web-push for what the handler builds,
 * with a stand-in for the push services where it sends; skips itself without the library.
 */

namespace RaceManager {
    // The plugin's main class as far as the push handler reaches: its log.
    class WP_RaceManager {
        public static $log = array();

        public static function write_log( $message ) {
            self::$log[] = $message;
        }
    }
}

namespace {

require_once __DIR__ . '/../bootstrap.php';

// Hooks kept with their priority, so the suite can run shutdown the way WordPress does.
$GLOBALS['rm_actions'] = array();
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
    $GLOBALS['rm_actions'][ $hook ][ $priority ][] = $callback;
}
function rm_pd_shutdown() {
    $by_priority = $GLOBALS['rm_actions']['shutdown'] ?? array();
    ksort( $by_priority );
    foreach ( $by_priority as $callbacks ) {
        foreach ( $callbacks as $callback ) {
            $callback();
        }
    }
}

// PHP-FPM's way to hand the client its answer, recorded in the order things happen.
$GLOBALS['rm_events'] = array();
function fastcgi_finish_request() {
    $GLOBALS['rm_events'][] = 'answer sent';
    return true;
}

$GLOBALS['rm_subscriptions'] = array();
$GLOBALS['rm_removed']       = array();
function rm_get_subscriptions( $race_id ) {
    return $GLOBALS['rm_subscriptions'];
}
function rm_delete_subscription( $endpoint ) {
    $GLOBALS['rm_removed'][] = $endpoint;
}

/** Fake $wpdb: keeps the subscriber updates the handler makes. */
class RM_Test_Wpdb {
    public $prefix  = 'wp_';
    public $updates = array();

    public function update( $table, $data, $where, $format = null, $where_format = null ) {
        $this->updates[ $where['id'] ] = $data;
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

/** A push service's answer to one push, as web-push reports it. */
class RM_Test_Report {
    private $endpoint;
    private $reason;

    public function __construct( $endpoint, $reason ) {
        $this->endpoint = $endpoint;
        $this->reason   = $reason;
    }
    public function getRequest() {
        $endpoint = $this->endpoint;
        // A PSR-7 request, whose URI is an object that turns into the endpoint.
        return new class( $endpoint ) {
            private $endpoint;
            public function __construct( $endpoint ) { $this->endpoint = $endpoint; }
            public function getUri() {
                $endpoint = $this->endpoint;
                return new class( $endpoint ) {
                    private $endpoint;
                    public function __construct( $endpoint ) { $this->endpoint = $endpoint; }
                    public function __toString() { return $this->endpoint; }
                };
            }
        };
    }
    public function isSuccess() { return '' === $this->reason; }
    public function getReason() { return $this->reason; }
}

/** Stands in for WebPush where it sends: keeps what is queued, and records when and how it goes out. */
class RM_Test_Push {
    public $queued  = array();
    public $answers = array(); // endpoint => the push service's reason; none is a success

    public function queueNotification( $subscription, $payload ) {
        $this->queued[] = array( $subscription->getEndpoint(), json_decode( $payload, true ) );
    }
    public function flushPooled( callable $callback ) {
        $GLOBALS['rm_events'][] = 'sent at once: ' . count( $this->queued );
        foreach ( $this->reports() as $report ) {
            $callback( $report );
        }
    }
    public function flush() {
        $GLOBALS['rm_events'][] = 'sent one after another: ' . count( $this->queued );
        foreach ( $this->reports() as $report ) {
            yield $report;
        }
    }
    private function reports() {
        $reports = array();
        foreach ( $this->queued as $entry ) {
            $reports[] = new RM_Test_Report( $entry[0], $this->answers[ $entry[0] ] ?? '' );
        }
        $this->queued = array();
        return $reports;
    }
}

/** The handler, sending through the stand-in. */
class RM_Test_Handler extends \RaceManager\PWA_Subscription_Handler {
    public $push;
    public $pooled = true;

    protected function web_push() {
        $this->push = new RM_Test_Push();
        return array( $this->push, $this->pooled );
    }
}

function rm_pd_subscriber( $id, $pilot_id, $heat_id = 0, $slot_id = 0 ) {
    return array(
        'id'               => $id,
        'pilot_id'         => $pilot_id,
        'pilot_callsign'   => 'TP' . $pilot_id,
        'heat_id'          => $heat_id,
        'slot_id'          => $slot_id,
        'heat_displayname' => $heat_id ? 'Heat ' . $heat_id : '',
        'endpoint'         => 'https://push.example.test/' . $id,
        'p256dh_key'       => 'key-' . $id,
        'auth_key'         => 'auth-' . $id,
    );
}

function rm_pd_upcoming( $pilot_id, $heat_id, $slot_id ) {
    return array(
        'heat_id'          => $heat_id,
        'heat_displayname' => 'Heat ' . $heat_id,
        'pilot_id'         => $pilot_id,
        'callsign'         => 'TP' . $pilot_id,
        'slot_id'          => $slot_id,
        'channel'          => 'R' . ( $slot_id + 1 ),
    );
}

function rm_pd_reset() {
    $GLOBALS['rm_events']        = array();
    $GLOBALS['rm_removed']       = array();
    $GLOBALS['wpdb']->updates    = array();
    \RaceManager\WP_RaceManager::$log = array();
    $GLOBALS['rm_subscriptions'] = array(
        rm_pd_subscriber( 1, 7 ),
        rm_pd_subscriber( 2, 8 ),
        rm_pd_subscriber( 3, 9 ),
    );
}

$log_file = tempnam( sys_get_temp_dir(), 'rm-push-delivery-' );
ini_set( 'error_log', $log_file );

/* --------------------------------------------------------------------------
 * After the answer
 * ----------------------------------------------------------------------- */

rm_test_section( 'After the answer' );

rm_pd_reset();
rm_after_response( function () { $GLOBALS['rm_events'][] = 'task 1'; } );
rm_after_response( function () { throw new \RuntimeException( 'push service gone' ); } );
rm_after_response( function () { $GLOBALS['rm_events'][] = 'task 3'; } );
rm_test_check( 'one shutdown hook, late, for any number of tasks', array( 1000 ) === array_keys( $GLOBALS['rm_actions']['shutdown'] ?? array() )
    && 1 === count( $GLOBALS['rm_actions']['shutdown'][1000] ) );
rm_test_check( 'nothing runs before shutdown', array() === $GLOBALS['rm_events'] );

rm_pd_shutdown();
rm_test_check( 'the answer first, then the tasks in order', array( 'answer sent', 'task 1', 'task 3' ) === $GLOBALS['rm_events'], implode( ', ', $GLOBALS['rm_events'] ) );
rm_test_check( 'debug.log says who closed the connection',
    in_array( 'rm_after_response: 3 task(s) after the answer, connection closed by fastcgi', \RaceManager\WP_RaceManager::$log, true ),
    implode( ' | ', \RaceManager\WP_RaceManager::$log ) );
rm_test_check( 'one that throws is logged and does not stop the next', false !== strpos( (string) file_get_contents( $log_file ), 'rm_after_response: push service gone' ) );

$GLOBALS['rm_events'] = array();
rm_pd_shutdown();
rm_test_check( 'each task runs once, and no answer is sent twice', array() === $GLOBALS['rm_events'] );

rm_test_check( 'here the server is PHP-FPM', 'fastcgi' === rm_finish_response() );

// Which server closes the connection, in a PHP process of its own each: a function cannot be
// undefined again.
function rm_pd_finish_in_child( $litespeed ) {
    $script = tempnam( sys_get_temp_dir(), 'rm-finish-' );
    file_put_contents( $script, "<?php\ndefine( 'ABSPATH', '/' );\nfunction add_action() {}\n"
        . ( $litespeed ? "function litespeed_finish_request() { echo 'closed;'; return true; }\n" : '' )
        . 'require ' . var_export( RM_PLUGIN_DIR . '/includes/after-response.php', true ) . ";\necho rm_finish_response();\n" );
    // shell_exec() answers null for no output at all.
    $out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) );
    unlink( $script );
    return $out;
}
rm_test_check( 'LiteSpeed, as on production: its function closes it', 'closed;litespeed' === rm_pd_finish_in_child( true ) );
rm_test_check( 'neither: the client waits until the request ends, as before', '' === rm_pd_finish_in_child( false ) );

/* --------------------------------------------------------------------------
 * Next up: queued in the request, sent after the answer
 * ----------------------------------------------------------------------- */

rm_test_section( 'Next up: queued in the request, sent after the answer' );

rm_pd_reset();
$handler  = new RM_Test_Handler();
$upcoming = array( rm_pd_upcoming( 7, 3, 0 ), rm_pd_upcoming( 8, 3, 1 ) );
$notified = $handler->send_next_up_notifications( 2578, $upcoming );
rm_test_check( 'the pilots whose followers get a push', array( 7, 8 ) === $notified, var_export( $notified, true ) );
rm_test_check( 'their pushes queued, with what they say', 2 === count( $handler->push->queued )
    && 'TP7: Next race is Heat 3. Channel is R1' === $handler->push->queued[0][1]['body'], var_export( $handler->push->queued, true ) );
rm_test_check( 'the followers\' heat and slot stored in the request', array( 1, 2 ) === array_keys( $GLOBALS['wpdb']->updates )
    && 3 === $GLOBALS['wpdb']->updates[1]['heat_id'] );
rm_test_check( 'nothing sent before the answer', array() === $GLOBALS['rm_events'] );

$handler->push->answers['https://push.example.test/2'] = '410 Gone';
rm_pd_shutdown();
rm_test_check( 'after the answer, all at once', array( 'answer sent', 'sent at once: 2' ) === $GLOBALS['rm_events'], implode( ', ', $GLOBALS['rm_events'] ) );
rm_test_check( 'a subscription its push service says is gone is forgotten', array( 'https://push.example.test/2' ) === $GLOBALS['rm_removed'] );

rm_pd_reset();
$handler         = new RM_Test_Handler();
$handler->pooled = false;
$handler->send_next_up_notifications( 2578, $upcoming );
$handler->push->answers['https://push.example.test/1'] = '500 Internal Server Error';
rm_pd_shutdown();
rm_test_check( 'no async client: one after another, after the answer too',
    array( 'answer sent', 'sent one after another: 2' ) === $GLOBALS['rm_events'], implode( ', ', $GLOBALS['rm_events'] ) );
rm_test_check( 'another failure is logged, the subscription kept',
    array() === $GLOBALS['rm_removed'] && (bool) preg_grep( '/failed to send to: https:\/\/push\.example\.test\/1/', \RaceManager\WP_RaceManager::$log ) );

/* --------------------------------------------------------------------------
 * notify-racers and a new subscriber
 * ----------------------------------------------------------------------- */

rm_test_section( 'A message to everyone following a race' );

rm_pd_reset();
$handler = new RM_Test_Handler();
rm_test_check( 'is taken', true === $handler->send_notification_to_all_in_race( 2578, 'Lunch', 'back at 13:00.' ) );
rm_test_check( 'queued for every follower', 3 === count( $handler->push->queued ) && 'Hi TP9, back at 13:00.' === $handler->push->queued[2][1]['body'] );
rm_test_check( 'and not sent before the answer', array() === $GLOBALS['rm_events'] );
rm_pd_shutdown();
rm_test_check( 'then all at once', array( 'answer sent', 'sent at once: 3' ) === $GLOBALS['rm_events'], implode( ', ', $GLOBALS['rm_events'] ) );

rm_test_section( 'A new subscriber\'s confirmation' );

rm_pd_reset();
$handler = new RM_Test_Handler();
$handler->send_notification_to_subscriber( 'https://push.example.test/9', 'key-9', 'auth-9', 'Subscribed', 'You follow TP9.' );
rm_test_check( 'one push, sent right away', array( 'sent one after another: 1' ) === $GLOBALS['rm_events'], implode( ', ', $GLOBALS['rm_events'] ) );

/* --------------------------------------------------------------------------
 * The client the handler builds, with the real library
 * ----------------------------------------------------------------------- */

rm_test_section( 'The push client (minishlink/web-push)' );

$real = new class() extends \RaceManager\PWA_Subscription_Handler {
    public function client() {
        return $this->web_push();
    }
};
list( $push, $pooled ) = $real->client();
$property = function ( $object, $name ) {
    $reflection = new \ReflectionProperty( $object, $name );
    $reflection->setAccessible( true );
    return $reflection->getValue( $object );
};

rm_test_check( 'a WebPush with the VAPID keys', $push instanceof \Minishlink\WebPush\WebPush );
rm_test_check( 'at most 50 pushes at once', 50 === $push->getDefaultOptions()['batchSize'] );
$client = $property( $push, 'client' );
$config = $client instanceof \GuzzleHttp\Client ? $property( $client, 'config' ) : array();
rm_test_check( 'each push 10 s at most, 5 s to connect', 10 === ( $config['timeout'] ?? null ) && 5 === ( $config['connect_timeout'] ?? null ) );
rm_test_check( 'with php-http/guzzle7-adapter: all at once', true === $pooled && class_exists( '\Http\Adapter\Guzzle7\Client' ),
    'the adapter is missing -- run "composer install"' );
$async = $property( $push, 'asyncClient' );
$async_config = $async instanceof \Http\Adapter\Guzzle7\Client ? $property( $property( $async, 'guzzle' ), 'config' ) : array();
rm_test_check( '  under the same limits', 10 === ( $async_config['timeout'] ?? null ) && 5 === ( $async_config['connect_timeout'] ?? null ) );

unlink( $log_file );
rm_test_finish();

}

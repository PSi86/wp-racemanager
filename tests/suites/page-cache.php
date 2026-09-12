<?php
/**
 * The rm/v1 answers kept out of page caches: includes/rest-handler.php
 *
 * Each answer belongs to the user who asked. LiteSpeed Cache takes a request that logs in with an
 * application password for a guest's and, with its shipped defaults, told the server to keep the
 * answer for anyone for 7 days: on production, two races' registrations and a timer user's race
 * list came out of the cache for requests without a login (2026-09-12). What LiteSpeed Cache 7.9.1
 * makes of these calls was measured on the local site; here, that they are made, for this
 * namespace only.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_actions_done'] = array();
function do_action( $hook, ...$args ) {
    $GLOBALS['rm_actions_done'][] = array( $hook, $args );
}
function current_user_can( $cap = '', ...$args ) { return true; }
function is_user_logged_in() { return true; }
function register_rest_route( $namespace, $route, $args = array() ) {}

class WP_REST_Request {
    private $route;
    public function __construct( $route ) { $this->route = $route; }
    public function get_route() { return $this->route; }
}
class WP_HTTP_Response {
    public $headers = array();
    public function header( $key, $value, $replace = true ) { $this->headers[ $key ] = $value; }
}
class WP_REST_Response extends WP_HTTP_Response {
    public $data;
    public $status;
    public function __construct( $data = null, $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }
}

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/rest-handler.php';

rm_test_section( 'Registered with the routes' );

rm_register_rest_routes_rh();
$callbacks = $GLOBALS['rm_filter_callbacks'] ?? array();
rm_test_check( 'rest_pre_dispatch marks it, given all three arguments',
    in_array( array( 'rm_keep_out_of_page_caches', 3 ), $callbacks['rest_pre_dispatch'] ?? array(), true ) );
rm_test_check( 'rest_post_dispatch adds the header, all three arguments',
    in_array( array( 'rm_say_no_cache', 3 ), $callbacks['rest_post_dispatch'] ?? array(), true ) );

// Another namespace first: DONOTCACHEPAGE is a constant, and once defined it stays.
rm_test_section( 'Another namespace is left alone' );

$result = rm_keep_out_of_page_caches( null, null, new WP_REST_Request( '/wp/v2/posts' ) );
rm_test_check( 'no DONOTCACHEPAGE', ! defined( 'DONOTCACHEPAGE' ) );
rm_test_check( 'no call to LiteSpeed Cache', array() === $GLOBALS['rm_actions_done'] );
rm_test_check( 'the result passes through', null === $result );

$other = rm_say_no_cache( new WP_REST_Response( array() ), null, new WP_REST_Request( '/wp/v2/posts' ) );
rm_test_check( 'no X-LiteSpeed-Cache-Control', ! isset( $other->headers['X-LiteSpeed-Cache-Control'] ) );

rm_test_section( 'An rm/v1 request' );

$earlier = new WP_Error( 'x', 'another filter answered', array( 'status' => 401 ) );
$result  = rm_keep_out_of_page_caches( $earlier, null, new WP_REST_Request( '/rm/v1/get-pilots' ) );
rm_test_check( 'DONOTCACHEPAGE, which the page-cache plugins honour', defined( 'DONOTCACHEPAGE' ) && true === DONOTCACHEPAGE );
rm_test_check( 'LiteSpeed Cache told, with a reason',
    1 === count( $GLOBALS['rm_actions_done'] )
    && 'litespeed_control_set_nocache' === $GLOBALS['rm_actions_done'][0][0]
    && false !== strpos( (string) ( $GLOBALS['rm_actions_done'][0][1][0] ?? '' ), 'WP RaceManager' ),
    var_export( $GLOBALS['rm_actions_done'], true ) );
rm_test_check( 'an answer another filter has already passes through', $earlier === $result );

$GLOBALS['rm_actions_done'] = array();
rm_keep_out_of_page_caches( null, null, new WP_REST_Request( '/rm/v1/races' ) );
rm_test_check( 'the race list too; the constant is not defined twice',
    1 === count( $GLOBALS['rm_actions_done'] ) );

$answer = rm_say_no_cache( new WP_REST_Response( array() ), null, new WP_REST_Request( '/rm/v1/races' ) );
rm_test_check( 'X-LiteSpeed-Cache-Control: no-cache on the answer', 'no-cache' === ( $answer->headers['X-LiteSpeed-Cache-Control'] ?? null ) );

$refusal = rm_say_no_cache( new WP_REST_Response( array(), 401 ), null, new WP_REST_Request( '/rm/v1/get-pilots' ) );
rm_test_check( '  on a refusal as well', 'no-cache' === ( $refusal->headers['X-LiteSpeed-Cache-Control'] ?? null ) );
rm_test_check( 'what is no response passes through', 'x' === rm_say_no_cache( 'x', null, new WP_REST_Request( '/rm/v1/races' ) ) );

rm_test_finish();

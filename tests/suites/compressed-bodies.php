<?php
/**
 * gzip-compressed request bodies on the rm/v1 routes: includes/rest-handler.php
 *
 * A timer's upload is the whole event, up to 1.8 MB, and gzip makes it 7 % of that. Core parses a
 * JSON body before any callback and answers compressed bytes with 400 rest_invalid_json -- measured
 * on production -- so rm_decode_compressed_body() decodes it on rest_pre_dispatch, which runs
 * first. Every answer of the namespace says Accept-Encoding: gzip (RFC 7694), and the timer
 * compresses only once it has read that.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_caps']      = array( 'edit_posts' );
$GLOBALS['rm_logged_in'] = true;

function current_user_can( $cap = '', ...$args ) {
    return in_array( $cap, $GLOBALS['rm_caps'], true );
}
function is_user_logged_in() {
    return $GLOBALS['rm_logged_in'];
}
function register_rest_route( $namespace, $route, $args = array() ) {}

// Header names as WordPress keeps them: case does not matter, and '-' is '_'
// (WP_REST_Request::canonicalize_header_name()).
class WP_REST_Request {
    private $route;
    private $body;
    private $headers = array();

    public function __construct( $route, $body = '', $headers = array() ) {
        $this->route = $route;
        $this->body  = $body;
        foreach ( $headers as $key => $value ) {
            $this->headers[ self::canonical( $key ) ] = $value;
        }
    }
    private static function canonical( $key ) {
        return str_replace( '-', '_', strtolower( $key ) );
    }
    public function get_route() { return $this->route; }
    public function get_body() { return $this->body; }
    public function set_body( $body ) { $this->body = $body; }
    public function get_header( $key ) { return $this->headers[ self::canonical( $key ) ] ?? null; }
    public function remove_header( $key ) { unset( $this->headers[ self::canonical( $key ) ] ); }
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

$event = json_encode( array( 'race_name' => 'Autumn Cup', 'heat_data' => array( 'heats' => array() ) ) );

/**
 * Run the filter on a request to $route with this body and these headers.
 *
 * @return array The filter's result, and the request as it left the filter.
 */
function rm_cb_decode( $body, $headers, $route = '/rm/v1/upload' ) {
    $request = new WP_REST_Request( $route, $body, $headers );
    return array( rm_decode_compressed_body( null, null, $request ), $request );
}

function rm_cb_status( $result ) {
    return is_wp_error( $result ) ? ( $result->get_error_data()['status'] ?? null ) : null;
}

rm_test_section( 'Registered with the routes' );

rm_register_rest_routes_rh();
$callbacks = $GLOBALS['rm_filter_callbacks'] ?? array();
rm_test_check( 'rest_pre_dispatch decodes, given all three arguments',
    in_array( array( 'rm_decode_compressed_body', 3 ), $callbacks['rest_pre_dispatch'] ?? array(), true ) );
rm_test_check( 'rest_post_dispatch announces, given all three arguments',
    in_array( array( 'rm_announce_compressed_bodies', 3 ), $callbacks['rest_post_dispatch'] ?? array(), true ) );

rm_test_section( 'A gzip body' );

list( $result, $request ) = rm_cb_decode( gzencode( $event ), array( 'Content-Encoding' => 'gzip' ) );
rm_test_check( 'is decoded before core reads it as JSON', $event === $request->get_body() );
rm_test_check( '  and dispatch goes on', null === $result );
rm_test_check( '  and the request no longer says it is compressed', null === $request->get_header( 'Content-Encoding' ) );

list( , $request ) = rm_cb_decode( gzencode( $event ), array( 'content-encoding' => ' GZIP ' ) );
rm_test_check( 'the header in any case, with blanks around it', $event === $request->get_body() );

list( , $request ) = rm_cb_decode( gzencode( $event ), array( 'Content-Encoding' => 'x-gzip' ) );
rm_test_check( 'x-gzip, the old name of gzip', $event === $request->get_body() );

// An event as large as the largest upload measured, 1.8 MB, and a new race's body alike.
$large = json_encode( array( 'race_name' => 'Big', 'result_data' => array_fill( 0, 60000, array( 'callsign' => 'TP01', 'lap' => 12.345 ) ) ) );
list( , $request ) = rm_cb_decode( gzencode( $large, 6 ), array( 'Content-Encoding' => 'gzip' ), '/rm/v1/races' );
rm_test_check( sprintf( 'a %.1f MB event, on POST /races too', strlen( $large ) / 1048576 ), $large === $request->get_body() );

rm_test_section( 'What stays as it came' );

list( $result, $request ) = rm_cb_decode( $event, array() );
rm_test_check( 'a body without Content-Encoding', null === $result && $event === $request->get_body() );

list( $result, $request ) = rm_cb_decode( $event, array( 'Content-Encoding' => 'identity' ) );
rm_test_check( 'identity, which is no encoding', null === $result && $event === $request->get_body() );

$compressed = gzencode( $event );
list( $result, $request ) = rm_cb_decode( $compressed, array( 'Content-Encoding' => 'gzip' ), '/wp/v2/posts' );
rm_test_check( 'a route of another namespace', null === $result && $compressed === $request->get_body() );

$earlier = new WP_Error( 'x', 'another filter answered', array( 'status' => 500 ) );
$request = new WP_REST_Request( '/rm/v1/upload', $compressed, array( 'Content-Encoding' => 'gzip' ) );
rm_test_check( 'an answer another filter has already',
    $earlier === rm_decode_compressed_body( $earlier, null, $request ) && $compressed === $request->get_body() );

rm_test_section( 'Refused' );

list( $result ) = rm_cb_decode( 'this is no gzip', array( 'Content-Encoding' => 'gzip' ) );
rm_test_check( 'a body that is no gzip: 400', 400 === rm_cb_status( $result ) );

list( $result ) = rm_cb_decode( substr( gzencode( $event ), 0, -12 ), array( 'Content-Encoding' => 'gzip' ) );
rm_test_check( 'a gzip body cut short: 400', 400 === rm_cb_status( $result ) );

list( $result ) = rm_cb_decode( $event, array( 'Content-Encoding' => 'br' ) );
rm_test_check( 'another encoding: 415', 415 === rm_cb_status( $result ) );

// 20 MB of zeros make a gzip stream of 20 kB: a body built to fill the memory.
$bomb = gzencode( str_repeat( "\0", 20 * 1048576 ), 9 );
memory_reset_peak_usage();
$base = memory_get_usage();
list( $result, $request ) = rm_cb_decode( $bomb, array( 'Content-Encoding' => 'gzip' ) );
$peak = memory_get_peak_usage() - $base;
rm_test_check( sprintf( 'a %d kB body that inflates to 20 MB: 400', strlen( $bomb ) / 1024 ), 400 === rm_cb_status( $result ) );
rm_test_check( '  saying the limit', is_wp_error( $result ) && false !== strpos( $result->get_error_message(), '10 MB' ) );
rm_test_check( sprintf( '  inflating %.1f MB at most on the way', $peak / 1048576 ), $peak < 13 * 1048576, 'peak ' . $peak );
rm_test_check( '  and the request keeps its body', $bomb === $request->get_body() );

$max = str_repeat( 'a', RM_MAX_BODY_BYTES );
rm_test_check( '10 MB decoded still decode', $max === rm_gunzip( gzencode( $max ), RM_MAX_BODY_BYTES ) );
rm_test_check( 'one byte more does not', null === rm_gunzip( gzencode( $max . 'a' ), RM_MAX_BODY_BYTES ) );
unset( $max );

rm_test_section( 'Nothing inflated for a stranger' );

$GLOBALS['rm_caps']      = array();
$GLOBALS['rm_logged_in'] = false;
list( $result, $request ) = rm_cb_decode( $bomb, array( 'Content-Encoding' => 'gzip' ) );
rm_test_check( 'without a login: 401, as the endpoints answer it', 401 === rm_cb_status( $result ) );
rm_test_check( '  with the body left compressed', $bomb === $request->get_body() );

$GLOBALS['rm_logged_in'] = true;
list( $result ) = rm_cb_decode( gzencode( $event ), array( 'Content-Encoding' => 'gzip' ) );
rm_test_check( 'logged in, but not allowed to edit posts: 403', 403 === rm_cb_status( $result ) );

list( $result, $request ) = rm_cb_decode( $event, array() );
rm_test_check( 'an uncompressed body is the gate\'s to refuse, later', null === $result && $event === $request->get_body() );
$GLOBALS['rm_caps'] = array( 'edit_posts' );

rm_test_section( 'Every answer says so' );

$response = new WP_REST_Response( array( 'status' => 'success' ) );
rm_announce_compressed_bodies( $response, null, new WP_REST_Request( '/rm/v1/races' ) );
rm_test_check( 'Accept-Encoding: gzip on this namespace', 'gzip' === ( $response->headers['Accept-Encoding'] ?? null ) );

$refusal = new WP_REST_Response( array( 'code' => 'rm_unsupported_encoding' ), 415 );
rm_announce_compressed_bodies( $refusal, null, new WP_REST_Request( '/rm/v1/upload' ) );
rm_test_check( '  a 415 as well, as RFC 7694 asks', 'gzip' === ( $refusal->headers['Accept-Encoding'] ?? null ) );

$other = new WP_REST_Response( array() );
rm_announce_compressed_bodies( $other, null, new WP_REST_Request( '/wp/v2/posts' ) );
rm_test_check( 'not on another namespace', ! isset( $other->headers['Accept-Encoding'] ) );
rm_test_check( 'what is no response passes through', 'x' === rm_announce_compressed_bodies( 'x', null, new WP_REST_Request( '/rm/v1/upload' ) ) );

rm_test_finish();

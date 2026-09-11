<?php
/**
 * Who may talk to the endpoints RotorHazard uses.
 *
 * E6: every route was guarded by is_user_logged_in() alone. On a site whose pilots have
 * accounts, that is everyone -- each of them could have a 10 MB body decoded and validated
 * before the per-race capability check inside the handler said no. The API key check that was
 * supposed to be the real gate had been commented out and compared keys with !==.
 */

require_once __DIR__ . '/../bootstrap.php';

/* --------------------------------------------------------------------------
 * Stubs: an authentication state this suite can steer
 * ----------------------------------------------------------------------- */

$GLOBALS['rm_logged_in']   = false;
$GLOBALS['rm_caps']        = array();
$GLOBALS['rm_rest_routes'] = array();

function current_user_can( $cap = '', ...$args ) {
    return in_array( $cap, $GLOBALS['rm_caps'], true );
}

function is_user_logged_in() {
    return (bool) $GLOBALS['rm_logged_in'];
}

function register_rest_route( $namespace, $route, $args = array() ) {
    $GLOBALS['rm_rest_routes'][ $namespace . $route ] = $args;
    return true;
}

function rest_is_integer( $value ) {
    return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
}

require_once RM_TEST_DIR . '/stubs/wordpress.php';

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private $headers = array();
        private $params;
        public function __construct( $params = array() ) { $this->params = $params; }
        public function get_header( $key ) { return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null; }
        public function get_body() { return ''; }
        public function get_param( $key ) { return $this->params[ $key ] ?? null; }
    }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public function __construct( $data = null, $status = 200 ) {}
    }
}
if ( ! class_exists( 'WP_Query' ) ) {
    class WP_Query {
        public $posts = array();
        public function __construct( $args = array() ) {}
        public function have_posts() { return false; }
    }
}

require_once RM_PLUGIN_DIR . '/includes/rest-handler.php';

$request = new WP_REST_Request();

/* --------------------------------------------------------------------------
 * The gate itself
 * ----------------------------------------------------------------------- */

rm_test_section( 'permission_check_user()' );

$GLOBALS['rm_logged_in'] = false;
$GLOBALS['rm_caps']      = array();
$result = permission_check_user( $request );
rm_test_check( 'a stranger is rejected', is_wp_error( $result ) );
rm_test_check( 'with 401, not 403', is_wp_error( $result ) && 401 === $result->get_error_data()['status'] );

$GLOBALS['rm_logged_in'] = true;
$result = permission_check_user( $request );
rm_test_check( 'a logged-in subscriber is rejected too', is_wp_error( $result ),
    'this is the whole point of E6: "logged in" is not a permission' );
rm_test_check( 'with 403', is_wp_error( $result ) && 403 === $result->get_error_data()['status'] );

$GLOBALS['rm_caps'] = array( 'edit_posts' );
rm_test_check( 'an author or editor is let through', true === permission_check_user( $request ) );

$GLOBALS['rm_caps'] = array( 'read', 'upload_files' );
rm_test_check( 'unrelated capabilities do not help', is_wp_error( permission_check_user( $request ) ) );

/* --------------------------------------------------------------------------
 * Every route is actually behind it
 * ----------------------------------------------------------------------- */

rm_test_section( 'Route registration' );

rm_register_rest_routes_rh();
$routes = $GLOBALS['rm_rest_routes'];

rm_test_check( 'four routes registered', 4 === count( $routes ), implode( ', ', array_keys( $routes ) ) );

// A route is one endpoint, or a list of them when it answers more than one method
// (GET and POST /races).
$endpoints = array();
foreach ( $routes as $route => $args ) {
    foreach ( isset( $args['methods'] ) ? array( $args ) : $args as $endpoint ) {
        $endpoints[] = array( 'route' => $route ) + $endpoint;
    }
}
rm_test_check( 'five endpoints', 5 === count( $endpoints ),
    implode( ', ', array_map( fn( $e ) => $e['methods'] . ' ' . $e['route'], $endpoints ) ) );
foreach ( $endpoints as $endpoint ) {
    rm_test_check( "{$endpoint['methods']} {$endpoint['route']} is guarded",
        in_array( $endpoint['permission_callback'] ?? null, array( 'permission_check_user', 'permission_check_user_and_race' ), true ) );
}
rm_test_check( 'no endpoint falls back to __return_true',
    ! in_array( '__return_true', array_column( $endpoints, 'permission_callback' ), true ) );

// WordPress validates a route's parameters before it calls its permission callback. A per-race
// check in race_id's validate_callback therefore answered a request without a valid login --
// a revoked application password, say -- with 400 "Invalid parameter(s): race_id" instead of
// 401. Measured on the local site against 1.3.2, for the upload and for get-pilots.
$race_id_arg = $routes['rm/v1/upload']['args']['race_id'] ?? array();
rm_test_check( 'upload takes race_id, optional, and checks only its form there',
    false === ( $race_id_arg['required'] ?? null )
    && 'rm_validate_race_id' === ( $race_id_arg['validate_callback'] ?? null ) );
rm_test_check( 'get-pilots checks the race after the login, in its permission callback',
    'permission_check_user_and_race' === ( $routes['rm/v1/get-pilots']['permission_callback'] ?? null )
    && 'rm_validate_race_id' === ( $routes['rm/v1/get-pilots']['args']['race_id']['validate_callback'] ?? null ) );

rm_test_section( 'race_id: its form' );

rm_test_check( 'a whole number is a race_id', true === rm_validate_race_id( '77', $request, 'race_id' ) );
foreach ( array( 'abc', '0', '-5', '7.5', '' ) as $bad ) {
    rm_test_check( "'$bad' is not", is_wp_error( rm_validate_race_id( $bad, $request, 'race_id' ) ) );
}

rm_test_section( 'get-pilots: the login, then the race' );

rm_test_post( 77, 'race', 'autumn-cup' );
rm_test_post( 78, 'page', 'about' );
$for_77 = new WP_REST_Request( array( 'race_id' => '77' ) );

$GLOBALS['rm_logged_in'] = false;
$GLOBALS['rm_caps']      = array();
$result = permission_check_user_and_race( $for_77 );
rm_test_check( 'no valid login: 401 from the login check, before the race is looked at',
    is_wp_error( $result ) && 'rest_forbidden' === $result->get_error_code() && 401 === $result->get_error_data()['status'] );

$GLOBALS['rm_logged_in'] = true;
$GLOBALS['rm_caps']      = array( 'edit_posts' ); // not edit_post on the race itself
$result = permission_check_user_and_race( $for_77 );
rm_test_check( 'a race the user may not edit: 403', is_wp_error( $result ) && 403 === $result->get_error_data()['status'] );
$GLOBALS['rm_caps'] = array( 'edit_posts', 'edit_post' );
rm_test_check( 'one they may edit: through', true === permission_check_user_and_race( $for_77 ) );
$GLOBALS['rm_caps'] = array( 'edit_posts' );
rm_test_check( 'an ID that is no race goes on to the handler, which answers 404',
    true === permission_check_user_and_race( new WP_REST_Request( array( 'race_id' => '78' ) ) ) );

/* --------------------------------------------------------------------------
 * The dead key check is gone
 * ----------------------------------------------------------------------- */

rm_test_section( 'Dead API key code (E6)' );

$source = file_get_contents( RM_PLUGIN_DIR . '/includes/rest-handler.php' );
rm_test_check( 'rm_validate_api_key() is removed', ! function_exists( 'rm_validate_api_key' ) );
rm_test_check( 'and so is its commented-out caller', ! str_contains( $source, 'rm_validate_api_key' ) );
rm_test_check( 'the rm_api_key option is no longer read', ! str_contains( $source, 'rm_api_key' ),
    'a key compared with !== is not an authentication mechanism' );

rm_test_finish();

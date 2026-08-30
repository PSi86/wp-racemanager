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
        public function get_header( $key ) { return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null; }
        public function get_body() { return ''; }
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

rm_test_check( 'three routes registered', 3 === count( $routes ), implode( ', ', array_keys( $routes ) ) );
foreach ( array( 'rm/v1/upload', 'rm/v1/get-pilots', 'rm/v1/notify-racers' ) as $route ) {
    rm_test_check( "$route is guarded",
        isset( $routes[ $route ]['permission_callback'] ) && 'permission_check_user' === $routes[ $route ]['permission_callback'] );
}
rm_test_check( 'no route falls back to __return_true',
    ! in_array( '__return_true', array_column( $routes, 'permission_callback' ), true ) );

rm_test_section( 'The per-race check is still there' );

$GLOBALS['rm_caps'] = array( 'edit_posts' ); // not edit_post on the race itself
$denied = permission_check_race_id( '77', $request, 'race_id' );
rm_test_check( 'get-pilots refuses a race the user cannot edit', is_wp_error( $denied ) );
$GLOBALS['rm_caps'] = array( 'edit_post' );
rm_test_check( 'and allows one they can', true === permission_check_race_id( '77', $request, 'race_id' ) );
rm_test_check( 'a non-numeric race_id is rejected', is_wp_error( permission_check_race_id( 'abc', $request, 'race_id' ) ) );

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

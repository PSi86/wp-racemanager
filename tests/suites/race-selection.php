<?php
/**
 * Choosing the race on the timer: GET /races, POST /races, and the upload by race_id.
 *
 * The upload used to find its race by title alone -- one character off, and it created a second
 * race, which the timer then switched to (D3 in the RotorHazard plugin's roadmap). A timer now
 * names its race by ID, gets the list of races it may upload to, and creates one on purpose. The
 * lookup by title stays for one release, for timers with an older plugin. The design is
 * docs/race-selection.md in the RotorHazard plugin's repository.
 */

namespace RaceManager {
    // The plugin's main class as far as rm_notify_nextup() reaches: no push handler loaded.
    class WP_RaceManager {
        public $pwa_subscription_handler = null;
        public static $live = true;

        public static function instance() {
            static $instance = null;
            return $instance ?? ( $instance = new self() );
        }

        public static function is_live_page() {
            return self::$live;
        }

        public static function write_log( $message ) {}
    }
}

namespace {

require_once __DIR__ . '/../bootstrap.php';

/* --------------------------------------------------------------------------
 * Stubs: a site with races, capabilities and meta this suite can steer
 * ----------------------------------------------------------------------- */

$GLOBALS['rm_caps']            = array();
$GLOBALS['rm_editable']        = array();
$GLOBALS['rm_meta']            = array();
$GLOBALS['rm_inserted']        = array();
$GLOBALS['rm_deleted']         = array();
$GLOBALS['rm_queries']         = array();
$GLOBALS['rm_listed']          = array();
$GLOBALS['rm_data_dir']        = sys_get_temp_dir() . '/rm-race-selection-' . getmypid() . '/';
$GLOBALS['rm_data_dir_broken'] = false;

function current_user_can( $cap = '', ...$args ) {
    if ( 'edit_post' === $cap ) {
        return in_array( (int) ( $args[0] ?? 0 ), $GLOBALS['rm_editable'], true );
    }
    return in_array( $cap, $GLOBALS['rm_caps'], true );
}
function is_user_logged_in() {
    return true;
}
function get_post_status( $id ) {
    $post = get_post( $id );
    return $post ? $post->post_status : false;
}
function get_post_meta( $id, $key = '', $single = false ) {
    return $GLOBALS['rm_meta'][ $id ][ $key ] ?? '';
}
// WordPress stores a scalar as a string and hands it back as one: 1 comes back as '1'.
function update_post_meta( $id, $key, $value ) {
    $GLOBALS['rm_meta'][ $id ][ $key ] = is_bool( $value ) ? ( $value ? '1' : '' ) : ( is_scalar( $value ) ? (string) $value : $value );
    return true;
}
function get_post_field( $field, $id ) {
    $post = get_post( $id );
    return $post ? $post->$field : '';
}
function current_time( $type ) {
    return '2026-09-11 10:00:00';
}
function sanitize_textarea_field( $value ) {
    return trim( (string) $value );
}
function wp_insert_post( $args ) {
    $id = 9000 + count( $GLOBALS['rm_inserted'] );
    rm_test_post( $id, $args['post_type'], sanitize_title( $args['post_title'] ), $args['post_status'], 0, $args['post_title'] );
    $GLOBALS['rm_inserted'][] = $id;
    return $id;
}
function wp_delete_post( $id, $force = false ) {
    unset( $GLOBALS['rm_posts'][ $id ] );
    $GLOBALS['rm_deleted'][] = $id;
    return true;
}
function rm_get_race_data_dir( $create = true ) {
    if ( $GLOBALS['rm_data_dir_broken'] ) {
        return new WP_Error( 'upload_dir_unavailable', 'Directory is not writable.', array( 'status' => 500 ) );
    }
    if ( $create && ! is_dir( $GLOBALS['rm_data_dir'] ) ) {
        mkdir( $GLOBALS['rm_data_dir'], 0777, true );
    }
    return $GLOBALS['rm_data_dir'];
}
function rm_normalize_event_datetime( $value ) {
    return date( 'Y-m-d H:i:s', (int) $value );
}
function rm_getUpcomingRacePilots( $data ) {
    return array();
}
function wp_upload_dir() {
    return array( 'basedir' => sys_get_temp_dir(), 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => '' );
}
function wp_check_filetype( $file, $mimes = null ) {
    return array( 'ext' => 'json', 'type' => 'application/json' );
}
function wp_insert_attachment( $attachment, $file, $parent ) {
    return 1;
}
function wp_generate_attachment_metadata( $id, $file ) {
    return array();
}
function wp_update_attachment_metadata( $id, $data ) {
    return true;
}

// rm_create_wp_attachment() loads wp-admin/includes/image.php for the attachment metadata.
if ( ! is_file( ABSPATH . 'wp-admin/includes/image.php' ) ) {
    @mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
    file_put_contents( ABSPATH . 'wp-admin/includes/image.php', "<?php\n" );
}

class WP_REST_Request {
    private $body;
    private $params;

    public function __construct( $body = '', $params = array() ) {
        $this->body   = $body;
        $this->params = $params;
    }
    public function get_body() { return $this->body; }
    public function get_json_params() { return json_decode( $this->body, true ); }
    public function get_param( $key ) { return $this->params[ $key ] ?? null; }
    public function get_header( $key ) { return null; }
}

class WP_REST_Response {
    public $data;
    public $status;

    public function __construct( $data = null, $status = 200 ) {
        $this->data   = $data;
        $this->status = $status;
    }
}

// A title query matches post_title exactly and, with post_status 'any', skips the bin; any
// other query is the race list and answers with whatever the suite put into rm_listed.
class WP_Query {
    public $posts = array();

    public function __construct( $args = array() ) {
        $GLOBALS['rm_queries'][] = $args;
        if ( isset( $args['title'] ) ) {
            foreach ( $GLOBALS['rm_posts'] as $post ) {
                if ( 'race' === $post->post_type && 'trash' !== $post->post_status && $post->post_title === $args['title'] ) {
                    $this->posts[] = $post->ID;
                }
            }
            $this->posts = array_slice( $this->posts, 0, $args['posts_per_page'] ?? 10 );
            return;
        }
        $this->posts = $GLOBALS['rm_listed'];
    }
    public function have_posts() { return ! empty( $this->posts ); }
}

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/rest-handler.php';

/* --------------------------------------------------------------------------
 * Fixtures
 * ----------------------------------------------------------------------- */

function rm_rs_reset() {
    $GLOBALS['rm_posts']           = array();
    $GLOBALS['rm_meta']            = array();
    $GLOBALS['rm_inserted']        = array();
    $GLOBALS['rm_deleted']         = array();
    $GLOBALS['rm_queries']         = array();
    $GLOBALS['rm_listed']          = array();
    $GLOBALS['rm_caps']            = array( 'edit_posts', 'publish_posts' );
    $GLOBALS['rm_editable']        = array();
    $GLOBALS['rm_data_dir_broken'] = false;
}

function rm_rs_race( $id, $title, $live = true, $status = 'publish' ) {
    rm_test_post( $id, 'race', sanitize_title( $title ), $status, 0, $title );
    update_post_meta( $id, '_race_live', $live ? 1 : 0 );
    $GLOBALS['rm_editable'][] = $id;
}

function rm_rs_event( $name = 'Autumn Cup' ) {
    return array( 'race_name' => $name, 'race_description' => 'Two classes', 'heat_data' => array( 'heats' => array() ) );
}

function rm_rs_upload( $event, $params = array() ) {
    return rm_handle_upload( new WP_REST_Request( json_encode( $event ), $params ) );
}

function rm_rs_written( $race_id ) {
    return is_file( $GLOBALS['rm_data_dir'] . $race_id . '-data.json' );
}

function rm_rs_title_queries() {
    return count( array_filter( $GLOBALS['rm_queries'], fn( $args ) => isset( $args['title'] ) ) );
}

/* --------------------------------------------------------------------------
 * The upload names its race
 * ----------------------------------------------------------------------- */

rm_test_section( 'Upload with race_id' );

rm_rs_reset();
rm_rs_race( 2578, 'Autumn Cup' );
$response = rm_rs_upload( rm_rs_event( 'Autumn Cup 2026' ), array( 'race_id' => '2578' ) );
rm_test_check( 'updates the race it names: 200', 200 === $response->status, print_r( $response->data, true ) );
rm_test_check( 'and answers with its ID', 2578 === $response->data['id'] );
rm_test_check( 'the files are written', rm_rs_written( 2578 ) );
rm_test_check( 'the last upload is recorded', '2026-09-11 10:00:00' === get_post_meta( 2578, '_race_last_upload', true ) );
rm_test_check( 'a title that matches nothing creates nothing', array() === $GLOBALS['rm_inserted'] );
rm_test_check( 'and is not even looked up', 0 === rm_rs_title_queries() );

rm_rs_reset();
rm_test_post( 77, 'post', 'news', 'publish', 0, 'News' );
$GLOBALS['rm_editable'] = array( 77 );
$response = rm_rs_upload( rm_rs_event(), array( 'race_id' => '77' ) );
rm_test_check( 'an ID that is no race: 404', 404 === $response->status );
rm_test_check( 'nothing created instead', array() === $GLOBALS['rm_inserted'] && ! rm_rs_written( 77 ) );

rm_rs_reset();
rm_rs_race( 2579, 'Binned', true, 'trash' );
rm_test_check( 'a race in the bin: 404', 404 === rm_rs_upload( rm_rs_event(), array( 'race_id' => '2579' ) )->status );

rm_rs_reset();
rm_rs_race( 2580, 'Finished', false );
$response = rm_rs_upload( rm_rs_event(), array( 'race_id' => '2580' ) );
rm_test_check( 'a race not flagged live: 400', 400 === $response->status );
rm_test_check( 'with its ID, as before', 2580 === $response->data['id'] );
rm_test_check( 'and its files untouched', ! rm_rs_written( 2580 ) );

rm_rs_reset();
rm_rs_race( 2581, 'Not mine' );
$GLOBALS['rm_editable'] = array();
rm_test_check( 'a race the user may not edit: 403', 403 === rm_rs_upload( rm_rs_event(), array( 'race_id' => '2581' ) )->status );

rm_rs_reset();
rm_rs_race( 2582, 'Autumn Cup' );
$GLOBALS['rm_data_dir_broken'] = true;
rm_test_check( 'files that cannot be written: 500', 500 === rm_rs_upload( rm_rs_event(), array( 'race_id' => '2582' ) )->status );

/* --------------------------------------------------------------------------
 * Without race_id: by title, as before
 * ----------------------------------------------------------------------- */

rm_test_section( 'Upload without race_id (older timers)' );

rm_rs_reset();
rm_rs_race( 2578, 'Autumn Cup' );
$response = rm_rs_upload( rm_rs_event( 'Autumn Cup' ) );
rm_test_check( 'a known title updates that race: 200', 200 === $response->status && 2578 === $response->data['id'] );
rm_test_check( 'nothing created', array() === $GLOBALS['rm_inserted'] );

rm_rs_reset();
$response = rm_rs_upload( rm_rs_event( 'Spring Cup' ) );
rm_test_check( 'an unknown title creates a race: 201', 201 === $response->status, print_r( $response->data, true ) );
$created = $GLOBALS['rm_inserted'][0] ?? 0;
rm_test_check( 'titled after the event', $created && 'Spring Cup' === get_post_field( 'post_title', $created ) );
rm_test_check( 'flagged live, with its files', '1' === get_post_meta( $created, '_race_live', true ) && rm_rs_written( $created ) );
rm_test_check( 'with placeholder times',
    '' !== get_post_meta( $created, '_race_event_start', true ) && '' !== get_post_meta( $created, '_race_event_end', true ) );

rm_rs_reset();
$GLOBALS['rm_caps'] = array( 'edit_posts' );
$response = rm_rs_upload( rm_rs_event( 'Spring Cup' ) );
rm_test_check( 'without publish_posts: 403', 403 === $response->status );
rm_test_check( 'and nothing created', array() === $GLOBALS['rm_inserted'] );

rm_rs_reset();
$GLOBALS['rm_data_dir_broken'] = true;
$response = rm_rs_upload( rm_rs_event( 'Spring Cup' ) );
rm_test_check( 'a race whose files cannot be written is removed again', 1 === count( $GLOBALS['rm_deleted'] ) );
rm_test_check( 'and the answer says so: 500', 500 === $response->status );

/* --------------------------------------------------------------------------
 * The list a timer chooses from
 * ----------------------------------------------------------------------- */

rm_test_section( 'GET /races' );

rm_rs_reset();
rm_rs_race( 10, 'Spring Cup' );
rm_rs_race( 11, 'Summer Cup', false );
rm_rs_race( 12, 'Someone else\'s' );
update_post_meta( 10, '_race_event_start', '2026-05-01 08:00:00' );
update_post_meta( 10, '_race_event_end', '2026-05-01 19:00:00' );
update_post_meta( 11, '_race_event_start', '2026-07-01 08:00:00' );
$GLOBALS['rm_editable'] = array( 10, 11 );
$GLOBALS['rm_listed']   = array( 12, 11, 10 );
$response = rm_list_races( new WP_REST_Request() );
rm_test_check( '200', 200 === $response->status );
rm_test_check( 'only the races the user may edit, in the order found',
    array( 11, 10 ) === array_column( $response->data, 'id' ), print_r( $response->data, true ) );
rm_test_check( 'with title, dates and the live flag',
    array( 'id' => 10, 'title' => 'Spring Cup', 'start' => '2026-05-01 08:00:00', 'end' => '2026-05-01 19:00:00', 'live' => true ) === $response->data[1] );
rm_test_check( 'a race that is not live says so', false === $response->data[0]['live'] );
$args = end( $GLOBALS['rm_queries'] );
rm_test_check( 'asks for races in any status but the bin', 'race' === $args['post_type'] && 'any' === $args['post_status'] );
rm_test_check( 'newest event first, at most 50',
    '_race_event_start' === $args['meta_key'] && 'meta_value' === $args['orderby'] && 'DESC' === $args['order'] && 50 === $args['posts_per_page'] );

rm_rs_reset();
rm_test_check( 'no races: an empty list', array() === rm_list_races( new WP_REST_Request() )->data );

/* --------------------------------------------------------------------------
 * Creating a race on purpose
 * ----------------------------------------------------------------------- */

rm_test_section( 'POST /races' );

rm_rs_reset();
rm_rs_race( 2578, 'Autumn Cup' );
$response = rm_handle_create_race( new WP_REST_Request( json_encode( rm_rs_event( 'Autumn Cup' ) ) ) );
rm_test_check( 'creates a race: 201', 201 === $response->status, print_r( $response->data, true ) );
rm_test_check( 'a new one, although the title exists', 1 === count( $GLOBALS['rm_inserted'] ) && 2578 !== $response->data['id'] );
rm_test_check( 'answers with the new ID', $GLOBALS['rm_inserted'][0] === $response->data['id'] );
rm_test_check( 'with the event\'s files from the start', rm_rs_written( $response->data['id'] ) );

rm_rs_reset();
$response = rm_handle_create_race( new WP_REST_Request( '{"race_name": ' ) );
rm_test_check( 'a body that is no JSON: 400', 400 === $response->status && array() === $GLOBALS['rm_inserted'] );
$response = rm_handle_create_race( new WP_REST_Request( json_encode( array( 'heat_data' => array() ) ) ) );
rm_test_check( 'an event without race_name: 400', 400 === $response->status && array() === $GLOBALS['rm_inserted'] );

rm_rs_reset();
$GLOBALS['rm_caps'] = array( 'edit_posts' );
$response = rm_handle_create_race( new WP_REST_Request( json_encode( rm_rs_event() ) ) );
rm_test_check( 'without publish_posts: 403', 403 === $response->status && array() === $GLOBALS['rm_inserted'] );

/* --------------------------------------------------------------------------
 * The error answer
 * ----------------------------------------------------------------------- */

rm_test_section( 'rm_race_error_response()' );

rm_test_check( 'status and ID from the error',
    404 === rm_race_error_response( new WP_Error( 'not_found', 'x', array( 'status' => 404 ) ) )->status );
$locked = rm_race_error_response( new WP_Error( 'race_locked', 'x', array( 'status' => 400, 'id' => 5 ) ) );
rm_test_check( 'a locked race keeps its ID in the answer', 400 === $locked->status && 5 === $locked->data['id'] );
$plain = rm_race_error_response( new WP_Error( 'other', 'x' ) );
rm_test_check( 'an error without status is a 400 without ID', 400 === $plain->status && 0 === $plain->data['id'] );

// Clean up.
array_map( 'unlink', glob( $GLOBALS['rm_data_dir'] . '*' ) ?: array() );
@rmdir( $GLOBALS['rm_data_dir'] );

rm_test_finish();

}

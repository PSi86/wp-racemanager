<?php
/**
 * The pilot key: one identifier per registered email address, the same every time, with no
 * account involved (decided on 2026-09-11, see docs/pilot-identity.md).
 *
 * RotorHazard matched returning pilots by the registration's user_id, which is 0 for everyone who
 * registers without a WordPress account -- most entrants -- and then by callsign, which fails the
 * moment a pilot changes it. What has to hold for the key to replace both:
 *
 *   - it is a version-5 UUID computed the way RFC 9562 defines it, checked against published
 *     values rather than against itself;
 *   - one address gives one key, whatever its case or surrounding blanks, and every call gives
 *     the same one; another address, another key;
 *   - it is derived in this site's own namespace, which is created once and then kept, so it is
 *     not a hash of the address anyone could recompute;
 *   - RM_PILOT_NAMESPACE in wp-config.php wins, and an invalid one yields no keys at all rather
 *     than quietly different ones;
 *   - every row the admin list, the CSV export and get-pilots show carries the key of its
 *     address -- checked on get-pilots itself, the endpoint RotorHazard calls.
 */

require_once __DIR__ . '/../bootstrap.php';

// Core's maybe_unserialize() without is_serialized()'s edge cases, which these rows never hit.
function maybe_unserialize( $data ) {
    if ( is_string( $data ) ) {
        $value = @unserialize( $data );
        if ( false !== $value || 'b:0;' === $data ) {
            return $value;
        }
    }
    return $data;
}

// get-pilots reads the race as $request['race_id']; the real class implements ArrayAccess.
class WP_REST_Request implements ArrayAccess {
    private $params;
    public function __construct( $params = array() ) { $this->params = $params; }
    public function get_param( $key ) { return $this->params[ $key ] ?? null; }
    public function offsetExists( $key ): bool { return isset( $this->params[ $key ] ); }
    public function offsetGet( $key ): mixed { return $this->params[ $key ] ?? null; }
    public function offsetSet( $key, $value ): void { $this->params[ $key ] = $value; }
    public function offsetUnset( $key ): void { unset( $this->params[ $key ] ); }
}
class WP_REST_Response {
    public $data;
    public $status;
    public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
}
function rest_ensure_response( $data ) { return $data instanceof WP_REST_Response ? $data : new WP_REST_Response( $data ); }

// The registrations table, as get_results() hands it back. ARRAY_A as wp-includes/class-wpdb.php
// defines it.
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}
class RM_Test_Registrations {
    public $prefix = 'wp_';
    public $rows   = array();
    public function prepare( $query, ...$args ) { return $query; }
    public function get_results( $query, $output = null ) { return $this->rows; }
}
$GLOBALS['wpdb'] = new RM_Test_Registrations();

$GLOBALS['rm_options'] = array();

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/pilot-key.php';
require_once RM_PLUGIN_DIR . '/includes/admin-registrations.php';
require_once RM_PLUGIN_DIR . '/includes/rest-handler.php';

const RM_TEST_UUID_V5 = '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
const RM_TEST_NS_DNS  = '6ba7b810-9dad-11d1-80b4-00c04fd430c8'; // RFC 9562, appendix A

rm_test_section( 'A version-5 UUID, as RFC 9562 defines it' );
// Published values, computed by somebody else's implementation: Python's documentation gives
// the first, the second is the example the RFC's predecessor used. Both were also recomputed
// with Node's crypto module before this suite relied on them.
rm_test_check( 'uuid5(DNS, "python.org") is Python\'s documented value',
    '886313e1-3b8a-5372-9b90-0c9aee199e5d' === rm_uuid5( RM_TEST_NS_DNS, 'python.org' ),
    rm_uuid5( RM_TEST_NS_DNS, 'python.org' ) );
rm_test_check( 'uuid5(DNS, "www.example.com") too',
    '2ed6657d-e927-568b-95e1-2665a8aea6a2' === rm_uuid5( RM_TEST_NS_DNS, 'www.example.com' ),
    rm_uuid5( RM_TEST_NS_DNS, 'www.example.com' ) );

rm_test_section( 'One address, one key, every time' );
$key = rm_pilot_key( 'pilot@example.com' );
$ns  = $GLOBALS['rm_options']['rm_pilot_namespace'] ?? null;
rm_test_check( 'the first key creates this site\'s namespace, a random version-4 UUID',
    is_string( $ns ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $ns ),
    var_export( $ns, true ) );
rm_test_check( 'the key is a version-5 UUID', 1 === preg_match( RM_TEST_UUID_V5, $key ), $key );
rm_test_check( 'derived in that namespace', $key === rm_uuid5( $ns, 'pilot@example.com' ) );
rm_test_check( 'the same key on the next call, the namespace untouched',
    $key === rm_pilot_key( 'pilot@example.com' ) && $ns === $GLOBALS['rm_options']['rm_pilot_namespace'] );
rm_test_check( 'case and surrounding blanks make no difference',
    $key === rm_pilot_key( "  Pilot@Example.COM\n" ), rm_pilot_key( "  Pilot@Example.COM\n" ) );
rm_test_check( 'another address, another key', $key !== rm_pilot_key( 'pilot2@example.com' ) );
rm_test_check( 'no address, no key', '' === rm_pilot_key( '' ) && '' === rm_pilot_key( '   ' ) && '' === rm_pilot_key( null ) );
// What the namespace is for: the same address on another site is another key, and nobody
// holding a list of addresses can recompute this one without the namespace.
$GLOBALS['rm_options']['rm_pilot_namespace'] = '0f1e2d3c-4b5a-4968-8776-655443322110';
rm_test_check( 'in another namespace the same address has another key', $key !== rm_pilot_key( 'pilot@example.com' ) );
$GLOBALS['rm_options']['rm_pilot_namespace'] = $ns;

rm_test_section( 'Every row carries the key of its address' );
$GLOBALS['wpdb']->rows = array(
    array( 'id' => 1, 'user_id' => 0,  'race_id' => 5, 'form_date' => '2026-09-01 10:00:00',
           'form_value' => serialize( array( 'pilot_nickname_1' => 'MaxDax', 'pilot_mail_1' => 'max@example.com', 'secret' => 'x' ) ) ),
    array( 'id' => 2, 'user_id' => 12, 'race_id' => 5, 'form_date' => '2026-09-02 10:00:00',
           'form_value' => serialize( array( 'pilot_nickname_1' => 'MaxDaxx', 'pilot_mail_1' => ' MAX@example.com ' ) ) ),
    array( 'id' => 3, 'user_id' => 0,  'race_id' => 5, 'form_date' => '2026-09-03 10:00:00',
           'form_value' => serialize( array( 'pilot_nickname_1' => 'Lego', 'pilot_mail_1' => 'lego@example.com' ) ) ),
    array( 'id' => 4, 'user_id' => 0,  'race_id' => 5, 'form_date' => '2026-09-04 10:00:00',
           'form_value' => serialize( array( 'pilot_nickname_1' => 'Walk-in' ) ) ),
);
$rows = rm_registration_rows( $GLOBALS['wpdb']->rows );
$keys = array_column( $rows, 'pilot_key' );
rm_test_check( 'one pilot under a changed callsign keeps the key -- the case callsign matching lost',
    $keys[0] === rm_pilot_key( 'max@example.com' ) && $keys[0] === $keys[1], wp_json_encode( $keys ) );
rm_test_check( 'another pilot has another', $keys[2] !== $keys[0] );
rm_test_check( 'a registration without an address has none', '' === $keys[3] );
rm_test_check( 'the record\'s own fields are still there',
    array( 1, 2, 3, 4 ) === array_column( $rows, 'id' ) && 12 === $rows[1]['user_id'] && '2026-09-01 10:00:00' === $rows[0]['form_date'] );
rm_test_check( 'and form fields outside the whitelist are not', ! array_key_exists( 'secret', $rows[0] ) );
rm_test_check( 'the admin list and the CSV export have the column, last',
    'pilot_key' === end( $GLOBALS['rm_gui_columns'] ) );

// get-pilots is what RotorHazard reads. Checked on the endpoint itself: the key in a helper
// nobody calls would change nothing for the timer.
rm_test_post( 5, 'race', 'autumn-cup' );
$response = rm_get_registration_data( new WP_REST_Request( array( 'race_id' => '5' ) ) );
$served   = $response instanceof WP_REST_Response ? array_column( (array) $response->data, 'pilot_key' ) : array();
rm_test_check( 'get-pilots hands every registration its key', $keys === $served, wp_json_encode( $served ) );
rm_test_check( 'and still the fields the connector reads today',
    isset( $response->data[0]['user_id'], $response->data[0]['pilot_mail_1'], $response->data[0]['id'] ) );

rm_test_section( 'A namespace in wp-config.php wins -- if it is one' );
rm_test_check( 'a UUID in capitals and blanks is read as the same UUID',
    RM_TEST_NS_DNS === rm_valid_pilot_namespace( '  6BA7B810-9DAD-11D1-80B4-00C04FD430C8 ' ) );
rm_test_check( 'any version will do as a namespace', '' !== rm_valid_pilot_namespace( '01920d5c-7a4b-7c3d-9e8f-001122334455' ) );
rm_test_check( 'anything else is none',
    '' === rm_valid_pilot_namespace( 'not-a-uuid' ) && '' === rm_valid_pilot_namespace( '' ) && '' === rm_valid_pilot_namespace( null ) );

// An invalid constant is a configuration error, and the one thing it must not do is fall back to
// the option: those keys would be different ones, handed out as the intended ones. A constant
// cannot be undefined again, so this runs in a process of its own.
$probe = sys_get_temp_dir() . '/rm-pilot-key-probe-' . getmypid() . '.php';
file_put_contents( $probe, '<?php
define( "RM_PILOT_NAMESPACE", "not-a-uuid" );
require ' . var_export( RM_TEST_DIR . '/bootstrap.php', true ) . ';
$GLOBALS["rm_options"] = array( "rm_pilot_namespace" => "0f1e2d3c-4b5a-4968-8776-655443322110" );
require ' . var_export( RM_TEST_DIR . '/stubs/wordpress.php', true ) . ';
require ' . var_export( RM_PLUGIN_DIR . '/includes/pilot-key.php', true ) . ';
echo json_encode( array( rm_pilot_namespace_source(), rm_pilot_key( "pilot@example.com" ) ) );
' );
$out = json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probe ) ), true );
unlink( $probe );
rm_test_check( 'an invalid constant hands out no key, and does not fall back to the option',
    array( 'constant-invalid', '' ) === $out, wp_json_encode( $out ) );

define( 'RM_PILOT_NAMESPACE', '6BA7B810-9DAD-11D1-80B4-00C04FD430C8' );
rm_test_check( 'a valid constant is the namespace, whatever the option holds',
    'constant' === rm_pilot_namespace_source() && rm_uuid5( RM_TEST_NS_DNS, 'pilot@example.com' ) === rm_pilot_key( 'pilot@example.com' ) );

rm_test_finish();

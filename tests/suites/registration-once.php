<?php
/**
 * One registration per pilot and race (1.19.1).
 *
 * The same address could be registered twice for one race, once with a photo and once without: both
 * were stored and mailed, and the race page listed the pilot twice - while RotorHazard, which takes
 * one address as one pilot, imported one. What has to hold:
 *
 *   - an address is registered for a race when one of the race's registrations has it, compared as
 *     the pilot key compares addresses: whatever its case and surrounding blanks; another race's
 *     registration does not count, and no address is nobody;
 *   - the registration form refuses a second one at the address field, with the form's own message,
 *     which its Messages tab lists; another address, another race, a race field naming no race, a
 *     form without a race field or without the address field, and a submission whose address is
 *     refused already, are left alone;
 *   - the race page lists each pilot once, as the timer imports them: at the place of the first
 *     registration, under the callsign sent last; registrations without an address are one pilot
 *     each;
 *   - a registration added by hand is refused for an address registered for the race already.
 */

require_once __DIR__ . '/../bootstrap.php';

// Core's maybe_serialize() and maybe_unserialize() without is_serialized()'s edge cases, which these
// rows never hit.
function maybe_serialize( $data ) {
    return is_array( $data ) || is_object( $data ) ? serialize( $data ) : $data;
}
function maybe_unserialize( $data ) {
    if ( is_string( $data ) ) {
        $value = @unserialize( $data );
        if ( false !== $value || 'b:0;' === $data ) {
            return $value;
        }
    }
    return $data;
}
function current_time( $type ) {
    return '2026-09-13 18:00:00';
}
function get_user_by( $field, $value ) {
    return false;
}
function get_the_ID() {
    return 0;
}

// The registrations table: prepare() hands its arguments on, which the queries by race need.
class RM_Test_Registrations_Table {
    public $prefix = 'wp_';
    public $rows   = array();
    public function prepare( $query, ...$args ) {
        return array( $query, $args );
    }
    private function of_race( $prepared ) {
        return array_values( array_filter( $this->rows, fn( $row ) => $row['race_id'] === (int) $prepared[1][0] ) );
    }
    public function get_col( $prepared ) {
        return array_column( $this->of_race( $prepared ), 'form_value' );
    }
    public function get_results( $prepared, $output = null ) {
        return array_map( fn( $row ) => (object) $row, $this->of_race( $prepared ) );
    }
    public function insert( $table, $data, $format = null ) {
        $data['id']   = count( $this->rows ) + 100;
        $this->rows[] = $data;
        return 1;
    }
}
$GLOBALS['wpdb'] = new RM_Test_Registrations_Table();

// Contact Form 7, as far as the rule reads it: the posted fields, the form's tags, the validation
// result as CF7 keeps it - the first reason a field is given stays - and the form's messages, which
// CF7 fills from wpcf7_messages() for every message a form has not stored.
class WPCF7_Submission {
    public static $posted = null;
    public static function get_instance() {
        return null === self::$posted ? null : new self();
    }
    public function get_posted_data( $name = '' ) {
        return self::$posted[ $name ] ?? null;
    }
}
class WPCF7_FormTag {
    public $name;
    public $type;
    public function __construct( $type, $name ) {
        $this->type = $type;
        $this->name = $name;
    }
}
class WPCF7_Validation {
    private $invalid_fields = array();
    public function invalidate( $tag, $message ) {
        if ( $this->is_valid( $tag->name ) ) {
            $this->invalid_fields[ $tag->name ] = array( 'reason' => (string) $message );
        }
    }
    public function is_valid( $name = null ) {
        return null === $name ? ! $this->invalid_fields : ! isset( $this->invalid_fields[ $name ] );
    }
    public function get_invalid_fields() {
        return $this->invalid_fields;
    }
}
$GLOBALS['rm_form_messages'] = array();
function wpcf7_get_message( $status ) {
    $defaults = array();
    foreach ( $GLOBALS['rm_filter_callbacks']['wpcf7_messages'] ?? array() as $registered ) {
        $defaults = call_user_func( $registered[0], $defaults );
    }
    return $GLOBALS['rm_form_messages'][ $status ] ?? ( $defaults[ $status ]['default'] ?? '' );
}

$GLOBALS['rm_options'] = array( 'rm_callsign_field' => 'pilot_nickname_1' );
require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/pilot-key.php';
require_once RM_PLUGIN_DIR . '/includes/race-info.php';
require_once RM_PLUGIN_DIR . '/includes/admin-registrations.php';
require_once RM_PLUGIN_DIR . '/includes/db-handler.php';
require_once RM_PLUGIN_DIR . '/includes/sc-rm_registered.php';

rm_test_post( 5, 'race', 'autumn-cup' );
rm_test_post( 6, 'race', 'winter-cup' );
rm_test_post( 7, 'race', 'spring-cup' );
rm_test_post( 20, 'page', 'about' );

/** A row of the registrations table. */
function rm_test_row( $id, $race, $callsign, $mail = null ) {
    $data = array( 'race_id' => (string) $race, 'pilot_nickname_1' => $callsign );
    if ( null !== $mail ) {
        $data['pilot_mail_1'] = $mail;
    }
    return array( 'id' => $id, 'race_id' => $race, 'user_id' => 0, 'form_date' => '2026-09-01 10:00:00', 'form_value' => serialize( $data ) );
}
$GLOBALS['wpdb']->rows = array(
    rm_test_row( 1, 5, 'MaxDax', 'max@example.com' ),
    rm_test_row( 2, 5, 'Lego', 'lego@example.com' ),
    rm_test_row( 3, 5, 'Walk-in' ),
    rm_test_row( 4, 6, 'Anna', 'anna@example.com' ),
);

rm_test_section( 'An address registered for a race' );
rm_test_check( 'one of the race\'s registrations has it', rm_race_has_registration( 5, 'max@example.com' ) );
rm_test_check( 'whatever its case and blanks, as the pilot key reads it', rm_race_has_registration( 5, "  MAX@Example.com\n" ) );
rm_test_check( 'another race\'s registration does not count', ! rm_race_has_registration( 5, 'anna@example.com' ) && rm_race_has_registration( 6, 'anna@example.com' ) );
rm_test_check( 'another address is not', ! rm_race_has_registration( 5, 'max2@example.com' ) );
rm_test_check( 'no address is nobody', ! rm_race_has_registration( 5, '' ) && ! rm_race_has_registration( 5, '   ' ) && ! rm_race_has_registration( 5, null ) );

rm_test_section( 'The form refuses a second registration' );
$tags = array( new WPCF7_FormTag( 'race', 'race_id' ), new WPCF7_FormTag( 'text*', 'pilot_name_1' ), new WPCF7_FormTag( 'email*', 'pilot_mail_1' ) );
/** The form's validation after the fields' own rules, as CF7 runs it. */
function rm_test_validate( $posted, $tags, $result = null ) {
    WPCF7_Submission::$posted = $posted;
    $result = $result ?? new WPCF7_Validation();
    foreach ( $GLOBALS['rm_filter_callbacks']['wpcf7_validate'] ?? array() as $registered ) {
        $result = call_user_func_array( $registered[0], array_slice( array( $result, $tags ), 0, $registered[1] ) );
    }
    return $result;
}
$result  = rm_test_validate( array( 'race_id' => '5', 'pilot_mail_1' => 'Max@Example.com' ), $tags );
$invalid = $result->get_invalid_fields();
rm_test_check( 'the address registered already is refused', array( 'pilot_mail_1' ) === array_keys( $invalid ), wp_json_encode( $invalid ) );
rm_test_check( '  saying so', str_contains( $invalid['pilot_mail_1']['reason'] ?? '', 'already registered for this race' ), $invalid['pilot_mail_1']['reason'] ?? '' );
$defaults = array();
foreach ( $GLOBALS['rm_filter_callbacks']['wpcf7_messages'] ?? array() as $registered ) {
    $defaults = call_user_func( $registered[0], $defaults );
}
rm_test_check( 'the message is in the form\'s Messages tab, described',
    '' !== ( $defaults[ RM_ALREADY_REGISTERED_MESSAGE ]['description'] ?? '' ) && '' !== ( $defaults[ RM_ALREADY_REGISTERED_MESSAGE ]['default'] ?? '' ),
    wp_json_encode( $defaults ) );
$GLOBALS['rm_form_messages'][ RM_ALREADY_REGISTERED_MESSAGE ] = 'Schon angemeldet.';
$result = rm_test_validate( array( 'race_id' => array( '5' ), 'pilot_mail_1' => 'lego@example.com' ), $tags );
rm_test_check( 'and says what the form\'s Messages tab says', 'Schon angemeldet.' === ( $result->get_invalid_fields()['pilot_mail_1']['reason'] ?? null ),
    wp_json_encode( $result->get_invalid_fields() ) );
$GLOBALS['rm_form_messages'] = array();
rm_test_check( 'another address is taken', rm_test_validate( array( 'race_id' => '5', 'pilot_mail_1' => 'new@example.com' ), $tags )->is_valid() );
rm_test_check( 'the address for another race is taken', rm_test_validate( array( 'race_id' => '6', 'pilot_mail_1' => 'max@example.com' ), $tags )->is_valid() );
rm_test_check( 'a race field naming no race: left alone', rm_test_validate( array( 'race_id' => '20', 'pilot_mail_1' => 'max@example.com' ), $tags )->is_valid() );
rm_test_check( 'a form without a race field: left alone', rm_test_validate( array( 'pilot_mail_1' => 'max@example.com' ), $tags )->is_valid() );
rm_test_check( 'a form without the address field: left alone',
    rm_test_validate( array( 'race_id' => '5', 'pilot_mail_1' => 'max@example.com' ), array( new WPCF7_FormTag( 'email*', 'your-email' ) ) )->is_valid() );
$refused = new WPCF7_Validation();
$refused->invalidate( $tags[2], 'Please enter an email address.' );
$result = rm_test_validate( array( 'race_id' => '5', 'pilot_mail_1' => 'max@example.com' ), $tags, $refused );
rm_test_check( 'an address refused already keeps its reason', 'Please enter an email address.' === ( $result->get_invalid_fields()['pilot_mail_1']['reason'] ?? null ) );
rm_test_check( 'outside a submission: left alone', rm_test_validate( null, $tags )->is_valid() );

rm_test_section( 'The race page lists each pilot once' );
$GLOBALS['wpdb']->rows = array_merge( $GLOBALS['wpdb']->rows, array(
    rm_test_row( 10, 7, 'MaxDax', 'max@example.com' ),
    rm_test_row( 11, 7, 'Lego', 'lego@example.com' ),
    rm_test_row( 12, 7, 'MaxDaxx', ' MAX@example.com ' ), // registered twice before 1.19.1
    rm_test_row( 13, 7, 'Walk-in' ),
    rm_test_row( 14, 7, 'Guest' ),
) );
$listed = rm_get_registered_callsigns( 7 );
rm_test_check( 'at the place of the first registration, under the callsign sent last', array( 'MaxDaxx', 'Lego', 'Walk-in', 'Guest' ) === $listed, wp_json_encode( $listed ) );
$html = rm_sc_registered_handler( array( 'race_id' => 7 ) );
rm_test_check( 'the page counts pilots', str_contains( $html, 'Registered Pilots: 4</h3>' ) && 4 === substr_count( $html, '<li>' ), $html );
rm_test_check( 'a race without doubles lists every registration', array( 'MaxDax', 'Lego', 'Walk-in' ) === rm_get_registered_callsigns( 5 ) );

rm_test_section( 'Added by hand' );
$before = count( $GLOBALS['wpdb']->rows );
$fields = fn( $mail ) => array( 'race_id' => '5', 'pilot_name_1' => 'Max D', 'pilot_nickname_1' => 'MaxDax', 'pilot_phone_1' => '', 'pilot_mail_1' => $mail );
rm_test_check( 'an address registered for the race already is refused', 'registered' === rm_add_registration( 5, $fields( 'Max@example.com' ) ) && $before === count( $GLOBALS['wpdb']->rows ) );
rm_test_check( 'another race\'s is not in the way', 'added' === rm_add_registration( 6, $fields( 'max@example.com' ) ) );
$added = end( $GLOBALS['wpdb']->rows );
rm_test_check( '  added to that race, as given', 6 === $added['race_id'] && 'max@example.com' === ( maybe_unserialize( $added['form_value'] )['pilot_mail_1'] ?? null ) && '2026-09-13 18:00:00' === $added['form_date'],
    wp_json_encode( $added ) );
rm_test_check( 'one without an address too', 'added' === rm_add_registration( 5, $fields( '' ) ) && 'added' === rm_add_registration( 5, $fields( '' ) ) );

rm_test_finish();

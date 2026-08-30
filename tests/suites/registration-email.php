<?php
/**
 * The address the registration confirmation mail uses.
 *
 * E8: `registration@copterrace.com` was hard-coded in three places of the CF7 form the plugin
 * creates on activation -- sender, Reply-To and Bcc. Correct on exactly one installation.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_home']          = 'https://www.copterrace.com';
$GLOBALS['rm_posts_store']   = array();
$GLOBALS['rm_settings_errors'] = array();
$GLOBALS['rm_cf7_saves']     = 0;
$GLOBALS['rm_cf7_forms']     = array();

function home_url( $path = '' ) {
    return $GLOBALS['rm_home'] . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}
function is_email( $email ) {
    return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
}
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
    $GLOBALS['rm_settings_errors'][] = $code;
}
function get_posts( $args = array() ) {
    $out = array();
    foreach ( $GLOBALS['rm_posts_store'] as $id => $post ) {
        if ( isset( $args['title'] ) && $post['post_title'] !== $args['title'] ) {
            continue;
        }
        $out[] = ( isset( $args['fields'] ) && 'ids' === $args['fields'] ) ? $id : (object) $post;
    }
    return $out;
}
function get_post_type( $id ) {
    return isset( $GLOBALS['rm_posts_store'][ $id ] ) ? $GLOBALS['rm_posts_store'][ $id ]['post_type'] : false;
}

require_once RM_TEST_DIR . '/stubs/wordpress.php';

/** Just enough of Contact Form 7 to create and edit a form. */
class WPCF7_ContactForm {
    public $id = 0;
    public $title = '';
    public $props = array();

    public static function get_template() { return new self(); }
    public static function get_instance( $id ) {
        return isset( $GLOBALS['rm_cf7_forms'][ $id ] ) ? $GLOBALS['rm_cf7_forms'][ $id ] : null;
    }
    public function set_title( $title ) { $this->title = $title; }
    public function set_properties( $props ) { $this->props = array_merge( $this->props, $props ); }
    public function prop( $name ) { return isset( $this->props[ $name ] ) ? $this->props[ $name ] : null; }
    public function id() { return $this->id; }
    public function save() {
        if ( ! $this->id ) {
            ++$GLOBALS['rm_cf7_saves'];
            $this->id = 600 + $GLOBALS['rm_cf7_saves'];
            $GLOBALS['rm_posts_store'][ $this->id ] = array( 'post_type' => 'wpcf7_contact_form', 'post_title' => $this->title );
        }
        $GLOBALS['rm_cf7_forms'][ $this->id ] = $this;
        return $this->id;
    }
}

$GLOBALS['rm_options']['admin_email'] = 'peter@example.test';

require_once RM_PLUGIN_DIR . '/includes/admin-registrations.php';
require_once RM_PLUGIN_DIR . '/includes/settings-handler.php';

/* --------------------------------------------------------------------------
 * Where the default comes from
 * ----------------------------------------------------------------------- */

rm_test_section( 'The default is derived from the site' );

rm_test_check( 'www. is dropped', 'registration@copterrace.com' === rm_default_registration_email(),
    rm_default_registration_email() );

$GLOBALS['rm_home'] = 'https://copterrace.com';
rm_test_check( 'a bare domain works too', 'registration@copterrace.com' === rm_default_registration_email() );

$GLOBALS['rm_home'] = 'https://racemanager.ddev.site';
rm_test_check( 'a development host gets its own address', 'registration@racemanager.ddev.site' === rm_default_registration_email() );

$GLOBALS['rm_home'] = 'http://localhost';
rm_test_check( 'a host that cannot carry a mailbox falls back to the admin address',
    'peter@example.test' === rm_default_registration_email(), rm_default_registration_email() );

$GLOBALS['rm_home'] = 'https://copterrace.com';

rm_test_section( 'The stored option wins' );

rm_test_check( 'nothing stored -> the default', 'registration@copterrace.com' === rm_registration_email() );
$GLOBALS['rm_options']['rm_registration_email'] = 'anmeldung@rotormaniacs.de';
rm_test_check( 'a stored address is used', 'anmeldung@rotormaniacs.de' === rm_registration_email() );
$GLOBALS['rm_options']['rm_registration_email'] = 'not an address';
rm_test_check( 'a broken one does not send mail from nowhere', 'registration@copterrace.com' === rm_registration_email() );
$GLOBALS['rm_options']['rm_registration_email'] = '';

/* --------------------------------------------------------------------------
 * Saving the setting
 * ----------------------------------------------------------------------- */

rm_test_section( 'Sanitising the setting' );

rm_test_check( 'a valid address passes', 'race@example.test' === rm_sanitize_registration_email( 'race@example.test' ) );
rm_test_check( 'empty stays empty, meaning "use the default"', '' === rm_sanitize_registration_email( '' ) );

$GLOBALS['rm_options']['rm_registration_email'] = 'race@example.test';
$result = rm_sanitize_registration_email( 'definitely-not-an-address' );
rm_test_check( 'garbage keeps the previous value', 'race@example.test' === $result, $result );
rm_test_check( 'and says so', in_array( 'rm_registration_email_invalid', $GLOBALS['rm_settings_errors'], true ) );
$GLOBALS['rm_options']['rm_registration_email'] = '';

/* --------------------------------------------------------------------------
 * What ends up in the form
 * ----------------------------------------------------------------------- */

rm_test_section( 'The generated form' );

$source = file_get_contents( RM_PLUGIN_DIR . '/includes/admin-registrations.php' );
$code   = preg_replace( '#/\*\*.*?\*/#s', '', $source ); // comments may still name the old address
rm_test_check( 'no address is hard-coded any more', ! str_contains( $code, 'copterrace.com' ),
    'the whole point of E8' );

$form_id = create_event_registration_cf7_form();
$mail    = $GLOBALS['rm_cf7_forms'][ $form_id ]->prop( 'mail' );

rm_test_check( 'the form was created', $form_id > 0 );
rm_test_check( 'sender carries the address', str_contains( $mail['sender'], '<registration@copterrace.com>' ), $mail['sender'] );
rm_test_check( 'Reply-To and Bcc too', 2 === substr_count( $mail['additional_headers'], 'registration@copterrace.com' ),
    $mail['additional_headers'] );
rm_test_check( 'the pilot still gets the confirmation', '[pilot_mail_1]' === $mail['recipient'] );
rm_test_check( 'the body is signed with the site title, not a club name',
    str_contains( $mail['body'], '[_site_title]' ) && ! str_contains( $mail['body'], 'TSV Korntal' ) );

/* --------------------------------------------------------------------------
 * Changing the setting afterwards
 * ----------------------------------------------------------------------- */

rm_test_section( 'Changing the address later' );

$GLOBALS['rm_options']['rm_registration_email'] = 'anmeldung@copterrace.com';
rm_sync_cf7_registration_email( '', 'anmeldung@copterrace.com' );
$mail = $GLOBALS['rm_cf7_forms'][ $form_id ]->prop( 'mail' );

rm_test_check( 'the untouched form follows along', str_contains( $mail['sender'], '<anmeldung@copterrace.com>' ), $mail['sender'] );
rm_test_check( 'headers follow too', 2 === substr_count( $mail['additional_headers'], 'anmeldung@copterrace.com' ) );

rm_test_section( 'A form the organiser edited is left alone' );

$GLOBALS['rm_cf7_forms'][ $form_id ]->props['mail']['sender'] = 'Rennleitung <orga@example.test>';
$GLOBALS['rm_options']['rm_registration_email'] = 'neu@copterrace.com';
rm_sync_cf7_registration_email( 'anmeldung@copterrace.com', 'neu@copterrace.com' );
$mail = $GLOBALS['rm_cf7_forms'][ $form_id ]->prop( 'mail' );

rm_test_check( 'the hand-written sender survives', 'Rennleitung <orga@example.test>' === $mail['sender'], $mail['sender'] );
rm_test_check( 'but the untouched headers still update',
    2 === substr_count( $mail['additional_headers'], 'neu@copterrace.com' ), $mail['additional_headers'] );

rm_test_section( 'Activation stays idempotent' );
$again = create_event_registration_cf7_form();
rm_test_check( 'still one form', 1 === $GLOBALS['rm_cf7_saves'] );
rm_test_check( 'and it is the same one', $again === $form_id );

rm_test_finish();

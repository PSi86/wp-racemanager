<?php
/**
 * The VAPID settings sanitize callback: rm_settings_sanitize_vapid()
 *
 * The private key is never rendered into the form, so a plain settings save must not be
 * able to wipe it. That is what this suite guards.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_options']        = array( 'admin_email' => 'race@example.test' );
$GLOBALS['rm_settings_errors'] = array();

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
    $GLOBALS['rm_settings_errors'][] = $code;
}
function register_setting( ...$a ) {}
function add_settings_section( ...$a ) {}
function add_settings_field( ...$a ) {}
function add_options_page( ...$a ) {}
function settings_fields( ...$a ) {}
function do_settings_sections( ...$a ) {}
function submit_button( ...$a ) {}
function wp_nonce_field( ...$a ) {}
function check_admin_referer( ...$a ) {}
function wp_die( ...$a ) {}
function checked( ...$a ) {}
function get_current_screen() { return null; }

class RM_Test_Wpdb {
    public $prefix = 'wp_';
    public function prepare( $q, ...$a ) { return $q; }
    public function get_var( $q ) { return null; }
    public function query( $q ) { return 0; }
}
$GLOBALS['wpdb'] = new RM_Test_Wpdb();

class WP_Query { public $post = null; public function __construct( $args = array() ) {} }

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/vapid-handler.php';
require_once RM_PLUGIN_DIR . '/includes/settings-handler.php';

$keep = array(
    'publicKey'  => 'PUBaaaaaaaaaaaaaaaaaaaaaaaaa',
    'privateKey' => 'PRIVbbbbbbbbbbbbbbbbbbbbbbbb',
    'subject'    => 'mailto:old@example.test',
);

rm_test_section( 'A plain save leaves the keys alone' );
$GLOBALS['rm_options']['rm_vapid'] = $keep;
$out = rm_settings_sanitize_vapid( array( 'subject' => 'mailto:old@example.test', 'import_public' => '', 'import_private' => '' ) );
rm_test_check( 'public key kept', $keep['publicKey'] === $out['publicKey'], 'key was wiped' );
rm_test_check( 'private key kept', $keep['privateKey'] === $out['privateKey'], 'key was wiped' );

rm_test_section( 'A form without the rm_vapid field at all' );
$out = rm_settings_sanitize_vapid( '' );
rm_test_check( 'public key kept', $keep['publicKey'] === $out['publicKey'] );
rm_test_check( 'private key kept', $keep['privateKey'] === $out['privateKey'] );

rm_test_section( 'Changing the contact' );
$out = rm_settings_sanitize_vapid( array( 'subject' => 'new@example.test' ) );
rm_test_check( 'normalised to mailto:', 'mailto:new@example.test' === $out['subject'] );
rm_test_check( 'keys untouched', $keep['privateKey'] === $out['privateKey'] );

rm_test_section( 'An invalid contact' );
$GLOBALS['rm_settings_errors'] = array();
$out = rm_settings_sanitize_vapid( array( 'subject' => 'javascript:alert(1)' ) );
rm_test_check( 'previous contact kept', $keep['subject'] === $out['subject'] );
rm_test_check( 'error reported', in_array( 'rm_vapid_subject_invalid', $GLOBALS['rm_settings_errors'], true ) );

rm_test_section( 'Importing both keys' );
$GLOBALS['rm_settings_errors'] = array();
$out = rm_settings_sanitize_vapid( array(
    'import_public'  => 'NEWpubcccccccccccccccccccccc',
    'import_private' => 'NEWprivdddddddddddddddddddddd',
) );
rm_test_check( 'public key taken', 'NEWpubcccccccccccccccccccccc' === $out['publicKey'] );
rm_test_check( 'private key taken', 'NEWprivdddddddddddddddddddddd' === $out['privateKey'] );
rm_test_check( 'success reported', in_array( 'rm_vapid_imported', $GLOBALS['rm_settings_errors'], true ) );

rm_test_section( 'A half-filled import is refused' );
$GLOBALS['rm_settings_errors'] = array();
$out = rm_settings_sanitize_vapid( array( 'import_public' => 'NEWpubcccccccccccccccccccccc', 'import_private' => '' ) );
rm_test_check( 'previous keys kept',
    $keep['publicKey'] === $out['publicKey'] && $keep['privateKey'] === $out['privateKey'],
    'the pair was partially overwritten' );
rm_test_check( 'error reported', in_array( 'rm_vapid_import_invalid', $GLOBALS['rm_settings_errors'], true ) );

rm_test_section( 'An invalid import is refused' );
$GLOBALS['rm_settings_errors'] = array();
$out = rm_settings_sanitize_vapid( array( 'import_public' => 'x', 'import_private' => 'y' ) );
rm_test_check( 'previous keys kept', $keep['privateKey'] === $out['privateKey'] );
rm_test_check( 'error reported', in_array( 'rm_vapid_import_invalid', $GLOBALS['rm_settings_errors'], true ) );

rm_test_finish();

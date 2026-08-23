<?php
/**
 * VAPID key handling: includes/vapid-handler.php
 *
 * Runs against the real minishlink/web-push library when it is installed, so key generation
 * is exercised rather than mocked. Skips itself otherwise.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_options'] = array( 'admin_email' => 'race@example.test' );

/** Fake $wpdb with a switchable subscription count. */
class RM_Test_Wpdb {
    public $prefix = 'wp_';
    public $rows   = 0;

    public function prepare( $query, ...$args ) { return $query; }
    public function get_var( $query ) {
        if ( false !== stripos( $query, 'SHOW TABLES' ) ) { return 'wp_rm_subscriptions'; }
        if ( false !== stripos( $query, 'COUNT(*)' ) )    { return $this->rows; }
        return null;
    }
    public function query( $query ) { $removed = $this->rows; $this->rows = 0; return $removed; }
}
$GLOBALS['wpdb'] = new RM_Test_Wpdb();

function get_current_screen() { return null; }

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/vapid-handler.php';

if ( ! rm_push_library_available() ) {
    rm_test_skip( 'minishlink/web-push not installed -- run "composer install" in the plugin directory' );
}

rm_test_section( 'Fresh install without subscriptions' );
rm_test_check( 'not configured yet', ! rm_vapid_is_configured() );
rm_test_check( 'source is none', 'none' === rm_vapid_source() );
rm_maybe_bootstrap_vapid_keys();
rm_test_check( 'keys generated automatically', rm_vapid_is_configured() );
rm_test_check( 'source is the option', 'option' === rm_vapid_source() );
rm_test_check( 'subject defaults to the admin address', 'mailto:race@example.test' === rm_get_vapid()['subject'] );
rm_test_check( 'push is available', rm_push_available() );
$generated = rm_get_vapid();

rm_test_section( 'Bootstrap is idempotent' );
rm_maybe_bootstrap_vapid_keys();
rm_test_check( 'keys unchanged', rm_get_vapid()['publicKey'] === $generated['publicKey'], 'keys were overwritten' );

rm_test_section( 'Existing subscriptions are protected' );
$GLOBALS['rm_options'] = array( 'admin_email' => 'race@example.test' ); // no keys
$GLOBALS['wpdb']->rows = 7;
rm_test_check( 'subscriptions detected', rm_has_push_subscriptions() );
rm_maybe_bootstrap_vapid_keys();
rm_test_check( 'no keys generated, so no subscription is invalidated', ! rm_vapid_is_configured() );

rm_test_section( 'Manual import keeps the subscriptions' );
$keys = rm_generate_vapid_keys();
rm_test_check( 'generation returns a pair', is_array( $keys ) && ! empty( $keys['publicKey'] ) );
rm_test_check( 'import stored', rm_store_vapid_keys( $keys['publicKey'], $keys['privateKey'] ) );
rm_test_check( 'configured now', rm_vapid_is_configured() );
rm_test_check( 'subscriptions untouched', rm_has_push_subscriptions() );

rm_test_section( 'Input validation' );
rm_test_check( 'garbage rejected', '' === rm_sanitize_vapid_key( 'not a key!!' ) );
rm_test_check( 'too short rejected', '' === rm_sanitize_vapid_key( 'abc' ) );
rm_test_check( 'valid key accepted', $keys['publicKey'] === rm_sanitize_vapid_key( $keys['publicKey'] ) );
rm_test_check( 'whitespace stripped', $keys['publicKey'] === rm_sanitize_vapid_key( " \n" . $keys['publicKey'] . ' ' ) );
rm_test_check( 'storing garbage fails', false === rm_store_vapid_keys( 'nope', 'nope' ) );
rm_test_check( 'bare address becomes mailto:', 'mailto:a@b.test' === rm_sanitize_vapid_subject( 'a@b.test' ) );
rm_test_check( 'mailto: kept', 'mailto:a@b.test' === rm_sanitize_vapid_subject( 'mailto:a@b.test' ) );
rm_test_check( 'https kept', 'https://example.test' === rm_sanitize_vapid_subject( 'https://example.test' ) );
rm_test_check( 'javascript: rejected', '' === rm_sanitize_vapid_subject( 'javascript:alert(1)' ) );

rm_test_section( 'Constants beat the database' );
define( 'RM_VAPID_PUBLIC_KEY', 'PUB-from-wp-config-000000000000000000' );
define( 'RM_VAPID_PRIVATE_KEY', 'PRIV-from-wp-config-00000000000000000' );
define( 'RM_VAPID_SUBJECT', 'mailto:ops@example.test' );
rm_test_check( 'public key from constant', 'PUB-from-wp-config-000000000000000000' === rm_get_vapid()['publicKey'] );
rm_test_check( 'private key from constant', 'PRIV-from-wp-config-00000000000000000' === rm_get_vapid()['privateKey'] );
rm_test_check( 'subject from constant', 'mailto:ops@example.test' === rm_get_vapid()['subject'] );
rm_test_check( 'source is constant', 'constant' === rm_vapid_source() );

rm_test_section( 'Clearing subscriptions' );
rm_test_check( 'seven removed', 7 === rm_delete_all_subscriptions() );
rm_test_check( 'table empty afterwards', ! rm_has_push_subscriptions() );

rm_test_finish();

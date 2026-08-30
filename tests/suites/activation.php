<?php
/**
 * What the activation hook leaves behind.
 *
 * Three findings meet here, and all three only show up on the *second* run of something:
 *
 *   E4  dbDelta() was handed "CREATE TABLE IF NOT EXISTS", so core read the table name as
 *       "IF" and no schema change ever applied.
 *   E10 the CF7 example form was created unconditionally, so every reactivation left another
 *       copy behind.
 *   E7  the plugin declared no Requires headers and three different version numbers.
 */

require_once __DIR__ . '/../bootstrap.php';

/* --------------------------------------------------------------------------
 * Stubs this suite needs before the shared ones are loaded
 * ----------------------------------------------------------------------- */

$GLOBALS['rm_dbdelta_sql'] = array();
$GLOBALS['rm_posts_store'] = array();

function dbDelta( $sql ) {
    $GLOBALS['rm_dbdelta_sql'][] = $sql;
    return array();
}

// get_posts() with title matching, which the shared stub does not do.
function get_posts( $args = array() ) {
    $out = array();
    foreach ( $GLOBALS['rm_posts_store'] as $id => $post ) {
        if ( isset( $args['post_type'] ) && $post['post_type'] !== $args['post_type'] ) {
            continue;
        }
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

// create_db_table() pulls in core's upgrade.php; a placeholder is enough, dbDelta() is above.
if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
    mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
}
if ( ! file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
    file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n// Placeholder for the test suites; dbDelta() is stubbed by the suite itself.\n" );
}

class RM_Test_Wpdb {
    public $prefix = 'wp_';
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
}
$GLOBALS['wpdb'] = new RM_Test_Wpdb();

/* --------------------------------------------------------------------------
 * E7 -- one version, and the headers WordPress needs to protect the site
 * ----------------------------------------------------------------------- */

rm_test_section( 'Plugin header (E7)' );

$main   = file_get_contents( RM_PLUGIN_DIR . '/wp-racemanager.php' );
$header = substr( $main, 0, 8192 ); // WordPress only reads this far

preg_match( '/^\s*\*\s*Version:\s*(.+)$/mi', $header, $m );
$header_version = isset( $m[1] ) ? trim( $m[1] ) : '';
preg_match( "/define\(\s*'WP_RACEMANAGER_VERSION',\s*'([^']+)'/", $main, $m );
$constant_version = isset( $m[1] ) ? $m[1] : '';
$package = json_decode( file_get_contents( RM_PLUGIN_DIR . '/package.json' ), true );

rm_test_check( 'Version header present', '' !== $header_version, $header_version );
rm_test_check( 'constant matches the header', $header_version === $constant_version, "$header_version vs $constant_version" );
rm_test_check( 'package.json matches too', $header_version === $package['version'], $header_version . ' vs ' . $package['version'] );
rm_test_check( 'Requires at least is declared', (bool) preg_match( '/^\s*\*\s*Requires at least:\s*\d/mi', $header ),
    'WordPress cannot warn about an incompatible core version' );
rm_test_check( 'Requires PHP is declared', (bool) preg_match( '/^\s*\*\s*Requires PHP:\s*8\.\d/mi', $header ),
    'the push library needs PHP 8.2; without this header WordPress installs it anyway' );

rm_test_section( 'Direct access and dead code (E7)' );
rm_test_check( 'ABSPATH guard is active, not commented out',
    (bool) preg_match( "/^if \(\s*! defined\(\s*'ABSPATH'\s*\)\s*\)\s*exit;/m", $main ), 'file is executable directly' );
rm_test_check( 'the unreachable REST detector is gone', ! str_contains( $main, 'is_racemanager_rest_api_request' ) );

/* --------------------------------------------------------------------------
 * E4 -- dbDelta must be able to read the table name
 * ----------------------------------------------------------------------- */

rm_test_section( 'Subscriptions table (E4)' );

require_once RM_PLUGIN_DIR . '/includes/pwa-subscription-handler.php';
\RaceManager\PWA_Subscription_Handler::create_db_table();

rm_test_check( 'dbDelta() was called once', 1 === count( $GLOBALS['rm_dbdelta_sql'] ) );
$sql = $GLOBALS['rm_dbdelta_sql'] ? $GLOBALS['rm_dbdelta_sql'][0] : '';

// This is verbatim what wp-admin/includes/upgrade.php does to find the table name.
preg_match( '|CREATE TABLE ([^ ]*)|', $sql, $matches );
$parsed = isset( $matches[1] ) ? $matches[1] : '';

rm_test_check( 'no IF NOT EXISTS in the statement', ! str_contains( strtoupper( $sql ), 'IF NOT EXISTS' ) );
rm_test_check( 'core parses the real table name', 'wp_rm_subscriptions' === $parsed, "read as: $parsed" );
rm_test_check( 'the charset collate is appended', str_contains( $sql, 'utf8mb4_unicode_ci' ) );
rm_test_check( 'PRIMARY KEY keeps its two spaces, as dbDelta expects', str_contains( $sql, 'PRIMARY KEY  (id)' ) );

// The registrations table was always written correctly -- guard it against a copy/paste regression.
$registrations = file_get_contents( RM_PLUGIN_DIR . '/includes/admin-registrations.php' );
preg_match( '|CREATE TABLE ([^ ]*)|', $registrations, $matches );
rm_test_check( 'the registrations table is unaffected', isset( $matches[1] ) && 'IF' !== $matches[1], $matches[1] ?? '' );

/* --------------------------------------------------------------------------
 * E10 -- the example form is created once, not once per activation
 * ----------------------------------------------------------------------- */

rm_test_section( 'CF7 example form (E10)' );

$GLOBALS['rm_cf7_saves'] = 0;

class WPCF7_ContactForm {
    public $title = '';
    public $props = array();
    public static function get_template() { return new self(); }
    public function set_title( $title ) { $this->title = $title; }
    public function set_properties( $props ) { $this->props = $props; }
    public function save() {
        ++$GLOBALS['rm_cf7_saves'];
        $id = 500 + $GLOBALS['rm_cf7_saves'];
        $GLOBALS['rm_posts_store'][ $id ] = array( 'post_type' => 'wpcf7_contact_form', 'post_title' => $this->title );
        $this->id = $id;
        return $id;
    }
    public function id() { return $this->id; }
}

require_once RM_PLUGIN_DIR . '/includes/admin-registrations.php';

$first = create_event_registration_cf7_form();
rm_test_check( 'first activation creates the form', $first > 0 && 1 === $GLOBALS['rm_cf7_saves'] );
rm_test_check( 'and records its ID', (int) get_option( 'rm_cf7_form_id' ) === $first );

$second = create_event_registration_cf7_form();
rm_test_check( 'reactivating creates nothing', 1 === $GLOBALS['rm_cf7_saves'], $GLOBALS['rm_cf7_saves'] . ' forms exist' );
rm_test_check( 'and returns the same form', $second === $first );

rm_test_section( 'An install that predates the recorded ID' );
$GLOBALS['rm_options']['rm_cf7_form_id'] = 0;   // nothing recorded, but the form is there
$third = create_event_registration_cf7_form();
rm_test_check( 'the existing form is found by title', $third === $first, "$third vs $first" );
rm_test_check( 'still only one form', 1 === $GLOBALS['rm_cf7_saves'] );
rm_test_check( 'and the ID is recorded now', (int) get_option( 'rm_cf7_form_id' ) === $first );

rm_test_section( 'Without Contact Form 7' );
rm_test_check( 'the function is guarded by class_exists',
    str_contains( $registrations, "if ( ! class_exists( 'WPCF7_ContactForm' ) ) {" ),
    'calling it without CF7 would be a fatal' );

rm_test_finish();

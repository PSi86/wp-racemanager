<?php
/**
 * A pilot's nationality and photo (1.11.0): what a registration gives and takes away, what deleting
 * registrations forgets, and what a race's files carry. The photo itself - turned upright, cut,
 * stripped of its metadata - takes a real image editor, and is checked on the development site by
 * tests/e2e/pilot-profiles.cjs.
 *
 * What has to hold:
 *   - a country is a code of the list, in upper case; anything else is none;
 *   - in the site's language where intl is there ("Österreich"), sorted by name;
 *   - every code has its flag in assets/, and every flag there its code;
 *   - a registration with the consent stores its country and keeps what it leaves out; one that
 *     repeats the profile leaves its date alone; without the consent the profile goes, photo and
 *     all; without an address nothing is stored; a photo the site cannot read is no photo;
 *   - the profiles are not autoloaded;
 *   - deleting registrations forgets the profile of a pilot who has none left, and only theirs, and
 *     only in the race it was asked for;
 *   - a race's files carry the profiles of its own pilots, by key, a photo as its URL with version.
 */

require_once __DIR__ . '/../bootstrap.php';

// update_option() as core's, and what it was told about autoloading.
$GLOBALS['rm_autoload'] = array();
function update_option( $key, $value, $autoload = null ) {
    $GLOBALS['rm_options'][ $key ]  = $value;
    $GLOBALS['rm_autoload'][ $key ] = $autoload;
    return true;
}

$GLOBALS['rm_now'] = '2026-09-12 10:00:00';
function current_time( $type ) {
    return $GLOBALS['rm_now'];
}

$GLOBALS['rm_uploads'] = sys_get_temp_dir() . '/rm-test-pilot-profiles-' . getmypid();
@mkdir( $GLOBALS['rm_uploads'], 0777, true );
function wp_upload_dir() {
    return array( 'basedir' => $GLOBALS['rm_uploads'], 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false );
}

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

if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

// The registrations table: prepare() hands its arguments on, which the deletes need.
class RM_Test_Profile_Registrations {
    public $prefix = 'wp_';
    public $rows   = array();
    public function prepare( $query, ...$args ) {
        return array( $query, ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args );
    }
    private function matching( $prepared ) {
        $args = $prepared[1];
        $race = array_shift( $args );
        return array_filter( $this->rows, fn( $row ) => $row['race_id'] === $race && in_array( $row['id'], $args, true ) );
    }
    public function get_results( $prepared, $output = null ) {
        return array_values( $this->matching( $prepared ) );
    }
    public function query( $prepared ) {
        $gone       = array_keys( $this->matching( $prepared ) );
        $this->rows = array_values( array_diff_key( $this->rows, array_flip( $gone ) ) );
        return count( $gone );
    }
    public function get_col( $query ) {
        return array_column( $this->rows, 'form_value' );
    }
}
$GLOBALS['wpdb'] = new RM_Test_Profile_Registrations();

$GLOBALS['rm_options'] = array();

require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/pilot-profiles.php';
require_once RM_PLUGIN_DIR . '/includes/admin-registrations.php';

/** A registration's posted data. */
function rm_test_registration( $mail, $extra = array() ) {
    return array_merge( array( 'pilot_mail_1' => $mail, 'pilot_nickname_1' => 'P', RM_PILOT_CONSENT_FIELD => '1' ), $extra );
}

rm_test_section( 'Countries' );
rm_test_check( 'a code of the list, in upper case', 'AT' === rm_valid_country( 'at' ) && 'DE' === rm_valid_country( ' DE ' ) );
rm_test_check( 'Kosovo, which ISO leaves to its users', 'XK' === rm_valid_country( 'xk' ) );
rm_test_check( 'anything else is none',
    '' === rm_valid_country( 'XX' ) && '' === rm_valid_country( 'Austria' ) && '' === rm_valid_country( '' ) && '' === rm_valid_country( array( 'AT' ) ) && '' === rm_valid_country( null ) );
rm_test_check( '250 of them', 250 === count( rm_countries() ), (string) count( rm_countries() ) );

$flags = array_map( fn( $f ) => strtoupper( basename( $f, '.svg' ) ), glob( RM_PLUGIN_DIR . '/' . RM_FLAG_ICONS_DIR . '*.svg' ) ?: array() );
sort( $flags );
$codes = array_keys( rm_countries() );
sort( $codes );
rm_test_check( 'every code has its flag, and every flag its code', $flags === $codes,
    'without flag: ' . implode( ',', array_diff( $codes, $flags ) ) . '; without code: ' . implode( ',', array_diff( $flags, $codes ) ) );
rm_test_check( 'the flags come with their license', is_file( RM_PLUGIN_DIR . '/assets/flag-icons-7.5.0/LICENSE' ) );

if ( class_exists( 'Locale' ) && class_exists( 'Collator' ) ) {
    $german = rm_country_names( 'de_DE' );
    $order  = array_keys( $german );
    rm_test_check( 'in the site\'s language', 'Österreich' === $german['AT'] && 'Deutschland' === $german['DE'], $german['AT'] . ' / ' . $german['DE'] );
    rm_test_check( 'sorted by name: Österreich among the O\'s',
        array_search( 'OM', $order, true ) < array_search( 'AT', $order, true ) && array_search( 'AT', $order, true ) < array_search( 'PK', $order, true ) );
    rm_test_check( 'every code, and a name for each', 250 === count( $german ) && ! in_array( '', $german, true ) );
} else {
    rm_test_check( 'without intl, the English names', 'Austria' === rm_country_names( 'de_DE' )['AT'] );
}

rm_test_section( 'What a registration gives' );
$key = rm_pilot_key( 'max@example.com' );
$got = rm_pilot_profile_from_registration( rm_test_registration( 'max@example.com', array( 'pilot_country_1' => 'at' ) ) );
rm_test_check( 'with the consent, the country is stored', 'AT' === ( rm_pilot_profiles()[ $key ]['country'] ?? null ), wp_json_encode( rm_pilot_profiles() ) );
rm_test_check( 'dated', '2026-09-12 10:00:00' === ( $got['updated'] ?? null ) );
rm_test_check( 'not autoloaded', false === ( $GLOBALS['rm_autoload'][ RM_PILOT_PROFILES_OPTION ] ?? null ) );

$GLOBALS['rm_now'] = '2026-09-13 10:00:00';
rm_pilot_profile_from_registration( rm_test_registration( 'max@example.com' ) );
rm_test_check( 'a registration without a country keeps the one there', 'AT' === rm_pilot_profiles()[ $key ]['country'] );
rm_test_check( 'and, changing nothing, the date', '2026-09-12 10:00:00' === rm_pilot_profiles()[ $key ]['updated'] );
rm_pilot_profile_from_registration( rm_test_registration( 'max@example.com', array( 'pilot_country_1' => 'XX' ) ) );
rm_test_check( 'a code not on the list changes nothing', 'AT' === rm_pilot_profiles()[ $key ]['country'] );
rm_pilot_profile_from_registration( rm_test_registration( 'max@example.com', array( 'pilot_country_1' => 'DE' ) ) );
rm_test_check( 'a new country replaces it, dated anew',
    'DE' === rm_pilot_profiles()[ $key ]['country'] && '2026-09-13 10:00:00' === rm_pilot_profiles()[ $key ]['updated'] );

$photo = $GLOBALS['rm_uploads'] . '/upload.jpg';
file_put_contents( $photo, 'not an image' );
rm_pilot_profile_from_registration( rm_test_registration( 'max@example.com', array( 'pilot_country_1' => 'DE' ) ), $photo );
rm_test_check( 'a photo this site cannot read is no photo', ! isset( rm_pilot_profiles()[ $key ]['photo'] ), wp_json_encode( rm_pilot_profiles()[ $key ] ) );

rm_test_check( 'no address, nothing stored',
    null === rm_pilot_profile_from_registration( rm_test_registration( '', array( 'pilot_country_1' => 'AT' ) ) ) && 1 === count( rm_pilot_profiles() ) );
rm_test_check( 'nothing to keep, nothing stored',
    null === rm_pilot_profile_from_registration( rm_test_registration( 'lego@example.com' ) ) && 1 === count( rm_pilot_profiles() ) );

// A photo stored before, as rm_store_pilot_photo() leaves it.
$dir = rm_pilot_photo_dir();
rm_test_check( 'the photos\' directory lists nothing', is_file( $dir . 'index.php' ) );
file_put_contents( $dir . $key . '.jpg', 'jpeg' );
$profiles                    = rm_pilot_profiles();
$profiles[ $key ]['photo']   = '1a2b3c4d';
rm_save_pilot_profiles( $profiles );
rm_pilot_profile_from_registration( rm_test_registration( 'max@example.com', array( RM_PILOT_CONSENT_FIELD => '' ) ) );
rm_test_check( 'without the consent the profile goes', ! isset( rm_pilot_profiles()[ $key ] ) );
rm_test_check( 'and the photo with it', ! is_file( $dir . $key . '.jpg' ) );
rm_test_check( 'the last profile gone, so is the option', ! array_key_exists( RM_PILOT_PROFILES_OPTION, $GLOBALS['rm_options'] ) );

rm_test_section( 'Deleting registrations' );
$max  = rm_pilot_key( 'max@example.com' );
$lego = rm_pilot_key( 'lego@example.com' );
$GLOBALS['wpdb']->rows = array(
    array( 'id' => 1, 'race_id' => 5, 'user_id' => 0, 'form_date' => '2026-09-01 10:00:00', 'form_value' => serialize( rm_test_registration( 'max@example.com' ) ) ),
    array( 'id' => 2, 'race_id' => 5, 'user_id' => 0, 'form_date' => '2026-09-01 10:00:00', 'form_value' => serialize( rm_test_registration( 'lego@example.com' ) ) ),
    array( 'id' => 3, 'race_id' => 6, 'user_id' => 0, 'form_date' => '2026-09-01 10:00:00', 'form_value' => serialize( rm_test_registration( ' MAX@example.com ' ) ) ),
);
rm_save_pilot_profiles( array( $max => array( 'country' => 'AT' ), $lego => array( 'country' => 'DE' ) ) );
file_put_contents( $dir . $lego . '.jpg', 'jpeg' );

rm_test_check( 'another race\'s registration is not deleted', 0 === rm_delete_registrations( 5, array( 3 ) ) && 3 === count( $GLOBALS['wpdb']->rows ) );
rm_test_check( 'this race\'s are', 2 === rm_delete_registrations( 5, array( 1, 2 ) ) && array( 3 ) === array_column( $GLOBALS['wpdb']->rows, 'id' ) );
rm_test_check( 'the pilot with none left loses the profile, photo and all', ! isset( rm_pilot_profiles()[ $lego ] ) && ! is_file( $dir . $lego . '.jpg' ) );
rm_test_check( 'the pilot registered for another race keeps it', 'AT' === ( rm_pilot_profiles()[ $max ]['country'] ?? null ) );
rm_delete_registrations( 6, array( 3 ) );
rm_test_check( 'until that registration goes too', array() === rm_pilot_profiles() );

rm_test_section( 'A photo\'s metadata' );
// A JPEG as GD writes it - or, without GD, the few segments the filter reads - with what a camera
// and a photo editor add: EXIF (APP1), Photoshop's IPTC block (APP13) and a comment, besides the
// colour profile (APP2 ICC_PROFILE) and Adobe's colour transform (APP14), which stay.
$segment = fn( $marker, $payload ) => "\xFF" . chr( $marker ) . pack( 'n', 2 + strlen( $payload ) ) . $payload;
if ( function_exists( 'imagecreatetruecolor' ) ) {
    $image = imagecreatetruecolor( 40, 30 );
    ob_start();
    imagejpeg( $image );
    $plain = ob_get_clean();
} else {
    $plain = "\xFF\xD8" . $segment( 0xE0, "JFIF\0\x01\x01\0\0\x01\0\x01\0\0" ) . "\xFF\xDA\0\x08image-data\xFF\xD9";
}
$marked = substr( $plain, 0, 2 )
    . $segment( 0xE1, "Exif\0\0MM\0*\0\0\0\x08GPS-canary" )
    . $segment( 0xE2, "ICC_PROFILE\0\x01\x01colour" )
    . $segment( 0xED, "Photoshop 3.0\0IPTC-canary" )
    . $segment( 0xEE, "Adobe\0\x64\0\0\0\0\x01" )
    . $segment( 0xFE, 'comment-canary' )
    . substr( $plain, 2 );
$clean = rm_jpeg_without_metadata( $marked );
rm_test_check( 'EXIF, IPTC and comments go', is_string( $clean ) && false === strpos( $clean, 'canary' ) && false === strpos( $clean, "Exif\0\0" ) );
rm_test_check( 'the colour profile and Adobe\'s transform stay', is_string( $clean ) && false !== strpos( $clean, "ICC_PROFILE\0" ) && false !== strpos( $clean, "Adobe\0" ) );
rm_test_check( 'the image itself is untouched', is_string( $clean ) && str_ends_with( $clean, substr( $plain, strpos( $plain, "\xFF\xDA" ) ) ) );
if ( function_exists( 'imagecreatefromstring' ) ) {
    $decoded = @imagecreatefromstring( (string) $clean );
    rm_test_check( 'and still an image of the same size', $decoded && 40 === imagesx( $decoded ) && 30 === imagesy( $decoded ) );
}
rm_test_check( 'once without, it stays as it is', is_string( $clean ) && $clean === rm_jpeg_without_metadata( $clean ) );
rm_test_check( 'GD\'s own comment ("CREATOR: gd-jpeg") goes too', false === strpos( (string) rm_jpeg_without_metadata( $plain ), 'CREATOR' ) );
rm_test_check( 'what is no JPEG is refused',
    null === rm_jpeg_without_metadata( 'GIF89a' ) && null === rm_jpeg_without_metadata( '' ) && null === rm_jpeg_without_metadata( "\x89PNG\r\n\x1a\n" ) );
rm_test_check( 'and so is a JPEG cut short before its image data',
    null === rm_jpeg_without_metadata( substr( $marked, 0, 40 ) ) && null === rm_jpeg_without_metadata( "\xFF\xD8\xFF\xE1\x00\x40Exif" ) );

rm_test_section( 'What a race\'s files carry' );
$keys = array(
    'a' => rm_pilot_key( 'a@example.com' ),
    'b' => rm_pilot_key( 'b@example.com' ),
    'c' => rm_pilot_key( 'c@example.com' ),
    'e' => rm_pilot_key( 'e@example.com' ),
);
rm_save_pilot_profiles( array(
    $keys['a'] => array( 'country' => 'AT', 'photo' => '0a1b2c3d', 'updated' => '2026-09-12 10:00:00' ),
    $keys['b'] => array( 'country' => 'DE', 'photo' => '../etc', 'updated' => '2026-09-12 10:00:00' ),
    $keys['e'] => array( 'country' => 'CZ', 'updated' => '2026-09-12 10:00:00' ),
) );
$race = array( 'pilot_data' => array( 'pilots' => array(
    array( 'pilot_id' => 1, 'callsign' => 'A', 'pilot_key' => $keys['a'] ),
    array( 'pilot_id' => 2, 'callsign' => 'B', 'pilot_key' => strtoupper( $keys['b'] ) ),
    array( 'pilot_id' => 3, 'callsign' => 'C', 'pilot_key' => $keys['c'] ),
    array( 'pilot_id' => 4, 'callsign' => 'D', 'pilot_key' => '' ),
) ) );
$carried = rm_race_pilot_profiles( $race );
rm_test_check( 'the race\'s pilots with a profile, by key, and nobody else',
    array( $keys['a'], $keys['b'] ) === array_keys( $carried ), wp_json_encode( $carried ) );
rm_test_check( 'a photo as its URL, with its version',
    'https://example.test/wp-content/uploads/rm-pilots/' . $keys['a'] . '.jpg?v=0a1b2c3d' === ( $carried[ $keys['a'] ]['photo'] ?? null ) );
rm_test_check( 'the country, and nothing a page does not need', array( 'country', 'photo' ) === array_keys( $carried[ $keys['a'] ] ) );
rm_test_check( 'a version that is none gives no photo', array( 'country' => 'DE' ) === $carried[ $keys['b'] ] );
rm_test_check( 'a race without keys carries none', array() === rm_race_pilot_profiles( array( 'pilot_data' => array( 'pilots' => array( array( 'pilot_id' => 1 ) ) ) ) ) );
rm_test_check( 'and neither does a payload without pilots', array() === rm_race_pilot_profiles( array() ) );

// Clean up the photos' directory.
foreach ( glob( $GLOBALS['rm_uploads'] . '/{,*/}{,.}*', GLOB_BRACE ) ?: array() as $file ) {
    if ( is_file( $file ) ) {
        @unlink( $file );
    }
}
@rmdir( $GLOBALS['rm_uploads'] . '/' . RM_PILOT_PHOTO_DIR );
@rmdir( $GLOBALS['rm_uploads'] );

rm_test_finish();

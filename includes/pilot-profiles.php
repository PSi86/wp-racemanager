<?php
// includes/pilot-profiles.php
// A pilot's nationality and photo (1.11.0), which the live pages show next to the callsign.
//
// Both are optional fields of the registration form - pilot_country_1 ([rm_country], see
// sc-cf7-country.php) and pilot_photo_1 (a CF7 [file] field) - and both are published under the
// consent the form asks for anyway, acceptance-media ("Name, Alter, Nationalität und Bild"). They
// are kept per pilot key, so the newest registration of a pilot serves every race written after it:
//
//   option rm_pilot_profiles      pilot key => [ country, photo (a version), updated ], autoload off
//   uploads/rm-pilots/{key}.jpg   the photo: 256 px square at most, turned upright, no metadata
//
// A registration without the consent takes the profile away, photo and all, and so does deleting a
// pilot's last registration. A race's files carry the profiles of its pilots (race-files.php), as
// they were when the race was last written: the photo, one file per pilot, is gone from every race at
// once, the country stays in the files of a race until it is written again.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once __DIR__ . '/pilot-key.php';
require_once __DIR__ . '/countries.php';

/** The option the profiles are kept in. */
const RM_PILOT_PROFILES_OPTION = 'rm_pilot_profiles';

/** The photos' directory, below uploads. */
const RM_PILOT_PHOTO_DIR = 'rm-pilots';

/** A photo's side, in pixels: a smaller photo keeps its own. */
const RM_PILOT_PHOTO_SIZE = 256;

/** The form fields: the consent, the nationality, the photo. */
const RM_PILOT_CONSENT_FIELD = 'acceptance-media';
const RM_PILOT_COUNTRY_FIELD = 'pilot_country_1';
const RM_PILOT_PHOTO_FIELD   = 'pilot_photo_1';

/**
 * Every profile: pilot key => [ 'country' => 'AT', 'photo' => '1a2b3c4d', 'updated' => mysql time ].
 *
 * @return array<string,array>
 */
function rm_pilot_profiles() {
    $profiles = get_option( RM_PILOT_PROFILES_OPTION, array() );
    return is_array( $profiles ) ? $profiles : array();
}

/**
 * Store every profile. Not autoloaded: only a registration and a race's write need them.
 *
 * @param array<string,array> $profiles
 * @return void
 */
function rm_save_pilot_profiles( $profiles ) {
    if ( $profiles ) {
        update_option( RM_PILOT_PROFILES_OPTION, $profiles, false );
    } else {
        delete_option( RM_PILOT_PROFILES_OPTION );
    }
}

/**
 * Take a registration's nationality and photo into its pilot's profile.
 *
 * Given, each replaces what the profile had; left out, the profile keeps it. Without the consent the
 * profile goes: the newest registration is the one that speaks for the pilot.
 *
 * @param array       $data       The form's posted data.
 * @param string|null $photo_path The uploaded photo, where the form keeps it until it is done.
 * @return array|null The profile as it is now; null when there is none, or no pilot key.
 */
function rm_pilot_profile_from_registration( $data, $photo_path = null ) {
    $email = isset( $data['pilot_mail_1'] ) && is_string( $data['pilot_mail_1'] ) ? $data['pilot_mail_1'] : '';
    $key   = rm_pilot_key( $email );
    if ( '' === $key ) {
        return null;
    }
    if ( ! rm_registration_consents_to_profile( $data ) ) {
        rm_forget_pilot_profile( $key );
        return null;
    }

    $profiles = rm_pilot_profiles();
    $before   = isset( $profiles[ $key ] ) && is_array( $profiles[ $key ] ) ? $profiles[ $key ] : array();
    $kept     = array_intersect_key( $before, array( 'country' => true, 'photo' => true ) );
    $profile  = $kept;

    $country = rm_valid_country( $data[ RM_PILOT_COUNTRY_FIELD ] ?? '' );
    if ( '' !== $country ) {
        $profile['country'] = $country;
    }
    if ( is_string( $photo_path ) && '' !== $photo_path ) {
        $version = rm_store_pilot_photo( $key, $photo_path );
        if ( '' !== $version ) {
            $profile['photo'] = $version;
        }
    }
    if ( ! $profile ) {
        return null;
    }

    // When it last changed; a registration that repeats the profile leaves the date alone.
    ksort( $kept );
    $now = $profile;
    ksort( $now );
    $profile['updated'] = ( $now === $kept && isset( $before['updated'] ) ) ? $before['updated'] : current_time( 'mysql' );
    if ( $profile !== $before ) {
        $profiles[ $key ] = $profile;
        rm_save_pilot_profiles( $profiles );
    }
    return $profile;
}

/**
 * Whether a registration consents to publishing nationality and photo: acceptance-media ticked.
 * CF7 posts "1" for a ticked box and nothing for one left empty.
 *
 * @param array $data The form's posted data.
 * @return bool
 */
function rm_registration_consents_to_profile( $data ) {
    return ! empty( $data[ RM_PILOT_CONSENT_FIELD ] );
}

/**
 * Remove a pilot's profile and photo.
 *
 * @param string $key The pilot key.
 * @return void
 */
function rm_forget_pilot_profile( $key ) {
    $key = rm_valid_pilot_key( $key );
    if ( '' === $key ) {
        return;
    }
    $profiles = rm_pilot_profiles();
    if ( isset( $profiles[ $key ] ) ) {
        unset( $profiles[ $key ] );
        rm_save_pilot_profiles( $profiles );
    }
    $dir = rm_pilot_photo_dir( false );
    if ( ! is_wp_error( $dir ) && is_file( $dir . $key . '.jpg' ) ) {
        @unlink( $dir . $key . '.jpg' );
    }
}

/**
 * Forget the profiles of pilot keys that no registration carries any more.
 *
 * @param string[] $keys The keys of registrations just deleted.
 * @return string[] The keys whose profile went.
 */
function rm_forget_unregistered_pilot_profiles( $keys ) {
    $keys = array_filter( array_unique( array_map( 'rm_valid_pilot_key', (array) $keys ) ) );
    if ( ! $keys ) {
        return array();
    }
    $still = array_flip( rm_registered_pilot_keys() );
    $gone  = array();
    foreach ( $keys as $key ) {
        if ( ! isset( $still[ $key ] ) ) {
            rm_forget_pilot_profile( $key );
            $gone[] = $key;
        }
    }
    return $gone;
}

/**
 * The pilot keys of every registration, of every race. The key is worked out from the address,
 * never stored, so this reads them all - a few hundred rows on a club's site.
 *
 * @return string[]
 */
function rm_registered_pilot_keys() {
    global $wpdb;
    $table = $wpdb->prefix . 'rm_registrations';
    $keys  = array();
    foreach ( (array) $wpdb->get_col( "SELECT form_value FROM $table" ) as $form_value ) {
        $data = maybe_unserialize( $form_value );
        if ( is_array( $data ) && isset( $data['pilot_mail_1'] ) && is_string( $data['pilot_mail_1'] ) ) {
            $key = rm_pilot_key( $data['pilot_mail_1'] );
            if ( '' !== $key ) {
                $keys[ $key ] = true;
            }
        }
    }
    return array_keys( $keys );
}

/**
 * The photos' directory, trailing-slashed; with $create, made when missing and given an index.php
 * so that the server lists nothing.
 *
 * @param bool $create
 * @return string|WP_Error
 */
function rm_pilot_photo_dir( $create = true ) {
    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['error'] ) ) {
        return new WP_Error( 'upload_dir_unavailable', sprintf( 'Uploads directory is not available: %s', $upload_dir['error'] ) );
    }
    $path = trailingslashit( $upload_dir['basedir'] ) . RM_PILOT_PHOTO_DIR . '/';
    if ( $create ) {
        if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
            return new WP_Error( 'upload_dir_not_writable', sprintf( 'Could not create the pilot photo directory: %s', $path ) );
        }
        if ( ! is_file( $path . 'index.php' ) ) {
            @file_put_contents( $path . 'index.php', "<?php\n// Silence is golden.\n" );
        }
    }
    return $path;
}

/**
 * The photos' URL, trailing-slashed.
 *
 * @return string
 */
function rm_pilot_photo_url() {
    $upload_dir = wp_upload_dir();
    return trailingslashit( $upload_dir['baseurl'] ) . RM_PILOT_PHOTO_DIR . '/';
}

/**
 * Store an uploaded photo as the pilot's: upright, cut to its centre square, at most
 * RM_PILOT_PHOTO_SIZE on a side, as a JPEG without the camera's metadata.
 *
 * One file per pilot, {key}.jpg, replaced whole: a race archived before the photo changed shows the
 * new one, not a broken image. Its version - the start of its hash - goes into the URL instead, so
 * that a browser does not keep showing the old one.
 *
 * @param string $key    The pilot key.
 * @param string $source The uploaded file.
 * @return string The version, or '' when the file is no image this site can read.
 */
function rm_store_pilot_photo( $key, $source ) {
    $key = rm_valid_pilot_key( $key );
    if ( '' === $key || ! is_file( $source ) || ! function_exists( 'wp_get_image_editor' ) ) {
        return '';
    }
    $dir = rm_pilot_photo_dir();
    if ( is_wp_error( $dir ) ) {
        error_log( 'rm_store_pilot_photo: ' . $dir->get_error_message() );
        return '';
    }

    // Imagick strips what WordPress lets it - not EXIF, IPTC and XMP, which it keeps on purpose;
    // rm_jpeg_without_metadata() takes those off below, whichever editor wrote the file.
    add_filter( 'image_strip_meta', '__return_true', PHP_INT_MAX );
    $temp = $dir . '.' . $key . '.' . bin2hex( random_bytes( 4 ) ) . '.jpg';
    try {
        $editor = wp_get_image_editor( $source );
        if ( is_wp_error( $editor ) ) {
            return '';
        }
        // A phone stores a photo sideways and says so in its EXIF data; turn it the way it is seen.
        $editor->maybe_exif_rotate();
        $size = $editor->get_size();
        $side = (int) min( $size['width'], $size['height'] );
        if ( $side < 1 ) {
            return '';
        }
        $target = min( $side, RM_PILOT_PHOTO_SIZE );
        $cropped = $editor->crop(
            (int) floor( ( $size['width'] - $side ) / 2 ),
            (int) floor( ( $size['height'] - $side ) / 2 ),
            $side,
            $side,
            $target,
            $target
        );
        if ( is_wp_error( $cropped ) ) {
            return '';
        }
        $editor->set_quality( 82 );
        $saved = $editor->save( $temp, 'image/jpeg' );
        if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! is_file( $saved['path'] ) ) {
            return '';
        }
        if ( $saved['path'] !== $temp ) {
            @unlink( $saved['path'] );
            return '';
        }
        // The camera's metadata - where and when the photo was taken, with what, by whom - never
        // goes out.
        $jpeg = rm_jpeg_without_metadata( (string) file_get_contents( $temp ) );
        if ( null === $jpeg || false === @file_put_contents( $temp, $jpeg ) ) {
            return '';
        }
        if ( ! @rename( $temp, $dir . $key . '.jpg' ) ) {
            error_log( 'rm_store_pilot_photo: could not move the photo into ' . $dir );
            return '';
        }
        return substr( hash( 'sha256', $jpeg ), 0, 8 );
    } finally {
        remove_filter( 'image_strip_meta', '__return_true', PHP_INT_MAX );
        if ( is_file( $temp ) ) {
            @unlink( $temp );
        }
    }
}

/**
 * A JPEG without its metadata: the APP segments but the JFIF header (APP0), the colour profile
 * (APP2 ICC_PROFILE) and Adobe's colour transform (APP14), and the comments. What goes is EXIF with
 * its GPS position, XMP, IPTC and Photoshop's data. The image data itself is left as it is.
 *
 * @param string $jpeg The file's bytes.
 * @return string|null The bytes without, or null when they are no JPEG this can read.
 */
function rm_jpeg_without_metadata( $jpeg ) {
    $length = strlen( $jpeg );
    if ( $length < 4 || "\xFF\xD8" !== substr( $jpeg, 0, 2 ) ) {
        return null;
    }
    $out = "\xFF\xD8";
    $pos = 2;
    while ( $pos + 2 <= $length ) {
        if ( "\xFF" !== $jpeg[ $pos ] ) {
            return null;
        }
        $marker = ord( $jpeg[ $pos + 1 ] );
        if ( 0xFF === $marker ) { // a fill byte before the marker
            $pos++;
            continue;
        }
        if ( 0xDA === $marker ) { // start of scan: the image data, to the end, as it is
            return $out . substr( $jpeg, $pos );
        }
        if ( 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) ) { // markers without a length
            $out .= substr( $jpeg, $pos, 2 );
            $pos += 2;
            continue;
        }
        if ( 0xD9 === $marker || $pos + 4 > $length ) { // the end, before any image data
            return null;
        }
        $size = unpack( 'n', substr( $jpeg, $pos + 2, 2 ) )[1];
        if ( $size < 2 || $pos + 2 + $size > $length ) {
            return null;
        }
        $segment = substr( $jpeg, $pos, 2 + $size );
        $keep    = ! ( ( $marker >= 0xE1 && $marker <= 0xEF ) || 0xFE === $marker )
            || 0xEE === $marker
            || ( 0xE2 === $marker && "ICC_PROFILE\0" === substr( $segment, 4, 12 ) );
        if ( $keep ) {
            $out .= $segment;
        }
        $pos += 2 + $size;
    }
    return null;
}

/**
 * The profiles of a race's pilots, as its files carry them: pilot key => [ country, photo URL ].
 *
 * Only the pilots in the race, only those whose timer sent their key, and only what their profile
 * has. PHP writes an empty result as a list, [], which the live pages take as none.
 *
 * @param array $race_data The race's data, as the timer uploaded it.
 * @return array<string,array>
 */
function rm_race_pilot_profiles( $race_data ) {
    $keys = array_unique( array_values( rm_pilot_keys_by_id( $race_data ) ) );
    if ( ! $keys ) {
        return array();
    }
    $profiles = rm_pilot_profiles();
    $base     = null;
    $out      = array();
    foreach ( $keys as $key ) {
        if ( empty( $profiles[ $key ] ) || ! is_array( $profiles[ $key ] ) ) {
            continue;
        }
        $profile = $profiles[ $key ];
        $entry   = array();
        $country = rm_valid_country( $profile['country'] ?? '' );
        if ( '' !== $country ) {
            $entry['country'] = $country;
        }
        if ( ! empty( $profile['photo'] ) && is_string( $profile['photo'] ) && preg_match( '/^[0-9a-f]{8}$/', $profile['photo'] ) ) {
            $base           = $base ?? rm_pilot_photo_url();
            $entry['photo'] = $base . $key . '.jpg?v=' . $profile['photo'];
        }
        if ( $entry ) {
            $out[ $key ] = $entry;
        }
    }
    return $out;
}

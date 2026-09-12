<?php
/**
 * The site's half of tests/e2e/pilot-profiles.cjs: a race open for registration, the example
 * registration form on a page of its own, a photo as a phone stores it, and what the plugin made
 * of the registration sent through the form.
 *
 *   ddev wp eval-file tests/e2e/pilot-profiles-site.php <op> [mail]
 *
 *   begin           a race open for registration, a form with the example form's content and mail,
 *                   a page showing it; prints their ids and the page's URL. What a run that did not
 *                   finish left behind is removed first.
 *   photo           a JPEG as a phone stores it: 600 x 400 pixels, red on the left, blue on the
 *                   right, with EXIF orientation 6 ("turn it 90° clockwise to show it") and an
 *                   Artist tag that no published photo may carry. Prints it base64-encoded, and the
 *                   orientation PHP reads back from it.
 *   inspect <mail>  that address's registrations for the race, its profile, its photo as stored -
 *                   size, type, metadata, the colour at the top, bottom, left and right - and what
 *                   a race's files carry for it
 *   editor <name>   the same photo through rm_store_pilot_photo() with one image editor, gd or
 *                   imagick, whichever the site would pick; prints the stored photo as inspect does,
 *                   and removes it again
 *   delete <mail>   deletes that address's registrations as the admin page does
 *   end             removes the race with its registrations, the form, the page, and the address's
 *                   profile and photo
 *
 * Only through WP-CLI: the plugin directory is served on the development site, and this file must
 * do nothing when requested.
 */

if ( ! defined( 'WP_CLI' ) ) {
    exit;
}

/** Where the ids of what begin made are kept, so that end finds them after any run. */
const RM_E2E_PROFILES_STATE = 'rm_e2e_pilot_profiles';
/** The address the test registers with. */
const RM_E2E_PROFILES_MAIL = 'rm-e2e-profile@example.test';

$op   = $args[0] ?? '';
$mail = $args[1] ?? RM_E2E_PROFILES_MAIL;

/**
 * The registrations of an address for the test race, as rows.
 *
 * @param int    $race_id
 * @param string $mail
 * @return array[]
 */
function rm_e2e_profiles_registrations( $race_id, $mail ) {
    global $wpdb;
    $table = $wpdb->prefix . 'rm_registrations';
    $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE race_id = %d", $race_id ), ARRAY_A );
    return array_values( array_filter( (array) $rows, function ( $row ) use ( $mail ) {
        $data = maybe_unserialize( $row['form_value'] );
        return is_array( $data ) && isset( $data['pilot_mail_1'] ) && 0 === strcasecmp( trim( (string) $data['pilot_mail_1'] ), $mail );
    } ) );
}

/**
 * The test photo: 600 x 400, red left, blue right, EXIF orientation 6 and an Artist.
 *
 * @return string JPEG bytes.
 */
function rm_e2e_profiles_photo() {
    $image = imagecreatetruecolor( 600, 400 );
    imagefilledrectangle( $image, 0, 0, 299, 399, imagecolorallocate( $image, 220, 20, 20 ) );
    imagefilledrectangle( $image, 300, 0, 599, 399, imagecolorallocate( $image, 20, 20, 220 ) );
    ob_start();
    imagejpeg( $image, null, 90 );
    $jpeg = ob_get_clean();
    // An APP1 segment with a TIFF header in big-endian order and one directory: Orientation
    // (0x0112, a SHORT) = 6, and Artist (0x013B, ASCII), whose text follows the directory.
    $artist = "rm-e2e-canary\0";
    $ifd    = pack( 'n', 2 )
            . pack( 'nnNnn', 0x0112, 3, 1, 6, 0 )
            . pack( 'nnNN', 0x013B, 2, strlen( $artist ), 8 + 2 + 2 * 12 + 4 )
            . pack( 'N', 0 );
    $tiff   = 'MM' . pack( 'nN', 42, 8 ) . $ifd . $artist;
    $app1   = "\xFF\xE1" . pack( 'n', 2 + 6 + strlen( $tiff ) ) . "Exif\0\0" . $tiff;
    return substr( $jpeg, 0, 2 ) . $app1 . substr( $jpeg, 2 );
}

/**
 * A stored photo: size, type, whether metadata is left, the colour at four points, its version.
 *
 * @param string $file
 * @return array|null
 */
function rm_e2e_profiles_inspect_photo( $file ) {
    if ( ! $file || ! is_file( $file ) ) {
        return null;
    }
    $bytes = file_get_contents( $file );
    $info  = getimagesize( $file );
    $image = imagecreatefromjpeg( $file );
    $w     = imagesx( $image );
    $h     = imagesy( $image );
    $at    = function ( $x, $y ) use ( $image ) {
        $c = imagecolorat( $image, $x, $y );
        return array( ( $c >> 16 ) & 255, ( $c >> 8 ) & 255, $c & 255 );
    };
    return array(
        'width'   => $w,
        'height'  => $h,
        'mime'    => $info['mime'] ?? '',
        'exif'    => false !== strpos( $bytes, "Exif\0\0" ),
        'artist'  => false !== strpos( $bytes, 'rm-e2e-canary' ),
        'top'     => $at( intdiv( $w, 2 ), intdiv( $h, 8 ) ),
        'bottom'  => $at( intdiv( $w, 2 ), $h - 1 - intdiv( $h, 8 ) ),
        'left'    => $at( intdiv( $w, 8 ), intdiv( $h, 2 ) ),
        'right'   => $at( $w - 1 - intdiv( $w, 8 ), intdiv( $h, 2 ) ),
        'version' => substr( hash( 'sha256', $bytes ), 0, 8 ),
    );
}

/**
 * Remove what begin made, and the test address's profile.
 *
 * @return array What was removed.
 */
function rm_e2e_profiles_end() {
    global $wpdb;
    $state   = get_option( RM_E2E_PROFILES_STATE );
    $removed = array();
    if ( is_array( $state ) ) {
        if ( ! empty( $state['race'] ) ) {
            $table            = $wpdb->prefix . 'rm_registrations';
            $removed['registrations'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE race_id = %d", $state['race'] ) );
            $removed['race']  = (bool) wp_delete_post( (int) $state['race'], true );
        }
        foreach ( array( 'form', 'page' ) as $what ) {
            if ( ! empty( $state[ $what ] ) ) {
                $removed[ $what ] = (bool) wp_delete_post( (int) $state[ $what ], true );
            }
        }
    }
    $key = rm_pilot_key( RM_E2E_PROFILES_MAIL );
    $removed['profile'] = isset( rm_pilot_profiles()[ $key ] );
    rm_forget_pilot_profile( $key );
    delete_option( RM_E2E_PROFILES_STATE );
    return $removed;
}

switch ( $op ) {
    case 'begin':
        rm_e2e_profiles_end();
        $day  = gmdate( 'Y-m-d', strtotime( '+30 days' ) );
        $race = wp_insert_post( array( 'post_type' => 'race', 'post_status' => 'publish', 'post_title' => 'E2E pilot profiles' ), true );
        if ( is_wp_error( $race ) ) {
            WP_CLI::error( $race->get_error_message() );
        }
        update_post_meta( $race, '_race_event_start', $day . ' 09:00:00' );
        update_post_meta( $race, '_race_event_end', $day . ' 18:00:00' );
        // Open for registration: the form's race list asks for this to be anything but 1, which a
        // race without it is not.
        update_post_meta( $race, '_race_reg_closed', 0 );

        $form = WPCF7_ContactForm::get_template();
        $form->set_title( 'E2E pilot profiles' );
        $form->set_properties( array(
            'form' => rm_cf7_registration_form_content(),
            'mail' => rm_cf7_registration_mail( rm_registration_email() ),
        ) );
        $form->save();
        $form_id = (int) $form->id();

        $page = wp_insert_post( array(
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => 'E2E pilot profiles',
            'post_content' => '[contact-form-7 id="' . $form_id . '"]',
        ), true );
        if ( is_wp_error( $page ) ) {
            WP_CLI::error( $page->get_error_message() );
        }
        update_option( RM_E2E_PROFILES_STATE, array( 'race' => $race, 'form' => $form_id, 'page' => $page ), false );
        echo wp_json_encode( array( 'race' => $race, 'form' => $form_id, 'page' => $page, 'url' => get_permalink( $page ) ) ), "\n";
        break;

    case 'photo':
        $jpeg = rm_e2e_profiles_photo();
        $temp = wp_tempnam( 'rm-e2e-photo.jpg' );
        file_put_contents( $temp, $jpeg );
        $exif = function_exists( 'exif_read_data' ) ? @exif_read_data( $temp ) : array();
        @unlink( $temp );
        echo wp_json_encode( array( 'jpeg' => base64_encode( $jpeg ), 'orientation' => (int) ( $exif['Orientation'] ?? 0 ), 'artist' => (string) ( $exif['Artist'] ?? '' ) ) ), "\n";
        break;

    case 'editor':
        $classes = array( 'gd' => 'WP_Image_Editor_GD', 'imagick' => 'WP_Image_Editor_Imagick' );
        $class   = $classes[ $args[1] ?? '' ] ?? '';
        require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
        require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
        require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
        if ( ! $class || ! call_user_func( array( $class, 'test' ), array() ) ) {
            echo wp_json_encode( array( 'available' => false ) ), "\n";
            break;
        }
        $only = fn() => array( $class );
        add_filter( 'wp_image_editors', $only, PHP_INT_MAX );
        $key  = rm_pilot_key( 'rm-e2e-editor@example.test' );
        $temp = wp_tempnam( 'rm-e2e-photo.jpg' );
        file_put_contents( $temp, rm_e2e_profiles_photo() );
        $version = rm_store_pilot_photo( $key, $temp );
        @unlink( $temp );
        remove_filter( 'wp_image_editors', $only, PHP_INT_MAX );
        $dir   = rm_pilot_photo_dir( false );
        $photo = rm_e2e_profiles_inspect_photo( is_wp_error( $dir ) ? '' : $dir . $key . '.jpg' );
        rm_forget_pilot_profile( $key );
        echo wp_json_encode( array( 'available' => true, 'editor' => $class, 'version' => $version, 'photo' => $photo ) ), "\n";
        break;

    case 'inspect':
        $state = get_option( RM_E2E_PROFILES_STATE );
        $key   = rm_pilot_key( $mail );
        $rows  = is_array( $state ) ? rm_e2e_profiles_registrations( (int) $state['race'], $mail ) : array();
        $data  = $rows ? maybe_unserialize( end( $rows )['form_value'] ) : array();
        $dir   = rm_pilot_photo_dir( false );
        $photo = rm_e2e_profiles_inspect_photo( is_wp_error( $dir ) ? '' : $dir . $key . '.jpg' );
        $payload = array( 'pilot_data' => array( 'pilots' => array( array( 'pilot_id' => 1, 'callsign' => 'E2E', 'pilot_key' => $key ) ) ) );
        echo wp_json_encode( array(
            'key'           => $key,
            'registrations' => count( $rows ),
            'stored'        => array( 'country' => $data['pilot_country_1'] ?? null, 'photo' => $data['pilot_photo_1'] ?? null ),
            'profile'       => rm_pilot_profiles()[ $key ] ?? null,
            'photo'         => $photo,
            'listing'       => ! is_wp_error( $dir ) && is_file( $dir . 'index.php' ),
            'race'          => rm_race_pilot_profiles( $payload ),
            'photo_url'     => rm_pilot_photo_url(),
        ) ), "\n";
        break;

    case 'delete':
        $state = get_option( RM_E2E_PROFILES_STATE );
        $key   = rm_pilot_key( $mail );
        $race  = is_array( $state ) ? (int) $state['race'] : 0;
        $ids   = array_map( fn( $row ) => (int) $row['id'], rm_e2e_profiles_registrations( $race, $mail ) );
        $gone  = rm_delete_registrations( $race, $ids );
        $dir   = rm_pilot_photo_dir( false );
        echo wp_json_encode( array(
            'deleted' => $gone,
            'profile' => rm_pilot_profiles()[ $key ] ?? null,
            'photo'   => ! is_wp_error( $dir ) && is_file( $dir . $key . '.jpg' ),
        ) ), "\n";
        break;

    case 'end':
        echo wp_json_encode( array( 'removed' => rm_e2e_profiles_end() ) ), "\n";
        break;

    default:
        WP_CLI::error( 'op: begin, photo, inspect <mail>, delete <mail> or end' );
}

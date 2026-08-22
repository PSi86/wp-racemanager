<?php
// includes/vapid-handler.php
// Central handling of the VAPID key pair used to sign Web Push messages.
//
// Sources, in order of precedence:
//   1. The constants RM_VAPID_PUBLIC_KEY / RM_VAPID_PRIVATE_KEY / RM_VAPID_SUBJECT,
//      defined in wp-config.php. Recommended for production: the private key then
//      stays out of the database and out of every database dump.
//   2. The 'rm_vapid' option, managed on Settings -> RaceManager.
//
// A key pair is generated automatically on activation, but only when that is safe:
// if push subscriptions already exist, generating new keys would invalidate every
// one of them, so the decision is left to the site owner (see rm_maybe_bootstrap_vapid_keys).

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Return the VAPID configuration, with constants taking precedence over the option.
 *
 * @return array{publicKey: string, privateKey: string, subject: string}
 */
function rm_get_vapid() {
    $stored = get_option( 'rm_vapid', array() );
    if ( ! is_array( $stored ) ) {
        $stored = array();
    }

    $vapid = wp_parse_args(
        $stored,
        array(
            'publicKey'  => '',
            'privateKey' => '',
            'subject'    => '',
        )
    );

    // Constants override the database, consistent with how WordPress treats
    // wp_get_environment_type() and friends.
    if ( defined( 'RM_VAPID_PUBLIC_KEY' ) && is_string( RM_VAPID_PUBLIC_KEY ) ) {
        $vapid['publicKey'] = RM_VAPID_PUBLIC_KEY;
    }
    if ( defined( 'RM_VAPID_PRIVATE_KEY' ) && is_string( RM_VAPID_PRIVATE_KEY ) ) {
        $vapid['privateKey'] = RM_VAPID_PRIVATE_KEY;
    }
    if ( defined( 'RM_VAPID_SUBJECT' ) && is_string( RM_VAPID_SUBJECT ) ) {
        $vapid['subject'] = RM_VAPID_SUBJECT;
    }

    // The push services require a contact; fall back to the site administrator.
    if ( '' === $vapid['subject'] ) {
        $vapid['subject'] = 'mailto:' . get_option( 'admin_email' );
    }

    return $vapid;
}

/**
 * Whether a usable key pair is available.
 *
 * @return bool
 */
function rm_vapid_is_configured() {
    $vapid = rm_get_vapid();
    return '' !== $vapid['publicKey'] && '' !== $vapid['privateKey'];
}

/**
 * Where the currently active keys come from. Used by the settings screen.
 *
 * @return string 'constant', 'option' or 'none'.
 */
function rm_vapid_source() {
    if ( defined( 'RM_VAPID_PUBLIC_KEY' ) && defined( 'RM_VAPID_PRIVATE_KEY' ) ) {
        return 'constant';
    }
    return rm_vapid_is_configured() ? 'option' : 'none';
}

/**
 * Load the minishlink/web-push library if it can be found.
 *
 * The Composer vendor directory has historically lived outside the plugin, so a few
 * known locations are probed instead of hard-coding a single relative path.
 *
 * @return bool True if the library is available.
 */
function rm_push_library_available() {
    static $available = null;

    if ( null !== $available ) {
        return $available;
    }

    if ( class_exists( '\Minishlink\WebPush\VAPID' ) ) {
        $available = true;
        return $available;
    }

    $candidates = array();
    if ( defined( 'WP_RACEMANAGER_DIR' ) ) {
        $candidates[] = WP_RACEMANAGER_DIR . 'vendor/autoload.php';        // plugin-local (preferred)
        $candidates[] = dirname( WP_RACEMANAGER_DIR, 4 ) . '/vendor/autoload.php'; // above the WordPress root
    }
    $candidates[] = ABSPATH . 'vendor/autoload.php';
    $candidates[] = dirname( ABSPATH ) . '/vendor/autoload.php';

    foreach ( array_unique( $candidates ) as $autoloader ) {
        if ( file_exists( $autoloader ) ) {
            require_once $autoloader;
            if ( class_exists( '\Minishlink\WebPush\VAPID' ) ) {
                $available = true;
                return $available;
            }
        }
    }

    $available = false;
    return $available;
}

/**
 * Whether push notifications can actually be sent right now.
 *
 * @return bool
 */
function rm_push_available() {
    return rm_vapid_is_configured() && rm_push_library_available();
}

/**
 * Normalise a base64url encoded VAPID key.
 *
 * @param string $key Raw input.
 * @return string The cleaned key, or an empty string if it does not look like one.
 */
function rm_sanitize_vapid_key( $key ) {
    if ( ! is_string( $key ) ) {
        return '';
    }

    $key = preg_replace( '/\s+/', '', $key );

    // base64url alphabet, optionally padded.
    if ( '' === $key || ! preg_match( '/^[A-Za-z0-9_-]{20,}={0,2}$/', $key ) ) {
        return '';
    }

    return $key;
}

/**
 * Normalise the VAPID subject, which must be a mailto: or https: URI.
 *
 * @param string $subject Raw input.
 * @return string The cleaned subject, or an empty string.
 */
function rm_sanitize_vapid_subject( $subject ) {
    if ( ! is_string( $subject ) ) {
        return '';
    }

    $subject = trim( $subject );
    if ( '' === $subject ) {
        return '';
    }

    if ( 0 === stripos( $subject, 'mailto:' ) ) {
        $mail = sanitize_email( substr( $subject, 7 ) );
        return $mail ? 'mailto:' . $mail : '';
    }

    if ( 0 === stripos( $subject, 'https://' ) ) {
        return esc_url_raw( $subject, array( 'https' ) );
    }

    // A bare address is the most likely input, so accept it.
    $mail = sanitize_email( $subject );
    return $mail ? 'mailto:' . $mail : '';
}

/**
 * Generate a fresh VAPID key pair.
 *
 * @return array{publicKey: string, privateKey: string}|WP_Error
 */
function rm_generate_vapid_keys() {
    if ( ! rm_push_library_available() ) {
        return new WP_Error(
            'rm_push_library_missing',
            __( 'The minishlink/web-push library could not be found. Run "composer install" first.', 'wp-racemanager' )
        );
    }

    try {
        $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
    } catch ( \Throwable $e ) {
        return new WP_Error( 'rm_vapid_generation_failed', $e->getMessage() );
    }

    if ( empty( $keys['publicKey'] ) || empty( $keys['privateKey'] ) ) {
        return new WP_Error(
            'rm_vapid_generation_failed',
            __( 'The key pair could not be generated.', 'wp-racemanager' )
        );
    }

    return array(
        'publicKey'  => $keys['publicKey'],
        'privateKey' => $keys['privateKey'],
    );
}

/**
 * Persist a key pair, preserving the configured subject.
 *
 * @param string $public_key  Public key.
 * @param string $private_key Private key.
 * @return bool True on success.
 */
function rm_store_vapid_keys( $public_key, $private_key ) {
    $public_key  = rm_sanitize_vapid_key( $public_key );
    $private_key = rm_sanitize_vapid_key( $private_key );

    if ( '' === $public_key || '' === $private_key ) {
        return false;
    }

    $stored = get_option( 'rm_vapid', array() );
    if ( ! is_array( $stored ) ) {
        $stored = array();
    }

    $stored['publicKey']  = $public_key;
    $stored['privateKey'] = $private_key;
    if ( empty( $stored['subject'] ) ) {
        $stored['subject'] = 'mailto:' . get_option( 'admin_email' );
    }

    // Keep the private key out of the autoloaded option cache.
    return update_option( 'rm_vapid', $stored, false );
}

/**
 * Whether any push subscriptions are stored.
 *
 * Used to decide whether generating keys automatically is safe: replacing the key
 * pair invalidates the applicationServerKey baked into every existing subscription.
 *
 * @return bool
 */
function rm_has_push_subscriptions() {
    global $wpdb;

    $table = $wpdb->prefix . 'rm_subscriptions';
    $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $found !== $table ) {
        return false;
    }

    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) > 0;
}

/**
 * Delete every stored push subscription.
 *
 * @return int|false Number of rows removed, or false on failure.
 */
function rm_delete_all_subscriptions() {
    global $wpdb;

    $table = $wpdb->prefix . 'rm_subscriptions';
    $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $found !== $table ) {
        return 0;
    }

    return $wpdb->query( "DELETE FROM $table" );
}

/**
 * Create a key pair on first run, but never at the cost of existing subscriptions.
 *
 * Runs on activation and on admin_init, so an install that is updated rather than
 * freshly activated also picks it up.
 *
 * @return void
 */
function rm_maybe_bootstrap_vapid_keys() {
    // Both checks below are a single option read, so this stays cheap on every
    // admin request once keys exist or the library is absent.
    if ( rm_vapid_is_configured() || ! rm_push_library_available() ) {
        return;
    }

    // Someone was pushing before, using keys we cannot see (previously hard-coded in
    // pwa-subscription-handler.php). Generating new ones would silently break every
    // existing subscription, so let the site owner decide on the settings screen.
    if ( rm_has_push_subscriptions() ) {
        return;
    }

    $keys = rm_generate_vapid_keys();
    if ( is_wp_error( $keys ) ) {
        return;
    }

    rm_store_vapid_keys( $keys['publicKey'], $keys['privateKey'] );
}
add_action( 'admin_init', 'rm_maybe_bootstrap_vapid_keys' );

/**
 * Point the administrator at the settings screen while push is not usable.
 *
 * @return void
 */
function rm_vapid_admin_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $screen = get_current_screen();
    if ( $screen && 'settings_page_rm' === $screen->id ) {
        return; // The settings screen states this itself.
    }

    if ( rm_push_available() ) {
        return;
    }

    $settings_url = admin_url( 'options-general.php?page=rm' );

    if ( ! rm_push_library_available() ) {
        $message = __( 'WP RaceManager: push notifications are disabled because the minishlink/web-push library could not be found.', 'wp-racemanager' );
    } elseif ( rm_has_push_subscriptions() ) {
        $message = __( 'WP RaceManager: push notifications are disabled because no VAPID keys are stored. Subscriptions already exist, so enter your existing keys instead of generating new ones.', 'wp-racemanager' );
    } else {
        $message = __( 'WP RaceManager: push notifications are disabled because no VAPID keys are stored.', 'wp-racemanager' );
    }

    printf(
        '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
        esc_html( $message ),
        esc_url( $settings_url ),
        esc_html__( 'Open RaceManager settings', 'wp-racemanager' )
    );
}
add_action( 'admin_notices', 'rm_vapid_admin_notice' );

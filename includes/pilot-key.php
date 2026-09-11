<?php
// includes/pilot-key.php
// One identifier per pilot, reproducible from the email address they register with.
//
// Registering needs no WordPress account, and none is created for it -- so user_id is 0 for
// most entrants and tells RotorHazard nothing about who is who. The pilot key does: the same
// address gives the same key, for every race and every download of the registrations, and the
// timer can recognise a returning pilot by it. Decided on 2026-09-11; docs/pilot-identity.md
// has the options that were weighed.
//
// The key is a name-based UUID (version 5, RFC 9562) of the normalised address, in a namespace
// that belongs to this site: a random UUID generated once and kept in the rm_pilot_namespace
// option, or RM_PILOT_NAMESPACE in wp-config.php, which takes precedence -- the same order as
// the VAPID keys. A namespace nobody else knows is what keeps the key from being a plain hash of
// the address, which anyone holding a list of addresses could recompute; the key goes to the
// timer, and from there possibly into published results. What that costs: the namespace has to
// be kept. A new one gives every pilot a new key.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * The namespace this site's pilot keys are derived in.
 *
 * RM_PILOT_NAMESPACE wins when it is defined. An invalid constant yields no namespace at all
 * rather than a fallback: keys from the option would be different keys, handed out as if they
 * were the intended ones. Without the constant, the option is created on first use.
 *
 * @return string Lower-case UUID, or '' when RM_PILOT_NAMESPACE is defined but invalid.
 */
function rm_pilot_namespace() {
    if ( defined( 'RM_PILOT_NAMESPACE' ) ) {
        return rm_valid_pilot_namespace( RM_PILOT_NAMESPACE );
    }

    $stored = rm_valid_pilot_namespace( get_option( 'rm_pilot_namespace', '' ) );
    if ( '' !== $stored ) {
        return $stored;
    }

    // A version-4 UUID from random_bytes() rather than wp_generate_uuid4(), which draws on
    // mt_rand(): this value is what keeps the keys from being recomputed, so it wants a
    // cryptographic source.
    $bytes    = random_bytes( 16 );
    $bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
    $bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
    $hex      = bin2hex( $bytes );
    $fresh    = substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-'
              . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );

    update_option( 'rm_pilot_namespace', $fresh, false );

    return $fresh;
}
// Created on the first admin request after an update rather than in the middle of the first
// registration download -- a ZIP replace does not run the activation hook (see deployment.md).
add_action( 'admin_init', 'rm_pilot_namespace' );

/**
 * A namespace value, if it is a UUID.
 *
 * Any UUID will do as a namespace, so this checks the shape only. Not wp_is_uuid(): that one
 * refuses capital letters and every version above 5, and a UUID pasted into wp-config.php in
 * capitals is the same UUID.
 *
 * @param mixed $value Candidate.
 * @return string Lower-case UUID, or '' when the value is none.
 */
function rm_valid_pilot_namespace( $value ) {
    if ( ! is_string( $value ) ) {
        return '';
    }
    $value = strtolower( trim( $value ) );
    return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value ) ? $value : '';
}

/**
 * Where the namespace comes from. Used by the settings screen.
 *
 * @return string 'constant', 'constant-invalid' or 'option'.
 */
function rm_pilot_namespace_source() {
    if ( defined( 'RM_PILOT_NAMESPACE' ) ) {
        return '' !== rm_valid_pilot_namespace( RM_PILOT_NAMESPACE ) ? 'constant' : 'constant-invalid';
    }
    return 'option';
}

/**
 * The pilot key for an email address.
 *
 * The address is trimmed and lower-cased first: "Pilot@Example.com " and "pilot@example.com" are
 * one pilot. Nothing beyond that -- a typo in the address is a different address, and so a
 * different pilot.
 *
 * @param string $email The address the pilot registered with.
 * @return string A version-5 UUID, or '' when there is no address or no usable namespace.
 */
function rm_pilot_key( $email ) {
    $email = strtolower( trim( (string) $email ) );
    if ( '' === $email ) {
        return '';
    }

    $namespace = rm_pilot_namespace();
    if ( '' === $namespace ) {
        return '';
    }

    return rm_uuid5( $namespace, $email );
}

/**
 * A name-based UUID, version 5 (RFC 9562, section 5.5): SHA-1 over the namespace's 16 bytes and
 * the name, the first 16 bytes of it, version and variant bits set.
 *
 * @param string $namespace A UUID.
 * @param string $name      Any string.
 * @return string Lower-case UUID.
 */
function rm_uuid5( $namespace, $name ) {
    $hash = sha1( hex2bin( str_replace( '-', '', $namespace ) ) . $name );

    return sprintf(
        '%s-%s-%04x-%04x-%s',
        substr( $hash, 0, 8 ),
        substr( $hash, 8, 4 ),
        ( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000,
        ( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000,
        substr( $hash, 20, 12 )
    );
}

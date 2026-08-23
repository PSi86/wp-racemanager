<?php
// includes/race-dates.php
// One canonical format for the event dates, plus the analysis and migration behind it.
//
// _race_event_start / _race_event_end used to hold two different things:
//
//   1750000000            a Unix integer, written by the REST upload when RotorHazard
//                         creates a race (strtotime('today 8:00'))
//   2026-08-22T10:00      a datetime-local string, written by the admin meta box
//
// Every query casts these with 'type' => 'DATE' / 'DATETIME'. CAST('1750000000' AS DATETIME)
// yields NULL, so every race auto-created by RotorHazard silently dropped out of the
// navigation submenu, the archive filter, the CF7 registration dropdown, and sorted as NULL
// in the race list.
//
// The canonical format is 'Y-m-d H:i:s' in site-local wall clock -- the same thing
// current_time('mysql') produces, which is what _race_last_upload already uses and what the
// queries compare against.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/** Meta keys holding an event date. */
const RM_EVENT_DATE_KEYS = array( '_race_event_start', '_race_event_end' );

/**
 * Bring any of the historically stored shapes into the canonical format.
 *
 * Integers are read back with gmdate() rather than wp_date(). WordPress runs PHP in UTC
 * (wp-settings.php calls date_default_timezone_set('UTC')), so the integers that strtotime()
 * produced reverse exactly -- 'today 8:00' comes back as 08:00:00, the wall clock the caller
 * meant. Converting through the site timezone instead would shift those values by the UTC
 * offset and turn an intended 8am start into 10am.
 *
 * @param mixed $value Stored meta value.
 * @return string Canonical 'Y-m-d H:i:s', or '' when the value cannot be understood.
 */
function rm_normalize_event_datetime( $value ) {
    if ( is_int( $value ) || ( is_string( $value ) && '' !== $value && ctype_digit( $value ) ) ) {
        $timestamp = (int) $value;
        return $timestamp > 0 ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
    }

    if ( ! is_string( $value ) ) {
        return '';
    }

    $value = trim( $value );
    if ( '' === $value ) {
        return '';
    }

    // 2026-08-22, 2026-08-22T10:00, 2026-08-22 10:00:00
    if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $value, $m ) ) {
        return '';
    }

    list( , $year, $month, $day ) = $m;
    $hour   = isset( $m[4] ) ? (int) $m[4] : 0;
    $minute = isset( $m[5] ) ? (int) $m[5] : 0;
    $second = isset( $m[6] ) ? (int) $m[6] : 0;

    if ( ! checkdate( (int) $month, (int) $day, (int) $year ) || $hour > 23 || $minute > 59 || $second > 59 ) {
        return '';
    }

    return sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second );
}

/**
 * Format a stored value for an <input type="datetime-local">.
 *
 * That input only accepts YYYY-MM-DDTHH:MM -- a space instead of the T makes browsers render
 * an empty field, which is why the canonical format cannot be used directly in the meta box.
 *
 * @param mixed $value Stored meta value.
 * @return string Value for the input, or '' when there is nothing to show.
 */
function rm_event_datetime_for_input( $value ) {
    $canonical = rm_normalize_event_datetime( $value );
    return '' === $canonical ? '' : str_replace( ' ', 'T', substr( $canonical, 0, 16 ) );
}

/**
 * Classify a stored value, for the analysis report.
 *
 * @param mixed $value Stored meta value.
 * @return string One of 'canonical', 'timestamp', 'iso-t', 'date-only', 'empty', 'unreadable'.
 */
function rm_classify_event_datetime( $value ) {
    if ( '' === $value || null === $value ) {
        return 'empty';
    }
    if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
        return 'timestamp';
    }
    if ( is_string( $value ) ) {
        if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) ) {
            return 'canonical';
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value ) ) {
            return 'iso-t';
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
            return 'date-only';
        }
    }
    return 'unreadable';
}

/**
 * Look at every stored event date without changing anything.
 *
 * This is the dry run: it reports what a migration would do, so the decision to write can be
 * made with the numbers in hand.
 *
 * @return array{
 *     counts: array<string,int>,
 *     total: int,
 *     would_change: int,
 *     unreadable: array<int,array{post_id:int,key:string,value:string}>,
 *     samples: array<int,array{post_id:int,title:string,key:string,from:string,to:string}>
 * }
 */
function rm_scan_event_dates() {
    global $wpdb;

    $report = array(
        'counts'       => array( 'canonical' => 0, 'timestamp' => 0, 'iso-t' => 0, 'date-only' => 0, 'empty' => 0, 'unreadable' => 0 ),
        'total'        => 0,
        'would_change' => 0,
        'unreadable'   => array(),
        'samples'      => array(),
    );

    $rows = $wpdb->get_results(
        "SELECT m.post_id, m.meta_key, m.meta_value
           FROM {$wpdb->postmeta} m
           INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
          WHERE p.post_type = 'race'
            AND m.meta_key IN ('_race_event_start', '_race_event_end')
          ORDER BY m.post_id ASC"
    );

    foreach ( (array) $rows as $row ) {
        ++$report['total'];

        $class = rm_classify_event_datetime( $row->meta_value );
        ++$report['counts'][ $class ];

        if ( 'unreadable' === $class ) {
            $report['unreadable'][] = array(
                'post_id' => (int) $row->post_id,
                'key'     => $row->meta_key,
                'value'   => (string) $row->meta_value,
            );
            continue;
        }

        $canonical = rm_normalize_event_datetime( $row->meta_value );
        if ( '' !== $canonical && $canonical !== $row->meta_value ) {
            ++$report['would_change'];
            if ( count( $report['samples'] ) < 10 ) {
                $report['samples'][] = array(
                    'post_id' => (int) $row->post_id,
                    'title'   => get_the_title( $row->post_id ),
                    'key'     => $row->meta_key,
                    'from'    => (string) $row->meta_value,
                    'to'      => $canonical,
                );
            }
        }
    }

    return $report;
}

/**
 * Rewrite every event date into the canonical format.
 *
 * Values that cannot be understood are left untouched and reported rather than guessed at or
 * cleared -- losing an organiser's date would be worse than leaving it inconsistent.
 *
 * @return array{changed:int, skipped:int, unreadable:int}
 */
function rm_migrate_event_dates() {
    global $wpdb;

    $result = array( 'changed' => 0, 'skipped' => 0, 'unreadable' => 0 );

    $rows = $wpdb->get_results(
        "SELECT m.post_id, m.meta_key, m.meta_value
           FROM {$wpdb->postmeta} m
           INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
          WHERE p.post_type = 'race'
            AND m.meta_key IN ('_race_event_start', '_race_event_end')"
    );

    foreach ( (array) $rows as $row ) {
        $canonical = rm_normalize_event_datetime( $row->meta_value );

        if ( '' === $canonical ) {
            if ( '' !== (string) $row->meta_value ) {
                ++$result['unreadable'];
            } else {
                ++$result['skipped'];
            }
            continue;
        }

        if ( $canonical === $row->meta_value ) {
            ++$result['skipped'];
            continue;
        }

        update_post_meta( (int) $row->post_id, $row->meta_key, $canonical );
        ++$result['changed'];
    }

    update_option( 'rm_event_dates_migrated', current_time( 'mysql' ), false );

    return $result;
}

/**
 * Handle the migration request from the settings screen.
 *
 * @return void
 */
function rm_handle_migrate_event_dates() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to manage these settings.', 'wp-racemanager' ), '', array( 'response' => 403 ) );
    }
    check_admin_referer( 'rm_migrate_event_dates' );

    $redirect = admin_url( 'options-general.php?page=rm' );

    if ( empty( $_POST['rm_dates_confirm'] ) ) {
        wp_safe_redirect( add_query_arg( 'rm_dates', 'unconfirmed', $redirect ) );
        exit;
    }

    $result = rm_migrate_event_dates();

    wp_safe_redirect( add_query_arg(
        array( 'rm_dates' => 'migrated', 'rm_dates_changed' => $result['changed'] ),
        $redirect
    ) );
    exit;
}
add_action( 'admin_post_rm_migrate_event_dates', 'rm_handle_migrate_event_dates' );

/**
 * Turn the redirect marker into an admin notice.
 *
 * @return void
 */
function rm_event_dates_notices() {
    if ( empty( $_GET['rm_dates'] ) ) {
        return;
    }

    switch ( sanitize_key( wp_unslash( $_GET['rm_dates'] ) ) ) {
        case 'migrated':
            $changed = isset( $_GET['rm_dates_changed'] ) ? absint( $_GET['rm_dates_changed'] ) : 0;
            add_settings_error(
                'rm_dates',
                'rm_dates_migrated',
                sprintf(
                    /* translators: %d: number of rewritten values */
                    _n( '%d event date was rewritten.', '%d event dates were rewritten.', $changed, 'wp-racemanager' ),
                    $changed
                ),
                'success'
            );
            break;
        case 'unconfirmed':
            add_settings_error( 'rm_dates', 'rm_dates_unconfirmed', __( 'Nothing was changed: the confirmation checkbox was not ticked.', 'wp-racemanager' ), 'warning' );
            break;
    }
}
add_action( 'admin_init', 'rm_event_dates_notices' );

<?php
// includes/race-status.php
// A race's two states, live and archived, and what follows from each.
//
// The parts and the race log belong to a race while it is live. An archived race keeps its results,
// whole, and nothing else (since 1.8.1): see rm_archive_race().

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// A race set to archive, however that happens: the meta box, Quick Edit, WP-CLI, or code that
// updates the meta. Not on deleted_post_meta: that fires for every meta of a race being deleted,
// and would write its files again after the attachments took them. update_post_meta fires before
// the value is stored, and says what the state was.
add_action( 'update_post_meta', 'rm_before_race_live_stored', 10, 4 );
add_action( 'added_post_meta', 'rm_on_race_live_changed', 10, 4 );
add_action( 'updated_post_meta', 'rm_on_race_live_changed', 10, 4 );

// A race's first results: from then on the race list shows it.
add_action( 'added_post_meta', 'rm_on_race_first_results', 10, 4 );

// A race deleted for good takes its push subscriptions with it.
add_action( 'before_delete_post', 'rm_on_race_deleted' );

// A race nobody archives is archived a day after its end, by the hour (WP-Cron).
add_action( 'init', 'rm_schedule_auto_archive' );
add_action( 'rm_auto_archive_races', 'rm_auto_archive_races' );

// The races that were archived before 1.8.1, once.
add_action( 'admin_init', 'rm_maybe_clear_archived_races' );

/**
 * Whether a race is live: it takes uploads and messages, and is stored in parts with its race log.
 *
 * '1', as the meta box, Quick Edit and rm_create_race() store it. Anything else -- '0', nothing --
 * is an archived race, the same reading rm_update_race() gives it when it refuses an upload.
 *
 * @param int $race_id
 * @return bool
 */
function rm_race_is_live( $race_id ) {
    return '1' === (string) get_post_meta( $race_id, '_race_live', true );
}

/**
 * Whether a race is live and has results: what the dot on the live link in the main navigation says.
 *
 * It said "a race had an upload in the last two hours" until 1.9.0. The dot then stayed for up to
 * two hours after a race was archived, went out in a long break of a race that was live -- and in
 * a page cache it stayed as it was whenever the page was cached, since the end of a time window is
 * no event anything could empty the cache on. The flag changes by events, and each empties the page
 * cache (rm_on_race_live_changed()); a race nobody archives is archived a day after its end.
 *
 * @return bool
 */
function rm_live_race_exists() {
    $query = new WP_Query( array(
        'post_type'      => 'race',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => array(
            array( 'key' => '_race_live', 'value' => '1' ),
            array( 'key' => '_race_last_upload', 'compare' => 'EXISTS' ),
        ),
    ) );
    return $query->have_posts();
}

/**
 * Remember, or tell, whether a race was live before its flag was stored again.
 *
 * @param int       $race_id
 * @param bool|null $was_live To remember; null to tell, and forget.
 * @return bool Whether it was live. A flag added rather than updated had no value: not live.
 */
function rm_race_live_before( $race_id, $was_live = null ) {
    static $before = array();
    if ( null !== $was_live ) {
        $before[ $race_id ] = $was_live;
        return $was_live;
    }
    $was = $before[ $race_id ] ?? false;
    unset( $before[ $race_id ] );
    return $was;
}

/**
 * A race's live flag is about to be stored: remember whether the race was live.
 *
 * @param int    $meta_id    Unused.
 * @param int    $post_id    The post whose meta is stored.
 * @param string $meta_key   Its key.
 * @param mixed  $meta_value Unused.
 * @return void
 */
function rm_before_race_live_stored( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( '_race_live' === $meta_key ) {
        rm_race_live_before( (int) $post_id, rm_race_is_live( $post_id ) );
    }
}

/**
 * A race's live flag was stored: when it is off, archive the race; when it changed, empty the page
 * cache.
 *
 * WordPress fires added_post_meta and updated_post_meta after it stored the value, so
 * rm_write_files() already reads the race as archived. It compares the old value with the new one
 * strictly, and Quick Edit passes a number where the database holds a string: saving an archived
 * race there fires this every time. rm_archive_race() does nothing when there is nothing to do, and
 * the page cache is emptied only when the state changed.
 *
 * @param int    $meta_id    Unused.
 * @param int    $post_id    The post whose meta was stored.
 * @param string $meta_key   Its key.
 * @param mixed  $meta_value Its new value.
 * @return void
 */
function rm_on_race_live_changed( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( '_race_live' !== $meta_key || 'race' !== get_post_type( $post_id ) ) {
        return;
    }
    $was_live = rm_race_live_before( (int) $post_id );
    $is_live  = '1' === (string) $meta_value;
    if ( ! $is_live ) {
        rm_archive_race( (int) $post_id );
    }
    if ( $was_live !== $is_live ) {
        rm_purge_page_caches();
    }
}

/**
 * A race got its first results: empty the page cache, since the race list now shows it.
 *
 * Later uploads change nothing a page renders -- the data comes from the race's files -- and empty
 * nothing.
 *
 * @param int    $meta_id    Unused.
 * @param int    $post_id    The post whose meta was added.
 * @param string $meta_key   Its key.
 * @param mixed  $meta_value Unused.
 * @return void
 */
function rm_on_race_first_results( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( '_race_last_upload' === $meta_key && 'race' === get_post_type( $post_id ) ) {
        rm_purge_page_caches();
    }
}

/**
 * Empty the page cache, so that pages rendered with a race's state are rendered anew.
 *
 * The live area is cacheable on purpose, and the state is in its markup: whether a view polls, the
 * next-up view's subscription form or "This race is over.", the "Live:" in the race list, and the
 * dot on the live link, which the navigation of every page carries. Measured on production on
 * 2026-09-12: the home page, the race list and a race's bracket came from LiteSpeed's page cache
 * (X-LiteSpeed-Cache: hit), which keeps a page for 7 days unless configured otherwise. So a change
 * of state empties all of it -- a few times per event -- rather than guessing which pages carry it.
 *
 * LiteSpeed Cache's own call, with the '*' it empties its page cache with; its CSS, JS and object
 * caches are left alone. Without the plugin, nothing happens. The purge travels in a response's
 * headers; from WP-CLI and WP-Cron, where no response carries it, LiteSpeed Cache keeps it and
 * sends it with the next request (read in its source, 7.9.1).
 *
 * @return void
 */
function rm_purge_page_caches() {
    do_action( 'litespeed_purge', '*' );
}

/**
 * What an archived race keeps: its results, whole. Its parts, its index, its race log and its push
 * subscriptions go.
 *
 * Decided on 2026-09-12. The parts serve the updates of a live race, and an archived race takes
 * none: rm_update_race() refuses an upload, handle_notification_request() a message. The race log
 * belongs to the event while it runs; "order lunch now", with the link to the order, has no place
 * on a finished race's pages or in its public JSON. And with nothing left to tell anyone, a push
 * subscription -- a browser's endpoint and keys, the pilot it followed -- has no use left (since
 * 1.9.0); a race set live again is followed anew.
 *
 * The files first and the rest after: when the files cannot be written, log and subscriptions are
 * still there to go the next time. The timestamp moves, since the data changed, and a browser that
 * held the log downloads the whole file once.
 *
 * @param int $race_id
 * @return true|WP_Error WP_Error for a race that is live, a whole file that cannot be read, or files
 *                       that cannot be written; log and subscriptions are then left as they were.
 */
function rm_archive_race( $race_id ) {
    if ( rm_race_is_live( $race_id ) ) {
        return new WP_Error( 'race_live', 'A live race is not archived.', array( 'status' => 400 ) );
    }
    $upload_path = rm_get_race_data_dir( false );
    if ( is_wp_error( $upload_path ) ) {
        return $upload_path;
    }

    $filename_data = $upload_path . $race_id . '-data.json';
    if ( is_file( $filename_data ) ) {
        $data = json_decode( (string) file_get_contents( $filename_data ), true );
        if ( ! is_array( $data ) ) {
            error_log( 'rm_archive_race: race ' . $race_id . ': the whole file cannot be read' );
            return new WP_Error( 'file_read_error', 'The race\'s data file cannot be read.', array( 'status' => 500 ) );
        }
        if ( ! empty( $data['notifications'] ) || isset( $data[ RM_RACE_INDEX_KEY ] ) ) {
            // Not live: the whole file and the timestamp, an empty log, the index and parts removed.
            $written = rm_write_files( $race_id, $data );
            if ( is_wp_error( $written ) ) {
                error_log( 'rm_archive_race: race ' . $race_id . ': ' . $written->get_error_message() );
                return $written;
            }
        }
    }
    if ( is_dir( $upload_path ) ) {
        rm_remove_race_index_and_parts( $upload_path, $race_id );
    }
    delete_post_meta( $race_id, '_race_notification_log' );
    rm_forget_race_followers( $race_id );
    return true;
}

/**
 * Delete a race's push subscriptions: when it is archived, and when it is deleted for good.
 *
 * rm_delete_all_race_subscriptions() was there before, and nothing called it: every subscription
 * ever made stayed, for races long over and races long gone.
 *
 * @param int $race_id
 * @return void
 */
function rm_forget_race_followers( $race_id ) {
    if ( false === rm_delete_all_race_subscriptions( $race_id ) ) {
        error_log( 'rm_forget_race_followers: race ' . $race_id . ': the push subscriptions could not be deleted' );
    }
}

/**
 * A post is deleted for good: when it is a race, its push subscriptions go with it.
 *
 * @param int $post_id
 * @return void
 */
function rm_on_race_deleted( $post_id ) {
    if ( 'race' === get_post_type( $post_id ) ) {
        rm_forget_race_followers( (int) $post_id );
    }
}

/**
 * How long a live race stays live past its end and past its last upload, in seconds: a day.
 */
const RM_AUTO_ARCHIVE_AFTER = 86400;

/**
 * Whether races are archived by themselves: yes, unless wp-config.php defines RM_AUTO_ARCHIVE as
 * false.
 *
 * For a development site, whose races are months old and one of which has to stay live for the
 * browser suites -- it would be archived within the hour, and again after every reset -- and for an
 * organiser who would rather archive by hand.
 *
 * @return bool
 */
function rm_auto_archive_enabled() {
    return ! defined( 'RM_AUTO_ARCHIVE' ) || false !== RM_AUTO_ARCHIVE;
}

/**
 * Schedule the archiving of races nobody archived, by the hour, unless it is scheduled -- or take the
 * schedule away where it is switched off.
 *
 * On init rather than on activation: a ZIP replace runs no activation hook. The plugin's
 * deactivation hook takes the schedule away as well.
 *
 * @return void
 */
function rm_schedule_auto_archive() {
    if ( ! rm_auto_archive_enabled() ) {
        if ( wp_next_scheduled( 'rm_auto_archive_races' ) ) {
            wp_clear_scheduled_hook( 'rm_auto_archive_races' );
        }
        return;
    }
    if ( ! wp_next_scheduled( 'rm_auto_archive_races' ) ) {
        wp_schedule_event( time(), 'hourly', 'rm_auto_archive_races' );
    }
}

/**
 * Archive every live race whose end and last upload are both more than a day ago.
 *
 * Decided on 2026-09-12. A race nobody archives stayed live for good: its visitors polled every 10
 * seconds, it carried the "Live:" and the dot, and a timer could still upload to it months later
 * -- it is among the 15 newest races the timer is offered. Archiving it sets the flag, and the hooks
 * do the rest: files, race log, subscriptions, page cache (rm_on_race_live_changed()).
 *
 * @return void
 */
function rm_auto_archive_races() {
    if ( ! rm_auto_archive_enabled() ) {
        return;
    }
    // The site's wall clock, read and written back in the same zone: a day earlier, as stored.
    $deadline = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - RM_AUTO_ARCHIVE_AFTER );
    $races    = get_posts( array(
        'post_type'   => 'race',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
        'meta_key'    => '_race_live',
        'meta_value'  => '1',
    ) );
    foreach ( $races as $race_id ) {
        if ( rm_race_due_for_archive( (int) $race_id, $deadline ) ) {
            update_post_meta( (int) $race_id, '_race_live', '0' );
        }
    }
}

/**
 * Whether a live race is to be archived: its end before $deadline, and its last upload too.
 *
 * Both, because the end can be wrong. A race an upload creates gets "today, 19:00" as its end, which
 * the organiser is expected to correct; an event running on into a second day would be locked in
 * the middle of it if the end alone counted. Uploads go on while it runs. A race without an end
 * says nothing about when it is over, and stays.
 *
 * @param int    $race_id
 * @param string $deadline Site-local wall clock, 'Y-m-d H:i:s': now, less a day.
 * @return bool
 */
function rm_race_due_for_archive( $race_id, $deadline ) {
    if ( ! rm_race_is_live( $race_id ) ) {
        return false;
    }
    $end    = rm_normalize_event_datetime( get_post_meta( $race_id, '_race_event_end', true ) );
    $upload = (string) get_post_meta( $race_id, '_race_last_upload', true );
    return '' !== $end && $end < $deadline && ( '' === $upload || $upload < $deadline );
}

/**
 * What the one-time archiving of the races archived before has covered: 1 their race log and parts
 * (1.8.1), 2 their push subscriptions as well (1.9.0). A site that ran 1.8.1's runs it again.
 */
const RM_ARCHIVE_SCHEMA = 2;

/**
 * Archive, once, the races that were archived before 1.8.1.
 *
 * A race archived since is archived as it happens (rm_on_race_live_changed()). One archived before
 * still carries its race log, in the database and in its public whole file, one archived under
 * 1.8.0 its parts, and every one its push subscriptions. Decided on 2026-09-12: they are cleared
 * once, on the first admin page after the update. An organiser is at hand then and no visitor is
 * kept waiting; an AJAX request, which runs admin_init too and comes from the live pages, is left
 * alone. A ZIP replace runs no activation hook. Recorded once the run got through every race, so a
 * run cut short goes on the next time; rm_archive_race() does nothing for a race that is done. A
 * race it could not archive is logged, and waits for the next time its flag is stored.
 *
 * @return void
 */
function rm_maybe_clear_archived_races() {
    if ( wp_doing_ajax() || (int) get_option( 'rm_archive_schema', 0 ) >= RM_ARCHIVE_SCHEMA ) {
        return;
    }
    $races = get_posts( array(
        'post_type'   => 'race',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields'      => 'ids',
    ) );
    foreach ( $races as $race_id ) {
        if ( ! rm_race_is_live( $race_id ) ) {
            rm_archive_race( (int) $race_id );
        }
    }
    update_option( 'rm_archive_schema', RM_ARCHIVE_SCHEMA );
    delete_option( 'rm_archived_races_cleared' ); // 1.8.1's record of the same, when it ran
}

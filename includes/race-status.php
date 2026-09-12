<?php
// includes/race-status.php
// A race's two states, live and archived, and what follows from each.
//
// The parts and the race log belong to a race while it is live. An archived race keeps its results,
// whole, and nothing else (since 1.8.1): see rm_archive_race().

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// A race set to archive, however that happens: the meta box, Quick Edit, WP-CLI, or code that
// updates the meta. Not on deleted_post_meta: that fires for every meta of a race being deleted,
// and would write its files again after the attachments took them.
add_action( 'added_post_meta', 'rm_on_race_live_changed', 10, 4 );
add_action( 'updated_post_meta', 'rm_on_race_live_changed', 10, 4 );

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
 * A race's live flag was stored: when it is off, archive the race.
 *
 * WordPress fires added_post_meta and updated_post_meta after it stored the value, so
 * rm_write_files() already reads the race as archived. It compares the old value with the new one
 * strictly, and Quick Edit passes a number where the database holds a string: saving an archived
 * race there fires this every time. rm_archive_race() does nothing when there is nothing to do.
 *
 * @param int    $meta_id    Unused.
 * @param int    $post_id    The post whose meta was stored.
 * @param string $meta_key   Its key.
 * @param mixed  $meta_value Its new value.
 * @return void
 */
function rm_on_race_live_changed( $meta_id, $post_id, $meta_key, $meta_value ) {
    if ( '_race_live' !== $meta_key || '1' === (string) $meta_value || 'race' !== get_post_type( $post_id ) ) {
        return;
    }
    rm_archive_race( (int) $post_id );
}

/**
 * What an archived race keeps: its results, whole. Its parts, its index and its race log go.
 *
 * Decided on 2026-09-12. The parts serve the updates of a live race, and an archived race takes
 * none: rm_update_race() refuses an upload, handle_notification_request() a message. The race log
 * belongs to the event while it runs; "order lunch now", with the link to the order, has no place
 * on a finished race's pages or in its public JSON.
 *
 * The files first and the log after: when the files cannot be written, the log is still there to
 * go the next time. The timestamp moves, since the data changed, and a browser that held the log
 * downloads the whole file once.
 *
 * @param int $race_id
 * @return true|WP_Error WP_Error for a race that is live, a whole file that cannot be read, or files
 *                       that cannot be written; the log is then left as it was.
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
    return true;
}

/**
 * Archive, once, the races that were archived before 1.8.1.
 *
 * A race archived since is archived as it happens (rm_on_race_live_changed()). One archived before
 * still carries its race log, in the database and in its public whole file, and one archived under
 * 1.8.0 its parts. Decided on 2026-09-12: they are cleared once, on the first admin page after the
 * update. An organiser is at hand then and no visitor is kept waiting; an AJAX request, which runs
 * admin_init too and comes from the live pages, is left alone. A ZIP replace runs no activation
 * hook. Recorded once the run got through every race, so a run cut short goes on the next time;
 * rm_archive_race() does nothing for a race that is done. A race it could not archive is logged,
 * and waits for the next time its flag is stored.
 *
 * @return void
 */
function rm_maybe_clear_archived_races() {
    if ( wp_doing_ajax() || get_option( 'rm_archived_races_cleared' ) ) {
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
    update_option( 'rm_archived_races_cleared', current_time( 'mysql' ) );
}

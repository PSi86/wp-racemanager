<?php
/**
 * block-render-race-date.php
 * Renders the race date according to the WP localization settings.
 * As source the current post id (if cpt "race") or alternatively from the URL parameter "race_id".
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Render callback for the Race Date block.
 *
 * @param array  $attributes Block attributes.
 * @param string $content    Block content.
 * @return string            HTML output for the block.
 */
function rm_render_race_date_block( $attributes, $content ) {
    $race_id = null;

    // Check if we're in a race context (singular or in the loop on an archive page).
    if ( get_post_type( get_the_ID() ) === 'race' ) {
        $race_id = get_the_ID();
    }
    // Otherwise, check if a race_id is provided in the URL.
    elseif ( isset( $_GET['race_id'] ) && ! empty( $_GET['race_id'] ) ) {
        $race_id = absint( $_GET['race_id'] );
    }

    // If no valid race_id is found, return empty.
    if ( ! $race_id ) {
        return 'Race date block only works with race posts';
    }

    // Retrieve the race meta values.
    $race_event_start = get_post_meta( $race_id, '_race_event_start', true );
    $race_event_end   = get_post_meta( $race_id, '_race_event_end', true );

    // If one or both meta values are empty, return empty.
    if ( empty( $race_event_start ) || empty( $race_event_end ) ) {
        return 'No date set';
    }

    // Convert the ISO date strings to Unix timestamps.
    $start_timestamp = strtotime( $race_event_start );
    $end_timestamp   = strtotime( $race_event_end );

    if ( ! $start_timestamp || ! $end_timestamp ) {
        return 'Bad date data';
    }

    // Retrieve the local date and time formats from WordPress settings.
    $date_format = get_option( 'date_format' );
    $time_format = get_option( 'time_format' );

    // Determine if the start and end times fall on the same day.
    $start_day = date_i18n( 'Y-m-d', $start_timestamp );
    $end_day   = date_i18n( 'Y-m-d', $end_timestamp );

    if ( $start_day === $end_day ) {
        // Format once for the date, then format the start and end times.
        $formatted_date      = date_i18n( $date_format, $start_timestamp );
        $formatted_startTime = date_i18n( $time_format, $start_timestamp );
        $formatted_endTime   = date_i18n( $time_format, $end_timestamp );
        $output = sprintf(
            '%s @ %s - %s',
            esc_html( $formatted_date ),
            esc_html( $formatted_startTime ),
            esc_html( $formatted_endTime )
        );
    } else {
        // Use the combined date and time for both.
        $combined_format = $date_format . ' @ ' . $time_format;
        $formatted_start = date_i18n( $combined_format, $start_timestamp );
        $formatted_end   = date_i18n( $combined_format, $end_timestamp );
        $output = sprintf(
            '%s - %s',
            esc_html( $formatted_start ),
            esc_html( $formatted_end )
        );
    }

    return $output;
}

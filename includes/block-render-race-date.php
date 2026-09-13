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
    // Otherwise take the race the current live URL points at.
    else {
        $race_id = rm_get_current_race_id();
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

    // One day: the date and the two times; several: date and time at each end. The same words as
    // the registration mail's [_race_dates] (includes/race-dates.php, 1.19.0).
    $output = rm_format_event_dates( $race_event_start, $race_event_end );
    if ( '' === $output ) {
        return 'Bad date data';
    }

    return esc_html( $output );
}

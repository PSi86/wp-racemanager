<?php
/**
 * block-render-race-buttons.php
 * Renders the race button row with registration and results links.
 * As source the current post id (if cpt "race") or alternatively from the URL parameter "race_id".
 */

function rm_render_race_buttons_block( $attributes, $content ) {
    // Ensure we're on a singular Race post.
    if ( ! is_singular( 'race' ) ) {
        return '';
    }

    global $post;
    $race_id = $post->ID;

    // Retrieve the race meta values.
    $reg_closed      = get_post_meta( $race_id, '_race_reg_closed', true );
    $event_end   = get_post_meta( $race_id, '_race_event_end', true );
    $upload_timestamp = get_post_meta( $race_id, '_race_last_upload', true );

    // Convert the race end date to a timestamp.
    $end_timestamp   = strtotime( $event_end );
    // Get the current local time.
    $current_time    = current_time( 'timestamp' );

    // Determine whether to show the "Join now!" button.
    // It is shown only if registration is not closed and the race has not yet ended.
    $show_join = ( ! $reg_closed && $end_timestamp > $current_time );
    
    // Determine whether to show the "Results" button.
    $show_results = ! empty( $upload_timestamp );

    // Begin building the output.
    //$output = '<div class="wp-block-group is-style-default">';
    $output = '<div class="wp-block-buttons is-layout-flex wp-block-buttons-is-layout-flex" style="gap: 12px;">';

    if ( $show_join ) {
        // Build the registration URL with the race_id parameter.
        $join_url = add_query_arg( 'race_id', $race_id, 'https://copterrace.com/register/' );
        $output  .= '<div class="wp-block-button">'; //  is-style-outline is-style-outline--5
        $output  .= '<a class="wp-block-button__link has-contrast-background-color has-background wp-element-button" href="' . esc_url( $join_url ) . '" style="padding-top:var(--wp--preset--spacing--x-small);padding-right:var(--wp--preset--spacing--x-small);padding-bottom:var(--wp--preset--spacing--x-small);padding-left:var(--wp--preset--spacing--x-small);">';
        $output  .= 'Join now!</a></div>';
    }

    if ( $show_results ) {
        // Build the results URL with the race_id parameter.
        $results_url = add_query_arg( 'race_id', $race_id, 'https://copterrace.com/live/bracket/' );
        $output     .= '<div class="wp-block-button">';
        $output     .= '<a class="wp-block-button__link has-contrast-background-color has-background wp-element-button" href="' . esc_url( $results_url ) . '" style="padding: var(--wp--preset--spacing--x-small);">';
        $output     .= 'Results</a></div>';
    }

    $output .= '</div>';

    return $output;
}

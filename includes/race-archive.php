<?php
// includes/race-archive.php
// Set custom ordering for race post type archive based on event start date

function rm_sort_race_archive( $query ) {
    // Only modify the main query on the front end for the race post type archive.
    /* if ( ! is_admin() && $query->is_main_query() && is_post_type_archive( 'race' ) ) {
        // Specify the meta key that holds your event start date.
        $query->set( 'meta_key', '_race_event_start' );
        // Order by the value of that meta key.
        $query->set( 'orderby', 'meta_value' );
        // Ensure the meta value is treated as a date.
        $query->set( 'meta_type', 'DATE' );
        // Choose ascending or descending order (e.g., ASC for earliest first).
        $query->set( 'order', 'DESC' );
    } */
    if ( ! is_admin() && $query->is_main_query() && is_post_type_archive( 'race' ) ) {
        // Default to upcoming events if the parameter is not set.
        $view = isset( $_GET['race_view'] ) ? sanitize_text_field( $_GET['race_view'] ) : 'past';
        
        // Get the current date/time in a format that matches your meta field.
        $today = current_time( 'Y-m-d H:i:s' );
        
        if ( 'past' === $view ) {
            // Past races: where the start date is before today.
            $meta_query = array(
                array(
                    'key'     => '_race_event_start',
                    'value'   => $today,
                    'compare' => '<',
                    'type'    => 'DATETIME'
                )
            );
            $query->set( 'meta_query', $meta_query );
            $query->set( 'meta_key', '_race_event_start' );
            $query->set( 'orderby', 'meta_value' );
            $query->set( 'meta_type', 'DATE' );
            $query->set( 'order', 'DESC' );
        } else {
            // Upcoming races: where the start date is today or later.
            $meta_query = array(
                array(
                    'key'     => '_race_event_end',
                    'value'   => $today,
                    'compare' => '>=',
                    'type'    => 'DATETIME'
                )
            );
            $query->set( 'meta_query', $meta_query );
            $query->set( 'meta_key', '_race_event_end' );
            $query->set( 'orderby', 'meta_value' );
            $query->set( 'meta_type', 'DATE' );
            $query->set( 'order', 'ASC' );
        }
    }
}
add_action( 'pre_get_posts', 'rm_sort_race_archive' );

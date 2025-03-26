<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function rm_render_race_title_block( $attributes ) {
    // Look for the race_id query parameter.
    $race_id = isset( $_GET['race_id'] ) ? intval( $_GET['race_id'] ) : 0;

    if ( $race_id ) {
        $race_post = get_post( $race_id );
        // Optionally, check if the post is of the expected custom post type.
        if ( $race_post && $race_post->post_type === 'race' ) {
            $title = get_the_title( $race_post );
            return '<h1>' . esc_html( $title ) . '</h1>';
        }
    }

    // Fallback output if no valid race is found.
    //return '<h1>No race selected</h1>';
    return '';
}
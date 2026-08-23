<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function rm_render_race_title_block( $attributes ) {
    // The race comes from the URL path (or a legacy ?race_id parameter); see live-routing.php.
    $race_post = rm_get_current_race();

    if ( $race_post ) {
        return '<h1>' . esc_html( get_the_title( $race_post ) ) . '</h1>';
    }

    // Fallback output if no valid race is found.
    //return '<h1>No race selected</h1>';
    return '';
}
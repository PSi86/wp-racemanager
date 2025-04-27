<?php
// includes/shortcodes.php
// Register the shortcode [rm_registered]

if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_shortcode('rm_registered', 'rm_sc_registered_handler');

function rm_sc_registered_handler($atts) {
    // Merge default shortcode attributes
    $atts = shortcode_atts(
        array(
            'race_id' => null,
        ),
        $atts,
        'rm_registered'
    );

    // Define your form ID either via shortcode attributes or directly.
    $race_id = ! empty( $atts['race_id'] ) ? $atts['race_id'] : get_the_ID();

    // check race meta for form_id
    /* $form_id_from_post_id = get_post_meta( $post_id, '_race_registration', true );
    if ( ! $form_id_from_post_id ) {
        return '<p>No form ID found for current page.</p>';
    } */

    $output = '<h3>Registered Pilots</h3>';

    // Retrieve the pilot callsigns.
    $callsigns = rm_get_registered_callsigns( $race_id );

    if ( is_string( $callsigns )) {
        // If the result is a string, return it directly.
        $output .= '<p>' . esc_html( $callsigns ) . '</p>';
    }
    elseif ( is_array( $callsigns ) && !empty( $callsigns ) ) {
        // If the result is an empty array, return a message.
        // Build the output.
        $output .= '<ul>';
        foreach ( $callsigns as $nickname ) {
            $output .= '<li>' . esc_html( $nickname ) . '</li>';
        }
        $output .= '</ul>';
    }
    else {
        // If the result is not an array, return an error message.
        $output .= '<p>Error retrieving registered pilots.</p>';
    }

    return $output;
}

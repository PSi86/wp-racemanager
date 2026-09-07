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

    // Enqueue Swiper assets for animated list of pilots
    /*
    wp_enqueue_style(
        'race-swiper-css',
        plugin_dir_url( __DIR__ ) . 'assets/swiper-11.2.6/swiper-bundle.min.css',
        [],
        '11.2.6'
    );
    wp_enqueue_script(
        'race-swiper-js',
        plugin_dir_url( __DIR__ ) . 'assets/swiper-11.2.6/swiper-bundle.min.js',
        [],
        '11.2.6',
        true
    );
    */
    // Start building the output
    $headline = 'Registered Pilots:';
    $output = '';

    // Retrieve the pilot callsigns.
    $callsigns = rm_get_registered_callsigns( $race_id );

    if ( is_array( $callsigns ) && !empty( $callsigns ) ) {
        // If the result is an empty array, return a message.
        // Build the output.
        $output = '<h3>'. $headline . ' ' . count( $callsigns ) . '</h3>';
        $output .= '<ul>';
        foreach ( $callsigns as $nickname ) {
            $output .= '<li>' . esc_html( $nickname ) . '</li>';
        }
        $output .= '</ul>';
    }
    elseif ( is_string( $callsigns )) {
        // If no results are found, only a message is returned.
        // If the result is a string, return it directly.
        $output = '<h3>'. $headline .'</h3>';
        $output .= '<p>' . esc_html( $callsigns ) . '</p>';
    }
    else {
        // If the result is not an array, return an error message.
        $output .= '<p>Error retrieving registered pilots.</p>';
    }

    return $output;
}

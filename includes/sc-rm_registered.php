<?php
// includes/shortcodes.php
// Register the custom shortcode [rm_viewer]

if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_shortcode('rm_registered', 'rm_sc_registered_handler');

function rm_sc_registered_handler($atts) {
    // Merge default shortcode attributes
    $atts = shortcode_atts(
        array(
            'form_title' => null,
        ),
        $atts,
        'rm_registered'
    );

    // Define your form ID either via shortcode attributes or directly.
    $form_title = ! empty( $atts['form_title'] ) ? $atts['form_title'] : 'latest';

    if ( ! $form_title ) {
        return '<p>No valid post found for [rm_registered].</p>';
    }

    // check race meta for form_id
    /* $form_id_from_post_id = get_post_meta( $post_id, '_race_registration', true );
    if ( ! $form_id_from_post_id ) {
        return '<p>No form ID found for current page.</p>';
    } */

    // Retrieve the pilot nicknames.
    $nicknames = rm_get_registered_callsigns( $form_title );
    
    if ( empty( $nicknames ) ) {
        return 'No pilot nicknames found.';
    }
    
    // Build the output. Customize this as needed.
    $output = '<ul>';
    foreach ( $nicknames as $nickname ) {
        $output .= '<li>' . esc_html( $nickname ) . '</li>';
    }
    $output .= '</ul>';
    
    return $output;
}

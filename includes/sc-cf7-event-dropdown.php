<?php
// includes/sc-cf7-event-dropdown.php
// Implement [race_dropdown] for Contact Form 7 using wpcf7_form_tag 
// 

if (!defined('ABSPATH')) exit; // Exit if accessed directly

function get_upcoming_races() {
    $today = current_time('Y-m-d');
    $args = array(
        'post_type' => 'race',
        'meta_query' => array(
            array(
                'key'     => '_race_event_end',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATE'
            )
        ),
        'posts_per_page' => -1,
    );
    $query = new WP_Query($args);
    $races = array();
    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $races[get_the_ID()] = get_the_title();
        }
        wp_reset_postdata();
    }
    return $races;
}

add_action('wpcf7_init', 'register_race_dropdown_form_tag');

// Register the custom form tag with Contact Form 7
function register_race_dropdown_form_tag() {
    wpcf7_add_form_tag('race_dropdown*', 'race_dropdown_handler', array('do_not_trim' => true));
}

// Handler for the custom form tag [race_dropdown]
function race_dropdown_handler($tag) {
    // Parse attributes: allow a "preselected" attribute.
    $preselected = null;
    
    if (isset($_GET['race_id'])) {
        $preselected = intval($_GET['race_id']);
    }
    
    // Get upcoming races.
    $races = get_upcoming_races();
    
    // If a race ID was provided but isn't available, set an error message.
    $error_message = '';
    if ($preselected && !array_key_exists($preselected, $races)) {
        $error_message = '<p class="error">The link is not valid, please select a race from the list.</p>';
        // Optionally, reset preselected to avoid marking any option as selected.
        $preselected = 0;
    }
    
    // Build the dropdown.
    $html = $error_message;
    $html .= '<select name="race_id">';
    foreach ($races as $id => $title) {
        $selected = ($id == $preselected) ? ' selected="selected"' : '';
        $html .= sprintf('<option value="%d"%s>%s</option>', $id, $selected, esc_html($title));
    }
    $html .= '</select>';
    
    return $html;
}

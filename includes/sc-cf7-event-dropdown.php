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
function register_race_dropdown_form_tag() {
    wpcf7_add_form_tag('race_dropdown*', 'race_dropdown_handler', array('do_not_trim' => true));
}

function race_dropdown_handler($tag) {
    $races = get_upcoming_races();
    $html = '<select name="race">';
    foreach ($races as $id => $title) {
        $html .= sprintf('<option value="%d">%s</option>', $id, esc_html($title));
    }
    $html .= '</select>';
    return $html;
}

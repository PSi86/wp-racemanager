<?php
function render_race_menu_item_block($attributes) {
    $post_offset = isset($attributes['postOffset']) ? $attributes['postOffset'] : 'latest';
    $offset = (int) str_replace('latest+', '', $post_offset);

    $args = array(
        'post_type' => 'race',
        'posts_per_page' => 1,
        'offset' => $offset,
        'orderby' => 'date',
        'order' => 'DESC'
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        $query->the_post();
        $output = '<a href="' . esc_url(get_permalink()) . '">' . esc_html(get_the_title()) . '</a>';
    } else {
        $output = '<p>' . __('No race found', 'wp-racemanager') . '</p>';
    }

    wp_reset_postdata();

    return $output;
}

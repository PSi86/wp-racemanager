<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function rm_render_race_select_block( $attributes, $content ) {
    $posts_per_page = isset( $attributes['postsPerPage'] ) ? intval( $attributes['postsPerPage'] ) : 10;
    $prev_text      = isset( $attributes['prevText'] ) ? $attributes['prevText'] : __( 'Previous', 'wp-racemanager' );
    $next_text      = isset( $attributes['nextText'] ) ? $attributes['nextText'] : __( 'Next', 'wp-racemanager' );
    $paged          = max( 1, get_query_var( 'paged', 1 ) );
    
    $args = array(
        'post_type'      => 'race',
        'posts_per_page' => $posts_per_page,
        'paged'          => $paged,
    );
    $query = new WP_Query( $args );
    
    if ( ! $query->have_posts() ) {
        return '<p>' . esc_html__( 'No races found.', 'wp-racemanager' ) . '</p>';
    }
    
    $output = '<ul class="race-select-list">';
    while ( $query->have_posts() ) {
        $query->the_post();
        $race_id = get_the_ID();
        // Build a custom URL with a "race_id" parameter. Adjust the target page as needed.
        $custom_link = site_url( '/live/bracket/?race_id=' . $race_id );
        $output .= '<li><a href="' . esc_url( $custom_link ) . '">' . get_the_title() . '</a></li>';
    }
    $output .= '</ul>';
    
    // Pagination generation.
    $big = 999999999;
    $pagination_links = paginate_links( array(
        'base'      => str_replace( $big, '%#%', esc_url( get_pagenum_link( $big ) ) ),
        'format'    => '?paged=%#%',
        'current'   => $paged,
        'total'     => $query->max_num_pages,
        'prev_text' => $prev_text,
        'next_text' => $next_text,
        'type'      => 'array',
    ) );
    
    if ( is_array( $pagination_links ) ) {
        $output .= '<nav class="race-pagination"><ul class="pagination">';
        foreach ( $pagination_links as $link ) {
            $output .= '<li>' . $link . '</li>';
        }
        $output .= '</ul>';
        $output .= '<div class="page-indicator">' . sprintf( __( 'Page %1$d of %2$d', 'wp-racemanager' ), $paged, $query->max_num_pages ) . '</div>';
        $output .= '</nav>';
    }
    
    wp_reset_postdata();
    return $output;
}

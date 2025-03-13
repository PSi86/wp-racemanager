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
        'meta_query'     => [
            [
                'key'     => '_race_last_upload',
                'compare' => 'EXISTS',
            ],
        ],
    );
    $query = new WP_Query( $args );
    
    if ( ! $query->have_posts() ) {
        return '<p>' . esc_html__( 'No races found.', 'wp-racemanager' ) . '</p>';
    }
    
    // Use current_time('timestamp') to get the site's local timestamp.
    $current_timestamp = current_time( 'timestamp' );

    $output = '<ul class="race-select-list">';
    while ( $query->have_posts() ) {
        $query->the_post();
        $race_id = get_the_ID();
        
        //$race_live = get_post_meta( $race_id, '_race_live', true );
        
        $last_upload = get_post_meta( $race_id, '_race_last_upload', true );
        // Convert the MySQL timestamp to a Unix timestamp
        $upload_timestamp = strtotime( $last_upload );
        
        // Build a custom URL with a "race_id" parameter. Adjust the target page as needed.
        $custom_link = site_url( '/live/bracket/?race_id=' . $race_id );
        $output .= '<li><a href="' . esc_url( $custom_link ) . '">';
        
        // TODO: use css stylesheet instead of inline style
        //$output .=  $race_live ? '<span style="color: red;">Live: </span>' : '';
        // If the last upload timestamp is less than two hours old, add the "Live:" prefix.
        if ( $upload_timestamp && ( $current_timestamp - $upload_timestamp ) < ( 2 * HOUR_IN_SECONDS ) ) {
            $output .= '<span style="color: red;">Live: </span>';
        }
        $output .=  get_the_title() . '</a></li>';
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
        'end_size'  => 0,
        'mid_size'  => 0,
    ) );
    
    $prev_link = '';
    $next_link = '';
    if ( is_array( $pagination_links ) ) {
        // Loop through the array to find the link that contains the previous text and the one with the next text.
        foreach ( $pagination_links as $link ) {
            if ( strpos( $link, $prev_text ) !== false ) {
                $prev_link = $link;
            } elseif ( strpos( $link, $next_text ) !== false ) {
                $next_link = $link;
            }
        }
    }
    
    if ( $prev_link || $next_link ) {
        //$output .= '<nav class="race-pagination" aria-label="Pagination">';
        $output .= '<nav class="wp-block-query-pagination is-content-justification-space-between is-layout-flex wp-container-core-query-pagination-is-layout-1 wp-block-query-pagination-is-layout-flex" aria-label="Seitennummerierung">';
        if ( $prev_link ) {
            $output .= $prev_link;
        }
        $output .= '<div class="page-indicator">' . sprintf( __( 'Page %1$d of %2$d', 'wp-racemanager' ), $paged, $query->max_num_pages ) . '</div>';
        if ( $next_link ) {
            $output .= $next_link;
        }
        $output .= '</nav>';
    }
    
    wp_reset_postdata();
    return $output;
}

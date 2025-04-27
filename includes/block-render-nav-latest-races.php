<?php
/**
 * Render callback for the Latest Races Submenu block.
 *
 * This outputs only a nested <ul> with submenu items.
 *
 * @param array  $attributes Block attributes.
 * @param string $content    Unused content.
 * @return string HTML markup for the submenu.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

function rm_render_nav_latest_races_block( $attributes, $content ) {
    // Query the five latest published race posts.
    $rm_last_races_count = get_option('rm_last_races_count', 5);
    // Get the current date/time in a format that matches your meta field.
    $today = current_time( 'Y-m-d H:i:s' );
    
    $args  = array(
        'post_type'      => 'race',
        'posts_per_page' => $rm_last_races_count,
        'post_status'    => 'publish',
        'meta_query'     => array(
            array(
                'key'     => '_race_event_end',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATETIME'
            )
        ),
        'meta_key'        => '_race_event_end',
        'orderby'         => 'meta_value',
        'meta_type'       => 'DATE',
        'order'           => 'ASC',
    );
    $races = get_posts( $args );

    // Build the submenu markup.
    //$output = '<ul class="sub-menu wp-racemanager-latest-races-submenu">'; // li class "current-menu-item" removed
    $output = '';
    if ( ! empty( $races ) ) {
        foreach ( $races as $race ) {
            $output .= sprintf(
                '<li style="white-space: nowrap;" class=" wp-block-navigation-item wp-block-navigation-link">
                    <a class="wp-block-navigation-item__content" href="%s" aria-current="page">
                        <span class="wp-block-navigation-item__label">%s</span>
                    </a>
                </li>',
                esc_url( get_permalink( $race->ID ) ),
                esc_html( get_the_title( $race->ID ) )
            );
        }
    } else {
        $output .= '<li class="wp-block-navigation-link">' . esc_html__( 'No races found', 'wp-racemanager' ) . '</li>';
    }
    // Append the Archive link.
    $archive_link = get_post_type_archive_link( 'race' );
    if ( $archive_link ) {
        $output .= sprintf(
            '<li style="white-space: nowrap;" class=" wp-block-navigation-item wp-block-navigation-link">
                    <a class="wp-block-navigation-item__content" href="%s" aria-current="page">
                        <span class="wp-block-navigation-item__label">%s</span>
                    </a>
                </li>',
            esc_url( $archive_link ),
            esc_html__( 'Archive', 'wp-racemanager' )
        );
    }
    //$output .= '</ul>';

    return $output;
}

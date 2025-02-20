<?php
/**
 * Register the Latest Races Submenu block for use inside a navigation link.
 */
function wp_racemanager_register_nav_latest_races_submenu_block() {
    // Register the editor script.
    wp_register_script(
        'wp-racemanager-nav-submenu-editor',
        plugin_dir_url( __DIR__ ) . 'js/nav-latest-races-submenu.js',
        array( 'wp-blocks', 'wp-element', 'wp-editor' ),
        '1.0.1'
    );

    register_block_type( 'wp-racemanager/nav-latest-races-submenu', array(
        'editor_script'   => 'wp-racemanager-nav-submenu-editor',
        'render_callback' => 'wp_racemanager_render_nav_latest_races_submenu',
        'supports'        => array(
            'align'           => false,
            'anchor'          => true,
            'customClassName' => true,
        ),
        // Restrict this block so it can only be added as a child of a Navigation Link.
        'parent'          => array( 'core/navigation-submenu' ),
    ) );
}
add_action( 'init', 'wp_racemanager_register_nav_latest_races_submenu_block' );

/**
 * Render callback for the Latest Races Submenu block.
 *
 * This outputs only a nested <ul> with submenu items.
 *
 * @param array  $attributes Block attributes.
 * @param string $content    Unused content.
 * @return string HTML markup for the submenu.
 */
function wp_racemanager_render_nav_latest_races_submenu( $attributes, $content ) {
    // Query the five latest published race posts.
    $rm_last_races_count = get_option('rm_last_races_count', 5);
    $args  = array(
        'post_type'      => 'race',
        'posts_per_page' => $rm_last_races_count,
        'post_status'    => 'publish',
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

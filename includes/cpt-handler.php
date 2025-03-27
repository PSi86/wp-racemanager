<?php
// includes/cpt-handler.php
// Register the custom post type "race" and its meta fields: _race_last_upload, _race_live

if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_action( 'init', 'rm_register_cpt' );

function rm_register_cpt() {
    $labels = array(
        'name'          => __('Races', 'wp-racemanager'),
        'singular_name' => __('Race', 'wp-racemanager'),
    );

    // Define a block template for new "Race" posts.
    // 1) A core/paragraph block for the race description,
    // 2) A core/shortcode block containing [my_json_viewer]
    $race_template = array(
        array( 'core/paragraph', array(
            'placeholder' => __('Enter race description here...', 'wp-racemanager'),
        ) ),
        array( 'core/shortcode', array(
            'text' => '[rm_viewer]',
        ) ),
    );

    $args = array(
        'labels'         => $labels,
        'public'         => true,
        'has_archive'    => true,
        'rewrite'        => array( 'slug' => 'races' ),
        'supports'       => array( 'title', 'editor' ),
        //'supports'       => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'custom-fields' ),
        'show_in_rest'   => true,
        'show_in_nav_menus' => true,
        'template'       => $race_template,
        // 'template_lock' => 'all' / 'insert' / 'contentOnly' / false,    // locks blocks completely (no move/add)
        'template_lock'  => false, // can reorder, edit and add blocks, can't remove existing blocks
        'show_in_menu'   => true,

        'menu_position'  => 5,
        'menu_icon'      => 'dashicons-airplane', // Choose an appropriate icon
        'capability_type'=> 'post', // check web for more info, maybe 
    );

    register_post_type( 'race', $args );

    // Post-Meta: event start date
    register_post_meta(
        'race',
        '_race_event_start',
        array(
            'show_in_rest' => false,
            'single'       => true,
            'type'         => 'datetime', // or 'datetime'
            'auth_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        )
    );    
    
    // Post-Meta: event end date
    register_post_meta(
        'race',
        '_race_event_end',
        array(
            'show_in_rest' => false,
            'single'       => true,
            'type'         => 'datetime', // or 'datetime'
            'auth_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        )
    );    
    
    // Post-Meta for the last update time
    register_post_meta(
        'race',
        '_race_last_upload',
        array(
            'show_in_rest' => false,
            'single'       => true,
            'type'         => 'datetime', // or 'datetime'string
            'auth_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        )
    );

    // Post-Meta for the race status
    // 1=live       enable client-side auto update of data (file based)
    // 0=locked     serve a static page with the data (file based for now)
    register_post_meta(
        'race',
        '_race_live',
        array(
            'show_in_rest' => false,
            'single'       => true,
            'type'         => 'boolean', // 'string', 'boolean', 'integer', 'number', 'array', 'object'
            'auth_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        )
    );
}


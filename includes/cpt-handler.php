<?php
// includes/cpt-handler.php
// Register the custom post type "race" and its meta fields: _race_last_upload, _race_live

if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_action( 'init', 'rm_register_cpt' );

function rm_register_cpt() {
    $labels = array(
        'name'          => __('Races', 'wp-racemanager'),
        'singular_name' => __('Race', 'wp-racemanager'),
        'menu_name'             => _x( 'Races', 'Admin Menu text', 'wp-racemanager' ),
        'name_admin_bar'        => _x( 'Race', 'Add New on Toolbar', 'wp-racemanager' ),
        'add_new'               => __( 'Add New', 'wp-racemanager' ),
        'add_new_item'          => __( 'Add New Race', 'wp-racemanager' ),
        'new_item'              => __( 'New Race', 'wp-racemanager' ),
        'edit_item'             => __( 'Edit Race', 'wp-racemanager' ),
        'view_item'             => __( 'View Race', 'wp-racemanager' ),
        'all_items'             => __( 'All Races', 'wp-racemanager' ),
        'search_items'          => __( 'Search Races', 'wp-racemanager' ),
        'parent_item_colon'     => __( 'Parent Races:', 'wp-racemanager' ),
        'not_found'             => __( 'No races found.', 'wp-racemanager' ),
        'not_found_in_trash'    => __( 'No races found in Trash.', 'wp-racemanager' ),
    );

    $race_template = array(
        // Outer Group block (Link Row)
        array(
            'core/group',
            array(
                'metadata' => array(
                    'name' => 'Link Row',
                ),
                'layout'   => array(
                    'type'           => 'flex',
                    'flexWrap'       => 'wrap',
                    'justifyContent' => 'space-between',
                ),
            ),
            array(
                // Custom Race Buttons block (self-closing)
                array(
                    'wp-racemanager/race-buttons',
                    array(),
                    array()
                ),
                // Social Links block YouTube Instagram, Discord links
                array(
                    'core/social-links',
                    array(
                        'iconColor'                => 'base',
                        'iconColorValue'           => '#ffffff',
                        'iconBackgroundColor'      => 'contrast',
                        'iconBackgroundColorValue' => '#000000',
                        'openInNewTab'             => true,
                        'metadata'                 => array( 'name' => 'Social Links' ),
                        'className'                => 'is-style-default',
                        'layout'                   => array(
                            'type'           => 'flex',
                            'justifyContent' => 'right',
                            'orientation'    => 'horizontal',
                        ),
                    ),
                    array(
                        array(
                            'core/social-link',
                            array(
                                'url'     => 'https://www.youtube.com/channel/0000000',
                                'service' => 'youtube',
                            ),
                            array()
                        ),
                        array(
                            'core/social-link',
                            array(
                                'url'     => 'https://www.instagram.com/00000/',
                                'service' => 'instagram',
                            ),
                            array()
                        ),
                        array(
                            'core/social-link',
                            array(
                                'url'     => 'https://discord.gg/0000',
                                'service' => 'discord',
                            ),
                            array()
                        ),
                    )
                ),  
            )
        ),
        // Columns block containing a description column and a featured image column
        array(
            'core/columns',
            array(
                'className' => 'is-style-columns-reverse',
                'style'     => array(
                    'spacing' => array(
                        'margin' => array(
                            'top'    => 'var:preset|spacing|x-small',
                            'bottom' => 'var:preset|spacing|x-small',
                        ),
                    ),
                ),
            ),
            array(
                // First Column: Race description paragraph
                array(
                    'core/column',
                    array(
                        'width'  => '66.66%',
                        'layout' => array( 'type' => 'default' ),
                    ),
                    array(
                        array(
                            'core/paragraph',
                            array(
                                'align'       => 'left',
                                'placeholder' => 'Enter race description here...',
                                'style'       => array(
                                    'layout' => array(
                                        'selfStretch' => 'fit',
                                        'flexSize'    => null,
                                    ),
                                ),
                                // Use the 'content' attribute for text content.
                                'content'     => 'Whoop Class: ducted, 1s, max 40mm Props <br>Tournament Mode: FAI Double Elimination <br>Maximum Pilots: 32 <br>Starter Fee: 20€'
                            ),
                            array()
                        ),
                    )
                ),
                // Second Column: Featured image block (self-closing)
                array(
                    'core/column',
                    array(
                        'width'  => '33.33%',
                        'layout' => array( 'type' => 'default' ),
                    ),
                    array(
                        array(
                            'core/post-featured-image',
                            array(
                                'width'  => '',
                                'height' => '',
                                'scale'  => 'contain',
                            ),
                            array()
                        ),
                    )
                ),
            )
        ),
        // Details block containing a schedule and a GMap block
        array(
            'core/details',
            array('summary' => '<strong>Details: </strong>'),
            array(
                // Paragraph inside Details block (schedule)
                array(
                    'core/paragraph',
                    array(
                        'placeholder' => 'Timetable, Location, Food, Rules, etc.',
                        'content'     => '08:30 Doors open <br>09:00 Training <br>10:00 Qualification <br>13:00 Lunch <br>17:00 Finals <br>18:00 End'
                    ),
                    array()
                ),
                // GMap block displaying location
                array(
                    'gmap/gmap-block',
                    array(
                        'address'    => 'Korntal-Münchingen',
                        'zoom'       => 11,
                        'uniqueId'   => 'gmap-block-gaoc5rx2',
                        'blockStyle' => "\n        \n        \n    \n        @media (max-width: 1024px) and (min-width: 768px) {\n            \n         \n    \n        }\n        @media (max-width: 767px) {\n            \n         \n    \n        }\n    ",
                    ),
                    array()
                ),
            )
        ),
        // Shortcode block for Gallery
        array(
            'core/shortcode',
            array(
                'metadata' => array( 'name' => 'Gallery' ),
                'text'  => '[rm_gallery]'
            ),
            array()
        ),
        // Shortcode block for Registered Pilots
        array(
            'core/shortcode',
            array(
                'metadata' => array( 'name' => 'Registered Pilots' ),
                'text'  => '[rm_registered]'
            ),
            array()
        ),
    );

    $args = array(
        'labels'         => $labels,
        'public'         => true,
        'has_archive'    => true,
        'rewrite'        => array( 'slug' => 'races' ),
        'supports'       => array( 'title', 'editor', 'thumbnail', 'revisions', 'author' ), // 'custom-fields'
        'description'    => 'Post Type Race bundles all information about a race in one place.',
        //'supports'       => array( 'title', 'editor', 'thumbnail', 'excerpt', 'comments', 'custom-fields' ),
        'show_in_rest'   => true,
        'show_in_nav_menus' => true,
        'show_in_menu'   => true,
        'template'       => $race_template,
        // 'template_lock' => 'all' / 'insert' / 'contentOnly' / false,    // locks blocks completely (no move/add)
        'template_lock'  => false, // can reorder, edit and add blocks, can't remove existing blocks
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

    // Post-Meta for the race registration lock status
    // 1=closed     registration closed (no new pilots can register)
    // 0=open       registration open (new pilots can register)
    register_post_meta(
        'race',
        '_race_reg_closed',
        array(
            'show_in_rest' => false,
            'single'       => true,
            'type'         => 'boolean', // 'string', 'boolean', 'integer', 'number', 'array', 'object'
            'auth_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        )
    );

    // Post-Meta for the race notificaton log
    // An array of arrays with the following keys: msg_title, msg_body, msg_icon, msg_url, msg_time
    // Only notifications sent by the race manager are stored here, not the individual nextup notifications.
    register_post_meta(
        'race',
        '_race_notification_log',
        array(
            'show_in_rest' => false,
            'single'       => false,
            'type'         => 'array', // 'string', 'boolean', 'integer', 'number', 'array', 'object'
            'auth_callback' => function() {
                return current_user_can( 'edit_posts' );
            },
        )
    );
}


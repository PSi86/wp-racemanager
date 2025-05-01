<?php
// includes/settings-handler.php
// Register the plugin settings and output the settings page.
// This file is included from the main plugin file (wp-racemanager.php).

// TODO: Add documentation to the settings page: rm-live-page-link custom class for live page navigation element

add_action('admin_menu', function () {
    add_options_page(
        'RaceManager Settings',            // Page title in the browser tab
        'RaceManager',                      // Menu title in the "Settings" menu
        'manage_options',                   // Capability required to see this page
        'rm',                               // Unique menu slug
        'rm_settings_page'                  // Callback function that outputs the settings page content
    );    
});

function rm_settings_page() {
    ?>
    <div class="wrap">
        <h1>RaceManager Settings</h1>
        <form method="post" action="options.php">
            <?php
            // Output the hidden fields, nonce, etc. for our "rm_options_group"
            settings_fields('rm_options_group');
            // Output all registered sections (WP Environment, SEO)
            do_settings_sections('rm');
            // Standard WP submit button
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function () {
    // Register existing settings
    register_setting('rm_options_group', 'rm_live_page_id', 'rm_settings_sanitize_live_page'); // integer via sanitize callback
    register_setting('rm_options_group', 'rm_last_races_count', ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 5]);
    register_setting('rm_options_group', 'rm_callsign_field', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'pilot_callsign']);

    // Register SEO settings as a single array
    register_setting(
        'rm_options_group',
        'rm_seo',
        [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => 'rm_settings_sanitize_seo',
        ]
    );

    // Settings sections
    add_settings_section('rm_wp_section', 'WordPress Environment Settings', null, 'rm');
    add_settings_section('rm_seo_section', 'SEO Settings', 'rm_settings_seo_section_cb', 'rm');

    // WP Environment section fields
    add_settings_field(
        'live_page_id_field',
        'Live Pages Main Page',
        'rm_settings_live_page_input',
        'rm',
        'rm_wp_section'
    );
    add_settings_field(
        'last_races_count_field',
        'Number of Last Races in Menu',
        function () {
            $value = get_option('rm_last_races_count', 5);
            echo "<input type='number' min='1' name='rm_last_races_count' value='" . esc_attr($value) . "' class='small-text'>";
            echo "<p class='description'>How many recent races should appear in the submenu?</p>";
        },
        'rm',
        'rm_wp_section'
    );
    add_settings_field(
        'callsign_field',
        'Pilot Nickname / Callsign Field Name',
        function () {
            $value = get_option('rm_callsign_field', 'pilot_callsign');
            echo "<input type='text' name='rm_callsign_field' value='" . esc_attr($value) . "' class='regular-text'>";
            echo "<p class='description'>Name of the Registration Form Field for the Pilot's Callsign. Default: pilot_callsign</p>";
        },
        'rm',
        'rm_wp_section'
    );

    // SEO Settings section fields
    add_settings_field(
        'seo_default_title_field',
        'Default Meta Title',
        'rm_seo_field_cb',
        'rm',
        'rm_seo_section',
        [
            'label_for'   => 'seo_default_title',
            'type'        => 'text',
            'option_key'  => 'default_title',
            'placeholder' => get_bloginfo('name'),
        ]
    );
    add_settings_field(
        'seo_default_description_field',
        'Default Meta Description',
        'rm_seo_field_cb',
        'rm',
        'rm_seo_section',
        [
            'label_for'   => 'seo_default_description',
            'type'        => 'textarea',
            'option_key'  => 'default_description',
            'placeholder' => get_bloginfo('description'),
        ]
    );
    add_settings_field(
        'seo_default_keywords_field',
        'Default Meta Keywords',
        'rm_seo_field_cb',
        'rm',
        'rm_seo_section',
        [
            'label_for'   => 'seo_default_keywords',
            'type'        => 'text',
            'option_key'  => 'default_keywords',
            'placeholder' => 'keyword1, keyword2, keyword3',
        ]
    );
});

// Section description callback for SEO
function rm_settings_seo_section_cb() {
    echo '<p>Global defaults for your site’s meta tags. These values will be used when no per-post override is provided.</p>';
}

// Live Page Title input field callback
function rm_settings_live_page_input() {
    $stored_page_id = get_option('rm_live_page_id');
    $page_title = '';
    if ( $stored_page_id ) {
        $page = get_post($stored_page_id);
        if ( $page ) {
            $page_title = $page->post_title;
        }
    }
    echo "<input type='text' name='rm_live_page_id' value='" . esc_attr($page_title) . "' class='regular-text' size='50'>";
    echo "<p class='description'>Entry Page Title to the Live Pages. On page and its child-pages the PWA installation and notification subscriptions are supported. Default: Live Races</p>";
}

// Sanitize callback: convert the input title to a page ID.
function rm_settings_sanitize_live_page( $input ) {
    $query = new WP_Query([
        'post_type'              => 'page',
        'title'                  => $input,
        'post_status'            => 'publish',
        'posts_per_page'         => 1,
        'no_found_rows'          => true,
        'ignore_sticky_posts'    => false,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
        'orderby'                => 'post_date ID',
        'order'                  => 'ASC',
    ]);
    if ( ! empty( $query->post ) ) {
        return $query->post->ID;
    }
    add_settings_error(
        'rm_live_page_id',
        'rm_live_page_id_error',
        'Page with the title "' . esc_html($input) . '" not found. Please enter a valid page title.',
        'error'
    );
    return get_option('rm_live_page_id');
}

// Generic field renderer for SEO settings
function rm_seo_field_cb( array $args ) {
    $opts = get_option('rm_seo', []);
    $key  = $args['option_key'];
    $val  = isset($opts[$key]) ? $opts[$key] : '';
    if ( $args['type'] === 'textarea' ) {
        printf(
            '<textarea id="%1$s" name="rm_seo[%2$s]" rows="3" cols="50" placeholder="%4$s">%3$s</textarea>',
            esc_attr($args['label_for']),
            esc_attr($key),
            esc_textarea($val),
            esc_attr($args['placeholder'])
        );
    } else {
        printf(
            '<input type="text" id="%1$s" name="rm_seo[%2$s]" value="%3$s" placeholder="%4$s" class="regular-text"/>',
            esc_attr($args['label_for']),
            esc_attr($key),
            esc_attr($val),
            esc_attr($args['placeholder'])
        );
    }
}

// Sanitize callback for SEO settings
function rm_settings_sanitize_seo( array $input ) {
    return [
        'default_title'       => sanitize_text_field( $input['default_title'] ?? '' ),
        'default_description' => sanitize_textarea_field( $input['default_description'] ?? '' ),
        'default_keywords'    => sanitize_text_field( $input['default_keywords'] ?? '' ),
    ];
}

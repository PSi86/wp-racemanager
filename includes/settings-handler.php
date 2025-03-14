<?php
// includes/settings-handler.php
// Register the plugin settings and output the settings page.
// This file is included from the main plugin file (wp-racemanager.php).

// TODO: Add documentation to the settings page: rm-live-page-link custom class for live page navigation element

add_action('admin_menu', function () {
    add_options_page(
        'RaceManager Settings',            // Page title in the browser tab
        'RaceManager',  // Menu title in the "Settings" menu
        'manage_options',         // Capability required to see this page
        'rm',                     // Unique menu slug
        'rm_settings_page'        // Callback function that outputs the settings page content
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
            // Output all registered sections (in this case, 'rm_main_section')
            do_settings_sections('rm');
            // Standard WP submit button
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

add_action('admin_init', function () {
    register_setting('rm_options_group', 'rm_api_key');         // a string
    register_setting('rm_options_group', 'rm_live_page_id', 'rm_settings_sanitize_live_page'); // an integer
    register_setting('rm_options_group', 'rm_last_races_count'); // an integer
    register_setting('rm_options_group', 'rm_callsign_field');    // a string
    //register_setting('rm_options_group', 'rm_main_menu_id');     // not needed for gutenberg block implementation (was needed for classic menu)
    //register_setting('rm_options_group', 'rm_parent_item_id');   // not needed for gutenberg block implementation (was needed for classic menu)

    // Add a settings section (no title or description here)
    add_settings_section('rm_interface_section', 'RotorHazard Interface Settings', null, 'rm');
    add_settings_section('rm_wp_section', 'Wordpress Environment Settings', null, 'rm');

    // API Key field
    add_settings_field(
        'api_key',
        'API Key',
        function () {
            $value = get_option('rm_api_key', '');
            echo "<input type='text' name='rm_api_key' value='" . esc_attr($value) . "' class='regular-text'>";
            echo "<p class='description'>Used to authenticate REST-API calls: get-pilots, upload</p>";
        },
        'rm',
        'rm_interface_section'
    );
    // Live Pages Path field
    add_settings_field(
        'live_page_id_field',
        'Live Pages Main Page',
        'rm_settings_live_page_input',
        'rm',
        'rm_wp_section'
    );
    // Field #1: Number of last races shown in menu
    add_settings_field(
        'last_races_count_field',
        'Number of Last Races in Menu',
        function () {
            // Default to 5 if not set
            $value = get_option('rm_last_races_count', 5);
            echo "<input type='number' min='1' name='rm_last_races_count' value='" . esc_attr($value) . "' class='small-text'>";
            echo "<p class='description'>How many recent races should appear in the submenu?</p>";
        },
        'rm',
        'rm_wp_section'
    );

    // Field #2: Main Menu ID
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

/*  // Field #2: Main Menu ID
    add_settings_field(
        'main_menu_id_field',
        'Main Menu ID',
        function () {
            $value = get_option('rm_main_menu_id', '');
            echo "<input type='number' min='0' name='rm_main_menu_id' value='" . esc_attr($value) . "' class='small-text'>";
            echo "<p class='description'>ID of the main WordPress menu to update (e.g., 2). If empty, the primary menu will be used.</p>";
        },
        'rm',
        'rm_wp_section'
    );

    // Field #3: Parent Menu Item ID
    add_settings_field(
        'parent_item_id_field',
        'Submenu Parent Item ID',
        function () {
            $value = get_option('rm_parent_item_id', '');
            echo "<input type='number' min='0' name='rm_parent_item_id' value='" . esc_attr($value) . "' class='small-text'>";
            echo "<p class='description'>ID of the parent menu item under which races should appear. If empty, the element called \"Races\" will be used (case-insensitive).</p>";
        },
        'rm',
        'rm_wp_section'
    ); */
});

// Display the Live Page Title input field.
function rm_settings_live_page_input() {
    // Retrieve the stored page ID.
    $stored_page_id = get_option('rm_live_page_id');
    $page_title = '';
    if ( $stored_page_id ) {
        $page = get_post($stored_page_id);
        if ( $page ) {
            $page_title = $page->post_title;
        }
    }
    //echo '<input type="text" id="live_races_page_field" name="live_races_page_id" value="' . esc_attr($page_title) . '" size="50" />';
    echo "<input type='text' name='rm_live_page_id' value='" . esc_attr($page_title) . "' class='regular-text' size='50'>";
    echo "<p class='description'>Entry Page Title to the Live Pages. On page and its child-pages the PWA installation and notification subscriptions are supported. Default: Live Races</p>";
}

// Sanitize callback: convert the input title to a page ID.
function rm_settings_sanitize_live_page( $input ) {
    // Attempt to find the page by title.
    $page = get_page_by_title( $input, OBJECT, 'page' );
    if ( $page ) {
        return $page->ID;
    }
    // If no page is found, add an error and return the previous value.
    add_settings_error(
        'rm_live_page_id',
        'rm_live_page_id_error',
        'Page with the title "' . esc_html( $input ) . '" not found. Please enter a valid page title.',
        'error'
    );
    return get_option('rm_live_page_id');
}

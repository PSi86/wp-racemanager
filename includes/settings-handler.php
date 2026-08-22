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
            // Output all registered sections (WP Environment, SEO, Push Notifications)
            do_settings_sections('rm');
            // Standard WP submit button
            submit_button();
            ?>
        </form>
        <?php
        // Rendered as a sibling form, not nested inside the options.php form above.
        rm_settings_render_vapid_generator();
        ?>
    </div>
    <?php
}

/**
 * Render the key generation form.
 *
 * Kept separate from the options form because generating keys is an action rather
 * than a setting, and because replacing an existing pair is destructive.
 */
function rm_settings_render_vapid_generator() {
    if ( 'constant' === rm_vapid_source() ) {
        return; // Nothing to generate: wp-config.php is in charge.
    }

    $has_keys          = rm_vapid_is_configured();
    $has_subscriptions = rm_has_push_subscriptions();
    ?>
    <hr>
    <h2><?php esc_html_e( 'VAPID Key Pair', 'wp-racemanager' ); ?></h2>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="rm_generate_vapid">
        <?php wp_nonce_field( 'rm_generate_vapid' ); ?>

        <?php if ( ! $has_keys ) : ?>
            <p><?php esc_html_e( 'Generate the key pair used to sign push messages. This replaces the manual keygen.php step.', 'wp-racemanager' ); ?></p>
            <?php submit_button( __( 'Generate key pair', 'wp-racemanager' ), 'secondary', 'submit', false ); ?>
        <?php else : ?>
            <div class="notice notice-warning inline" style="margin:0 0 1em;">
                <p>
                    <strong><?php esc_html_e( 'Replacing the key pair invalidates every existing push subscription.', 'wp-racemanager' ); ?></strong>
                    <?php esc_html_e( 'The public key is stored inside each browser subscription, so all subscribers would have to subscribe again.', 'wp-racemanager' ); ?>
                </p>
            </div>
            <p>
                <label>
                    <input type="checkbox" name="rm_vapid_confirm" value="1" required>
                    <?php esc_html_e( 'I understand that all existing subscriptions will stop working.', 'wp-racemanager' ); ?>
                </label>
            </p>
            <?php if ( $has_subscriptions ) : ?>
                <p>
                    <label>
                        <input type="checkbox" name="rm_vapid_purge" value="1" checked>
                        <?php esc_html_e( 'Also delete the stored subscriptions, which are dead afterwards.', 'wp-racemanager' ); ?>
                    </label>
                </p>
            <?php endif; ?>
            <?php submit_button( __( 'Generate new key pair', 'wp-racemanager' ), 'delete', 'submit', false ); ?>
        <?php endif; ?>
    </form>
    <?php
}

/**
 * Handle the key generation request.
 */
function rm_handle_generate_vapid() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to manage these settings.', 'wp-racemanager' ), '', array( 'response' => 403 ) );
    }
    check_admin_referer( 'rm_generate_vapid' );

    $redirect = admin_url( 'options-general.php?page=rm' );

    if ( 'constant' === rm_vapid_source() ) {
        wp_safe_redirect( add_query_arg( 'rm_vapid', 'constant', $redirect ) );
        exit;
    }

    // Replacing an existing pair is destructive and needs the confirmation checkbox.
    if ( rm_vapid_is_configured() && empty( $_POST['rm_vapid_confirm'] ) ) {
        wp_safe_redirect( add_query_arg( 'rm_vapid', 'unconfirmed', $redirect ) );
        exit;
    }

    $keys = rm_generate_vapid_keys();
    if ( is_wp_error( $keys ) ) {
        wp_safe_redirect( add_query_arg( 'rm_vapid', 'failed', $redirect ) );
        exit;
    }

    if ( ! rm_store_vapid_keys( $keys['publicKey'], $keys['privateKey'] ) ) {
        wp_safe_redirect( add_query_arg( 'rm_vapid', 'failed', $redirect ) );
        exit;
    }

    if ( ! empty( $_POST['rm_vapid_purge'] ) ) {
        rm_delete_all_subscriptions();
    }

    wp_safe_redirect( add_query_arg( 'rm_vapid', 'generated', $redirect ) );
    exit;
}
add_action( 'admin_post_rm_generate_vapid', 'rm_handle_generate_vapid' );

/**
 * Turn the redirect marker from rm_handle_generate_vapid() into an admin notice.
 */
function rm_settings_vapid_notices() {
    if ( empty( $_GET['rm_vapid'] ) ) {
        return;
    }

    switch ( sanitize_key( wp_unslash( $_GET['rm_vapid'] ) ) ) {
        case 'generated':
            add_settings_error( 'rm_vapid', 'rm_vapid_generated', __( 'A new VAPID key pair was generated.', 'wp-racemanager' ), 'success' );
            break;
        case 'unconfirmed':
            add_settings_error( 'rm_vapid', 'rm_vapid_unconfirmed', __( 'Nothing was changed: the confirmation checkbox was not ticked.', 'wp-racemanager' ), 'warning' );
            break;
        case 'constant':
            add_settings_error( 'rm_vapid', 'rm_vapid_constant', __( 'The keys are defined in wp-config.php and cannot be changed here.', 'wp-racemanager' ), 'warning' );
            break;
        default:
            add_settings_error( 'rm_vapid', 'rm_vapid_failed', __( 'The key pair could not be generated. Check that the minishlink/web-push library is installed.', 'wp-racemanager' ), 'error' );
    }
}
add_action( 'admin_init', 'rm_settings_vapid_notices' );

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

    // Register push notification settings as a single array
    register_setting(
        'rm_options_group',
        'rm_vapid',
        [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => 'rm_settings_sanitize_vapid',
        ]
    );

    // Settings sections
    add_settings_section('rm_wp_section', 'WordPress Environment Settings', null, 'rm');
    add_settings_section('rm_seo_section', 'SEO Settings', 'rm_settings_seo_section_cb', 'rm');
    add_settings_section('rm_push_section', 'Push Notifications', 'rm_settings_push_section_cb', 'rm');

    // Push notification section fields
    add_settings_field(
        'vapid_status_field',
        'Status',
        'rm_settings_vapid_status_cb',
        'rm',
        'rm_push_section'
    );
    add_settings_field(
        'vapid_subject_field',
        'Contact (VAPID subject)',
        'rm_settings_vapid_subject_cb',
        'rm',
        'rm_push_section'
    );
    add_settings_field(
        'vapid_import_field',
        'Import existing keys',
        'rm_settings_vapid_import_cb',
        'rm',
        'rm_push_section'
    );

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

// Section description callback for push notifications
function rm_settings_push_section_cb() {
    echo '<p>' . esc_html__( 'Web Push needs a VAPID key pair. It is generated automatically on activation; the private key never leaves the server.', 'wp-racemanager' ) . '</p>';
    echo '<p class="description">' . wp_kses(
        __( 'For production, keep the private key out of the database by defining <code>RM_VAPID_PUBLIC_KEY</code> and <code>RM_VAPID_PRIVATE_KEY</code> in <code>wp-config.php</code>. Those constants take precedence over the values stored here.', 'wp-racemanager' ),
        [ 'code' => [] ]
    ) . '</p>';
}

// Status field: where the keys come from and whether push can work at all
function rm_settings_vapid_status_cb() {
    $source  = rm_vapid_source();
    $library = rm_push_library_available();
    $vapid   = rm_get_vapid();

    if ( ! $library ) {
        echo '<p><strong>' . esc_html__( 'Push disabled:', 'wp-racemanager' ) . '</strong> '
            . esc_html__( 'the minishlink/web-push library could not be found. Run "composer install".', 'wp-racemanager' ) . '</p>';
    }

    switch ( $source ) {
        case 'constant':
            echo '<p>' . esc_html__( 'Keys are defined in wp-config.php.', 'wp-racemanager' ) . '</p>';
            break;
        case 'option':
            echo '<p>' . esc_html__( 'Keys are stored in the database.', 'wp-racemanager' ) . '</p>';
            break;
        default:
            echo '<p><strong>' . esc_html__( 'No keys stored yet.', 'wp-racemanager' ) . '</strong> ';
            if ( rm_has_push_subscriptions() ) {
                echo esc_html__( 'Subscriptions already exist, so no keys were generated automatically. Import your existing keys below, otherwise every subscriber has to subscribe again.', 'wp-racemanager' );
            } else {
                echo esc_html__( 'Use the button below the form to generate a pair.', 'wp-racemanager' );
            }
            echo '</p>';
    }

    if ( '' !== $vapid['publicKey'] ) {
        echo '<p><label>' . esc_html__( 'Public key', 'wp-racemanager' ) . '<br>';
        echo '<input type="text" class="large-text code" readonly onfocus="this.select();" value="' . esc_attr( $vapid['publicKey'] ) . '"></label></p>';
        echo '<p class="description">' . esc_html__( 'Safe to share. It is delivered to the browser when subscribing.', 'wp-racemanager' ) . '</p>';
    }
}

// Contact address sent to the push services
function rm_settings_vapid_subject_cb() {
    $stored = get_option( 'rm_vapid', [] );
    $value  = is_array( $stored ) && isset( $stored['subject'] ) ? $stored['subject'] : '';
    $readonly = defined( 'RM_VAPID_SUBJECT' ) ? ' readonly' : '';

    echo '<input type="text" name="rm_vapid[subject]" value="' . esc_attr( $value ) . '" class="regular-text" placeholder="mailto:' . esc_attr( get_option( 'admin_email' ) ) . '"' . $readonly . '>';
    echo '<p class="description">' . esc_html__( 'Contact for the push services, as a mailto: or https: URI. Defaults to the site administrator address.', 'wp-racemanager' ) . '</p>';
}

// One-time import for installs that carried the keys in the source code
function rm_settings_vapid_import_cb() {
    if ( 'constant' === rm_vapid_source() ) {
        echo '<p class="description">' . esc_html__( 'Not applicable: the keys come from wp-config.php.', 'wp-racemanager' ) . '</p>';
        return;
    }

    echo '<p><label>' . esc_html__( 'Public key', 'wp-racemanager' ) . '<br>';
    echo '<input type="text" name="rm_vapid[import_public]" value="" class="large-text code" autocomplete="off"></label></p>';
    echo '<p><label>' . esc_html__( 'Private key', 'wp-racemanager' ) . '<br>';
    echo '<input type="password" name="rm_vapid[import_private]" value="" class="large-text code" autocomplete="off"></label></p>';
    echo '<p class="description">' . esc_html__( 'Only needed once, when moving keys that were previously hard-coded in pwa-subscription-handler.php. Both fields must be filled; leaving them empty keeps the current keys. Importing the previous pair preserves all existing subscriptions.', 'wp-racemanager' ) . '</p>';
}

// Sanitize callback for push notification settings.
// Never derives the stored keys from the form alone: the private key is not rendered,
// so an unchanged submit must not wipe it.
function rm_settings_sanitize_vapid( $input ) {
    $stored = get_option( 'rm_vapid', [] );
    if ( ! is_array( $stored ) ) {
        $stored = [];
    }
    $output = wp_parse_args( $stored, [
        'publicKey'  => '',
        'privateKey' => '',
        'subject'    => '',
    ] );

    if ( ! is_array( $input ) ) {
        return $output;
    }

    if ( isset( $input['subject'] ) ) {
        $subject = rm_sanitize_vapid_subject( $input['subject'] );
        if ( '' === $subject && '' !== trim( (string) $input['subject'] ) ) {
            add_settings_error( 'rm_vapid', 'rm_vapid_subject_invalid', __( 'The contact must be an email address or an https: URL. The previous value was kept.', 'wp-racemanager' ), 'error' );
        } else {
            $output['subject'] = $subject;
        }
    }

    $import_public  = isset( $input['import_public'] ) ? rm_sanitize_vapid_key( $input['import_public'] ) : '';
    $import_private = isset( $input['import_private'] ) ? rm_sanitize_vapid_key( $input['import_private'] ) : '';
    $wants_import   = ! empty( trim( (string) ( $input['import_public'] ?? '' ) ) )
                   || ! empty( trim( (string) ( $input['import_private'] ?? '' ) ) );

    if ( $wants_import ) {
        if ( '' !== $import_public && '' !== $import_private ) {
            $output['publicKey']  = $import_public;
            $output['privateKey'] = $import_private;
            add_settings_error( 'rm_vapid', 'rm_vapid_imported', __( 'The VAPID key pair was imported.', 'wp-racemanager' ), 'success' );
        } else {
            add_settings_error( 'rm_vapid', 'rm_vapid_import_invalid', __( 'Both the public and the private key are required, in base64url format. Nothing was imported.', 'wp-racemanager' ), 'error' );
        }
    }

    return $output;
}

// Sanitize callback for SEO settings
function rm_settings_sanitize_seo( array $input ) {
    return [
        'default_title'       => sanitize_text_field( $input['default_title'] ?? '' ),
        'default_description' => sanitize_textarea_field( $input['default_description'] ?? '' ),
        'default_keywords'    => sanitize_text_field( $input['default_keywords'] ?? '' ),
    ];
}

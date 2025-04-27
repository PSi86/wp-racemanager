<?php
// includes/admin-registrations.php
// Implement functions to display, edit and export race registrations in the admin area
// Currently here is also the functiont to save the form data to the custom table
// TODO: Move the form data saving to a separate file and check loading on admin only for the remaining code
// 

if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Global configuration which fields to display in the UI and which to exclude in CSV processing.
global $rm_gui_columns;
// Define the allowed keys for display
$rm_gui_columns = array(
    'pilot_name_1', 
    'pilot_nickname_1', 
    'pilot_phone_1', 
    'pilot_mail_1', 
    'acceptance-communication',
    'user_id', 
    'form_date'
);

function rm_create_registration_table() {
    global $wpdb;
    $registrations_table = $wpdb->prefix . 'rm_registrations';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $registrations_table (
      id mediumint(9) NOT NULL AUTO_INCREMENT,
      user_id INT(11) NOT NULL DEFAULT 0,
      race_id int(11) NOT NULL,
      form_value longtext NOT NULL,
      form_date datetime NOT NULL,
      PRIMARY KEY  (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
//register_activation_hook(__FILE__, 'rm_create_registration_table');

/**
 * Retrieve a user ID from the WordPress users database by email.
 *
 * @param string $email The email address to look up.
 * @return int The user ID if found, or 0 if no user matches.
 */
function rm_get_user_id_by_email( $email ) {
    // If the email is empty, return 0.
    if ( empty( $email ) ) {
        return 0;
    }

    // Attempt to retrieve the user by email.
    $user = get_user_by( 'email', $email );

    // Return the user ID if a valid user is found, otherwise return 0.
    return ( $user ) ? $user->ID : 0;
}

add_action('wpcf7_before_send_mail', 'rm_save_submission');
function rm_save_submission($cf7) {
    $submission = WPCF7_Submission::get_instance();
    if ( $submission ) {
        $data = $submission->get_posted_data();
        // Assume the field name "race" holds the race_id. Adjust if needed.
        $race_id = isset($data['race_id']) ? intval($data['race_id']) : 0;

        // Check if a valid race_id was provided and if the CPT "race" exists with this ID.
        if ( ! $race_id || 'race' !== get_post_type( $race_id ) ) {
            // Optionally, you can log an error or handle the missing/invalid race here.
            return; // Skip storing if the condition isn't met.
        }

        // Retrieve the user_login from the submitted data.
        $user_mail = isset( $data['pilot_mail_1'] ) ? sanitize_email( $data['pilot_mail_1'] ) : '';
        $user_id = rm_get_user_id_by_email($user_mail);

        // Serialize the entire submitted data.
        $form_value = maybe_serialize($data);

        global $wpdb;
        $registrations_table = $wpdb->prefix . 'rm_registrations';
        $wpdb->insert(
            $registrations_table,
            array(
                'user_id'    => $user_id,
                'race_id'    => $race_id,
                'form_value' => $form_value,
                'form_date'  => current_time('mysql')
            ),
            array('%d', '%d', '%s', '%s')
        );
    }
}

// Register a hidden submenu page for viewing race registrations
add_action('admin_menu', 'rm_register_race_registrations_page');
function rm_register_race_registrations_page() {
    add_submenu_page(
        'race', // parent slug
        __('Race Registrations', 'wp-racemanager'),
        __('Race Registrations', 'wp-racemanager'),
        'edit_posts', // permission requirement: should be allowed to edit the race post
        'rm_race_registrations',
        'rm_render_race_registrations'
    );
}

function rm_render_race_registrations() {
    // Validate race id and permissions.
    $race_id = isset($_GET['race_id']) ? absint($_GET['race_id']) : 0;
    if ( !$race_id || ! current_user_can('edit_post', $race_id) ) {
        wp_die(__('You are not allowed to access this page.', 'wp-racemanager'));
    }
    
    // Optionally check if the current user is the event organiser.
    /* $organiser_id = get_post_meta($race_id, '_race_organiser', true);
    if ( get_current_user_id() != $organiser_id && !current_user_can('manage_options') ) {
        wp_die(__('You are not allowed to view this registration data.', 'wp-racemanager'));
    } */
    
    global $wpdb;
    $registrations_table = $wpdb->prefix . 'rm_registrations';

    // Process new registration submission.
    if ( isset($_POST['new_registration']) ) {
        // (Optional: add nonce check here for security.)
        // Sanitize input fields from the new registration form.
        $pilot_name_1      = isset($_POST['pilot_name_1'])      ? sanitize_text_field($_POST['pilot_name_1'])      : '';
        $pilot_nickname_1  = isset($_POST['pilot_nickname_1'])  ? sanitize_text_field($_POST['pilot_nickname_1'])  : '';
        $pilot_phone_1     = isset($_POST['pilot_phone_1'])     ? sanitize_text_field($_POST['pilot_phone_1'])     : '';
        $pilot_mail_1      = isset($_POST['pilot_mail_1'])      ? sanitize_email($_POST['pilot_mail_1'])           : '';
        $user_id           = rm_get_user_id_by_email($pilot_mail_1);
        $form_date         = current_time('mysql');
        
        // Build the array for form_value using only the whitelisted fields.
        $form_fields = array(
            'race_id'          => strval($race_id),
            'pilot_name_1'     => $pilot_name_1,
            'pilot_nickname_1' => $pilot_nickname_1,
            'pilot_phone_1'    => $pilot_phone_1,
            'pilot_mail_1'     => $pilot_mail_1,
        );
        
        // Insert the new registration.
        $inserted = $wpdb->insert(
            $registrations_table,
            array(
                'user_id'    => $user_id,
                'race_id'    => $race_id,
                'form_value' => maybe_serialize($form_fields),
                'form_date'  => $form_date,
            ),
            array(
                '%d',
                '%d',
                '%s',
                '%s',
            )
        );
        
        if ( $inserted ) {
            echo '<div class="updated"><p>' . __('New registration added successfully.', 'wp-racemanager') . '</p></div>';
        } else {
            echo '<div class="error"><p>' . __('Error adding new registration.', 'wp-racemanager') . '</p></div>';
        }
    }
    
    // Handle CSV download.
    if ( isset($_GET['action']) && $_GET['action'] === 'download_csv' ) {
        rm_download_csv($race_id);
        exit;
    }
    
    // Handle bulk deletion.
    if ( isset($_POST['bulk_delete']) && !empty($_POST['registration_ids']) ) {
        $ids = array_map('absint', $_POST['registration_ids']);
        $ids_placeholder = implode(',', $ids);
        $wpdb->query("DELETE FROM $registrations_table WHERE id IN ($ids_placeholder)");
        echo '<div class="updated"><p>' . __('Registrations deleted.', 'wp-racemanager') . '</p></div>';
    }
    
    // Query the registrations for this race.
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $registrations_table WHERE race_id = %d", 
        $race_id
    ), ARRAY_A );
    
    // Process the results: unserialize the form data and add extra fields.
    // TODO: Move this to a separate function to avoid code duplication.
    // Define the allowed keys for display
    global $rm_gui_columns;
    $rows = array();

    if ( $results ) {
        foreach ( $results as $row ) {
            $data = maybe_unserialize($row['form_value']);
            if (!is_array($data)) {
                $data = array();
            }
            // Filter the array so only allowed keys remain
            $filtered_data = array_intersect_key($data, array_flip($rm_gui_columns));
            // Add extra fields from the record.
            $filtered_data['user_id']   = $row['user_id'];
            $filtered_data['form_date'] = $row['form_date'];
            $filtered_data['id']        = $row['id']; // required for checkboxes.
            $rows[] = $filtered_data;
        }
    }
    
    // Use the whitelist as headers so that only these columns are shown.
    //$headers = $allowed_columns;
    $headers = $rm_gui_columns;
    ?>
    <div class="wrap">
        <h1><?php echo sprintf( __('Registrations for Race: %s', 'wp-racemanager'), esc_html( get_the_title( $race_id ) ) ); ?></h1>        
        <!-- Registrations Table -->
        <form method="post">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col" class="manage-column check-column">
                            <input type="checkbox" id="cb-select-all">
                        </th>
                        <?php foreach ( $headers as $header ) : ?>
                            <th scope="col"><?php echo esc_html( ucfirst(str_replace('_', ' ', $header)) ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $rows ) : ?>
                        <?php foreach ( $rows as $item ) : ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="registration_ids[]" value="<?php echo intval($item['id']); ?>">
                                </th>
                                <?php foreach ( $headers as $header ) : ?>
                                    <td>
                                        <?php
                                        $value = isset($item[$header]) ? $item[$header] : '';
                                        if ( is_array($value) ) {
                                            $value = implode(', ', $value);
                                        }
                                        echo esc_html( (string) $value );
                                        ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr>
                            <td colspan="<?php echo count($headers) + 1; ?>"><?php _e('No registrations found.', 'wp-racemanager'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p>
                <input type="submit" name="bulk_delete" class="button-secondary" value="<?php _e('Delete Selected', 'wp-racemanager'); ?>" onclick="return confirm('<?php _e('Are you sure you want to delete the selected registrations?', 'wp-racemanager'); ?>');">
                <input type="submit" name="download_csv" class="button-secondary" value="<?php _e('Download CSV', 'wp-racemanager'); ?>">
            </p>
        </form>
        <!-- Registration Form -->
        <h2><?php _e('Add New Registration', 'wp-racemanager'); ?></h2>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="pilot_name_1"><?php _e('Pilot Name', 'wp-racemanager'); ?></label></th>
                    <td><input name="pilot_name_1" type="text" id="pilot_name_1" value="" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pilot_nickname_1"><?php _e('Pilot Nickname', 'wp-racemanager'); ?></label></th>
                    <td><input name="pilot_nickname_1" type="text" id="pilot_nickname_1" value="" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pilot_phone_1"><?php _e('Pilot Phone', 'wp-racemanager'); ?></label></th>
                    <td><input name="pilot_phone_1" type="text" id="pilot_phone_1" value="" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="pilot_mail_1"><?php _e('Pilot Mail', 'wp-racemanager'); ?></label></th>
                    <td><input name="pilot_mail_1" type="email" id="pilot_mail_1" value="" class="regular-text"></td>
                </tr>
            </table>
            <?php // Optional: add wp_nonce_field('new_registration') for security ?>
            <p>
                <input type="submit" name="new_registration" class="button-primary" value="<?php _e('Add Registration', 'wp-racemanager'); ?>">
            </p>
        </form>
    </div>
    <script>
    // "Select All" checkbox behavior.
    document.getElementById('cb-select-all').addEventListener('click', function(e) {
        var checkboxes = document.querySelectorAll('input[name="registration_ids[]"]');
        for (var i = 0; i < checkboxes.length; i++) {
            checkboxes[i].checked = e.target.checked;
        }
    });
    </script>
    <?php
}

add_action('admin_init', 'rm_handle_csv_download');
function rm_handle_csv_download() {
    if ( isset($_GET['page']) && $_GET['page'] === 'rm_race_registrations' && isset($_POST['download_csv']) && isset($_GET['race_id']) ) {
        $race_id = isset($_GET['race_id']) ? absint($_GET['race_id']) : null;
        if ( !$race_id || ! current_user_can('edit_post', $race_id) ) {
            wp_die(__('You are not allowed to access this page.', 'wp-racemanager'));
        }
        $selected_ids = ( isset($_POST['registration_ids']) && is_array($_POST['registration_ids']) ) ? array_map('absint', $_POST['registration_ids']) : array();
        rm_download_csv($race_id, $selected_ids);
        exit;
    }
}

function rm_download_csv($race_id, $selected_ids = array()) {
    global $wpdb;
    $registrations_table = $wpdb->prefix . 'rm_registrations';
    
    // If there are selected IDs, filter by those. Otherwise, get all rows for the race.
    if ( ! empty($selected_ids) ) {
        $ids_placeholder = implode(',', $selected_ids);
        $query = "SELECT * FROM $registrations_table WHERE id IN ($ids_placeholder) AND race_id = %d";
        $results = $wpdb->get_results( $wpdb->prepare( $query, $race_id ), ARRAY_A );
    } else {
        $results = $wpdb->get_results( $wpdb->prepare("SELECT * FROM $registrations_table WHERE race_id = %d", $race_id), ARRAY_A );
    }
    
    // Process the results: unserialize the form data and add extra fields.
    // TODO: Move this to a separate function to avoid code duplication.
    // Define the allowed keys for display
    global $rm_gui_columns;
    $rows = array();
    if ( $results ) {
        foreach ( $results as $row ) {
            $data = maybe_unserialize($row['form_value']);
            if (!is_array($data)) {
                $data = array();
            }
            // Filter the array so only allowed keys remain
            $filtered_data = array_intersect_key($data, array_flip($rm_gui_columns));
            // Add extra fields from the record.
            $filtered_data['user_id']   = $row['user_id'];
            $filtered_data['form_date'] = $row['form_date'];
            $filtered_data['id']        = $row['id']; // required for checkboxes.
            $rows[] = $filtered_data;
        }
    }
    
    if ( empty($rows) ) {
        wp_die( __('No registrations to download.', 'wp-racemanager') );
    }
    
    // Build CSV headers from the first row.
    $headers = array_keys( $rows[0] );

    // Clear the output buffer to prevent header issues.
    if (ob_get_length()) {
        ob_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=registrations_race_' . $race_id . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// Create the Contact Form 7 form for event registration
// This function should be called only once, e.g., on plugin activation.
function create_event_registration_cf7_form() {
/*     // Check if a form with the title "Event Registration" already exists.
    $args = array(
        'post_type'      => 'wpcf7_contact_form',
        'post_status'    => 'any',
        's'              => 'Event Registration',
        'posts_per_page' => 5,
    );
    $query = new WP_Query( $args );
    $existing_form = null;
    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post ) {
            if ( $post->post_title === 'Event Registration' ) {
                $existing_form = $post;
                break;
            }
        }
        // Rename the existing form to avoid conflicts.
        if ( $existing_form ) {
            $existing_form->post_title = 'Event Registration Backup';
            wp_update_post( $existing_form );
        }
    }
    wp_reset_postdata(); */

    // Define the form content.
    $form_content = '<h4>Select Event</h4>
[race race_id]

<h4>Pilot Details </h4>
Name: [text* pilot_name_1 autocomplete:name default:user_last_name placeholder "Vorname Nachname"]

Callsign: [text* pilot_nickname_1 autocomplete:callsign default:user_nickname placeholder "Dein Nickname"]

Mobile: [tel* pilot_phone_1 autocomplete:phone placeholder "Deine Mobile Nummer"]

Mail: [email* pilot_mail_1 autocomplete:email default:user_email placeholder "Email Adresse"]

<h4>Consent</h4>

[acceptance acceptance-pay]
Ich akzeptiere hiermit die Vollständigkeit der Angaben und bezahle den ausstehenden Betrag am Veranstaltungstag.
[/acceptance]
[acceptance acceptance-media]
Ich bin mit der Veröffentlichung von Name, Alter und Bild im Rahmen des Wettkampfs einverstanden.
[/acceptance]
[acceptance acceptance-communication optional]
Fügt mich zur Whatsapp-Gruppe / Discord-Server hinzu.
[/acceptance]

[cf7-simple-turnstile]

[submit "Senden"]';

    // Define the mail content.
    $mail_content = 'Hallo,

Wir von Rotormaniacs freuen uns, dass du dich angemeldet hast und auf deine Teilnahme am Rennen.

Deine angegeben Daten / Your specified data :

Name: [pilot_name_1]
Nickname: [pilot_nickname_1]
Mobile: [pilot_phone_1]
Email: [pilot_mail_1]
Rennen: [race_id]


Mit freundlichen Grüssen / Best regards

TSV Korntal - FPV racing

-- 
Diese E-Mail ist eine Bestätigung für das Absenden deines Kontaktformulars auf unserer Website ([_site_title] [_site_url]), in der deine E-Mail-Adresse verwendet wurde. Wenn du das nicht warst, ignoriere bitte diese Nachricht.
';

    // Create a new contact form using CF7 API.
    $contact_form = WPCF7_ContactForm::get_template();
    $contact_form->set_title( 'Event Registration Example' );
    $contact_form->set_properties( array(
        'form' => $form_content,
        'mail' => array(
            'active'    => true,
            'sender'    => '[_site_title] <registration@copterrace.com>',
            'recipient' => '[pilot_mail_1]',
            'subject'   => '[_site_title]: Race Registration Confirmation',
            'body'      => $mail_content,
            'additional_headers' => 
                "Reply-To: registration@copterrace.com\r\n" .
                "Bcc: registration@copterrace.com",
        ),
        'additional_settings' => 'skip_mail: off',
        // additional properties (like messages, mail_2, etc.)
    ) );
    $contact_form->save();
}

// register_activation_hook( __FILE__, 'create_event_registration_cf7_form' );
<?php
// includes/admin-registrations.php
// Implement functions to display, edit and export race registrations in the admin area
// Currently here is also the functiont to save the form data to the custom table
// TODO: Move the form data saving to a separate file and check loading on admin only for the remaining code
// 

if (!defined('ABSPATH')) exit; // Exit if accessed directly

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
        $user_login = isset( $data['user_login'] ) ? sanitize_text_field( $data['user_login'] ) : '';

        // Initialize user ID to 0 (not logged in).
        $user_id = 0;

        // If user_login is present, look up the user object.
        if ( ! empty( $user_login ) ) {
            $user = get_user_by( 'login', $user_login );
            if ( $user ) {
                $user_id = $user->ID;
            }
        }

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
    
    // Handle CSV download
    if ( isset($_GET['action']) && $_GET['action'] === 'download_csv' ) {
        rm_download_csv($race_id);
        exit;
    }
    
    // Handle bulk deletion
    if ( isset($_POST['bulk_delete']) && !empty($_POST['registration_ids']) ) {
        global $wpdb;
        $registrations_table = $wpdb->prefix . 'rm_registrations';
        $ids = array_map('absint', $_POST['registration_ids']);
        $ids_placeholder = implode(',', $ids);
        $wpdb->query("DELETE FROM $registrations_table WHERE id IN ($ids_placeholder)");
        echo '<div class="updated"><p>' . __('Registrations deleted.', 'wp-racemanager') . '</p></div>';
    }
    
    // Query the custom table for registrations for this race.
    global $wpdb;
    $registrations_table = $wpdb->prefix . 'rm_registrations';
    $results = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM $registrations_table WHERE race_id = %d", 
        $race_id
    ), ARRAY_A );
    
    // Define the whitelist of columns that should be visible in the UI.
    
    $allowed_columns = array('pilot_name_1', 'pilot_nickname_1', 'pilot_phone_1', 'pilot_mail_1', 'user_id', 'form_date');
    
    // Process the results: unserialize the form data and prepare the table rows.
    $rows = array();
    if ( $results ) {
        foreach ( $results as $row ) {
            $data = maybe_unserialize($row['form_value']);
            // Add additional info from the custom table record.
            $data['user_id'] = $row['user_id'];
            $data['form_date'] = $row['form_date'];
            $data['id'] = $row['id']; // needed for checkboxes
            $rows[] = $data;
        }
    }
    
    // Use the whitelist as our headers so that only these columns will be shown.
    $headers = $allowed_columns;
    ?>
    <div class="wrap">
        <h1><?php echo sprintf( __('Registrations for Race: %s', 'wp-racemanager'), esc_html( get_the_title( $race_id ) ) ); ?></h1>
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
    
    $csv_rows = array();
    if ($results) {
        foreach ($results as $row) {
            $data = ! empty($row['form_value']) ? maybe_unserialize($row['form_value']) : array();
            if ( ! is_array($data) ) {
                $data = array();
            }
            $data['user_id'] = $row['user_id'];
            $data['id'] = $row['id'];
            $data['form_date'] = $row['form_date'];
            // Flatten any array values and replace nulls with an empty string
            foreach ($data as $key => $value) {
                if ( is_array($value) ) {
                    $data[$key] = implode(', ', $value);
                }
                if ( is_null($data[$key]) ) {
                    $data[$key] = '';
                }
            }
            $csv_rows[] = $data;
        }
    }
    
    if ( empty($csv_rows) ) {
        wp_die( __('No registrations to download.', 'wp-racemanager') );
    }
    
    // Build CSV headers from the first row.
    $headers = array_keys( $csv_rows[0] );
    
    // Clear the output buffer to prevent header issues.
    if (ob_get_length()) {
        ob_clean();
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=registrations_race_' . $race_id . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, $headers);
    foreach ($csv_rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}




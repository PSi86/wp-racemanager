<?php
// includes/db-handler.php
// Query registered pilots from the cfdb7_table
if (!defined('ABSPATH')) exit; // Exit if accessed directly

function rm_get_registered_callsigns( $form_title ) {
    // API key authentication

    global $wpdb;

    //$form_title = sanitize_text_field($form_title);
    
    $cfdb7_table = $wpdb->prefix . 'db7_forms'; // cfdb7 table name holds all form replies
    $posts_table = $wpdb->prefix . 'posts'; // WordPress posts table. The 'wpcf7_contact_form' post type holds the form definitions

    $form_post_id = null;

    if ($form_title === 'latest') {
        // Get the highest form_post_id directly from the cfdb7 table
        //$form_post_id = $wpdb->get_var("SELECT MAX(form_post_id) FROM $cfdb7_table");

        // Find the latest published form in the posts table otherwise there is the risk of reading registrations from a old, deleted or unpublished form
        $form_post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(ID) FROM $posts_table 
                WHERE post_status = 'publish' AND post_type = 'wpcf7_contact_form'"
            )
        );
        
        if (!$form_post_id) {
            return new WP_Error('no_latest_form', 'No form data found in the database.', ['status' => 404]);
        }
    } elseif (strlen($form_title) > 1) {
        // Find the highest post_id for the given form_title in the posts table
        $form_post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(ID) FROM $posts_table 
                WHERE post_title = %s AND post_type = 'wpcf7_contact_form'",
                $form_title
            )
        );

        if (!$form_post_id) {
            return new WP_Error('no_form_found', 'No form found with the specified form_title.', ['status' => 404]);
        }
    } else {
        return new WP_Error('invalid_form_title', 'Invalid form_title parameter.', ['status' => 404]);
    }

    // Query the cfdb7 table for entries matching the form_post_id
    $query = $wpdb->prepare(
        "SELECT form_value, form_date FROM $cfdb7_table WHERE form_post_id = %d",
        $form_post_id
    );
    $results = $wpdb->get_results( $query );
    
    $nicknames = array();

    // Process each submission and extract the 'pilot_nickname_1' field.
    if ( ! empty( $results ) ) {
        foreach ( $results as $row ) {
            // Unserialize the data (it’s stored as a serialized array)
            $data = maybe_unserialize( $row->form_value );
            // TODO: make name of the field configurable in settings
            if ( isset( $data['pilot_nickname_1'] ) ) {
                $nicknames[] = $data['pilot_nickname_1'];
            }
            else {
                $nicknames[] = "not set";
            }
        }
    }
    
    return $nicknames;
}
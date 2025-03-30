<?php
// includes/db-handler.php
// Query registered pilots from the registrations_table
if (!defined('ABSPATH')) exit; // Exit if accessed directly

function rm_get_registered_callsigns( $race_id ) {
    // API key authentication

    global $wpdb;

    //$race_id = sanitize_text_field($race_id);
    $race_id=intval($race_id);
    if(get_post_type($race_id) != 'race') {
        return 'Invalid Race ID';
    }

    $registrations_table = $wpdb->prefix . 'rm_registrations'; // cfdb7 table name holds all form replies

    // Query the cfdb7 table for entries matching the race_id
    $query = $wpdb->prepare(
        "SELECT form_value, form_date FROM $registrations_table WHERE race_id = %d",
        $race_id
    );
    $results = $wpdb->get_results( $query );
    
    $nicknames = array();
    $callsign_field = get_option('rm_callsign_field', 'pilot_callsign');

    // Process each submission and extract the 'pilot_nickname_1' field.
    if ( ! empty( $results ) ) {
        foreach ( $results as $row ) {
            // Unserialize the data (it’s stored as a serialized array)
            $data = maybe_unserialize( $row->form_value );
            // TODO: make name of the field configurable in settings
            if ( isset( $data[$callsign_field] ) ) {
                $nicknames[] = $data[$callsign_field];
            }
            else {
                $nicknames[] = "Field not found";
            }
        }
    }
    
    return $nicknames;
}
<?php
// includes/sc-rm_viewer.php
// Register the custom shortcode [rm_viewer]

if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_shortcode('rm_viewer', 'rm_shortcode_handler');

function rm_shortcode_handler($atts) {
    // Merge default shortcode attributes
    $atts = shortcode_atts(
        array(
            'race_id' => null,
        ),
        $atts,
        'rm_viewer'
    );

    // Use supplied race_id param or get the current post ID of the page where the shortcode is used
    //$race_id = ! empty( $atts['race_id'] ) ? $atts['race_id'] : get_the_ID();
    
    // Priority: shortcode attribute > URL parameter > current post ID
    if ( ! empty( $atts['race_id'] ) ) {
        // Use the race_id provided in the shortcode
        $race_id = $atts['race_id'];
    } elseif ( isset( $_GET['race_id'] ) && ! empty( $_GET['race_id'] ) ) {
        // Use the race_id from the URL parameter, sanitizing the input for security
        $race_id = sanitize_text_field( $_GET['race_id'] );
    } else {
        // Fallback to the current post ID
        //global $post;
        //$race_id = $post->ID;
        $race_id = get_the_ID(); // newer version
    }

    if ( $race_id === 'latest' ) {
        global $wpdb;
        $posts_table = $wpdb->prefix . 'posts'; // WordPress posts table.
        $race_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT MAX(ID) FROM $posts_table 
                WHERE post_status = 'publish' AND post_type = 'race'"
            )
        );
    }

    if( $race_id ) {
        $race_id = intval($race_id);
    }
    else {
        return '<p>No valid post found for [rm_viewer].</p>';
    }

    // check race status. live: set script to periodically check load live data, not live (locked): set script to load static data
    $race_live = get_post_meta( $race_id, '_race_live', true );

    // For now it is ok to build the file names of data and timestamp purely from the post ID
    $upload_path_local = WP_CONTENT_DIR . '/uploads/races/';

    $upload_dir = wp_upload_dir();
    $upload_path_url = trailingslashit( $upload_dir['baseurl'] ) . 'races/';

    $filename_timestamp = $race_id . '-timestamp.json';
    $filename_data = $race_id . '-data.json';

    $file_timestamp_local = $upload_path_local . $filename_timestamp;
    $file_timestamp_url = $upload_path_url . $filename_timestamp;
    $file_data_local = $upload_path_local . $filename_data;
    $file_data_url = $upload_path_url . $filename_data;
    

    if(!file_exists($file_timestamp_local) || !file_exists($file_data_local)) {
        return '<p>No JSON files found for this Race.</p>';
    }

    // Enqueue custom CSS and JS
    wp_enqueue_style(
        'rm-sc-viewer-css', 
        plugin_dir_url( __DIR__ ) . 'css/rm_viewer.css'
    );

    wp_enqueue_script(
        'rm-bracket-template', 
        plugin_dir_url( __DIR__ ) . 'js/class_templates_V1.js', 
        ['jquery'], 
        '1.0.1', 
        false
    );

    wp_enqueue_script(
        'rm-bracketview', 
        plugin_dir_url( __DIR__ ) . 'js/bracketV25.js', 
        ['jquery'], 
        '1.0.1',
        true
    );

    if($race_live) {
        // This is a live event -> serve live data script
        wp_localize_script('rm-bracketview', 'wp_vars', [
            'webmode' => 'live', // live mode -> data is asynchronously updated
            'timestampUrl' => $file_timestamp_url, // rest_url('rh/v1/latest-timestamp'), OR '/wp/wp-content/'.$results[0]->id.'-timestamp.json'
            'dataUrl' => $file_data_url, // rest_url('rh/v1/latest-data'), OR '/wp/wp-content/'.$results[0]->id.'-data.json'
            'refreshInterval' => 10000, // Polling interval in milliseconds (5 seconds)
        ]);

        //$output = '<p>This race is live. Data will automatically update.</p>';
    }
    else {
        // This is not a live event -> serve static data script
        wp_localize_script('rm-bracketview', 'wp_vars', [
            'webmode' => 'static', // static mode -> data is loaded once
            'timestampUrl' => $file_timestamp_url, // rest_url('rh/v1/latest-timestamp'), OR '/wp/wp-content/'.$results[0]->id.'-timestamp.json'
            'dataUrl' => $file_data_url, // rest_url('rh/v1/latest-data'), OR '/wp/wp-content/'.$results[0]->id.'-data.json'
            'refreshInterval' => 10000, // Polling interval in milliseconds (5 seconds)
        ]);

        //$output = '<p>This race is archived.</p>';
    }

    $output = '<div class="web-controls">
                    <label for="pilotSelector">Highlight Pilot: </label>
                    <select id="pilotSelector" onchange="updateFilterAndHighlight()">
                        <option value="0">-- Select a Pilot --</option>
                    </select>
                    <label>
                        <input type="checkbox" id="filterCheckbox" onchange="updateFilterAndHighlight()"> Filter by Selected Pilot
                    </label>
                    <a href="/results.html">Detailed Heat Data</a>
                </div>
                <!-- id needs to be *-display (eg. elimination-display) and class must be raceclass-container -->
                <div id="elimination-display" class="raceclass-container"></div>
                <div id="qualifying-display" class="raceclass-container"></div>
                <div id="training-display" class="raceclass-container"></div>
                <div id="progress-bar" class="rh-controls"></div>
                <div id="bottom" style="visibility: hidden;">Rotormaniacs - Galaxy Whoop Race</div>';

    return $output;
}

// Old implementation - not in use
/**
 * Check if the requested race is locked or not!
 * if the race is locked -> serve page showing static data
 * if the race is not locked -> serve page with async data loading
 */
function rm_viewer_event($query) {
    global $wpdb;

    // Execute the query
    $results = $wpdb->get_results($query);

    // If no results found, return a message
    if (!$results) {
        return '<p>No data available.</p>';
    }

    if (!$results[0]->locked) {
        // This is a live event -> serve live data script
        wp_enqueue_script('rm-bracket-template', plugin_dir_url( __DIR__ ) . 'js/class_templates_V1.js', ['jquery'], null, true);
        wp_enqueue_script('rm-bracketview', plugin_dir_url( __DIR__ ) . 'js/bracketV25.js', ['jquery'], null, true);
        wp_localize_script('rm-bracketview', 'wp_vars', [
            'mode' => 'live', // Environment (wordpress or rotorhazard) - not used currently
            //'timestampUrl' => rest_url('rh/v1/latest-timestamp'), // Timestamp REST endpoint
            'timestampUrl' => '/wp/wp-content/'.$results[0]->id.'-timestamp.json', // Timestamp REST endpoint
            //'dataUrl' => rest_url('rh/v1/latest-data'), // Data REST endpoint
            'dataUrl' => '/wp/wp-content/'.$results[0]->id.'-data.json', // Data REST endpoint '/wp/wp-content/latest-data.json';
            'refreshInterval' => 10000, // Polling interval in milliseconds (5 seconds)
        ]);

        // Add a placeholder for the data
       /*  $output .= '<div id="rm-latest-container">
                    <p class="timestamp">Loading latest timestamp...</p>
                    <pre class="json-data">Loading latest JSON data...</pre>
                </div>'; */
        $output = '<div class="web-controls">
                        <label for="pilotSelector">Highlight Pilot: </label>
                        <select id="pilotSelector" onchange="updateFilterAndHighlight()">
                            <option value="0">-- Select a Pilot --</option>
                        </select>
                        <label>
                            <input type="checkbox" id="filterCheckbox" onchange="updateFilterAndHighlight()"> Filter by Selected Pilot
                        </label>
                        <a href="/results.html">Detailed Heat Data</a>
                    </div>
                    <!-- id needs to be *-display (eg. elimination-display) and class must be raceclass-container -->
                    <div id="elimination-display" class="raceclass-container"></div>
                    <div id="qualifying-display" class="raceclass-container"></div>
                    <div id="training-display" class="raceclass-container"></div>
                    <div id="progress-bar" class="rh-controls"></div>
                    <div id="bottom" style="visibility: hidden;">Rotormaniacs - Galaxy Whoop Race</div>';
        $output .= '<p>This race is live. Data will automatically update.</p>';
    }
    else {
        // This is a locked event -> serve static data from the database
        wp_enqueue_script('rm-bracket-template', plugin_dir_url( __DIR__ ) . 'js/class_templates_V1.js', ['jquery'], null, true);
        wp_enqueue_script('rm-bracketview', plugin_dir_url( __DIR__ ) . 'js/bracketV25.js', ['jquery'], null, true);
        wp_localize_script('rm-bracketview', 'wp_vars', [
            'mode' => 'static', // Environment (wordpress or rotorhazard) - not used currently
            'data' => json_decode($results[0]->json_data), // send data to client;
        ]);

        $output = '<div class="web-controls">
                        <label for="pilotSelector">Highlight Pilot: </label>
                        <select id="pilotSelector" onchange="updateFilterAndHighlight()">
                            <option value="0">-- Select a Pilot --</option>
                        </select>
                        <label>
                            <input type="checkbox" id="filterCheckbox" onchange="updateFilterAndHighlight()"> Filter by Selected Pilot
                        </label>
                        <a href="/results.html">Detailed Heat Data</a>
                    </div>
                    <!-- id needs to be *-display (eg. elimination-display) and class must be raceclass-container -->
                    <div id="elimination-display" class="raceclass-container"></div>
                    <div id="qualifying-display" class="raceclass-container"></div>
                    <div id="training-display" class="raceclass-container"></div>
                    <div id="progress-bar" class="rh-controls"></div>
                    <div id="bottom" style="visibility: hidden;">Rotormaniacs - Galaxy Whoop Race</div>';
        $output .= '<p>This race is not live. Shown data does not update.</p>';
    }
    // Generate the output table
    return $output;
}

// currently not necessary - we use the cpt "race" and the wp archive page to display the list of races
/* function rm_viewer_list($query) {
    global $wpdb;

    // Execute the query
    $results = $wpdb->get_results($query);

    // If no results found, return a message
    if (!$results) {
        return '<p>No data available.</p>';
    }

    // Generate the output table
    $output = '<table class="rm-viewer-table">';
    //$output .= '<thead><tr><th>ID</th><th>Race Name</th><th>Race Description</th><th>JSON Data</th><th>Timestamp</th></tr></thead><tbody>';
    $output .= '<thead><tr><th>Race Name</th><th>Race Description</th><th>Timestamp</th></tr></thead><tbody>';

    foreach ($results as $row) {
        $output .= '<tr>';
        //$output .= '<td>' . esc_html($row->id) . '</td>';
        $output .= '<td>' . esc_html($row->race_name) . '</td>';
        $output .= '<td>' . esc_html($row->race_description) . '</td>';
        //$output .= '<td><pre>' . esc_html($row->json_data) . '</pre></td>';
        $output .= '<td>' . esc_html($row->timestamp_upload) . '</td>';
        $output .= '</tr>';
    }

    $output .= '</tbody></table>';
    return $output;
} */
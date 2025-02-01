<?php
// includes/shortcodes.php
// Register the custom shortcode [rm_viewer]

if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_shortcode('rm_viewer', 'rm_shortcode_handler');

function rm_shortcode_handler($atts) {
    // Merge default shortcode attributes
    $atts = shortcode_atts(
        array(
            'post_id' => null,
        ),
        $atts,
        'rm_viewer'
    );


    // Determine which post to load JSON for
    $post_id = ! empty( $atts['post_id'] ) ? intval( $atts['post_id'] ) : get_the_ID();

    if ( ! $post_id ) {
        return '<p>No valid post found for [rm_viewer].</p>';
    }

    // For now it is ok to build the file names of data and timestamp purely from the post ID
    $upload_path = WP_CONTENT_DIR . '/uploads/races/';
    $file_timestamp = $upload_path . $post_id . '-timestamp.json';
    $file_data = $upload_path . $post_id . '-data.json';

    if(!file_exists($file_timestamp) || !file_exists($file_data)) {
        return '<p>No JSON files found for this Race.</p>';
    }

    // Optionally parse and display
    // For simplicity, just output in <pre>
    $output  = '<div class="my-json-viewer">';
    //$output .= '<pre>' . esc_html( $json_data ) . '</pre>';
    $output .= '<pre>Shortcode executed.</pre>';
    $output .= '</div>';

    return $output;

    // Enqueue custom CSS
    wp_enqueue_style('rm-custom-style', plugin_dir_url( __DIR__ ) . 'css/rm-custom.css');


    //return rm_viewer_event($query);
    //return rm_viewer_list($query);
}

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

function rm_viewer_list($query) {
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
}
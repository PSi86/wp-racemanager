<?php
// includes/sc-cf7-event-dropdown.php
// Implement [race_dropdown] for Contact Form 7 using wpcf7_form_tag 
// 

if (!defined('ABSPATH')) exit; // Exit if accessed directly

function get_upcoming_races() {
    $today = current_time('Y-m-d');
    $args = array(
        'post_type' => 'race',
        'meta_key'       => '_race_event_start',  // This tells WP which meta field to sort by.
        'meta_type'      => 'DATE',               // Use 'DATE' if the field is stored as a date.
        'orderby'        => 'meta_value',         // Sort by the value of the meta field.
        'order'          => 'ASC', 
        'meta_query' => array(
            array(
                'key'     => '_race_event_end',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATE'
            ),
            array(
                'key'     => '_race_reg_closed',
                'value'   => 1,
                'compare' => '!=',
                'type'    => 'NUMERIC'
            )
        ),
        'posts_per_page' => -1,
    );
    $query = new WP_Query($args);
    $races = array();
    if ( $query->have_posts() ) {
        while ( $query->have_posts() ) {
            $query->the_post();
            $races[get_the_ID()] = get_the_title();
        }
        wp_reset_postdata();
    }
    return $races;
}

add_action( 'wpcf7_admin_init', 'rm_custom_tag_generator_init' );
function rm_custom_tag_generator_init() {
    wpcf7_add_tag_generator(
        'race',     // unique name for the tag
        'race',     // title displayed in the tag list
        'tag-generator-panel-race',     // identifier for the generator pane
        'rm_tag_generator_callback',    // callback function that outputs the generator UI
        array( 'version' => 2 )         // Optional attributes for the tag generator
    );
}

// Step 2: Define the callback to display the generator UI
function rm_tag_generator_callback( $contact_form, $args = '' ) {
?>
<header class="description-box">
	<h3>Dropdown showing races open for registrations.</h3>
	<p>Generates a form-tag for a select input showing races that currently accept registrations.</p>
</header>
<div class="control-box">
    <fieldset>
        <legend id="tag-generator-panel-menu-type-legend">Field type</legend>
        <select data-tag-part="basetype" aria-labelledby="tag-generator-panel-menu-type-legend">
            <option value="race">RaceManager races drop-down</option>
        </select>
        <br>
        <label>
            <input type="checkbox" data-tag-part="type-suffix" value="*">
            This is a required field.	</label>
	</fieldset>
    <fieldset>
        <legend id="tag-generator-panel-race-name-legend">Field name</legend>
        <input type="text" data-tag-part="name" pattern="[A-Za-z][A-Za-z0-9_\-]*" aria-labelledby="tag-generator-panel-race-name-legend">
    </fieldset>
    <fieldset>
        <legend id="tag-generator-panel-race-class-legend">Class attribute</legend>
        <input type="text" data-tag-part="option" data-tag-option="class:" pattern="[A-Za-z0-9_\-\s]*" aria-labelledby="tag-generator-panel-race-class-legend">
    </fieldset>
</div>
<footer class="insert-box">
	<div class="flex-container">
	<input type="text" class="code" readonly="readonly" onfocus="this.select();" data-tag-part="tag" aria-label="The form-tag to be inserted into the form template">	<button type="button" class="button button-primary" data-taggen="insert-tag">Insert Tag</button>
</div>
<p class="mail-tag-tip">To use the user input in the email, insert the corresponding mail-tag <strong data-tag-part="mail-tag">[race]</strong> into the email template.</p>
</footer>
<?php
}

add_action('wpcf7_init', 'register_race_dropdown_form_tag');

// Register the custom form tag with Contact Form 7
function register_race_dropdown_form_tag() {
    wpcf7_add_form_tag(
        array( 'race', 'race*' ), 
        'race_dropdown_handler', 
        array( 'name-attr' => true )
    );
}

// Handler for the custom form tag [race_dropdown]
function race_dropdown_handler($tag) {
    // Parse attributes: allow a "preselected" attribute.
    $preselected = null;
    
    if (isset($_GET['race_id'])) {
        $preselected = intval($_GET['race_id']);
    }
    
    // Get upcoming races.
    $races = get_upcoming_races();
    
    // If a race ID was provided but isn't available, set an error message.
    $error_message = '';
    if ($preselected && !array_key_exists($preselected, $races)) {
        $error_message = '<p class="error">The link is not valid, please select a race from the list.</p>';
        // Optionally, reset preselected to avoid marking any option as selected.
        $preselected = 0;
    }
    
    // Example tag object for debugging: 
    // serialize($tag)=
    /*    O:13:"WPCF7_FormTag":11:{
            s:4:"type";s:5:"race*";
            s:8:"basetype";s:4:"race";
            s:8:"raw_name";s:8:"race-120";
            s:4:"name";s:8:"race-120";
            s:7:"options";a:0:{}
                s:10:"raw_values";a:0:{}
                s:6:"values";a:0:{}
                s:5:"pipes";
                    O:11:"WPCF7_Pipes":1:{s:18:"WPCF7_Pipespipes";a:0:{}}s:6:"labels";a:0:{}s:4:"attr";s:0:"";s:7:"content";s:0:"";}
    */
    
    // Read the tag attributes.
    $tagname = isset($tag['name']) && !empty($tag['name']) ? $tag['name'] : 'race_id';
    $tagrequired = isset($tag['type']) && !empty($tag['type']) ? str_ends_with( $tag['type'], '*' ) : false;

    // Build the dropdown.
    $html = $error_message;
    $html .= '<span class="wpcf7-form-control-wrap" data-name="'. esc_attr($tagname) .'">';
    if (! $tagrequired) {
        $html .= '<select class="wpcf7-form-control wpcf7-select" aria-invalid="false" name="'. $tagname . '">';
    } else {
        $html .= '<select class="wpcf7-form-control wpcf7-select wpcf7-validates-as-required" aria-required="true" aria-invalid="false" name="'. $tagname . '">';
    }
    foreach ($races as $id => $title) {
        $selected = ($id == $preselected) ? ' selected="selected"' : '';
        $html .= sprintf('<option value="%d"%s>%s</option>', $id, $selected, esc_html($title));
    }
    if ( empty($races) ) {
        $html .= '<option value="none"></option>';
    }
    $html .= '</select></span>';
    
    return $html;
}

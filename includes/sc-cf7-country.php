<?php
// includes/sc-cf7-country.php
// [rm_country] for Contact Form 7 (1.11.0): a drop-down of the countries (countries.php) for a
// pilot's nationality. It sends the country's code; [rm_country* name] makes it required. The
// registration form's field is pilot_country_1, which pilot-profiles.php reads.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once __DIR__ . '/countries.php';

add_action( 'wpcf7_init', 'rm_register_country_form_tag' );
add_action( 'wpcf7_admin_init', 'rm_country_tag_generator_init' );
add_filter( 'wpcf7_validate_rm_country', 'rm_country_validation_filter', 10, 2 );
add_filter( 'wpcf7_validate_rm_country*', 'rm_country_validation_filter', 10, 2 );

/**
 * Register the form tag.
 *
 * @return void
 */
function rm_register_country_form_tag() {
    wpcf7_add_form_tag(
        array( 'rm_country', 'rm_country*' ),
        'rm_country_form_tag_handler',
        array( 'name-attr' => true )
    );
}

/**
 * The drop-down: an empty first entry, then the countries by their name in the site's language.
 *
 * @param WPCF7_FormTag $tag
 * @return string
 */
function rm_country_form_tag_handler( $tag ) {
    if ( empty( $tag->name ) ) {
        return '';
    }

    $validation_error = wpcf7_get_validation_error( $tag->name );
    // Styled as CF7 styles its own drop-downs.
    $class = wpcf7_form_controls_class( 'select' );
    if ( $validation_error ) {
        $class .= ' wpcf7-not-valid';
    }

    $atts = array(
        'name'          => $tag->name,
        'class'         => $tag->get_class_option( $class ),
        'id'            => $tag->get_id_option(),
        // A browser fills "country" with the code, which is what the options hold.
        'autocomplete'  => 'country',
        'aria-required' => $tag->is_required() ? 'true' : 'false',
        'aria-invalid'  => $validation_error ? 'true' : 'false',
    );
    if ( $validation_error ) {
        $atts['aria-describedby'] = wpcf7_get_validation_error_reference( $tag->name );
    }

    // What the pilot chose, when the form comes back after a failed check without JavaScript.
    $chosen  = rm_valid_country( (string) wpcf7_get_hangover( $tag->name, '' ) );
    $options = '<option value="">—</option>';
    foreach ( rm_country_names() as $code => $name ) {
        $options .= sprintf(
            '<option value="%1$s"%2$s>%3$s</option>',
            esc_attr( $code ),
            $code === $chosen ? ' selected="selected"' : '',
            esc_html( $name )
        );
    }

    return sprintf(
        '<span class="wpcf7-form-control-wrap" data-name="%1$s"><select %2$s>%3$s</select>%4$s</span>',
        esc_attr( $tag->name ),
        wpcf7_format_atts( $atts ),
        $options,
        $validation_error
    );
}

/**
 * Empty is fine unless the tag is required; anything else has to be a code of the list.
 *
 * @param WPCF7_Validation $result
 * @param WPCF7_FormTag    $tag
 * @return WPCF7_Validation
 */
function rm_country_validation_filter( $result, $tag ) {
    $value = trim( (string) wpcf7_superglobal_post( $tag->name ) );
    if ( '' === $value ) {
        if ( $tag->is_required() ) {
            $result->invalidate( $tag, wpcf7_get_message( 'invalid_required' ) );
        }
    } elseif ( '' === rm_valid_country( $value ) ) {
        $result->invalidate( $tag, __( 'Please choose a country from the list.', 'wp-racemanager' ) );
    }
    return $result;
}

/**
 * The tag's entry in the form editor.
 *
 * @return void
 */
function rm_country_tag_generator_init() {
    wpcf7_add_tag_generator(
        'rm_country',
        'country',
        'tag-generator-panel-rm-country',
        'rm_country_tag_generator_callback',
        array( 'version' => 2 )
    );
}

/**
 * The generator's panel.
 *
 * @param WPCF7_ContactForm $contact_form
 * @param array|string      $args
 * @return void
 */
function rm_country_tag_generator_callback( $contact_form, $args = '' ) {
?>
<header class="description-box">
	<h3>Drop-down of countries, for a pilot's nationality.</h3>
	<p>Generates a form-tag for a select input of the countries (ISO 3166 codes), named in the site's language. The registration form's field is <code>pilot_country_1</code>: WP RaceManager shows its flag next to the pilot on the live pages, under the consent <code>acceptance-media</code>.</p>
</header>
<div class="control-box">
	<fieldset>
		<legend id="tag-generator-panel-rm-country-type-legend">Field type</legend>
		<select data-tag-part="basetype" aria-labelledby="tag-generator-panel-rm-country-type-legend">
			<option value="rm_country">Countries drop-down</option>
		</select>
		<br>
		<label>
			<input type="checkbox" data-tag-part="type-suffix" value="*">
			This is a required field.</label>
	</fieldset>
	<fieldset>
		<legend id="tag-generator-panel-rm-country-name-legend">Field name</legend>
		<input type="text" data-tag-part="name" value="pilot_country_1" pattern="[A-Za-z][A-Za-z0-9_\-]*" aria-labelledby="tag-generator-panel-rm-country-name-legend">
	</fieldset>
	<fieldset>
		<legend id="tag-generator-panel-rm-country-class-legend">Class attribute</legend>
		<input type="text" data-tag-part="option" data-tag-option="class:" pattern="[A-Za-z0-9_\-\s]*" aria-labelledby="tag-generator-panel-rm-country-class-legend">
	</fieldset>
</div>
<footer class="insert-box">
	<div class="flex-container">
	<input type="text" class="code" readonly="readonly" onfocus="this.select();" data-tag-part="tag" aria-label="The form-tag to be inserted into the form template">	<button type="button" class="button button-primary" data-taggen="insert-tag">Insert Tag</button>
</div>
<p class="mail-tag-tip">To use the user input in the email, insert the corresponding mail-tag <strong data-tag-part="mail-tag">[pilot_country_1]</strong> into the email template.</p>
</footer>
<?php
}

<?php
/**
 * block-render-race-winner.php
 * Renders the race-winner block: who won the race, as includes/race-winner.php kept it from the
 * results. Since 1.14.0 the block's setting "show" says how much:
 *
 *   - winner (the default, and all 1.12 and 1.13 had): the cup, the pilot's photo or initials, the
 *     flag, the callsign, and the class when a race has several;
 *   - podium: places 1 to 3 of each bracket class, a medal each, under the class's name when a race
 *     has several;
 *   - standing: the podium, and under it the whole standing of each bracket class - place, pilot, the
 *     round they went out in - as the bracket page has it under the brackets, drawn in the browser
 *     from the race's data by js/rm-m-displayStandings.js. Only on the race's own page, which is where
 *     its data is loaded for; elsewhere - on the race list's cards - the podium alone. The podium
 *     stays where the standing draws nothing: without JavaScript, or for a class that forms no
 *     bracket the standing could order.
 *
 * Photo and flag are the pilot's profile as it is now, so a pilot who took back the consent shows the
 * callsign only. Nothing while the race has no winner.
 *
 * The race is the block's post: the post the Query Loop hands it on the race list's cards, else the
 * current post.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/race-winner.php';
require_once __DIR__ . '/pilot-profiles.php';

/** The block's settings for "show", the first the default. */
const RM_RACE_WINNER_SHOWS = array( 'winner', 'podium', 'standing' );

/** Where the standing is drawn on the race's page: js/rm-m-displayStandings.js's containerId. */
const RM_RACE_WINNER_STANDING_ID = 'rm-race-standing';

/**
 * Render callback for the Race Winner block.
 *
 * @param array         $attributes Block attributes: show.
 * @param string        $content    Block content.
 * @param WP_Block|null $block      The block, with its context.
 * @return string HTML, '' without a winner.
 */
function rm_render_race_winner_block( $attributes, $content = '', $block = null ) {
    $race_id = ( $block instanceof WP_Block && ! empty( $block->context['postId'] ) ) ? (int) $block->context['postId'] : (int) get_the_ID();
    if ( ! $race_id || 'race' !== get_post_type( $race_id ) ) {
        return '';
    }
    $winners = rm_get_race_winners( $race_id );
    if ( ! $winners ) {
        return '';
    }

    $show = in_array( $attributes['show'] ?? '', RM_RACE_WINNER_SHOWS, true ) ? $attributes['show'] : RM_RACE_WINNER_SHOWS[0];
    if ( 'standing' === $show && ! rm_race_winner_on_own_page( $race_id ) ) {
        $show = 'podium';
    }

    $profiles = rm_pilot_profiles();
    $several  = count( $winners ) > 1;
    $html     = '';
    foreach ( $winners as $winner ) {
        $class_name = (string) ( $winner['class_name'] ?? '' );
        // A race worked out before 1.14.0 has no podium until the backfill reaches it: its winner.
        $podium = ( 'winner' !== $show && ! empty( $winner['podium'] ) && is_array( $winner['podium'] ) ) ? $winner['podium'] : null;
        if ( null === $podium ) {
            $html .= rm_race_winner_line( $winner, $profiles, '🏆', $several ? $class_name : '' );
            continue;
        }
        $lines = '';
        foreach ( $podium as $place ) {
            $lines .= rm_race_winner_line( $place, $profiles, rm_race_winner_medal( (int) ( $place['place'] ?? 0 ) ), '', (int) ( $place['place'] ?? 0 ) );
        }
        $html .= '<div class="rm-race-podium">'
            . ( $several && '' !== $class_name ? '<div class="rm-race-podium-class">' . esc_html( $class_name ) . '</div>' : '' )
            . $lines
            . '</div>';
    }

    if ( 'standing' === $show ) {
        rm_race_winner_enqueue_standing();
        // Under the podium; js/rm-m-displayStandings.js fills it, and hides it when it has nothing.
        $html .= '<div id="' . esc_attr( RM_RACE_WINNER_STANDING_ID ) . '" class="rm-race-standing"></div>';
    }

    $label = 'winner' === $show
        ? ( $several ? __( 'Winners', 'wp-racemanager' ) : __( 'Winner', 'wp-racemanager' ) )
        : ( 'podium' === $show ? __( 'Podium', 'wp-racemanager' ) : __( 'Standing', 'wp-racemanager' ) );
    return sprintf(
        '<div %1$s role="group" aria-label="%2$s">%3$s</div>',
        get_block_wrapper_attributes( array( 'class' => 'rm-race-winner rm-race-winner-' . $show ) ),
        esc_attr( $label ),
        $html
    );
}

/**
 * One line: the cup or a medal, the photo or the initials, the flag, the callsign, and the class.
 *
 * @param array  $pilot    pilot_key and callsign.
 * @param array  $profiles The pilot profiles (rm_pilot_profiles()).
 * @param string $mark     The cup or the medal.
 * @param string $class    The class's name, '' for none.
 * @param int    $place    The place, 0 for the winner line.
 * @return string
 */
function rm_race_winner_line( $pilot, $profiles, $mark, $class = '', $place = 0 ) {
    $callsign = (string) ( $pilot['callsign'] ?? '' );
    $key      = rm_valid_pilot_key( $pilot['pilot_key'] ?? '' );
    $profile  = ( $key && isset( $profiles[ $key ] ) && is_array( $profiles[ $key ] ) ) ? $profiles[ $key ] : array();

    $avatar = '<span class="rm-race-winner-avatar" aria-hidden="true">' . esc_html( rm_race_winner_initials( $callsign ) );
    if ( ! empty( $profile['photo'] ) && preg_match( '/^[0-9a-f]{8}$/', (string) $profile['photo'] ) ) {
        $avatar .= sprintf(
            '<img src="%s" alt="" width="64" height="64" loading="lazy" decoding="async">',
            esc_url( rm_pilot_photo_url() . $key . '.jpg?v=' . $profile['photo'] )
        );
    }
    $avatar .= '</span>';

    $flag    = '';
    $country = rm_valid_country( $profile['country'] ?? '' );
    if ( '' !== $country ) {
        $flag = sprintf(
            '<img class="rm-race-winner-flag" src="%1$s" alt="%2$s" title="%2$s" width="16" height="12" loading="lazy" decoding="async">',
            esc_url( rm_flag_base_url() . strtolower( $country ) . '.svg' ),
            esc_attr( $country )
        );
    }

    // The place for those who cannot see the medal.
    $mark_html = $place
        ? '<span class="rm-race-winner-cup" role="img" aria-label="' . esc_attr( sprintf( __( 'Place %d', 'wp-racemanager' ), $place ) ) . '">' . $mark . '</span>'
        : '<span class="rm-race-winner-cup" aria-hidden="true">' . $mark . '</span>';

    return '<div class="rm-race-winner-line' . ( $place ? ' rm-race-winner-place-' . $place : '' ) . '">'
        . $mark_html
        . $avatar . $flag
        . '<span class="rm-race-winner-callsign">' . esc_html( $callsign ) . '</span>'
        . ( '' !== $class ? '<span class="rm-race-winner-class">' . esc_html( $class ) . '</span>' : '' )
        . '</div>';
}

/**
 * The medal of a place: gold, silver, bronze.
 *
 * @param int $place
 * @return string
 */
function rm_race_winner_medal( $place ) {
    return array( 1 => '🥇', 2 => '🥈', 3 => '🥉' )[ $place ] ?? '';
}

/**
 * Whether the block stands on its race's own page - the race's post shown by itself - where the
 * race's data is loaded (rm_get_current_race() is the queried race there), rather than on a card of
 * the race list.
 *
 * @param int $race_id
 * @return bool
 */
function rm_race_winner_on_own_page( $race_id ) {
    return is_singular( 'race' ) && (int) get_queried_object_id() === (int) $race_id;
}

/**
 * The standing's module, its stylesheet and configuration. The data loader gets the race's files from
 * rm_print_js_module_config(), for the race rm_get_current_race() names - the page's own.
 *
 * @return void
 */
function rm_race_winner_enqueue_standing() {
    // rm_add_js_module_config() and the printing on wp_head: loaded with the plugin on live pages
    // only, and a race's page is none.
    require_once __DIR__ . '/livepage-handler.php';
    wp_enqueue_style(
        'rm-standings-css',
        plugin_dir_url( __DIR__ ) . 'css/rm-standings.css',
        array(),
        WP_RACEMANAGER_VERSION
    );
    wp_register_script_module(
        'rm-displayStandings',
        plugin_dir_url( __DIR__ ) . 'js/rm-m-displayStandings.js',
        array(),
        WP_RACEMANAGER_VERSION
    );
    wp_enqueue_script_module( 'rm-displayStandings' );
    rm_add_js_module_config( array(
        'dataLoader'       => array(), // filled by rm_print_js_module_config()
        'displayStandings' => array(
            'containerId' => RM_RACE_WINNER_STANDING_ID,
            'flagBaseUrl' => rm_flag_base_url(),
        ),
    ) );
}

/**
 * Two letters for a pilot without a photo: of the first two words, else the first two.
 *
 * @param string $callsign
 * @return string
 */
function rm_race_winner_initials( $callsign ) {
    $words = array_values( array_filter( array_map(
        fn( $w ) => preg_replace( '/[^\p{L}\p{N}]/u', '', $w ),
        preg_split( '/\s+/u', trim( (string) $callsign ) ) ?: array()
    ), 'strlen' ) );
    $letters = count( $words ) > 1
        ? mb_substr( $words[0], 0, 1 ) . mb_substr( $words[1], 0, 1 )
        : mb_substr( $words[0] ?? '', 0, 2 );
    return mb_strtoupper( $letters );
}

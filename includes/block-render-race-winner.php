<?php
/**
 * block-render-race-winner.php
 * Renders the race-winner block (1.12.0): who won the race, as includes/race-winner.php kept it from
 * the results - the cup, the pilot's photo or initials, the flag, the callsign, and the class when a
 * race has several. Photo and flag are the pilot's profile as it is now, so a pilot who took back the
 * consent shows the callsign only. Nothing while the race has no winner.
 *
 * The race is the block's post: the post the Query Loop hands it on the race list's cards, else the
 * current post.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/race-winner.php';
require_once __DIR__ . '/pilot-profiles.php';

/**
 * Render callback for the Race Winner block.
 *
 * @param array         $attributes Block attributes.
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

    $profiles = rm_pilot_profiles();
    $several  = count( $winners ) > 1;
    $lines    = '';
    foreach ( $winners as $winner ) {
        $callsign = (string) ( $winner['callsign'] ?? '' );
        $key      = rm_valid_pilot_key( $winner['pilot_key'] ?? '' );
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

        $class = $several && '' !== (string) ( $winner['class_name'] ?? '' )
            ? '<span class="rm-race-winner-class">' . esc_html( $winner['class_name'] ) . '</span>'
            : '';

        $lines .= '<div class="rm-race-winner-line">'
            . '<span class="rm-race-winner-cup" aria-hidden="true">🏆</span>'
            . $avatar . $flag
            . '<span class="rm-race-winner-callsign">' . esc_html( $callsign ) . '</span>'
            . $class
            . '</div>';
    }

    $label = $several ? __( 'Winners', 'wp-racemanager' ) : __( 'Winner', 'wp-racemanager' );
    return sprintf(
        '<div %1$s role="group" aria-label="%2$s">%3$s</div>',
        get_block_wrapper_attributes( array( 'class' => 'rm-race-winner' ) ),
        esc_attr( $label ),
        $lines
    );
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

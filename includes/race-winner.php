<?php
// includes/race-winner.php
// Who won a race (1.12.0), for the race's card in the race list and its page: worked out from the
// race's data whenever its files are written, and kept in the race's _race_winner. Nobody enters it -
// it is what the timer's results say, and it follows them.
//
// One winner per bracket class - a class whose heats seed each other - in the timer's class order:
//
//   - a class ranked with "Brackets" (Class Rank: Brackets): its ranking's place 1 once it has one;
//     with Chase the Ace, nobody before that - the final is flown until a pilot has won twice;
//   - any other: the first of the final's result, once the final has one. The final is the heat that
//     no other heat of the class seeds from and that takes someone's first place; a single
//     elimination's small final, which takes the semifinals' third and fourth, is not.
//
// With the winner, the podium (1.14.0): places 1 to 3 from the same source - the ranking's, or the
// final's result in its order, which is the order the bracket page's standing gives the final
// (js/rm-m-bracketStandings.js). The race-winner block shows either, or the whole standing.
//
// Races stored before get theirs from their data file, a few per admin page load: those from before
// 1.12.0 their winner, those from before 1.14.0 their podium.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

require_once __DIR__ . '/race-results.php'; // rm_heat_primary_entries(), rm_class_ranking_first(), rm_class_chases_the_ace()
require_once __DIR__ . '/pilot-key.php';    // rm_valid_pilot_key()
// rm_get_race_data_dir() (race-data-functions.php), for the races stored before, is loaded with the
// plugin; the suites that write races bring their own.

/** The race's meta key: a list of winners, [] for none. */
const RM_RACE_WINNER_META = '_race_winner';

/**
 * The version of the rule a race's winners were worked out with, kept beside them - 2 has the podium
 * (1.14.0) - and, in the option, whether every race is worked out with it.
 */
const RM_RACE_WINNER_SCHEMA_META   = '_race_winner_schema';
const RM_RACE_WINNER_SCHEMA_OPTION = 'rm_race_winner_schema';
const RM_RACE_WINNER_SCHEMA        = 2;

/** How many places the podium has. */
const RM_RACE_PODIUM_PLACES = 3;

/**
 * Races worked out per admin request until every one has its winner. admin_init runs for the
 * editor's heartbeat too, and a race's data file is up to 2 MB of JSON to decode.
 */
const RM_RACE_WINNER_BATCH = 5;

add_action( 'admin_init', 'rm_race_winner_backfill' );

/**
 * The winners of a race: one per bracket class that has one, in the timer's class order.
 *
 * @param array $race_data The race's data, as the timer uploaded it.
 * @return array[] Each [ 'class_id', 'class_name', 'pilot_id', 'pilot_key', 'callsign', 'podium' ],
 *                 the podium places 1 to 3, each [ 'place', 'pilot_id', 'pilot_key', 'callsign' ].
 */
function rm_race_winners( $race_data ) {
    if ( ! is_array( $race_data ) ) {
        return array();
    }
    $heats   = array_values( array_filter( (array) ( $race_data['heat_data']['heats'] ?? array() ), 'is_array' ) );
    $classes = array_values( array_filter( (array) ( $race_data['class_data']['classes'] ?? array() ), 'is_array' ) );
    $ordered = array_filter( $classes, fn( $c ) => is_numeric( $c['order'] ?? null ) );
    usort( $classes, count( $ordered ) === count( $classes )
        ? fn( $a, $b ) => ( (int) $a['order'] <=> (int) $b['order'] ) ?: ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) )
        : fn( $a, $b ) => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );

    $pilots = array();
    foreach ( (array) ( $race_data['pilot_data']['pilots'] ?? array() ) as $pilot ) {
        if ( is_array( $pilot ) && ! empty( $pilot['pilot_id'] ) ) {
            $pilots[ (int) $pilot['pilot_id'] ] = $pilot;
        }
    }

    $winners = array();
    foreach ( $classes as $class ) {
        $class_id = (int) ( $class['id'] ?? 0 );
        $final    = rm_race_final_heat( $heats, $class_id );
        if ( null === $final ) {
            continue; // no bracket
        }
        $podium = rm_race_podium( $race_data, $class, $final );
        if ( ! $podium ) {
            continue;
        }
        // The pilot's callsign and key as the pilot list has them, else as the result wrote them.
        foreach ( $podium as &$place ) {
            $pilot = $pilots[ $place['pilot_id'] ] ?? null;
            $place = array(
                'place'     => $place['place'],
                'pilot_id'  => $place['pilot_id'],
                'pilot_key' => rm_valid_pilot_key( is_array( $pilot ) ? ( $pilot['pilot_key'] ?? '' ) : '' ),
                'callsign'  => (string) ( ( is_array( $pilot ) && isset( $pilot['callsign'] ) ) ? $pilot['callsign'] : $place['callsign'] ),
            );
        }
        unset( $place );
        $winners[] = array(
            'class_id'   => $class_id,
            'class_name' => (string) ( $class['displayname'] ?? ( $class['name'] ?? '' ) ),
            'pilot_id'   => $podium[0]['pilot_id'],
            'pilot_key'  => $podium[0]['pilot_key'],
            'callsign'   => $podium[0]['callsign'],
            'podium'     => $podium,
        );
    }
    return $winners;
}

/**
 * A bracket class's places 1 to 3 (1.14.0), from where its winner comes: the ranking of the timer
 * once it has a place 1; else, without Chase the Ace, the final's result once its first has a place -
 * in the result's order, pilots without a place included, as the bracket page's standing orders the
 * final (js/rm-m-bracketStandings.js).
 *
 * @param array $race_data The race's data.
 * @param array $class     The class.
 * @param int   $final     Its final (rm_race_final_heat()).
 * @return array[] Each [ 'place', 'pilot_id', 'callsign' ], place 1 first; [] without a winner.
 */
function rm_race_podium( $race_data, $class, $final ) {
    $ranked = rm_class_ranking_top( $race_data, (int) ( $class['id'] ?? 0 ), RM_RACE_PODIUM_PLACES );
    if ( $ranked || rm_class_chases_the_ace( $class ) ) {
        return $ranked;
    }
    $entries = rm_heat_primary_entries( $race_data, $final );
    $entries = is_array( $entries ) ? array_values( array_filter( $entries, fn( $e ) => is_array( $e ) && ! empty( $e['pilot_id'] ) ) ) : array();
    if ( ! $entries || 1 !== (int) ( $entries[0]['position'] ?? 0 ) ) {
        return array();
    }
    $podium = array();
    foreach ( array_slice( $entries, 0, RM_RACE_PODIUM_PLACES ) as $index => $entry ) {
        $podium[] = array( 'place' => $index + 1, 'pilot_id' => (int) $entry['pilot_id'], 'callsign' => (string) ( $entry['callsign'] ?? '' ) );
    }
    return $podium;
}

/**
 * A class's final: of the heats no other heat of the class seeds from, the one that takes someone's
 * first place - the last such, by the timer's order - or, where none does, the last of them.
 *
 * @param array[] $heats    heat_data.heats.
 * @param int     $class_id The class.
 * @return int|null The heat's id, or null for a class whose heats do not seed each other.
 */
function rm_race_final_heat( $heats, $class_id ) {
    $own = array_values( array_filter( $heats, fn( $h ) => (int) ( $h['class_id'] ?? 0 ) === $class_id && ! empty( $h['id'] ) ) );
    $ids = array_flip( array_map( fn( $h ) => (int) $h['id'], $own ) );
    $seeded_from = array();
    foreach ( $own as $heat ) {
        foreach ( (array) ( $heat['slots'] ?? array() ) as $slot ) {
            if ( is_array( $slot ) && 1 === (int) ( $slot['method'] ?? -1 ) && isset( $ids[ (int) ( $slot['seed_id'] ?? 0 ) ] ) ) {
                $seeded_from[ (int) $slot['seed_id'] ] = true;
            }
        }
    }
    if ( ! $seeded_from ) {
        return null;
    }
    $last_ones  = array_values( array_filter( $own, fn( $h ) => ! isset( $seeded_from[ (int) $h['id'] ] ) ) );
    $take_first = array_values( array_filter( $last_ones, function ( $h ) use ( $ids ) {
        foreach ( (array) ( $h['slots'] ?? array() ) as $slot ) {
            if ( is_array( $slot ) && 1 === (int) ( $slot['method'] ?? -1 ) && 1 === (int) ( $slot['seed_rank'] ?? 0 ) && isset( $ids[ (int) ( $slot['seed_id'] ?? 0 ) ] ) ) {
                return true;
            }
        }
        return false;
    } ) );
    $candidates = $take_first ?: $last_ones;
    if ( ! $candidates ) {
        return null; // every heat seeds another: a circle
    }
    usort( $candidates, fn( $a, $b ) => ( (int) ( $a['order'] ?? $a['id'] ) <=> (int) ( $b['order'] ?? $b['id'] ) ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
    return (int) end( $candidates )['id'];
}

/**
 * Work out a race's winners and keep them; empty the page cache when they changed, since the race
 * list's cards show them.
 *
 * @param int   $race_id   The race.
 * @param array $race_data Its data.
 * @return bool Whether they changed.
 */
function rm_store_race_winners( $race_id, $race_data ) {
    $winners = rm_race_winners( $race_data );
    $before  = get_post_meta( $race_id, RM_RACE_WINNER_META, true );
    if ( RM_RACE_WINNER_SCHEMA !== (int) get_post_meta( $race_id, RM_RACE_WINNER_SCHEMA_META, true ) ) {
        update_post_meta( $race_id, RM_RACE_WINNER_SCHEMA_META, RM_RACE_WINNER_SCHEMA );
    }
    if ( is_array( $before ) && $before === $winners ) {
        return false;
    }
    update_post_meta( $race_id, RM_RACE_WINNER_META, $winners );
    if ( ( is_array( $before ) ? $before : array() ) !== $winners && function_exists( 'rm_purge_page_caches' ) ) {
        rm_purge_page_caches();
    }
    return true;
}

/**
 * Work out the races stored before - before 1.12.0 without winners, before 1.14.0 without podiums -
 * from their data files: a batch per admin page load, since production has no WP-CLI to do it in one
 * go. A race worked out has the schema in its RM_RACE_WINNER_SCHEMA_META; done, the option says so.
 *
 * @return int How many races were worked out.
 */
function rm_race_winner_backfill() {
    if ( (int) get_option( RM_RACE_WINNER_SCHEMA_OPTION, 0 ) >= RM_RACE_WINNER_SCHEMA ) {
        return 0;
    }
    $ids = get_posts( array(
        'post_type'        => 'race',
        'post_status'      => 'any',
        'posts_per_page'   => RM_RACE_WINNER_BATCH,
        'fields'           => 'ids',
        'orderby'          => 'ID',
        'order'            => 'ASC',
        'suppress_filters' => true,
        'meta_query'       => array(
            'relation' => 'OR',
            array( 'key' => RM_RACE_WINNER_SCHEMA_META, 'compare' => 'NOT EXISTS' ),
            array( 'key' => RM_RACE_WINNER_SCHEMA_META, 'value' => RM_RACE_WINNER_SCHEMA, 'compare' => '<', 'type' => 'NUMERIC' ),
        ),
    ) );
    $dir = rm_get_race_data_dir( false );
    foreach ( $ids as $race_id ) {
        $file = is_wp_error( $dir ) ? '' : $dir . $race_id . '-data.json';
        $data = ( $file && is_file( $file ) ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
        rm_store_race_winners( (int) $race_id, is_array( $data ) ? $data : array() );
    }
    if ( count( $ids ) < RM_RACE_WINNER_BATCH ) {
        update_option( RM_RACE_WINNER_SCHEMA_OPTION, RM_RACE_WINNER_SCHEMA, false );
    }
    return count( $ids );
}

/**
 * The winners of a race as kept.
 *
 * @param int $race_id
 * @return array[]
 */
function rm_get_race_winners( $race_id ) {
    $winners = get_post_meta( $race_id, RM_RACE_WINNER_META, true );
    return is_array( $winners ) ? $winners : array();
}

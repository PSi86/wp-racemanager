<?php
/**
 * Who flies next: rm_getUpcomingRacePilots(), which every upload runs to send the "your next
 * race" pushes.
 *
 * What has to hold:
 *
 *   - the heat on the timer is announced even when it is the last one, and whatever number the
 *     timer gave its first heat -- a class generated again starts far above 1;
 *   - a slot the timer fills from a class's result (method 2) takes that class's pilot, not the
 *     pilot of the heat that happens to carry the same number;
 *   - a slot filled from a heat's result (method 1) takes the entry at seed_rank - 1 of the heat's
 *     leaderboard, which is how RotorHazard seeds (heat_automation.py), not the entry whose
 *     position equals seed_rank: a pilot who never started has no position.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once RM_PLUGIN_DIR . '/includes/race-data-functions.php';

/**
 * A heat as the upload carries it.
 *
 * @param int   $id         Heat id.
 * @param int   $class_id   Class id.
 * @param int   $next_round Rounds flown.
 * @param array $slots      Slots: pilot_id, method, seed_rank, seed_id.
 * @return array
 */
function rm_test_heat( $id, $class_id, $next_round, $slots ) {
    $out = array();
    foreach ( $slots as $i => $slot ) {
        $out[] = array_merge(
            array( 'id' => $id * 10 + $i, 'node_index' => $i, 'pilot_id' => null, 'method' => 0, 'seed_rank' => null, 'seed_id' => null ),
            $slot
        );
    }
    return array(
        'id'          => $id,
        'displayname' => 'Heat ' . $id,
        'class_id'    => $class_id,
        'next_round'  => $next_round,
        'slots'       => $out,
    );
}

/**
 * A leaderboard entry.
 *
 * @param int      $pilot_id Pilot id.
 * @param int|null $position Position, null for a pilot who did not start.
 * @return array
 */
function rm_test_entry( $pilot_id, $position ) {
    return array( 'pilot_id' => $pilot_id, 'callsign' => 'P' . $pilot_id, 'position' => $position );
}

/**
 * The event around the heats.
 *
 * @param int   $current  The heat on the timer.
 * @param array $heats    Heats from rm_test_heat().
 * @param array $classes  Classes: id, name, rounds.
 * @param array $results  result_data.
 * @return array
 */
function rm_test_event( $current, $heats, $classes, $results = array() ) {
    $pilots = array();
    for ( $p = 1; $p <= 40; $p++ ) {
        $pilots[] = array( 'pilot_id' => $p, 'callsign' => 'P' . $p );
    }
    return array(
        'current_heat'   => array( 'current_heat' => $current ),
        'frequency_data' => array( 'fdata' => array(
            array( 'band' => 'R', 'channel' => 1 ),
            array( 'band' => 'R', 'channel' => 2 ),
            array( 'band' => 'F', 'channel' => 2 ),
            array( 'band' => 'F', 'channel' => 4 ),
        ) ),
        'pilot_data'     => array( 'pilots' => $pilots ),
        'class_data'     => array( 'classes' => $classes ),
        'heat_data'      => array( 'heats' => $heats ),
        'result_data'    => $results,
    );
}

/**
 * The upcoming pilots as "heat:pilot" strings, in order.
 *
 * @param array $event The event.
 * @return string[]
 */
function rm_test_upcoming( $event ) {
    $out = array();
    foreach ( (array) rm_getUpcomingRacePilots( $event ) as $row ) {
        $out[] = $row['heat_id'] . ':' . $row['pilot_id'];
    }
    return $out;
}

$elimination = array( 'id' => 3, 'name' => 'Elimination', 'rounds' => 1, 'order' => 3 );

rm_test_section( 'The last heat is announced' );
$event = rm_test_event(
    3,
    array(
        rm_test_heat( 1, 3, 1, array( array( 'pilot_id' => 1 ), array( 'pilot_id' => 2 ) ) ),
        rm_test_heat( 2, 3, 1, array( array( 'pilot_id' => 3 ), array( 'pilot_id' => 4 ) ) ),
        rm_test_heat( 3, 3, 0, array( array( 'pilot_id' => 5 ), array( 'pilot_id' => 6 ) ) ),
    ),
    array( $elimination )
);
$got = rm_test_upcoming( $event );
rm_test_check( 'the final on the timer has its two pilots', array( '3:5', '3:6' ) === $got, 'got ' . implode( ',', $got ) );

rm_test_section( 'Heats numbered from 50' );
$event = rm_test_event(
    50,
    array(
        rm_test_heat( 50, 3, 0, array( array( 'pilot_id' => 1 ), array( 'pilot_id' => 2 ) ) ),
        rm_test_heat( 51, 3, 0, array( array( 'pilot_id' => 3 ), array( 'pilot_id' => 4 ) ) ),
        rm_test_heat( 52, 3, 0, array( array( 'pilot_id' => 5 ), array( 'pilot_id' => 6 ) ) ),
    ),
    array( $elimination )
);
$got = rm_test_upcoming( $event );
rm_test_check(
    'the heat on the timer and the next two are announced',
    array( '50:1', '50:2', '51:3', '51:4', '52:5', '52:6' ) === $got,
    'got ' . implode( ',', $got )
);

rm_test_section( 'A slot filled from a class result' );
// Class 2 (Qualifying) ranks pilot 12 first; heat 2 of class 1 (Training) has pilot 11 first. The
// elimination heat 20 takes Qualifying's first -- seed_id 2 is the class, not heat 2.
$event = rm_test_event(
    20,
    array(
        rm_test_heat( 2, 1, 1, array( array( 'pilot_id' => 11 ) ) ),
        rm_test_heat( 20, 3, 0, array( array( 'method' => 2, 'seed_id' => 2, 'seed_rank' => 1 ) ) ),
    ),
    array(
        array( 'id' => 1, 'name' => 'Training', 'rounds' => 1, 'order' => 1 ),
        array( 'id' => 2, 'name' => 'Qualifying', 'rounds' => 1, 'order' => 2 ),
        $elimination,
    ),
    array(
        'heats'   => array(
            '2' => array( 'heat_id' => 2, 'leaderboard' => array(
                'by_race_time' => array( rm_test_entry( 11, 1 ) ),
                'meta'         => array( 'primary_leaderboard' => 'by_race_time' ),
            ) ),
        ),
        'classes' => array(
            '2' => array( 'id' => 2, 'ranking' => false, 'leaderboard' => array(
                'by_consecutives' => array( rm_test_entry( 12, 1 ), rm_test_entry( 13, 2 ) ),
                'meta'            => array( 'primary_leaderboard' => 'by_consecutives' ),
            ) ),
        ),
    )
);
$got = rm_test_upcoming( $event );
rm_test_check( "Qualifying's first, pilot 12", array( '20:12' ) === $got, 'got ' . implode( ',', $got ) );

// With a ranking method on the class, RotorHazard seeds from the ranking instead.
$event['result_data']['classes']['2']['ranking'] = array(
    'ranking' => array( rm_test_entry( 13, 1 ), rm_test_entry( 12, 2 ) ),
    'meta'    => array( 'method_label' => 'Brackets' ),
);
$got = rm_test_upcoming( $event );
rm_test_check( "the class ranking's first, pilot 13", array( '20:13' ) === $got, 'got ' . implode( ',', $got ) );

rm_test_section( 'A slot filled from a heat result, by index' );
// Heat 30 was flown: pilots 21 and 22 placed, 23 and 24 did not start (no position). The third
// slot of heat 31 takes the third entry, pilot 23, as RotorHazard does.
$event = rm_test_event(
    31,
    array(
        rm_test_heat( 30, 3, 1, array( array( 'pilot_id' => 21 ), array( 'pilot_id' => 22 ), array( 'pilot_id' => 23 ), array( 'pilot_id' => 24 ) ) ),
        rm_test_heat( 31, 3, 0, array( array( 'method' => 1, 'seed_id' => 30, 'seed_rank' => 3 ) ) ),
    ),
    array( $elimination ),
    array(
        'heats' => array(
            '30' => array( 'heat_id' => 30, 'leaderboard' => array(
                'by_race_time' => array( rm_test_entry( 21, 1 ), rm_test_entry( 22, 2 ), rm_test_entry( 23, null ), rm_test_entry( 24, null ) ),
                'meta'         => array( 'primary_leaderboard' => 'by_race_time' ),
            ) ),
        ),
    )
);
$got = rm_test_upcoming( $event );
rm_test_check( 'the third entry, pilot 23', array( '31:23' ) === $got, 'got ' . implode( ',', $got ) );

rm_test_section( 'A Chase the Ace final' );
// The final (heat 41) of a class ranked with "Brackets" (Class Rank: Brackets), its Chase the Ace
// switch untouched and so on: one round flown, which the class's single round counts as done.
$cta_event = function ( $ranking ) use ( $elimination ) {
    $class = $elimination;
    $class['win_condition'] = 'Brackets';
    $class['ranksettings'] = null;
    return rm_test_event(
        41,
        array(
            rm_test_heat( 40, 3, 1, array( array( 'pilot_id' => 1 ), array( 'pilot_id' => 2 ) ) ),
            rm_test_heat( 41, 3, 1, array( array( 'pilot_id' => 3 ), array( 'pilot_id' => 4 ) ) ),
        ),
        array( $class ),
        array(
            'classes' => array(
                '3' => array( 'id' => 3, 'ranking' => $ranking ),
            ),
        )
    );
};
$got = rm_test_upcoming( $cta_event( array( 'ranking' => array(), 'meta' => array() ) ) );
rm_test_check( 'not decided yet: its pilots are announced for the next round', array( '41:3', '41:4' ) === $got, 'got ' . implode( ',', $got ) );
$got = rm_test_upcoming( $cta_event( array( 'ranking' => array( rm_test_entry( 3, 1 ) ), 'meta' => array() ) ) );
rm_test_check( 'decided by the timer: nobody flies next', array() === $got, 'got ' . implode( ',', $got ) );
$switched_off = $cta_event( array( 'ranking' => array(), 'meta' => array() ) );
$switched_off['class_data']['classes'][0]['ranksettings'] = array( 'chase_the_ace' => false );
$got = rm_test_upcoming( $switched_off );
rm_test_check( 'Chase the Ace switched off: the final is done after its round', array() === $got, 'got ' . implode( ',', $got ) );

rm_test_finish();

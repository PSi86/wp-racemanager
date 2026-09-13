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
 *     position equals seed_rank: a pilot who never started has no position;
 *   - a pilot's channel is that of the slot's node, not of its place in the list, and it is named
 *     only once the heat's seats are fixed - flown, confirmed, or without automatic frequencies
 *     (1.13.0);
 *   - until then, the channel RotorHazard will likely give the pilot is named as such, where its
 *     automatic frequency assignment decides it without drawing lots: from the seats the heat's
 *     pilots flew on before, by start time, in the order its default (adaptive calibration) fills
 *     the seats - and not at all while a seed may still bring somebody; one whose source has its
 *     result without that rank brings nobody (1.15.0);
 *   - where the upload carries RotorHazard's own used_frequencies, those count, matched by
 *     frequency as RotorHazard matches them, and the rounds' seats do not (1.16.0).
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

rm_test_section( 'The seat and its channel' );
// Heat 60 on the timer: pilot 1 on node 1, pilot 2 on node 3, the free slots with no node and first,
// as RotorHazard sorts them while it gives out the seats. The profile: R1, R2, F2, F4.
$seated_event = function ( $heat_keys ) use ( $elimination ) {
    $heat = array_merge(
        rm_test_heat( 60, 3, 0, array(
            array( 'node_index' => null ),
            array( 'node_index' => null ),
            array( 'pilot_id' => 1, 'node_index' => 1 ),
            array( 'pilot_id' => 2, 'node_index' => 3 ),
        ) ),
        $heat_keys
    );
    return rm_test_event( 60, array( $heat ), array( $elimination ) );
};
$channels = function ( $event ) {
    $out = array();
    foreach ( (array) rm_getUpcomingRacePilots( $event ) as $row ) {
        $out[] = $row['pilot_id'] . '@' . $row['slot_id'] . '=' . $row['channel'];
    }
    return implode( ',', $out );
};
$got = $channels( $seated_event( array( 'auto_frequency' => false, 'status' => 0, 'locked' => false ) ) );
rm_test_check( 'fixed seats: the node\'s channel, not that of the place in the list', '1@1=R2,2@3=F4' === $got, "got $got" );
$got = $channels( $seated_event( array( 'auto_frequency' => true, 'status' => 0, 'locked' => false ) ) );
rm_test_check( 'a generated heat not called yet: no seat, no channel', '1@0=,2@0=' === $got, "got $got" );
$got = $channels( $seated_event( array( 'auto_frequency' => true, 'status' => 2, 'locked' => false ) ) );
rm_test_check( 'its plan confirmed: the channels', '1@1=R2,2@3=F4' === $got, "got $got" );
$got = $channels( $seated_event( array( 'auto_frequency' => true, 'status' => 0, 'locked' => true ) ) );
rm_test_check( 'flown: the channels it was flown on', '1@1=R2,2@3=F4' === $got, "got $got" );
$event = $seated_event( array( 'auto_frequency' => false ) );
$event['frequency_data']['fdata'][1] = array( 'band' => 'R', 'channel' => 2, 'frequency' => 5695 );
$event['frequency_data']['fdata'][3] = array( 'band' => null, 'channel' => null, 'frequency' => 5705 );
$got = $channels( $event );
rm_test_check( 'a frequency no band names: the frequency', '1@1=R2,2@3=5705' === $got, "got $got" );
// Frequency 0 is a node switched off, whatever it says besides (RotorHazard shows "—" for it).
$event['frequency_data']['fdata'][1] = array( 'band' => 'R', 'channel' => 2, 'frequency' => 0 );
$event['frequency_data']['fdata'][3] = array( 'band' => null, 'channel' => null, 'frequency' => 0 );
$got = $channels( $event );
rm_test_check( 'a node switched off: no channel', '1@1=,2@3=' === $got, "got $got" );

rm_test_section( 'The likely channel, before the seats are fixed' );
// Heat 71, generated and not called yet: pilots 1, 2 and 3 in the plan's order. What they flew
// before comes from the rounds of other heats; the profile is R1, R2, F2, F4.
$round = function ( $time, $seats ) {
    $nodes = array();
    foreach ( $seats as $pilot_id => $node_index ) {
        $nodes[] = array( 'pilot_id' => $pilot_id, 'callsign' => 'P' . $pilot_id, 'node_index' => $node_index );
    }
    return array( 'start_time_formatted' => '2025-06-01 ' . $time, 'nodes' => $nodes );
};
$likely_event = function ( $rounds, $heat_keys = array(), $slots = null ) use ( $elimination ) {
    $slots = $slots ?? array( array( 'pilot_id' => 1 ), array( 'pilot_id' => 2 ), array( 'pilot_id' => 3 ) );
    $heat  = array_merge( rm_test_heat( 71, 3, 0, $slots ), array( 'auto_frequency' => true, 'status' => 0, 'locked' => false ), $heat_keys );
    $heats = array();
    foreach ( $rounds as $heat_id => $heat_rounds ) {
        $heats[ (string) $heat_id ] = array( 'heat_id' => $heat_id, 'rounds' => $heat_rounds );
    }
    return rm_test_event( 71, array( $heat ), array( $elimination ), array( 'heats' => $heats ) );
};
$likely = function ( $event ) {
    $out = array();
    foreach ( (array) rm_getUpcomingRacePilots( $event ) as $row ) {
        $out[] = $row['pilot_id'] . '=' . $row['channel'] . '/' . ( $row['likely'] ?? 'none' );
    }
    return implode( ',', $out );
};

$apart = array( 60 => array( $round( '10:00:00.000', array( 1 => 2, 2 => 0, 3 => 3 ) ) ) );
$got   = $likely( $likely_event( $apart ) );
rm_test_check( 'each on a seat of their own before: that seat, as likely', '1=/F2,2=/R1,3=/F4' === $got, "got $got" );
$got = $likely( $likely_event( $apart, array( 'status' => 2 ) ) );
rm_test_check( 'the plan confirmed: the channel, nothing likely', '1=R1/,2=R2/,3=F2/' === $got, "got $got" );

// Pilots 1 and 2 come from the same seat: RotorHazard draws lots between them.
$got = $likely( $likely_event( array( 60 => array( $round( '10:00:00.000', array( 1 => 1, 3 => 3 ) ), $round( '10:05:00.000', array( 2 => 1 ) ) ) ) ) );
rm_test_check( 'two from the same seat: none for them, the third still told', '1=/,2=/,3=/F4' === $got, "got $got" );

// Pilot 1 flew on seats 1 and 0, pilot 2 on 0 and 2, pilot 3 on 2. Seat 1 has only pilot 1, so it
// is theirs first, though nobody else came from seat 0 last; then seat 0 has only pilot 2, and seat 2
// only pilot 3.
$rounds = array(
    60 => array( $round( '11:00:00.000', array( 2 => 2 ) ) ),
    61 => array( $round( '10:00:00.000', array( 1 => 1, 2 => 0 ) ), $round( '10:30:00.000', array( 1 => 0, 3 => 2 ) ) ),
);
$got = $likely( $likely_event( $rounds ) );
rm_test_check( 'a seat only one of them flew on is theirs first, as RotorHazard gives them out', '1=/R2,2=/R1,3=/F2' === $got, "got $got" );
// Asked only where it exists, so that against a version without it the checks after these still run.
$used_seats = function ( $event ) {
    return function_exists( 'rm_used_seats' ) ? rm_used_seats( $event ) : null;
};
$used = $used_seats( $likely_event( $rounds ) );
rm_test_check( 'the seats flown on go by start time, not by where the rounds are listed', array( 1 => array( 1, 0 ), 2 => array( 0, 2 ), 3 => array( 2 ) ) === $used, var_export( $used, true ) );
$used = $used_seats( $likely_event( array( 60 => array( $round( '09:00:00.000', array( 1 => 0 ) ), $round( '09:10:00.000', array( 1 => 1 ) ), $round( '09:20:00.000', array( 1 => 0 ) ) ) ) ) );
rm_test_check( 'a seat flown on again counts once, as the last', array( 1 => array( 1, 0 ) ) === $used, var_export( $used, true ) );

$got = $likely( $likely_event( $apart, array(), array( array( 'pilot_id' => 1 ), array( 'pilot_id' => 5 ) ) ) );
rm_test_check( 'a pilot who has not flown yet: none for them', '1=/F2,5=/' === $got, "got $got" );
$seeded = array( array( 'pilot_id' => 1 ), array( 'pilot_id' => 2 ), array( 'method' => 1, 'seed_id' => 60, 'seed_rank' => 3 ) );
$got    = $likely( $likely_event( $apart, array(), $seeded ) );
rm_test_check( 'a seed from a heat not flown yet: none for anyone of the heat', '1=/,2=/' === $got, "got $got" );
// Heat 60 has flown with two pilots: its third rank brings nobody, as FAI 32 round 1 seeds do in an
// event of 24.
$event = $likely_event( $apart, array(), $seeded );
$event['result_data']['heats']['60']['leaderboard'] = array(
    'by_race_time' => array( rm_test_entry( 1, 1 ), rm_test_entry( 2, 2 ) ),
    'meta'         => array( 'primary_leaderboard' => 'by_race_time' ),
);
$got = $likely( $event );
rm_test_check( 'a seed that brings nobody: no pilot, the others told', '1=/F2,2=/R1' === $got, "got $got" );
$seeded[2] = array( 'method' => 2, 'seed_id' => 2, 'seed_rank' => 3 );
$event     = $likely_event( $apart, array(), $seeded );
$got       = $likely( $event );
rm_test_check( 'a seed from a class without a result: none for anyone', '1=/,2=/' === $got, "got $got" );
$event['result_data']['classes'] = array( '2' => array( 'id' => 2, 'ranking' => false, 'leaderboard' => array(
    'by_race_time' => array( rm_test_entry( 1, 1 ), rm_test_entry( 2, 2 ) ),
    'meta'         => array( 'primary_leaderboard' => 'by_race_time' ),
) ) );
$got = $likely( $event );
rm_test_check( 'a class seed beyond its result: no pilot, the others told', '1=/F2,2=/R1' === $got, "got $got" );
$event = $likely_event( $apart );
$event['frequency_data']['fdata'][3] = array( 'band' => 'F', 'channel' => 4, 'frequency' => 0 );
$got = $likely( $event );
rm_test_check( 'the seat a pilot came from switched off: none for them', '1=/F2,2=/R1,3=/' === $got, "got $got" );

rm_test_section( "RotorHazard's own frequencies, where the upload carries them (1.16.0)" );
// The profile with its frequencies, and each pilot's used_frequencies as the connector sends them:
// every pilot carries the list, empty for one who has not flown. The rounds still say $apart:
// pilot 1 on F2, pilot 2 on R1, pilot 3 on F4.
$with_frequencies = function ( $event, $lists ) {
    $event['frequency_data']['fdata'] = array(
        array( 'band' => 'R', 'channel' => 1, 'frequency' => 5658 ),
        array( 'band' => 'R', 'channel' => 2, 'frequency' => 5695 ),
        array( 'band' => 'F', 'channel' => 2, 'frequency' => 5760 ),
        array( 'band' => 'F', 'channel' => 4, 'frequency' => 5800 ),
    );
    foreach ( $event['pilot_data']['pilots'] as $i => $pilot ) {
        $event['pilot_data']['pilots'][ $i ]['used_frequencies'] = array_map(
            fn( $f ) => array( 'b' => null, 'c' => null, 'f' => $f ),
            $lists[ $pilot['pilot_id'] ] ?? array()
        );
    }
    return $event;
};
$got = $likely( $with_frequencies( $likely_event( $apart ), array( 1 => array( 5695 ), 2 => array( 5658 ), 3 => array( 5800 ) ) ) );
rm_test_check( 'the frequency RotorHazard has for the pilot, not the seat of the rounds', '1=/R2,2=/R1,3=/F4' === $got, "got $got" );
$got = $likely( $with_frequencies( $likely_event( $apart ), array( 1 => array( 5769 ), 2 => array( 5658 ), 3 => array( 5800 ) ) ) );
rm_test_check( 'a frequency the profile does not have: none for that pilot', '1=/,2=/R1,3=/F4' === $got, "got $got" );
$got = $likely( $with_frequencies( $likely_event( $apart ), array( 1 => array( 5695 ), 2 => array( 5658 ) ) ) );
rm_test_check( 'an empty list, whatever the rounds say: none for that pilot', '1=/R2,2=/R1,3=/' === $got, "got $got" );
// Pilot 1 flew R2, then R1; pilot 2 R1. R1 has two priority matches, R2 pilot 1 alone: R2 is theirs.
$got = $likely( $with_frequencies( $likely_event( $apart ), array( 1 => array( 5695, 5658 ), 2 => array( 5658 ) ) ) );
rm_test_check( 'a frequency only one of them flew is theirs first, matched by frequency', '1=/R2,2=/R1,3=/' === $got, "got $got" );

rm_test_finish();

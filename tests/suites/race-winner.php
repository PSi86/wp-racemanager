<?php
/**
 * Who won a race (1.12.0): rm_race_winners() on the race's data, what it keeps in _race_winner, the
 * races stored before, and the race-winner block.
 *
 * What has to hold:
 *   - the final of every regulation bracket and ladder RotorHazard ships is the heat the winner
 *     comes from - a single elimination's Final, not its Small Final, which no heat seeds from either;
 *     a ranked fill, whose mains are all seeded from the class above, has none;
 *   - the winner is the first of the final's result, and only once the final has one with a place 1;
 *   - a class ranked with "Brackets" wins by its ranking's place 1; with Chase the Ace, nobody wins
 *     before that, whatever the final's rounds say; switched off, the final decides;
 *   - a class whose heats do not seed each other has no winner; several bracket classes, one each,
 *     in the timer's class order;
 *   - kept in the race's meta, the page cache emptied when it changes and only then;
 *   - the races stored before get theirs from their data file, a batch at a time, then no more;
 *   - the block shows the cup, the photo or the initials, the flag and the callsign, the class when
 *     there are several, and nothing without a winner;
 *   - on the real events of the local site, a winner each: the first of the Grand Final.
 */

require_once __DIR__ . '/../bootstrap.php';

$GLOBALS['rm_meta'] = array();
function get_post_meta( $id, $key = '', $single = false ) {
    return $GLOBALS['rm_meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
    $GLOBALS['rm_meta'][ $id ][ $key ] = $value;
    return true;
}
$GLOBALS['rm_uploads'] = sys_get_temp_dir() . '/rm-test-race-winner-' . getmypid();
@mkdir( $GLOBALS['rm_uploads'] . '/races', 0777, true );
function wp_upload_dir() {
    return array( 'basedir' => $GLOBALS['rm_uploads'], 'baseurl' => 'https://example.test/wp-content/uploads', 'error' => false );
}
function get_block_wrapper_attributes( $extra = array() ) {
    return 'class="wp-block-wp-racemanager-race-winner ' . ( $extra['class'] ?? '' ) . '"';
}
class WP_Block {
    public $context = array();
}
// get_posts() as core runs the backfill's query: the races without the meta, a batch, by ID.
function get_posts( $args = array() ) {
    $ids = array();
    foreach ( $GLOBALS['rm_posts'] ?? array() as $post ) {
        if ( ( $args['post_type'] ?? '' ) === $post->post_type && ! isset( $GLOBALS['rm_meta'][ $post->ID ][ RM_RACE_WINNER_META ] ) ) {
            $ids[] = $post->ID;
        }
    }
    sort( $ids );
    return array_slice( $ids, 0, $args['posts_per_page'] ?? 5 );
}

$GLOBALS['rm_options'] = array();
require_once RM_TEST_DIR . '/stubs/wordpress.php';
require_once RM_PLUGIN_DIR . '/includes/race-status.php';
require_once RM_PLUGIN_DIR . '/includes/race-data-functions.php';
require_once RM_PLUGIN_DIR . '/includes/race-winner.php';
require_once RM_PLUGIN_DIR . '/includes/block-render-race-winner.php';

const RM_TEST_QUALIFYING = 2;
const RM_TEST_BRACKET    = 3;

/** A plan of RotorHazard's (tests/fixtures/brackets/plans.json) as heats of a class, ids from $first. */
function rm_test_plan_heats( $plan, $class_id = RM_TEST_BRACKET, $first = 10 ) {
    $ids   = range( $first, $first + count( $plan['heats'] ) - 1 );
    $heats = array();
    foreach ( $plan['heats'] as $i => $h ) {
        $slots = array();
        foreach ( $h['slots'] as $n => $s ) {
            $input   = 'INPUT' === $s['method'];
            $index   = $input ? 0 : (int) $s['seed_index'];
            $slots[] = array(
                'node_index' => $n,
                'pilot_id'   => null,
                'method'     => $input ? 2 : 1,
                'seed_rank'  => $s['seed_rank'],
                'seed_id'    => $input ? RM_TEST_QUALIFYING : $ids[ $index < 0 ? count( $ids ) + $index : $index ],
            );
        }
        $heats[] = array( 'id' => $ids[ $i ], 'displayname' => $h['name'], 'class_id' => $class_id, 'order' => null, 'slots' => $slots );
    }
    return $heats;
}

/** A heat's result: pilots in finishing order, those in $dns without a place. */
function rm_test_result( $heat_id, $order, $dns = array() ) {
    $entries = array();
    $place   = 0;
    foreach ( $order as $pilot_id ) {
        $entries[] = array( 'pilot_id' => $pilot_id, 'callsign' => 'P' . $pilot_id, 'position' => in_array( $pilot_id, $dns, true ) ? null : ++$place );
    }
    return array( 'heat_id' => $heat_id, 'leaderboard' => array( 'by_race_time' => $entries, 'meta' => array( 'primary_leaderboard' => 'by_race_time' ) ) );
}

/** An event around the heats: 16 pilots with keys, a Qualifying class and the given classes. */
function rm_test_event( $heats, $classes, $results = array(), $rankings = array() ) {
    $pilots = array();
    for ( $p = 1; $p <= 16; $p++ ) {
        $pilots[] = array( 'pilot_id' => $p, 'callsign' => 'P' . $p, 'pilot_key' => sprintf( 'aaaaaaaa-0000-5000-8000-%012d', $p ) );
    }
    $result_classes = array();
    foreach ( $rankings as $class_id => $ranking ) {
        $result_classes[ (string) $class_id ] = array( 'id' => $class_id, 'ranking' => $ranking );
    }
    return array(
        'pilot_data'  => array( 'pilots' => $pilots ),
        'class_data'  => array( 'classes' => array_merge( array( array( 'id' => RM_TEST_QUALIFYING, 'name' => 'Qualifying', 'order' => 1 ) ), $classes ) ),
        'heat_data'   => array( 'heats' => $heats ),
        'result_data' => array( 'heats' => $results, 'classes' => $result_classes ),
    );
}

$plans = json_decode( file_get_contents( RM_PLUGIN_DIR . '/tests/fixtures/brackets/plans.json' ), true )['plans'];
$elimination = array( 'id' => RM_TEST_BRACKET, 'name' => 'Elimination', 'order' => 2 );

rm_test_section( 'The final of every plan RotorHazard ships' );
$expected = array(
    'single-fai16' => 'Final', 'single-fai32' => 'Final', 'single-fai64' => 'Final',
    'double-fai16' => 'Final', 'double-fai32' => 'Final', 'double-fai64' => 'Final',
    'double-multigp16' => 'Race 14: Winners Bracket Final',
    'ladder-7' => 'A Main', 'ladder-12' => 'A Main',
    // A ranked fill seeds every main from the class above, none from another: no bracket, as
    // rm-m-bracketModel.js has it too, and so no winner line.
    'ranked-fill-7' => null, 'ranked-fill-12' => null,
);
foreach ( $expected as $key => $name ) {
    $heats = rm_test_plan_heats( $plans[ $key ] );
    $final = rm_race_final_heat( $heats, RM_TEST_BRACKET );
    $got   = null;
    foreach ( $heats as $heat ) {
        if ( $heat['id'] === $final ) {
            $got = $heat['displayname'];
        }
    }
    rm_test_check( null === $name ? "$key: none" : "$key: \"$name\"", $name === $got, var_export( $got, true ) );
}
// The Small Final comes before the Final in RotorHazard's plans; turned round, it still is not it.
$heats = array_reverse( rm_test_plan_heats( $plans['single-fai16'] ) );
foreach ( $heats as $i => &$heat ) {
    $heat['order'] = $i;
}
unset( $heat );
$final = rm_race_final_heat( $heats, RM_TEST_BRACKET );
rm_test_check( 'the small final is not the final, in whatever order the timer lists them',
    'Final' === current( array_filter( $heats, fn( $h ) => $h['id'] === $final ) )['displayname'] );
rm_test_check( 'a class whose heats do not seed each other has none',
    null === rm_race_final_heat( array( array( 'id' => 1, 'class_id' => 1, 'slots' => array( array( 'method' => 0, 'pilot_id' => 1 ) ) ) ), 1 ) );

rm_test_section( 'The winner' );
$heats    = rm_test_plan_heats( $plans['double-fai16'] );
$final_id = rm_race_final_heat( $heats, RM_TEST_BRACKET );
$flown    = array( (string) $final_id => rm_test_result( $final_id, array( 7, 3, 12, 1 ) ) );
$winners  = rm_race_winners( rm_test_event( $heats, array( $elimination ), $flown ) );
rm_test_check( 'the first of the final', 1 === count( $winners ) && 7 === $winners[0]['pilot_id'] && 'P7' === $winners[0]['callsign'], wp_json_encode( $winners ) );
rm_test_check( 'with the class, and the pilot key the timer sent',
    RM_TEST_BRACKET === $winners[0]['class_id'] && 'Elimination' === $winners[0]['class_name'] && 'aaaaaaaa-0000-5000-8000-000000000007' === $winners[0]['pilot_key'] );
rm_test_check( 'nobody before the final has a result', array() === rm_race_winners( rm_test_event( $heats, array( $elimination ) ) ) );
rm_test_check( 'nor when its first has no place',
    array() === rm_race_winners( rm_test_event( $heats, array( $elimination ), array( (string) $final_id => rm_test_result( $final_id, array( 7, 3 ), array( 7, 3 ) ) ) ) ) );

$brackets = array_merge( $elimination, array( 'win_condition' => 'Brackets', 'ranksettings' => null ) );
$ranking  = array( 'ranking' => array( array( 'pilot_id' => 3, 'callsign' => 'P3', 'position' => 1 ), array( 'pilot_id' => 7, 'callsign' => 'P7', 'position' => 2 ) ) );
$winners  = rm_race_winners( rm_test_event( $heats, array( $brackets ), $flown, array( RM_TEST_BRACKET => $ranking ) ) );
rm_test_check( 'ranked with "Brackets": its place 1, whatever the final\'s first round says', 3 === ( $winners[0]['pilot_id'] ?? null ), wp_json_encode( $winners ) );
rm_test_check( 'Chase the Ace not decided yet: nobody', array() === rm_race_winners( rm_test_event( $heats, array( $brackets ), $flown, array( RM_TEST_BRACKET => array( 'ranking' => array() ) ) ) ) );
rm_test_check( 'Chase the Ace without a ranking at all: nobody', array() === rm_race_winners( rm_test_event( $heats, array( $brackets ), $flown ) ) );
$no_cta  = array_merge( $brackets, array( 'ranksettings' => array( 'chase_the_ace' => false ) ) );
$winners = rm_race_winners( rm_test_event( $heats, array( $no_cta ), $flown ) );
rm_test_check( 'Chase the Ace switched off, no ranking: the final decides', 7 === ( $winners[0]['pilot_id'] ?? null ) );

// Two brackets, the second listed first but ordered after.
$pro      = array( 'id' => 4, 'name' => 'Pro', 'displayname' => 'Pro', 'order' => 3 );
$heats2   = array_merge( rm_test_plan_heats( $plans['single-fai16'], 4, 50 ), $heats );
$final2   = rm_race_final_heat( $heats2, 4 );
$results2 = $flown + array( (string) $final2 => rm_test_result( $final2, array( 12, 4 ) ) );
$winners  = rm_race_winners( rm_test_event( $heats2, array( $pro, $elimination ), $results2 ) );
rm_test_check( 'a winner per bracket class, in the timer\'s order', array( 7, 12 ) === array_column( $winners, 'pilot_id' ) && array( 'Elimination', 'Pro' ) === array_column( $winners, 'class_name' ), wp_json_encode( $winners ) );
rm_test_check( 'a payload that is none has none', array() === rm_race_winners( null ) && array() === rm_race_winners( array() ) );

rm_test_section( 'Kept with the race' );
rm_test_post( 42, 'race', 'autumn-cup' );
$event = rm_test_event( $heats, array( $elimination ), $flown );
$GLOBALS['rm_actions_fired'] = array();
$purges = fn() => count( array_filter( $GLOBALS['rm_actions_fired'], fn( $a ) => 'litespeed_purge' === $a[0] ) );
rm_test_check( 'stored, and the page cache emptied', rm_store_race_winners( 42, $event ) && 7 === rm_get_race_winners( 42 )[0]['pilot_id'] && 1 === $purges() );
rm_test_check( 'the same again: nothing changes, nothing emptied', ! rm_store_race_winners( 42, $event ) && 1 === $purges() );
rm_store_race_winners( 42, rm_test_event( $heats, array( $elimination ) ) );
rm_test_check( 'no winner any more: kept as none, emptied again', array() === rm_get_race_winners( 42 ) && array() === get_post_meta( 42, RM_RACE_WINNER_META, true ) && 2 === $purges() );

rm_test_section( 'The races stored before' );
$GLOBALS['rm_posts'] = array();
$GLOBALS['rm_meta']  = array();
for ( $id = 101; $id <= 107; $id++ ) {
    rm_test_post( $id, 'race', 'race-' . $id );
    file_put_contents( $GLOBALS['rm_uploads'] . "/races/$id-data.json", wp_json_encode( $id % 2 ? $event : array() ) );
}
rm_test_check( 'a batch at a time', RM_RACE_WINNER_BATCH === rm_race_winner_backfill() && ! get_option( RM_RACE_WINNER_SCHEMA_OPTION ) );
rm_test_check( 'the rest, and then it is done', 7 - RM_RACE_WINNER_BATCH === rm_race_winner_backfill() && RM_RACE_WINNER_SCHEMA === get_option( RM_RACE_WINNER_SCHEMA_OPTION ) );
rm_test_check( 'each from its data file', 7 === rm_get_race_winners( 101 )[0]['pilot_id'] && array() === rm_get_race_winners( 102 ) );
rm_test_check( 'done, it reads no more', 0 === rm_race_winner_backfill() );

rm_test_section( 'The block' );
$block                      = new WP_Block();
$block->context['postId']   = 42;
rm_test_post( 42, 'race', 'autumn-cup' );
$GLOBALS['rm_meta'][42][ RM_RACE_WINNER_META ] = array(
    array( 'class_id' => 3, 'class_name' => 'Elimination', 'pilot_id' => 7, 'pilot_key' => 'aaaaaaaa-0000-5000-8000-000000000007', 'callsign' => 'Max <Dax>' ),
);
$GLOBALS['rm_options'][ RM_PILOT_PROFILES_OPTION ] = array(
    'aaaaaaaa-0000-5000-8000-000000000007' => array( 'country' => 'AT', 'photo' => '0a1b2c3d' ),
);
$html = rm_render_race_winner_block( array(), '', $block );
rm_test_check( 'the callsign, escaped', str_contains( $html, 'Max &lt;Dax&gt;' ) && ! str_contains( $html, '<Dax>' ), $html );
rm_test_check( 'the photo, with its version', str_contains( $html, 'rm-pilots/aaaaaaaa-0000-5000-8000-000000000007.jpg?v=0a1b2c3d' ) );
rm_test_check( 'over the initials', str_contains( $html, '>MD<img' ) );
rm_test_check( 'the flag', str_contains( $html, 'flag-icons-7.5.0/flags/4x3/at.svg' ) );
rm_test_check( 'no class name for a single winner', ! str_contains( $html, 'rm-race-winner-class' ) );
$GLOBALS['rm_options'][ RM_PILOT_PROFILES_OPTION ] = array();
$html = rm_render_race_winner_block( array(), '', $block );
rm_test_check( 'a pilot without a profile: initials and callsign only', str_contains( $html, '>MD</span>' ) && ! str_contains( $html, '<img' ), $html );
$GLOBALS['rm_meta'][42][ RM_RACE_WINNER_META ][] = array( 'class_id' => 4, 'class_name' => 'Pro', 'pilot_id' => 12, 'pilot_key' => '', 'callsign' => 'P12' );
$html = rm_render_race_winner_block( array(), '', $block );
rm_test_check( 'several: one line each, with the class', 2 === substr_count( $html, 'rm-race-winner-line' ) && str_contains( $html, '>Pro<' ) && str_contains( $html, '>Elimination<' ) );
$GLOBALS['rm_meta'][42][ RM_RACE_WINNER_META ] = array();
rm_test_check( 'no winner, nothing at all', '' === rm_render_race_winner_block( array(), '', $block ) );
rm_test_post( 43, 'page', 'about' );
$block->context['postId'] = 43;
rm_test_check( 'and nothing for a post that is no race', '' === rm_render_race_winner_block( array(), '', $block ) );

rm_test_section( 'The races from production, where the local site has them' );
$real = glob( dirname( RM_PLUGIN_DIR ) . '/wp-app/wp-content/uploads/races/{32,33,34}-data.json', GLOB_BRACE ) ?: array();
foreach ( $real as $file ) {
    $data    = json_decode( file_get_contents( $file ), true );
    $winners = rm_race_winners( $data );
    $grand   = null;
    foreach ( (array) ( $data['heat_data']['heats'] ?? array() ) as $heat ) {
        if ( ! empty( $winners ) && $heat['id'] === rm_race_final_heat( $data['heat_data']['heats'], $winners[0]['class_id'] ) ) {
            $grand = $heat['displayname'] ?? '';
        }
    }
    $first = ! empty( $winners ) ? rm_heat_primary_entries( $data, rm_race_final_heat( $data['heat_data']['heats'], $winners[0]['class_id'] ) )[0]['pilot_id'] ?? null : null;
    rm_test_check( basename( $file ) . ': one winner, the first of the final (' . $grand . ')',
        1 === count( $winners ) && $first === $winners[0]['pilot_id'], count( $winners ) . ' winners' );
}
if ( ! $real ) {
    rm_test_check( 'no real events here - nothing to compare', true );
}

// Clean up.
foreach ( glob( $GLOBALS['rm_uploads'] . '/races/*' ) ?: array() as $file ) {
    @unlink( $file );
}
@rmdir( $GLOBALS['rm_uploads'] . '/races' );
@rmdir( $GLOBALS['rm_uploads'] );

rm_test_finish();

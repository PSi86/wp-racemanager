<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/race-results.php'; // a heat's result, a class's ranking, Chase the Ace

// How a RotorHazard heat slot gets its pilot (Database.ProgramMethod): -1 none, 0 assigned,
// 1 from a heat's result (seed_id = heat), 2 from a class's result (seed_id = class).
if (!defined('RM_SLOT_HEAT_RESULT')) define('RM_SLOT_HEAT_RESULT', 1);
if (!defined('RM_SLOT_CLASS_RESULT')) define('RM_SLOT_CLASS_RESULT', 2);

/**
 * Absolute path of the directory holding the per-race JSON files.
 *
 * Derived from wp_upload_dir() rather than WP_CONTENT_DIR so that a custom UPLOADS
 * constant or a filtered upload path is honoured, and created on demand: nothing
 * else ever created it, so the very first upload on a fresh install failed.
 *
 * @param bool $create Whether to create the directory if it is missing.
 * @return string|WP_Error Trailing-slashed path, or WP_Error if it cannot be created.
 */
function rm_get_race_data_dir( $create = true ) {
    $upload_dir = wp_upload_dir();

    if ( ! empty( $upload_dir['error'] ) ) {
        return new WP_Error(
            'upload_dir_unavailable',
            sprintf( 'Uploads directory is not available: %s', $upload_dir['error'] ),
            array( 'status' => 500 )
        );
    }

    $path = trailingslashit( $upload_dir['basedir'] ) . 'races/';

    if ( $create && ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
        return new WP_Error(
            'upload_dir_not_writable',
            sprintf( 'Could not create the race data directory: %s', $path ),
            array( 'status' => 500 )
        );
    }

    return $path;
}

/**
 * Public URL of the directory holding the per-race JSON files.
 *
 * @return string Trailing-slashed URL.
 */
function rm_get_race_data_url() {
    $upload_dir = wp_upload_dir();
    return trailingslashit( $upload_dir['baseurl'] ) . 'races/';
}

/**
 * Returns an array of pilots scheduled to race in the next heats (current + 3).
 * Uses "double-run" scheduling for Training/Qualifying:
 *   heat A twice, heat B twice, ... last heat twice, then wrap, until each heat reached class rounds.
 * Elimination (and other classes) stay sequential (no wrap).
 *
 * Each entry:
 *   - heat_id, heat_displayname, pilot_id, callsign, slot_id, channel, likely
 *   - slot_id is the pilot's seat (the slot's node) and channel its name ("R1") once the heat's seats
 *     are fixed (rm_heat_seats_fixed()); before that 0 and '' (1.13.0);
 *   - likely, while they are not, the channel RotorHazard will likely give the pilot
 *     (rm_likely_channels()), '' where it cannot tell (1.15.0).
 */
function rm_getUpcomingRacePilots($rhData) {
    if (!$rhData
        || !isset($rhData['current_heat']['current_heat'])
        || !isset($rhData['heat_data']['heats'])
        || !isset($rhData['pilot_data']['pilots'])
        || !isset($rhData['class_data']['classes'])
    ) {
        error_log("rm_getUpcomingRacePilots: Missing required data in rhData");
        return null;
    }

    // pilot_id => callsign map (avoid repeated scans)
    $pilotCallsignById = array();
    foreach ($rhData['pilot_data']['pilots'] as $p) {
        if (!isset($p['pilot_id'])) continue;
        $pilotCallsignById[(int)$p['pilot_id']] = isset($p['callsign']) ? (string)$p['callsign'] : '';
    }

    // Build heat indexes
    $heatsById = array();         // heat_id => heat array
    $classHeats = array();        // class_id => [heat_id, heat_id, ...] ordered
    foreach ($rhData['heat_data']['heats'] as $h) {
        if (!isset($h['id'])) continue;
        $hid = (int)$h['id'];
        $heatsById[$hid] = $h;

        $cid = isset($h['class_id']) ? (int)$h['class_id'] : 0;
        if ($cid > 0) {
            if (!isset($classHeats[$cid])) $classHeats[$cid] = array();
            $classHeats[$cid][] = $hid;
        }
    }
    foreach ($classHeats as $cid => $list) {
        sort($list, SORT_NUMERIC);
        $classHeats[$cid] = $list;
    }

    // Class metadata: order + rounds + double-run flag for Training/Qualifying
    $classes = $rhData['class_data']['classes'];
    usort($classes, function($a, $b) {
        $oa = isset($a['order']) ? $a['order'] : null;
        $ob = isset($b['order']) ? $b['order'] : null;
        if (is_numeric($oa) && is_numeric($ob)) {
            return (int)$oa <=> (int)$ob;
        }
        $ia = isset($a['id']) ? (int)$a['id'] : 0;
        $ib = isset($b['id']) ? (int)$b['id'] : 0;
        return $ia <=> $ib;
    });

    $classOrder = array();        // [class_id,...]
    $classRounds = array();       // class_id => rounds
    $doubleRunClass = array();    // class_id => true (Training/Qualifying)
    foreach ($classes as $c) {
        if (!isset($c['id'])) continue;
        $cid = (int)$c['id'];
        $classOrder[] = $cid;
        $classRounds[$cid] = isset($c['rounds']) ? (int)$c['rounds'] : 1;

        $name = strtolower((string)($c['name'] ?? $c['displayname'] ?? ''));
        if ($name === 'training' || $name === 'qualifying') {
            $doubleRunClass[$cid] = true;
        }
    }

    // A Chase the Ace final is flown in rounds until the timer's ranking names a winner, whatever
    // the class's number of rounds says: until then it stays the heat to come.
    foreach (rm_open_cta_finals($rhData, $classHeats) as $finalId) {
        if (isset($heatsById[$finalId])) {
            $heatsById[$finalId]['next_round'] = 0;
        }
    }

    $currentHeatId = (int)$rhData['current_heat']['current_heat'];

    // If current heat is already "complete", jump to the next runnable one.
    $startHeatId = rm_findNextRunnableHeatId(
        $currentHeatId, $heatsById, $classHeats, $classOrder, $classRounds, $doubleRunClass
    );
    if ($startHeatId === null) {
        return array();
    }

    // Build upcoming heat-id sequence: current + 3
    $heatIdsToCheck = array();
    $hid = $startHeatId;
    $maxHeats = 4; // keep existing behavior: current + next 3
    for ($i = 0; $i < $maxHeats && $hid !== null; $i++) {
        $heatIdsToCheck[] = $hid;
        $hid = rm_getNextHeatId($hid, $heatsById, $classHeats, $classOrder, $classRounds, $doubleRunClass);
    }

    // Collect pilots, dedupe by (heat_id, pilot_id) (prevents double notifications if same heat appears twice)
    $upcomingPilots = array();
    $seen = array();
    $usedSeats = rm_used_seats($rhData);

    foreach ($heatIdsToCheck as $heatId) {
        if (!isset($heatsById[$heatId])) continue;
        $heat = $heatsById[$heatId];

        $heatDisplayname = isset($heat['displayname']) ? (string)$heat['displayname'] : ('Heat ' . $heatId);
        if (!isset($heat['slots']) || !is_array($heat['slots'])) continue;
        $seatsFixed = rm_heat_seats_fixed($heat);
        $heatPilots = array();
        $pilotsKnown = true; // false once a seed names nobody yet

        foreach ($heat['slots'] as $slot) {
            $pilotId = isset($slot['pilot_id']) ? (int)$slot['pilot_id'] : 0;
            $callsign = $pilotId ? ($pilotCallsignById[$pilotId] ?? '') : '';

            // If not assigned, resolve the seed as RotorHazard will when the heat comes up: from a
            // heat's result (method 1) or from a class's result (method 2). seed_id names a heat
            // in the first case and a class in the second.
            if (!$pilotId && isset($slot['seed_id'], $slot['seed_rank'])) {
                $seedId   = (int)$slot['seed_id'];
                $seedRank = (int)$slot['seed_rank'];
                $method   = isset($slot['method']) ? (int)$slot['method'] : 0;
                $seededPilot = null;
                if ($method === RM_SLOT_HEAT_RESULT) {
                    $seededPilot = rm_getSeededPilot($seedId, $seedRank, $rhData, $heatsById);
                } elseif ($method === RM_SLOT_CLASS_RESULT) {
                    $seededPilot = rm_getClassSeededPilot($seedId, $seedRank, $rhData);
                }
                if ($seededPilot !== null) {
                    $pilotId = (int)$seededPilot['pilot_id'];
                    $callsign = (string)($seededPilot['callsign'] ?? '');
                } elseif (($method === RM_SLOT_HEAT_RESULT || $method === RM_SLOT_CLASS_RESULT)
                    && !rm_seed_source_has_result($method, $seedId, $rhData, $heatsById)) {
                    // Somebody may still come; from a result without that rank, nobody will.
                    $pilotsKnown = false;
                }
            }

            if (!$pilotId) continue;

            $key = $heatId . ':' . $pilotId;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            // The seat is the slot's node, and it tells the channel only once it is fixed. Until
            // 1.13.0 the slot's place in the list stood for the seat - the same number once the
            // seats are fixed, but not while RotorHazard gives them out, when the free slots have
            // no node and come first - and a heat's seats counted as fixed from the start.
            $seat = ($seatsFixed && isset($slot['node_index']) && is_numeric($slot['node_index'])) ? (int)$slot['node_index'] : null;

            $heatPilots[] = array(
                'heat_id'          => $heatId,
                'heat_displayname' => $heatDisplayname,
                'pilot_id'         => $pilotId,
                'callsign'         => $callsign,
                'slot_id'          => null === $seat ? 0 : $seat,
                'channel'          => null === $seat ? '' : rm_channel_label($rhData, $seat),
            );
        }

        // Seats not fixed yet: the channel each pilot will likely get. It depends on every pilot of
        // the heat, so none while a seed has not decided who is in it.
        $likely = ($seatsFixed || !$pilotsKnown) ? array() : rm_likely_channels($rhData, array_column($heatPilots, 'pilot_id'), $usedSeats);
        foreach ($heatPilots as $entry) {
            $entry['likely'] = $likely[$entry['pilot_id']] ?? '';
            $upcomingPilots[] = $entry;
        }
    }

    return $upcomingPilots;
}

/**
 * The seats each pilot has flown on, oldest first and each once, the last one last (1.15.0): from
 * the rounds of every heat, by start time. RotorHazard keeps a pilot's frequencies that way, one
 * more with every saved race (RHData.set_pilot_used_frequency), and gives out seats by them. Kept
 * by seat, since a round names the node, not the frequency: the same as long as the event keeps its
 * frequency profile.
 *
 * @param array $rhData The upload.
 * @return array pilot_id => node_index[].
 */
function rm_used_seats($rhData) {
    $rounds = array();
    foreach ((array)($rhData['result_data']['heats'] ?? array()) as $heat) {
        foreach ((array)(is_array($heat) ? ($heat['rounds'] ?? array()) : array()) as $round) {
            if (is_array($round)) {
                $rounds[] = array((string)($round['start_time_formatted'] ?? ''), (array)($round['nodes'] ?? array()));
            }
        }
    }
    usort($rounds, fn($a, $b) => strcmp($a[0], $b[0])); // "2025-01-19 10:44:29.065" sorts as it reads
    $used = array();
    foreach ($rounds as $round) {
        foreach ($round[1] as $node) {
            if (!is_array($node) || empty($node['pilot_id']) || !isset($node['node_index']) || !is_numeric($node['node_index'])) {
                continue;
            }
            $pilotId = (int)$node['pilot_id'];
            $seat    = (int)$node['node_index'];
            $seats   = array_values(array_diff($used[$pilotId] ?? array(), array($seat)));
            $seats[] = $seat;
            $used[$pilotId] = $seats;
        }
    }
    return $used;
}

/**
 * The seats RotorHazard will give a heat's pilots, as far as that is decided (1.15.0): its automatic
 * frequency assignment (heat_automation.py, run_auto_frequency) with the calibration mode's default,
 * find_best_slot_node_adaptive, followed until it would draw lots. It fills one seat at a time, in
 * node order, looking at who flew there before: a seat only one pilot flew on, as their last seat;
 * then a seat only one pilot flew on at all; then a seat that was the last of only one of them. The
 * pilot seated leaves the other seats' lists. Where none of these is left, it draws lots, and so the
 * seats it would fill from there on are not told.
 *
 * Measured on race 32 of the local site (Galaxy Cup 2025, 40 heats with automatic frequencies, first
 * rounds only) from the rounds flown before each: 82 of the 142 pilots got a seat this way, 81 of
 * them the one they flew; three heats earlier, when the first push goes out, 71 of 74. Only a
 * pilot's last seat, where nobody else of the heat had the same, told 53, 49 of them right. A timer
 * set to manual calibration gives out seats by find_best_slot_node_basic, which puts the last seats
 * first: there a seat told from the second step on can be wrong.
 *
 * @param array $rhData    The upload.
 * @param int[] $pilotIds  Every pilot of the heat.
 * @param array $usedSeats rm_used_seats().
 * @return array pilot_id => node_index, for the pilots whose seat is decided.
 */
function rm_likely_seats($rhData, $pilotIds, $usedSeats) {
    // The seats with a frequency, each with the pilots who flew there: true for their last seat.
    $open = array();
    foreach ((array)($rhData['frequency_data']['fdata'] ?? array()) as $seat => $frequency) {
        if (!is_array($frequency) || (isset($frequency['frequency']) && 0 === (int)$frequency['frequency'])) {
            continue; // switched off: RotorHazard's FREQUENCY_ID_NONE, a seat it gives nobody
        }
        $open[(int)$seat] = array();
        foreach ($pilotIds as $pilotId) {
            $used = $usedSeats[$pilotId] ?? array();
            if (in_array((int)$seat, $used, true)) {
                $open[(int)$seat][$pilotId] = end($used) === (int)$seat;
            }
        }
    }

    $likely = array();
    while ($open) {
        $pick = null;
        foreach ($open as $seat => $flown) {
            if (1 === count($flown) && reset($flown)) {
                $pick = array($seat, key($flown));
                break;
            }
        }
        if (null === $pick) {
            foreach ($open as $seat => $flown) {
                if (1 === count($flown)) {
                    $pick = array($seat, key($flown));
                    break;
                }
            }
        }
        if (null === $pick) {
            foreach ($open as $seat => $flown) {
                $last = array_keys(array_filter($flown));
                if (1 === count($last)) {
                    $pick = array($seat, $last[0]);
                    break;
                }
            }
        }
        if (null === $pick) {
            break; // lots from here on
        }
        list($seat, $pilotId) = $pick;
        $likely[$pilotId] = $seat;
        unset($open[$seat]);
        foreach ($open as $other => $flown) {
            unset($open[$other][$pilotId]);
        }
    }
    return $likely;
}

/**
 * The channel each pilot of a heat whose seats are not fixed yet will likely get (1.15.0): that of
 * the seat rm_likely_seats() tells.
 *
 * @param array $rhData    The upload.
 * @param int[] $pilotIds  Every pilot of the heat.
 * @param array $usedSeats rm_used_seats().
 * @return array pilot_id => channel, for the pilots it can tell.
 */
function rm_likely_channels($rhData, $pilotIds, $usedSeats) {
    $likely = array();
    foreach (rm_likely_seats($rhData, $pilotIds, $usedSeats) as $pilotId => $seat) {
        $label = rm_channel_label($rhData, $seat);
        if ('' !== $label) {
            $likely[$pilotId] = $label;
        }
    }
    return $likely;
}

/**
 * The pilot a slot seeded from a heat's result (method 1) will get.
 *
 * RotorHazard takes the entry at seed_rank - 1 of the heat's primary leaderboard
 * (heat_automation.py), not the entry whose position equals seed_rank: a pilot who did not start
 * is listed without a position.
 * Optional $heatsById allows early skip if seed heat has next_round <= 0 (no completed rounds).
 */
function rm_getSeededPilot($seedHeatId, $seedRank, $rhData, $heatsById = null) {
    if ($seedHeatId <= 0 || $seedRank <= 0) return null;

    // Optimization: if we know next_round is 0, there's nothing meaningful to seed yet.
    if (is_array($heatsById) && isset($heatsById[$seedHeatId])) {
        $nr = isset($heatsById[$seedHeatId]['next_round']) ? (int)$heatsById[$seedHeatId]['next_round'] : 0;
        if ($nr <= 0) return null;
    }

    $entries = rm_heat_primary_entries($rhData, $seedHeatId);
    return $entries !== null ? rm_seededEntry($entries, $seedRank) : null;
}

/**
 * The pilot a slot seeded from a class's result (method 2) will get.
 *
 * As RotorHazard does it: the class's ranking when a ranking method produced one, else the
 * class leaderboard the class's format makes primary; the entry at seed_rank - 1 of either.
 *
 * @param int   $seedClassId The class the slot seeds from.
 * @param int   $seedRank    The slot's seed_rank, 1-based.
 * @param array $rhData      The upload.
 * @return array|null        pilot_id and callsign, or null while nobody is there.
 */
function rm_getClassSeededPilot($seedClassId, $seedRank, $rhData) {
    if ($seedClassId <= 0 || $seedRank <= 0) return null;

    $positions = rm_class_seed_positions($seedClassId, $rhData);
    return is_array($positions) ? rm_seededEntry($positions, $seedRank) : null;
}

/**
 * Whether the heat or class a slot seeds from has its result (1.15.0). Then a rank the result does
 * not have brings nobody - RotorHazard leaves such a slot empty -, while before it somebody may
 * still come.
 *
 * @param int        $method    The slot's method: RM_SLOT_HEAT_RESULT or RM_SLOT_CLASS_RESULT.
 * @param int        $seedId    The heat or class it seeds from.
 * @param array      $rhData    The upload.
 * @param array|null $heatsById heat_id => heat, as rm_getSeededPilot() takes it.
 * @return bool
 */
function rm_seed_source_has_result($method, $seedId, $rhData, $heatsById = null) {
    if ($method === RM_SLOT_HEAT_RESULT) {
        if (is_array($heatsById) && isset($heatsById[$seedId]) && (int)($heatsById[$seedId]['next_round'] ?? 0) <= 0) {
            return false;
        }
        return rm_heat_primary_entries($rhData, $seedId) !== null;
    }
    if ($method === RM_SLOT_CLASS_RESULT) {
        return rm_class_seed_positions($seedId, $rhData) !== null;
    }
    return false;
}

/**
 * What a class seeds from, as RotorHazard does it: the class's ranking when a ranking method produced
 * one, else the class leaderboard the class's format makes primary.
 *
 * @param int   $seedClassId The class.
 * @param array $rhData      The upload.
 * @return array|null        Its entries in order, or null while the class has no result.
 */
function rm_class_seed_positions($seedClassId, $rhData) {
    $classes = $rhData['result_data']['classes'] ?? null;
    if (!is_array($classes)) return null;

    $resultClass = $classes[(string)$seedClassId] ?? null;
    if (!is_array($resultClass)) {
        foreach ($classes as $candidate) {
            if (is_array($candidate) && isset($candidate['id']) && (int)$candidate['id'] === $seedClassId) {
                $resultClass = $candidate;
                break;
            }
        }
    }
    if (!is_array($resultClass)) return null;

    // RotorHazard's `if ranking:` - false without a ranking method, an array with one.
    if (!empty($resultClass['ranking']) && is_array($resultClass['ranking'])) {
        $positions = $resultClass['ranking']['ranking'] ?? null;
    } else {
        $leaderboard = $resultClass['leaderboard'] ?? null;
        $primary = is_array($leaderboard) ? ($leaderboard['meta']['primary_leaderboard'] ?? 'by_race_time') : null;
        $positions = $primary !== null ? ($leaderboard[$primary] ?? null) : null;
    }

    return is_array($positions) ? $positions : null;
}

/**
 * The entry at seed_rank - 1 of a leaderboard, as pilot_id and callsign.
 *
 * @param array $entries  Leaderboard entries, in order.
 * @param int   $seedRank 1-based.
 * @return array|null
 */
function rm_seededEntry($entries, $seedRank) {
    $entries = array_values($entries);
    $entry = $entries[$seedRank - 1] ?? null;
    if (!is_array($entry) || empty($entry['pilot_id'])) return null;
    return array(
        'pilot_id' => (int)$entry['pilot_id'],
        'callsign' => isset($entry['callsign']) ? (string)$entry['callsign'] : ''
    );
}

/**
 * The Chase the Ace finals still being flown.
 *
 * On the timer, Chase the Ace is the ranking method "Brackets" of the community plugin Class
 * Rank: Brackets: the class's last heat is flown again until a pilot has won two rounds, and the
 * class's ranking then has its place 1. Its "Chase the Ace" setting is on unless switched off;
 * RotorHazard uploads a class's ranksettings only as far as someone changed them.
 *
 * @param array $rhData     The upload.
 * @param array $classHeats class_id => heat ids, ascending.
 * @return int[] Heat ids.
 */
function rm_open_cta_finals($rhData, $classHeats) {
    $open = array();
    foreach ((array)($rhData['class_data']['classes'] ?? array()) as $class) {
        if (!is_array($class) || !rm_class_chases_the_ace($class)) continue;
        $cid = (int)($class['id'] ?? 0);
        if (empty($classHeats[$cid])) continue;

        if (rm_class_ranking_first($rhData, $cid) === null) {
            $open[] = (int)end($classHeats[$cid]);
        }
    }
    return $open;
}

/* -------------------------
   Scheduling helper logic
   ------------------------- */

function rm_heatIsComplete($heatId, $heatsById, $classRounds) {
    if (!isset($heatsById[$heatId])) return false;
    $heat = $heatsById[$heatId];
    $cid = isset($heat['class_id']) ? (int)$heat['class_id'] : 0;
    $total = isset($classRounds[$cid]) ? (int)$classRounds[$cid] : 1;
    $done = isset($heat['next_round']) ? (int)$heat['next_round'] : 0;
    return $done >= $total;
}

function rm_getFirstIncompleteHeatIdInClass($classId, $heatsById, $classHeats, $classRounds) {
    if (!isset($classHeats[$classId]) || !is_array($classHeats[$classId])) return null;
    $total = isset($classRounds[$classId]) ? (int)$classRounds[$classId] : 1;

    foreach ($classHeats[$classId] as $hid) {
        if (!isset($heatsById[$hid])) continue;
        $done = isset($heatsById[$hid]['next_round']) ? (int)$heatsById[$hid]['next_round'] : 0;
        if ($done < $total) return $hid;
    }
    return null;
}

function rm_getNextClassId($currentClassId, $classOrder) {
    $idx = array_search($currentClassId, $classOrder, true);
    if ($idx === false) return null;
    return isset($classOrder[$idx + 1]) ? (int)$classOrder[$idx + 1] : null;
}

/**
 * Returns the next heat id according to:
 * - Training/Qualifying: double-run + wrap within class until all heats completed class rounds.
 * - Other classes: sequential, no wrap; when finished -> next class.
 */
function rm_getNextHeatId($currentHeatId, $heatsById, $classHeats, $classOrder, $classRounds, $doubleRunClass) {
    if (!isset($heatsById[$currentHeatId])) return null;

    $heat = $heatsById[$currentHeatId];
    $classId = isset($heat['class_id']) ? (int)$heat['class_id'] : 0;
    if ($classId <= 0 || !isset($classHeats[$classId]) || !is_array($classHeats[$classId])) return null;

    $heatsInClass = $classHeats[$classId];
    $totalRounds = isset($classRounds[$classId]) ? (int)$classRounds[$classId] : 1;

    $done = isset($heat['next_round']) ? (int)$heat['next_round'] : 0;
    $isDouble = !empty($doubleRunClass[$classId]) && $totalRounds > 1;

    // Double-run rule:
    // If there is exactly one round remaining (odd total rounds), finish current heat once more.
    if ($isDouble && $done < $totalRounds && (($totalRounds - $done) === 1)) {
        return $currentHeatId;
    }

    // If in double-run and we've done an odd number of rounds: repeat same heat (second run)
    if ($isDouble && $done < $totalRounds && (($done % 2) === 1)) {
        return $currentHeatId;
    }

    // Find current position in class heat list
    $idx = array_search($currentHeatId, $heatsInClass, true);
    if ($idx === false) $idx = -1;
    $n = count($heatsInClass);

    // Next heat selection within class
    for ($step = 1; $step <= $n; $step++) {
        $candIdx = $idx + $step;

        if ($isDouble) {
            // wrap within class
            $candId = $heatsInClass[$candIdx % $n];
        } else {
            // sequential only, no wrap
            if ($candIdx >= $n) break;
            $candId = $heatsInClass[$candIdx];
        }

        if (!isset($heatsById[$candId])) continue;
        $candDone = isset($heatsById[$candId]['next_round']) ? (int)$heatsById[$candId]['next_round'] : 0;

        // pick only heats that still have rounds left in this class
        if ($candDone < $totalRounds) {
            return (int)$candId;
        }
    }

    // No remaining heats in this class => advance to next classes until we find an incomplete heat
    $nextClassId = rm_getNextClassId($classId, $classOrder);
    while ($nextClassId !== null) {
        $first = rm_getFirstIncompleteHeatIdInClass($nextClassId, $heatsById, $classHeats, $classRounds);
        if ($first !== null) return $first;
        $nextClassId = rm_getNextClassId($nextClassId, $classOrder);
    }

    return null;
}

function rm_findNextRunnableHeatId($startHeatId, $heatsById, $classHeats, $classOrder, $classRounds, $doubleRunClass) {
    $hid = $startHeatId;
    $guard = 0;
    // One step per heat is enough to walk past every complete heat. Heat ids say nothing about how
    // many heats are left: counting from the id returned null at the last heat, and always once a
    // regenerated class numbered its heats above their count.
    $guardMax = count($heatsById) + 1;

    // Try to skip over already-complete heats (e.g. when current_heat still points to a finished one)
    while ($hid !== null && $guard < $guardMax) {
        if (!rm_heatIsComplete($hid, $heatsById, $classRounds)) return $hid;
        $hid = rm_getNextHeatId($hid, $heatsById, $classHeats, $classOrder, $classRounds, $doubleRunClass);
        $guard++;
    }

    return null;
}

/* Existing helpers kept as-is */
function rm_getPilotCallsign($pilotId, $rhData) {
    if (!isset($rhData['pilot_data']['pilots']) || !is_array($rhData['pilot_data']['pilots'])) {
        return "";
    }
    foreach ($rhData['pilot_data']['pilots'] as $pilot) {
        if (isset($pilot['pilot_id']) && $pilot['pilot_id'] == $pilotId) {
            return isset($pilot['callsign']) ? $pilot['callsign'] : "";
        }
    }
    return "";
}

/**
 * Whether a heat's seats are those its pilots will fly on (1.13.0), as RotorHazard's own event page
 * decides it: the heat has flown (locked), its plan is confirmed (status 2), or it assigns no
 * frequencies by itself. RotorHazard's heat generator switches that on for every heat it makes;
 * such a heat gets its seats only when the race director calls it, and until then a slot's node is
 * the plan's order, not a seat. js/rm-m-displayHeats.js decides the same.
 *
 * @param array $heat A heat of heat_data.
 * @return bool
 */
function rm_heat_seats_fixed($heat) {
    return !empty($heat['locked'])
        || (isset($heat['status']) && 2 === (int)$heat['status'])
        || empty($heat['auto_frequency']);
}

/**
 * A seat's video channel as RotorHazard names it: band and channel ("R1"), else the frequency where
 * no band names it; '' for a node switched off (frequency 0), or one the data does not have.
 *
 * @param array $rhData The upload.
 * @param int   $seat   The slot's node_index.
 * @return string
 */
function rm_channel_label($rhData, $seat) {
    $f = $rhData['frequency_data']['fdata'][$seat] ?? null;
    if (!is_array($f)) {
        return '';
    }
    $frequency = isset($f['frequency']) ? (int)$f['frequency'] : null;
    if (0 === $frequency) {
        return '';
    }
    $band    = isset($f['band']) ? (string)$f['band'] : '';
    $channel = isset($f['channel']) ? (string)$f['channel'] : '';
    if ('' !== $band && '' !== $channel) {
        return $band . $channel;
    }
    return $frequency > 0 ? (string)$frequency : '';
}

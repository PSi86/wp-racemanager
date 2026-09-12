<?php
if (!defined('ABSPATH')) exit;

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
 *   - heat_id, heat_displayname, pilot_id, callsign, slot_id, channel
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

    $channelMapping = rm_buildChannelMapping($rhData);

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

    foreach ($heatIdsToCheck as $heatId) {
        if (!isset($heatsById[$heatId])) continue;
        $heat = $heatsById[$heatId];

        $heatDisplayname = isset($heat['displayname']) ? (string)$heat['displayname'] : ('Heat ' . $heatId);
        if (!isset($heat['slots']) || !is_array($heat['slots'])) continue;

        foreach ($heat['slots'] as $slotIndex => $slot) {
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
                }
            }

            if (!$pilotId) continue;

            $key = $heatId . ':' . $pilotId;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $upcomingPilots[] = array(
                'heat_id'          => $heatId,
                'heat_displayname' => $heatDisplayname,
                'pilot_id'         => $pilotId,
                'callsign'         => $callsign,
                'slot_id'          => $slotIndex,
                'channel'          => isset($channelMapping[$slotIndex]) ? $channelMapping[$slotIndex] : 'unknown',
            );
        }
    }

    return $upcomingPilots;
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

    if (!isset($rhData['result_data']['heats']) || !is_array($rhData['result_data']['heats'])) {
        return null;
    }

    $resultHeats = $rhData['result_data']['heats'];

    // Fast path: direct access by key (JSON object -> associative array with string keys)
    $resultHeat = null;
    $key = (string)$seedHeatId;
    if (isset($resultHeats[$key]) && is_array($resultHeats[$key])) {
        $resultHeat = $resultHeats[$key];
    } else {
        // Fallback: scan
        foreach ($resultHeats as $rh) {
            if (isset($rh['heat_id']) && (int)$rh['heat_id'] === $seedHeatId) {
                $resultHeat = $rh;
                break;
            }
        }
    }

    if (!$resultHeat) return null;

    $primaryLeaderboard = 'by_race_time';
    if (isset($resultHeat['leaderboard']['meta']['primary_leaderboard'])) {
        $primaryLeaderboard = (string)$resultHeat['leaderboard']['meta']['primary_leaderboard'];
    }

    if (!isset($resultHeat['leaderboard'][$primaryLeaderboard]) || !is_array($resultHeat['leaderboard'][$primaryLeaderboard])) {
        return null;
    }

    return rm_seededEntry($resultHeat['leaderboard'][$primaryLeaderboard], $seedRank);
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

    return is_array($positions) ? rm_seededEntry($positions, $seedRank) : null;
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

function rm_buildChannelMapping($rhData) {
    $mapping = array();
    if (isset($rhData['frequency_data']['fdata']) && is_array($rhData['frequency_data']['fdata'])) {
        foreach ($rhData['frequency_data']['fdata'] as $index => $fdata) {
            $band    = isset($fdata['band']) ? $fdata['band'] : '';
            $channel = isset($fdata['channel']) ? $fdata['channel'] : '';
            $mapping[$index] = $band . $channel;
        }
    }
    return $mapping;
}

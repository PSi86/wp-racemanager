<?php
// includes/race-results.php
// Reading a race's results out of the upload: a heat's result, a class's ranking, whether a class
// flies Chase the Ace. Shared by the next-up schedule (race-data-functions.php) and the race's winner
// (race-winner.php), and pure: nothing here touches WordPress.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * A heat's result as its primary leaderboard lists it, in order.
 *
 * @param array $rhData The upload.
 * @param int   $heatId The heat.
 * @return array|null The entries, or null while the heat has no result.
 */
function rm_heat_primary_entries($rhData, $heatId) {
    if (!isset($rhData['result_data']['heats']) || !is_array($rhData['result_data']['heats'])) {
        return null;
    }

    $resultHeats = $rhData['result_data']['heats'];

    // Fast path: direct access by key (JSON object -> associative array with string keys)
    $resultHeat = null;
    $key = (string)$heatId;
    if (isset($resultHeats[$key]) && is_array($resultHeats[$key])) {
        $resultHeat = $resultHeats[$key];
    } else {
        // Fallback: scan
        foreach ($resultHeats as $rh) {
            if (isset($rh['heat_id']) && (int)$rh['heat_id'] === (int)$heatId) {
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

    return $resultHeat['leaderboard'][$primaryLeaderboard];
}

/**
 * Whether a class flies its final as Chase the Ace: ranked with "Brackets" (the community plugin
 * Class Rank: Brackets), its "Chase the Ace" setting not switched off. The setting is on by default,
 * and RotorHazard uploads a class's ranksettings only as far as someone changed them.
 *
 * @param array $class A class of class_data.classes.
 * @return bool
 */
function rm_class_chases_the_ace($class) {
    if (($class['win_condition'] ?? '') !== 'Brackets') return false;
    $settings = is_array($class['ranksettings'] ?? null) ? $class['ranksettings'] : array();
    $cta = $settings['chase_the_ace'] ?? true;
    return !($cta === false || $cta === '0' || $cta === 0);
}

/**
 * The place 1 of a class's ranking from the timer, as pilot_id and callsign.
 *
 * @param array $rhData  The upload.
 * @param int   $classId The class.
 * @return array|null Null while the ranking has no place 1, and without a ranking.
 */
function rm_class_ranking_first($rhData, $classId) {
    $ranking = $rhData['result_data']['classes'][(string)$classId]['ranking']['ranking'] ?? null;
    foreach ((array)$ranking as $entry) {
        if (is_array($entry) && isset($entry['position']) && (int)$entry['position'] === 1 && !empty($entry['pilot_id'])) {
            return array('pilot_id' => (int)$entry['pilot_id'], 'callsign' => (string)($entry['callsign'] ?? ''));
        }
    }
    return null;
}

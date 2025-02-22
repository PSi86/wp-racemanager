<?php
// includes/race-data-functions.php
// Reads the RotorHazard JSON data and extracts the pilots scheduled
// Clean up attachments on post deletion

if (!defined('ABSPATH')) exit; // Exit if accessed directly
 
/**
 * Returns an array of pilots scheduled to race in the next three heats.
 * Each entry is an associative array with keys:
 *   - heat_id
 *   - heat_displayname
 *   - pilot_id
 *   - callsign
 *   - slot_id (indicates the channel the pilot will race on)
 *
 * @param array $rhData The RotorHazard data decoded from JSON.
 * @return array|null List of pilots for upcoming races or null if data is missing.
 */
function rm_getUpcomingRacePilots($rhData) {
    if (!$rhData 
        || !isset($rhData['current_heat']['current_heat']) 
        || !isset($rhData['heat_data']['heats']) 
        || !isset($rhData['pilot_data']['pilots'])
    ) {
        error_log("getUpcomingRacePilots: Missing required data in rhData");
        return null;
    }
    
    $currentHeat = $rhData['current_heat']['current_heat'];
    $limitHeat = $currentHeat + 3;
    $upcomingPilots = [];

    // Process each heat within the upcoming range.
    foreach ($rhData['heat_data']['heats'] as $heat) {
        if ($heat['id'] > $currentHeat && $heat['id'] <= $limitHeat) {
            $heatId = $heat['id'];
            $heatDisplayname = $heat['displayname'];
            
            if (isset($heat['slots']) && is_array($heat['slots'])) {
                // Use the slot index as the channel (slot id)
                foreach ($heat['slots'] as $slotIndex => $slot) {
                    $pilotId = 0;
                    $callsign = "";
                    
                    // If the slot already has a pilot assigned
                    if (isset($slot['pilot_id']) && $slot['pilot_id'] != 0) {
                        $pilotId = $slot['pilot_id'];
                        $callsign = rm_getPilotCallsign($pilotId, $rhData);
                    } else {
                        // If not seeded, check if we can seed from a previous heat
                        if (isset($slot['seed_id'], $slot['seed_rank']) && $slot['seed_id'] <= $currentHeat) {
                            $seededPilot = rm_getSeededPilot($slot['seed_id'], $slot['seed_rank'], $rhData);
                            if ($seededPilot !== null) {
                                $pilotId = $seededPilot['pilot_id'];
                                $callsign = $seededPilot['callsign'];
                            }
                        }
                    }
                    
                    // Only add valid pilot entries.
                    if ($pilotId) {
                        $upcomingPilots[] = [
                            'heat_id'         => $heatId,
                            'heat_displayname'=> $heatDisplayname,
                            'pilot_id'        => $pilotId,
                            'callsign'        => $callsign,
                            'slot_id'         => $slotIndex
                        ];
                    }
                }
            }
        }
    }
    
    return $upcomingPilots;
}

/**
 * Looks up a pilot's callsign using the pilot_id.
 *
 * @param int $pilotId
 * @param array $rhData
 * @return string The pilot callsign or an empty string if not found.
 */
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
 * Attempts to resolve a seeded pilot based on a seed heat id and seed rank.
 * This function looks into the result_data (if available) to find the pilot who finished in the position
 * matching seed_rank in the heat identified by seedHeatId.
 *
 * @param int $seedHeatId
 * @param int $seedRank
 * @param array $rhData
 * @return array|null Returns an associative array with keys 'pilot_id' and 'callsign' or null if not found.
 */
function rm_getSeededPilot($seedHeatId, $seedRank, $rhData) {
    if (!isset($rhData['result_data']['heats'])) {
        return null;
    }
    
    $resultHeats = $rhData['result_data']['heats'];
    if (!is_array($resultHeats)) {
        return null;
    }
    
    // Find the results for the seed heat.
    foreach ($resultHeats as $resultHeat) {
        if (isset($resultHeat['heat_id']) && $resultHeat['heat_id'] == $seedHeatId) {
            // Determine which leaderboard to use.
            $primaryLeaderboard = "by_race_time";
            if (isset($resultHeat['leaderboard']['meta']['primary_leaderboard'])) {
                $primaryLeaderboard = $resultHeat['leaderboard']['meta']['primary_leaderboard'];
            }
            if (isset($resultHeat['leaderboard'][$primaryLeaderboard]) && is_array($resultHeat['leaderboard'][$primaryLeaderboard])) {
                foreach ($resultHeat['leaderboard'][$primaryLeaderboard] as $entry) {
                    if (isset($entry['position']) && $entry['position'] == $seedRank) {
                        if (isset($entry['pilot_id'])) {
                            return [
                                'pilot_id' => $entry['pilot_id'],
                                'callsign' => isset($entry['callsign']) ? $entry['callsign'] : ""
                            ];
                        }
                    }
                }
            }
        }
    }
    return null;
}


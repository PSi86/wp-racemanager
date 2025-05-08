// rm-m-calcRanking.js

export function computeLeaderboard(race_data) {
    const data = race_data.result_data;
  
    // 0) how many pilots → cap places
    const pilotsArr = Array.isArray(race_data.pilot_data?.pilots)
      ? race_data.pilot_data.pilots
      : Object.values(race_data.pilot_data?.pilots || {});
    const maxPlaces = pilotsArr.length;
  
    // 1) normalize classes to Array
    const classesArr = Array.isArray(data.classes)
      ? data.classes
      : Object.values(data.classes);
  
    // 2) find Elimination & Qualifying
    const elimClass = classesArr.find(c => c.name.toLowerCase() === 'elimination');
    const qualClass = classesArr.find(c => c.name.toLowerCase() === 'qualifying');
    if (!elimClass || !qualClass) {
      throw new Error('Both "Elimination" and "Qualifying" classes must exist');
    }
  
    // 3) grab each class’s primary_leaderboard
    const getPbKey = clazz => {
      const m = clazz.leaderboard?.meta;
      if (!m || m.primary_leaderboard == null) {
        throw new Error(`Class "${clazz.name}" missing leaderboard.meta.primary_leaderboard`);
      }
      return m.primary_leaderboard;
    };
    const elimLbKey = getPbKey(elimClass);
    const qualLbKey = getPbKey(qualClass);
  
    // 4) list elimination-heat IDs
    const heatsByClass = data.heats_by_class || {};
    const elimHeats    = heatsByClass[elimClass.id] || [];
  
    // 5) determine which races are done
    const currentHeatId     = race_data.current_heat.current_heat;  
    const currentIdx        = elimHeats.indexOf(currentHeatId);
    if (currentIdx === -1) {
      throw new Error(`current_heat ${currentHeatId} not found in elimination heats`);
    }
    const currentHeatNum    = currentIdx + 1;
    const hasCompleted      = rn => {
      if (rn < currentHeatNum) return true;
      if (rn === currentHeatNum) {
        const heat = data.heats[elimHeats[rn - 1]];
        const lb = heat?.leaderboard?.[elimLbKey];
        return Array.isArray(lb) && lb.length > 0;
      }
      return false;
    };
  
    // 6) helper to fetch a completed elimination-heat’s leaderboard
    function getElimHeatLb(rn) {
      if (!hasCompleted(rn)) {
        throw new Error(`Heat ${rn} not yet completed`);
      }
      const heat = data.heats[elimHeats[rn - 1]];
      return heat.leaderboard[elimLbKey];
    }
  
    // 7) pull Qualifying class-level leaderboard array
    const qualLb = qualClass.leaderboard[qualLbKey];
    if (!Array.isArray(qualLb)) {
      throw new Error(`Qualifying class has no "${qualLbKey}" leaderboard`);
    }
  
    // 8) prepare a board capped at maxPlaces (only pilot_id & place)
    const board = Array(maxPlaces).fill(null);
    const assign = entries => {
      entries.forEach(e => {
        if (e.place <= maxPlaces) {
          board[e.place - 1] = { pilot_id: e.pilot_id, place: e.place };
        }
      });
    };
  
    // 9) process blocks of races (3rd & 4th places)
    function processGroup(races, startPlace) {
      if (!races.every(hasCompleted)) return [];
      const pilots = races.flatMap(rn => {
        const lb = getElimHeatLb(rn);
        // guard against missing entries
        return [ lb[2], lb[3] ].filter(p => p && p.pilot_id);
      });
      pilots.sort((a, b) => {
        const pa = qualLb.find(x => x.pilot_id === a.pilot_id)?.position ?? Infinity;
        const pb = qualLb.find(x => x.pilot_id === b.pilot_id)?.position ?? Infinity;
        return pa - pb;
      });
      return pilots.map((p, i) => ({ place: startPlace + i, pilot_id: p.pilot_id }));
    }
  
    // 10) fill places 25–32, 17–24, 13–16, 9–12
    assign(processGroup([13,14,15,16], 25));
    assign(processGroup([17,18,19,20], 17));
    assign(processGroup([21,22],       13));
    assign(processGroup([25,26],        9));
  
    // 11) assign semis & final as soon as done
    if (hasCompleted(27)) {
      const semi1 = getElimHeatLb(27);
      assign([
        { place: 7, pilot_id: semi1[2]?.pilot_id },
        { place: 8, pilot_id: semi1[3]?.pilot_id }
      ]);
    }
    if (hasCompleted(29)) {
      const semi2 = getElimHeatLb(29);
      assign([
        { place: 5, pilot_id: semi2[2]?.pilot_id },
        { place: 6, pilot_id: semi2[3]?.pilot_id }
      ]);
    }
    if (hasCompleted(30)) {
      const finalLb = getElimHeatLb(30);
      assign([
        { place: 1, pilot_id: finalLb[0]?.pilot_id },
        { place: 2, pilot_id: finalLb[1]?.pilot_id },
        { place: 3, pilot_id: finalLb[2]?.pilot_id },
        { place: 4, pilot_id: finalLb[3]?.pilot_id }
      ]);
    }
  
    // 12) pull event_leaderboard for pilot metadata
    const eventLb = data.event_leaderboard?.by_race_time;
    if (!Array.isArray(eventLb)) {
      throw new Error('Missing result_data.event_leaderboard.by_race_time');
    }
  
    // 13) merge place/pilot_id with full pilot data from event leaderboard
    return board
      .filter(Boolean)
      .map(({ place, pilot_id }) => {
        const pilotData = eventLb.find(p => p.pilot_id === pilot_id);
        if (!pilotData) {
          throw new Error(`Pilot ${pilot_id} not found in event_leaderboard`);
        }
        return { place, ...pilotData };
      });
  }
  
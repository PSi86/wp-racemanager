// rm-m-displayRanking.js
// This module displays the ranking of a double elimination tournament in a container specified by the user in the config file.
// It listens for data from the dataLoader and updates the ranking accordingly.

import { dataLoaderInstance } from './rm-m-dataLoader.js';
import { computeLeaderboard } from './rm-m-calcRanking.js';

// Exports const displayRankingInstance = new DisplayRanking(); (at the bottom)

class DisplayRanking {
  constructor() {
    const configData = window.RmJsConfig?.displayRanking || {};
    this.containerId = configData.containerId || 'ranking-container';
    this.leaderboard = [];
    this.injectStyles();

    if (document.readyState === 'complete') {
      this.initialize();
    } else {
      window.addEventListener('load', () => this.initialize());
    }
  }

  injectStyles() {
    if (document.getElementById('rm-ranking-styles')) return;
    const css = `
#${this.containerId} {
  width: 100%;
  margin: 0 auto;
}
.rm-headline {
  font-size: 1.5rem;
  text-align: center;
  margin: 1rem 0;
}
.rm-pilot-row {
  display: flex;
  align-items: center;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #e0e0e0;
  cursor: pointer;
  transition: background 0.3s;
}
.rm-pilot-row:hover {
  background: rgba(0,0,0,0.05);
}
.rm-place, .rm-callsign {
  transition: transform 0.3s;
}
.rm-place {
  font-weight: bold;
  margin-right: 1rem;
}
.rm-pilot-row:hover .rm-place,
.rm-pilot-row:hover .rm-callsign {
  transform: scale(1.05);
}
.rm-place-first .rm-place { color: #ffd700; }
.rm-place-second .rm-place { color: #c0c0c0; }
.rm-place-third .rm-place { color: #cd7f32; }
.rm-details {
  display: none;
  padding: 0.75rem 1rem;
  background: #fafafa;
  font-size: 0.9rem;
}
.rm-pilot-row.expanded + .rm-details {
  display: block;
}
.rm-details p {
  margin: 0.25rem 0;
}
`;
    const style = document.createElement('style');
    style.id = 'rm-ranking-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

  initialize() {
    dataLoaderInstance.subscribe(this.handleDataLoaderEvent.bind(this));
  }

  handleDataLoaderEvent(data) {
    this.leaderboard = computeLeaderboard(data);
    console.log('DisplayRanking: Leaderboard updated', this.leaderboard);
    this.updateRankingDisplay();
  }

  updateRankingDisplay() {
    const container = document.getElementById(this.containerId);
    if (!container) {
      console.warn(`DisplayRanking: Container "${this.containerId}" not found`);
      return;
    }

    // Hide if no data
    if (!this.leaderboard?.length) {
      container.style.display = 'none';
      return;
    }
    container.style.display = '';

    // Clear and add headline
    //container.innerHTML = `<h2 class="rm-headline">Final Ranking</h2>`;
    container.innerHTML = `<h2>Final Ranking</h2>`;

    this.leaderboard.forEach(pilot => {
      // Row
      const row = document.createElement('div');
      row.className = 'rm-pilot-row';
      if (pilot.place === 1) row.classList.add('rm-place-first');
      else if (pilot.place === 2) row.classList.add('rm-place-second');
      else if (pilot.place === 3) row.classList.add('rm-place-third');

      // Place & Callsign
      const placeEl = document.createElement('span');
      placeEl.className = 'rm-place';
      placeEl.textContent = `#${pilot.place}`;
      const callEl = document.createElement('span');
      callEl.className = 'rm-callsign';
      callEl.textContent = pilot.callsign;

      row.append(placeEl, callEl);
      container.appendChild(row);

      // Details (hidden)
      const details = document.createElement('div');
      details.className = 'rm-details';

      // List every other property
      const exclude = new Set(['place','pilot_id','callsign', 'team_name', 'points', 'total_time_laps_raw', 'total_time_raw', 'position', 'last_lap', 'last_lap_raw', 'node', 'consecutives_raw', 'consecutives_base', 'average_lap_raw']);
      Object.entries(pilot).forEach(([key, val]) => {
        if (!exclude.has(key)) {
          const p = document.createElement('p');
          p.innerHTML = `<strong>${this.toTitleCase(key)}:</strong> ${val ?? ''}`;
          details.appendChild(p);
        }
      });

      container.appendChild(details);

      // Toggle on click
      row.addEventListener('click', () => {
        row.classList.toggle('expanded');
      });
    });
  }

  toTitleCase(str) {
    return str
      .replace(/_/g, ' ')
      .replace(/\b\w/g, l => l.toUpperCase());
  }
}

export const displayRankingInstance = new DisplayRanking();

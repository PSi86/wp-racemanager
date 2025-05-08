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

    // inject our minimalistic styles once
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
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px,1fr));
  gap: 1rem;
  /* padding: 1rem; */
}
.rm-pilot-card {
  background: #fff;
  border-radius: 0.5rem;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
  overflow: hidden;
  cursor: pointer;
  transform: translateY(20px);
  opacity: 0;
  animation: fadeIn 0.5s ease forwards;
}
.rm-pilot-card:nth-child(n) { animation-delay: calc(0.05s * var(--i)); }
.rm-pilot-header {
  padding: 1rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #3f51b5;
  color: white;
}
.rm-pilot-header h3 { margin: 0; font-size: 1.1rem; }
.rm-expand-icon {
  transition: transform 0.3s ease;
}
.rm-pilot-card.expanded .rm-expand-icon {
  transform: rotate(180deg);
}
.rm-pilot-details {
  max-height: 0;
  overflow: hidden;
  transition: max-height 0.3s ease;
  padding: 0 1rem;
}
.rm-pilot-card.expanded .rm-pilot-details {
  max-height: 200px;
  padding: 1rem;
}
.rm-pilot-details p {
  margin: 0.3rem 0;
  font-size: 0.9rem;
  color: #333;
}
@keyframes fadeIn {
  to { transform: translateY(0); opacity: 1; }
}
`;
    const style = document.createElement('style');
    style.id = 'rm-ranking-styles';
    style.textContent = css;
    document.head.appendChild(style);
  }

  initialize() {
        // Subscribe to the dataLoader
    dataLoaderInstance.subscribe(this.handleDataLoaderEvent.bind(this));
  }

  handleDataLoaderEvent(data) {
    this.leaderboard = computeLeaderboard(data);
    console.log(this.leaderboard);
    this.updateRankingDisplay();
  }

  updateRankingDisplay() {
    const container = document.getElementById(this.containerId);
    if (!container) {
      console.warn(`DisplayRanking: Container not found (${this.containerId})`);
      return;
    }

    // 0) If there's no data, hide the whole container
    if (!this.leaderboard || this.leaderboard.length === 0) {
      container.style.display = 'none';
      return;
    }

    // 1) Otherwise, make sure it's visible again
    container.style.display = ''; // or 'grid', as your CSS expects

    // 2) Clear previous contents, then add the headline
    container.innerHTML = '<h2 style="grid-column: 1/-1; margin:0 0 1rem;">Final Ranking</h2>';

    // 3) Render each pilot card as before
    this.leaderboard.forEach((pilot, idx) => {
      const card = document.createElement('div');
      card.className = 'rm-pilot-card';
      card.style.setProperty('--i', idx);

      const header = document.createElement('div');
      header.className = 'rm-pilot-header';
      header.innerHTML = `
        <h3>#${pilot.place} ${pilot.callsign}</h3>
        <span class="rm-expand-icon">▼</span>
      `;
      card.appendChild(header);

      const details = document.createElement('div');
      details.className = 'rm-pilot-details';
      details.innerHTML = `
        <p><strong>Team:</strong> ${pilot.team_name || '—'}</p>
        <p><strong>Laps:</strong> ${pilot.laps ?? '—'}</p>
        <p><strong>Best Time:</strong> ${pilot.best_time ?? '—'}</p>
        <p><strong>Avg Time:</strong> ${pilot.avg_time ?? '—'}</p>
      `;
      card.appendChild(details);

      header.addEventListener('click', () => {
        card.classList.toggle('expanded');
      });

      container.appendChild(card);
    });
  }
}

export const displayRankingInstance = new DisplayRanking();

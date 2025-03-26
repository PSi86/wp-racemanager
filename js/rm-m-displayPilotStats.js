// displayPilotStats.js
// This module displays pilot statistics using prepared leaderboard data from the event,
// falling back to pilot_data if no leaderboard is available.
// It shows: Pilot, Fastest Lap, Fastest Consecutive, and Laps Completed.
// Table headers are clickable for sorting.
// Each stat cell always includes a span with source info.
// On wide screens (>=1024px) the source info is always shown inline via CSS.
// On narrow screens (<1024px) the inline source info is hidden,
// and clicking a cell shows a tooltip bubble positioned directly above the cell,
// horizontally centered, with position calculated taking scrolling into account.

import { dataLoaderInstance } from './rm-m-dataLoader.js';

let currentTooltip = null;
let activeTooltipCell = null;

function removeTooltip() {
  if (currentTooltip) {
    currentTooltip.remove();
    currentTooltip = null;
    activeTooltipCell = null;
  }
}

function showTooltip(cell, sourceText) {
  removeTooltip(); // Close any existing tooltip.
  const tooltip = document.createElement('div');
  tooltip.className = 'tooltip-bubble';
  tooltip.textContent = sourceText; // "Source: " + sourceText;
  document.body.appendChild(tooltip);

  const rect = cell.getBoundingClientRect();
  const scrollX = window.pageXOffset;
  const scrollY = window.pageYOffset;
  // Force a reflow to get the tooltip dimensions.
  const tooltipWidth = tooltip.offsetWidth;
  const tooltipHeight = tooltip.offsetHeight;
  // Position the tooltip directly above the cell, horizontally centered.
  const left = rect.left + scrollX + rect.width / 2 - tooltipWidth / 2;
  const top = rect.top + scrollY - tooltipHeight; // 8px gap above cell
  tooltip.style.position = 'absolute';
  tooltip.style.left = left + 'px';
  tooltip.style.top = top + 'px';
  tooltip.style.transform = 'none';
  tooltip.style.fontSize = 'small';
  currentTooltip = tooltip;
  activeTooltipCell = cell;
}

class DisplayPilotStats {
  constructor() {
    // When clicking anywhere in the document, remove any open tooltip.
    document.addEventListener('click', () => {
      removeTooltip();
    });
    dataLoaderInstance.subscribe(this.displayPilotStats.bind(this));
  }

  /**
   * Build and display the pilot stats table.
   * Uses rhdata.result_data.event_leaderboard.by_fastest_lap if available;
   * otherwise falls back to rhdata.pilot_data.pilots.
   * @param {Object} rhdata - The race event data.
   */
  displayPilotStats(rhdata) {
    const container = document.getElementById('pilot-stats');
    if (!container) {
      console.error("displayPilotStats: Container element with id 'pilot-stats' not found.");
      return;
    }
    container.innerHTML = '';

    // Determine data source.
    let dataArray = [];
    let usingLeaderboard = false;
    if (
      rhdata.result_data &&
      rhdata.result_data.event_leaderboard &&
      Array.isArray(rhdata.result_data.event_leaderboard.by_fastest_lap) &&
      rhdata.result_data.event_leaderboard.by_fastest_lap.length > 0
    ) {
      dataArray = rhdata.result_data.event_leaderboard.by_fastest_lap;
      usingLeaderboard = true;
    } else if (rhdata.pilot_data && Array.isArray(rhdata.pilot_data.pilots)) {
      // Fallback: Use pilot_data with placeholder values.
      dataArray = rhdata.pilot_data.pilots.map(pilot => ({
        pilot_id: pilot.pilot_id,
        callsign: pilot.callsign,
        fastest_lap: '—',
        fastest_lap_raw: Infinity,
        consecutives: '—',
        consecutives_raw: Infinity,
        laps: '—'
      }));
    } else {
      container.textContent = "No pilot data available.";
      return;
    }

    // Build table structure.
    const table = document.createElement('table');
    table.className = 'pilot-stats-table';
    const thead = document.createElement('thead');
    const headerRow = document.createElement('tr');

    // Define table headers.
    const headers = [
      { label: 'Pilot', key: 'callsign' },
      { label: 'Fastest Lap', key: 'fastest_lap' },
      { label: 'Fastest Consecutive', key: 'consecutives' },
      { label: 'Laps Completed', key: 'laps' }
    ];

    headers.forEach(header => {
      const th = document.createElement('th');
      th.textContent = header.label;
      th.dataset.key = header.key;
      th.classList.add('sortable');
      th.style.cursor = 'pointer';
      th.addEventListener('click', () => sortTableByColumn(header.key));
      headerRow.appendChild(th);
    });
    thead.appendChild(headerRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');

    // Build table body rows.
    const buildTbody = () => {
      tbody.innerHTML = '';
      dataArray.forEach(entry => {
        const tr = document.createElement('tr');

        // Pilot cell.
        const tdPilot = document.createElement('td');
        tdPilot.textContent = entry.callsign || '—';
        tr.appendChild(tdPilot);

        // Fastest Lap cell.
        const tdFastest = document.createElement('td');
        tdFastest.textContent = entry.fastest_lap || '—';
        if (usingLeaderboard && entry.fastest_lap_source) {
          let src = entry.fastest_lap_source.displayname || '';
          if (entry.fastest_lap_source.round) {
            src += " / Round " + entry.fastest_lap_source.round;
          }
          const span = document.createElement('span');
          span.classList.add("source-info");
          span.setAttribute("data-tooltip", src);
          // Always include the full source text inline; CSS will hide it on narrow screens.
          span.textContent = " (" + src + ")";
          tdFastest.appendChild(span);
          tdFastest.addEventListener('click', e => {
            if (window.innerWidth >= 1024) return; // On wide screens, inline display is used.
            e.stopPropagation();
            if (activeTooltipCell === tdFastest) {
              removeTooltip();
            } else {
              showTooltip(tdFastest, src);
            }
          });
        }
        tr.appendChild(tdFastest);

        // Fastest Consecutive cell.
        const tdConsec = document.createElement('td');
        tdConsec.textContent = entry.consecutives || '—';
        if (usingLeaderboard && entry.consecutives_source) {
          let src = entry.consecutives_source.displayname || '';
          if (entry.consecutives_source.round) {
            src += " / Round " + entry.consecutives_source.round;
          }
          const span = document.createElement('span');
          span.classList.add("source-info");
          span.setAttribute("data-tooltip", src);
          span.textContent = " (" + src + ")";
          tdConsec.appendChild(span);
          tdConsec.addEventListener('click', e => {
            if (window.innerWidth >= 1024) return;
            e.stopPropagation();
            if (activeTooltipCell === tdConsec) {
              removeTooltip();
            } else {
              showTooltip(tdConsec, src);
            }
          });
        }
        tr.appendChild(tdConsec);

        // Laps Completed cell.
        const tdLaps = document.createElement('td');
        tdLaps.textContent = (entry.laps !== undefined && entry.laps !== null) ? entry.laps : '—';
        tr.appendChild(tdLaps);

        tbody.appendChild(tr);
      });
    };

    table.appendChild(tbody);
    container.appendChild(table);

    // Sorting functionality.
    let sortOrder = 1;
    const getSortValue = (item, key) => {
      if (key === 'callsign') {
        return item.callsign ? item.callsign.toLowerCase() : "";
      } else if (key === 'fastest_lap') {
        if (item.fastest_lap_raw !== undefined && typeof item.fastest_lap_raw === 'number' && item.fastest_lap_raw !== Infinity) {
          return item.fastest_lap_raw;
        }
        return this.parseTime(item.fastest_lap);
      } else if (key === 'consecutives') {
        if (item.consecutives_raw !== undefined && typeof item.consecutives_raw === 'number' && item.consecutives_raw !== Infinity) {
          return item.consecutives_raw;
        }
        return this.parseTime(item.consecutives);
      } else if (key === 'laps') {
        return (item.laps !== undefined && !isNaN(item.laps)) ? Number(item.laps) : Infinity;
      }
      return item[key];
    };

    const sortTableByColumn = key => {
      dataArray.sort((a, b) => {
        const valA = getSortValue(a, key);
        const valB = getSortValue(b, key);
        if (key === 'callsign') {
          return valA.localeCompare(valB) * sortOrder;
        }
        return (valA - valB) * sortOrder;
      });
      sortOrder = -sortOrder;
      buildTbody();
    };

    // Helper: Parse time string "m:ss.mmm" into milliseconds.
    this.parseTime = timeStr => {
      if (!timeStr || timeStr === '—') return Infinity;
      const parts = timeStr.split(':');
      if (parts.length !== 2) return Infinity;
      const minutes = parseInt(parts[0], 10);
      const secParts = parts[1].split('.');
      if (secParts.length !== 2) return Infinity;
      const seconds = parseInt(secParts[0], 10);
      const millis = parseInt(secParts[1], 10);
      return minutes * 60000 + seconds * 1000 + millis;
    };

    buildTbody();
  }
}

export const displayPilotStatsInstance = new DisplayPilotStats();

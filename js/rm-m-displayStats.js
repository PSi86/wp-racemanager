// rm-m-displayStats.js
// Code copied from RotorHazard
// https://github.com/RotorHazard/RotorHazard/blob/main/src/server/templates/results.html
// https://github.com/RotorHazard/RotorHazard/blob/main/src/server/static/rotorhazard.js

import { dataLoaderInstance } from './rm-m-dataLoader.js';
//import { pilotSelectInstance } from './rm-m-pilotSelector.js';

const RACING_MODE_INDV = 0;   // INDIVIDUAL
const RACING_MODE_TEAM = 1;   // TEAM_ENABLED
const RACING_MODE_COOP = 2;   // COOP_ENABLED
const rotorhazard = {
    min_lap: 10,
    panelstates: {}
};

// Helper functions for sliding animations
function slideUp(element, duration = 300) {
    element.style.transitionProperty = 'height, margin, padding';
    element.style.transitionDuration = duration + 'ms';
    element.style.boxSizing = 'border-box';
    element.style.height = element.offsetHeight + 'px';
    element.offsetHeight; // force repaint
    element.style.overflow = 'hidden';
    element.style.height = 0;
    window.setTimeout(() => {
        element.style.display = 'none';
        element.style.removeProperty('height');
        element.style.removeProperty('overflow');
        element.style.removeProperty('transition-duration');
        element.style.removeProperty('transition-property');
    }, duration);
}

function slideDown(element, duration = 300) {
    element.style.removeProperty('display');
    let display = window.getComputedStyle(element).display;
    if (display === 'none') display = 'block';
    element.style.display = display;
    let height = element.offsetHeight;
    element.style.overflow = 'hidden';
    element.style.height = 0;
    element.offsetHeight; // force repaint
    element.style.transitionProperty = 'height, margin, padding';
    element.style.transitionDuration = duration + 'ms';
    element.style.height = height + 'px';
    window.setTimeout(() => {
        element.style.removeProperty('height');
        element.style.removeProperty('overflow');
        element.style.removeProperty('transition-duration');
        element.style.removeProperty('transition-property');
    }, duration);
}

// Event delegation for panel collapsing
document.addEventListener('click', function (e) {
    const header = e.target.closest('.collapsing .panel-header');
    if (header) {
        const panel = header.parentElement;
        const panelId = panel.id;
        const panelContent = panel.querySelectorAll(':scope > .panel-content'); //'.panel-content'
        if (panel.classList.contains('open')) {
            panel.classList.remove('open');
            panelContent.forEach(el => slideUp(el));
            if (panelId) {
                rotorhazard.panelstates[panelId] = false;
            }
        } else {
            panel.classList.add('open');
            panelContent.forEach(el => slideDown(el));
            if (panelId) {
                rotorhazard.panelstates[panelId] = true;
            }
        }
    }
});

// Process all collapsing panels on page load
document.querySelectorAll('.collapsing').forEach(function (el) {
    el.classList.add('active');

    // Hide all panel-content elements
    el.querySelectorAll('.panel-content').forEach(function (pc) {
        pc.style.display = 'none';
    });

    // Wrap inner contents of all direct children of .panel-header with a button
    el.querySelectorAll('.panel-header > *').forEach(function (child) {
        const btn = document.createElement('button');
        btn.className = 'no-style';
        // Move all child nodes into the new button element
        while (child.firstChild) {
            btn.appendChild(child.firstChild);
        }
        child.appendChild(btn);
    });
});

// If there's a hash in the URL, open the corresponding panel
if (window.location.hash) {
    const panel = document.querySelector(window.location.hash);
    if (panel && panel.querySelector('.panel-header')) {
        panel.classList.add('open');
        panel.querySelectorAll('.panel-content').forEach(function (pc) {
            pc.style.display = 'block';
        });
        // Reassign the hash to trigger any hashchange events
        location.hash = window.location.hash;
    }
}


//import { __ } from './rm-m-i18n.js';

class DisplayStats {
    constructor() {
        const configData = (window.RmJsConfig && window.RmJsConfig["displayStats"]) || null;
        if (!configData) {
            throw new Error("displayStats: Missing configuration data");
        }

        // Configuration properties
        // Read dependency configuration
        this.raceId = dataLoaderInstance.storageKey; // Load storageKey from dataLoader
        
        this.pilotSelectorId = null;
        this.pilotSelectorElement = null;
        this.selectedPilotId = null;

        if(typeof pilotSelectInstance !== 'undefined') {
            this.pilotSelectorId = pilotSelectInstance.pilotSelectorId; // Load pilotSelectorId from pilotSelector
            this.pilotSelectorElement = document.getElementById(`${this.pilotSelectorId}`);
            this.selectedPilotId = pilotSelectInstance.selectedPilotId;
            console.log("displayStats: initialized with selected pilot ID:", typeof(this.selectedPilotId), this.selectedPilotId);
        }
        // Required properties
        // none

        // Optional properties
        this.filterCheckboxId = configData.filterCheckboxId || 'filterCheckbox';
        this.filterCheckboxElement = document.getElementById(`${this.filterCheckboxId}`);

        // Other properties       
        this.filterCheckboxKey = this.filterCheckboxId; //`filterOption` //  currently not using the race_id in the key (making it globally reusable) // `${this.raceId}_filterOption`
        this.filterCheckboxState = JSON.parse(sessionStorage.getItem(this.filterCheckboxKey)) || false; //|| null;

        //this.cr_rh_data = null; // no default data, upon subscribing to dataLoader this will be populated



        // Run initialization
        if (document.readyState === 'complete') {
            console.log("displayStats: Site is already loaded, initializing immediately");
            this.initialize();
        } else {
            // Otherwise, wait for the window load event.
            console.log("displayStats: Registering load event listener");
            window.addEventListener('load', () => this.initialize());
        }
    }
    initialize() {
        // Init UI elements and attach event handlers
        if (this.filterCheckboxElement !== null) {
            this.filterCheckboxElement.checked = this.filterCheckboxState;
            this.filterCheckboxElement.addEventListener('change', this.handleFilterChange.bind(this));
        }

        if (this.pilotSelectorElement !== null) {
            this.pilotSelectorElement.addEventListener('change', this.handleFilterChange.bind(this));
        }

        // Subscribe to the dataLoader (singleton)
        console.log("displayStats: Subscribed to DataLoader");
        dataLoaderInstance.subscribe(this.displayStats.bind(this));
    }

    handleFilterChange() {
        //this.selectedPilotId = event.target.value;
        if (this.filterCheckboxElement) {
            this.selectedPilotId = parseInt(this.pilotSelectorElement.value) || 0; // could possibly be pulled from the pilotSelector instance
        }
        if (this.pilotSelectorElement) {
            this.filterCheckboxState = this.filterCheckboxElement.checked;
            sessionStorage.setItem(this.filterCheckboxKey, this.filterCheckboxState);
        }

        console.log('displayStats: Filter changed:', this.selectedPilotId, this.filterCheckboxState);
        // Filter and display heats for the selected pilot
        // filterBracketData calls calculateClassLayout and renderGrid
        //filterBracketData();
        //this.displayStats();
    }

    /**
     * Displays race heats and leaderboards based on the given message object.
     * @param {Object} msg - The message object containing race data.
     */
    displayStats(rhdata) {
        const msg = rhdata.result_data;
        // Helper function to order leaderboard boards
        const order_boards = (primary) => {
            const boards = ['by_race_time', 'by_fastest_lap', 'by_consecutives'];
            boards.sort((x, y) => (x === primary ? -1 : y === primary ? 1 : 0));
            return boards;
        };

        // Default meta (assumes RACING_MODE_INDV is globally available)
        /* const defaultMeta = {
            team_racing_mode: RACING_MODE_INDV,
            start_behavior: 0,
            consecutives_count: msg.consecutives_count,
        }; */
        var defaultMeta = new Object;
			defaultMeta.team_racing_mode = RACING_MODE_INDV;
			defaultMeta.start_behavior = 0;
			defaultMeta.consecutives_count = msg.consecutives_count;

        // Get the results container and clear it
        const page = document.getElementById('results');
        page.innerHTML = '';

        if (Object.keys(msg.heats).length > 0) {
            // Move unclassified heats to end of list (assumes ES6 key ordering)
            let classOrdered = Object.keys(msg.heats_by_class);
            classOrdered = classOrdered.concat(classOrdered.splice(0, 1));

            classOrdered.forEach((race_class, classIndex) => {
                let valid_heats = false;
                if (msg.heats_by_class[race_class].length) {
                    for (const heatId of msg.heats_by_class[race_class]) {
                        if (msg.heats[heatId]) {
                            valid_heats = true;
                            break;
                        }
                    }
                }

                if (valid_heats) {
                    // Create class panel
                    const classPanel = document.createElement('div');
                    classPanel.id = 'class_' + classIndex;
                    classPanel.className = 'panel collapsing';

                    const classPanelHeader = document.createElement('div');
                    classPanelHeader.className = 'panel-header';

                    const classPanelContent = document.createElement('div');
                    classPanelContent.className = 'panel-content';
                    classPanelContent.style.display = 'none';

                    const currentClass = msg.classes[race_class];
                    if (currentClass) {
                        // Create header for class name
                        const h2 = document.createElement('h2');
                        const btn = document.createElement('button');
                        btn.className = 'no-style';
                        btn.textContent = currentClass.name
                            ? currentClass.name
                            : 'Class' + ' ' + currentClass.id;
                        h2.appendChild(btn);
                        classPanelHeader.appendChild(h2);

                        // Create class ranking panel if available
                        if (currentClass.ranking) {
                            const classSpecial = document.createElement('div');
                            classSpecial.id = 'class_' + classIndex + '_leaderboard';
                            classSpecial.className = 'panel collapsing class-leaderboard';

                            const classSpecialHeader = document.createElement('div');
                            classSpecialHeader.className = 'panel-header';
                            const h3 = document.createElement('h3');
                            const btnRanking = document.createElement('button');
                            btnRanking.className = 'no-style';
                            let classHeaderText = 'Class Ranking';
                            if (
                                currentClass.ranking.meta &&
                                currentClass.ranking.meta.method_label
                            ) {
                                classHeaderText += '—' + currentClass.ranking.meta.method_label;
                            }
                            btnRanking.textContent = classHeaderText;
                            h3.appendChild(btnRanking);
                            classSpecialHeader.appendChild(h3);
                            classSpecial.appendChild(classSpecialHeader);

                            const classSpecialContent = document.createElement('div');
                            classSpecialContent.className = 'panel-content';
                            classSpecialContent.style.display = 'none';
                            classSpecialContent.appendChild(this.build_ranking(currentClass.ranking));
                            classSpecial.appendChild(classSpecialContent);
                            classPanelContent.appendChild(classSpecial);
                        }

                        // Create class leaderboard summary
                        const classLeaderboard = document.createElement('div');
                        classLeaderboard.id = 'class_' + classIndex + '_leaderboard';
                        classLeaderboard.className = 'panel collapsing class-leaderboard';

                        const classLeaderboardHeader = document.createElement('div');
                        classLeaderboardHeader.className = 'panel-header';
                        const h3Summary = document.createElement('h3');
                        const btnSummary = document.createElement('button');
                        btnSummary.className = 'no-style';
                        btnSummary.textContent = 'Class Summary';
                        h3Summary.appendChild(btnSummary);
                        classLeaderboardHeader.appendChild(h3Summary);
                        classLeaderboard.appendChild(classLeaderboardHeader);

                        const classLeaderboardContent = document.createElement('div');
                        classLeaderboardContent.className = 'panel-content';
                        classLeaderboardContent.style.display = 'none';

                        const boards = order_boards(
                            currentClass.leaderboard.meta.primary_leaderboard
                        );
                        boards.forEach((board) => {
                            if (board === 'by_race_time') {
                                const h4 = document.createElement('h4');
                                h4.textContent = 'Race Totals';
                                classLeaderboardContent.appendChild(h4);
                                classLeaderboardContent.appendChild(
                                    this.build_leaderboard(
                                        currentClass.leaderboard.by_race_time,
                                        'by_race_time',
                                        currentClass.leaderboard.meta,
                                        true
                                    )
                                );
                            } else if (board === 'by_fastest_lap') {
                                const h4 = document.createElement('h4');
                                h4.textContent = 'Fastest Laps';
                                classLeaderboardContent.appendChild(h4);
                                classLeaderboardContent.appendChild(
                                    this.build_leaderboard(
                                        currentClass.leaderboard.by_fastest_lap,
                                        'by_fastest_lap',
                                        currentClass.leaderboard.meta,
                                        true
                                    )
                                );
                            } else if (board === 'by_consecutives') {
                                const h4 = document.createElement('h4');
                                h4.textContent = 'Fastest Consecutive Laps';
                                classLeaderboardContent.appendChild(h4);
                                classLeaderboardContent.appendChild(
                                    this.build_leaderboard(
                                        currentClass.leaderboard.by_consecutives,
                                        'by_consecutives',
                                        currentClass.leaderboard.meta,
                                        true
                                    )
                                );
                            }
                        });
                        classLeaderboard.appendChild(classLeaderboardContent);
                        classPanelContent.appendChild(classLeaderboard);
                    } else {
                        // Fallback if there is no specific class info
                        const h2 = document.createElement('h2');
                        const btn = document.createElement('button');
                        btn.className = 'no-style';
                        btn.textContent =
                            Object.keys(msg.classes).length === 0
                                ? 'Heats'
                                : 'Unclassified';
                        h2.appendChild(btn);
                        classPanelHeader.appendChild(h2);
                    }

                    classPanel.appendChild(classPanelHeader);

                    // Process each heat for this class
                    msg.heats_by_class[race_class].forEach((heatId, heatIndex) => {
                        const heat = msg.heats[heatId];
                        if (heat) {
                            const panel = document.createElement('div');
                            panel.id = 'class_' + classIndex + '_heat_' + heatIndex;
                            panel.className = 'panel collapsing';

                            const panelHeader = document.createElement('div');
                            panelHeader.className = 'panel-header';
                            const h3 = document.createElement('h3');
                            const btnHeat = document.createElement('button');
                            btnHeat.className = 'no-style';
                            btnHeat.textContent = heat.displayname;
                            h3.appendChild(btnHeat);
                            panelHeader.appendChild(h3);
                            panel.appendChild(panelHeader);

                            const panelContent = document.createElement('div');
                            panelContent.className = 'panel-content';
                            panelContent.style.display = 'none';

                            // Heat leaderboard (if more than one round)
                            if (heat.rounds.length > 1) {
                                const heatSummaryPanel = document.createElement('div');
                                heatSummaryPanel.id =
                                    'class_' + classIndex + '_heat_' + heatIndex + '_leaderboard';
                                heatSummaryPanel.className = 'panel collapsing open';

                                const heatSummaryPanelHeader = document.createElement('div');
                                heatSummaryPanelHeader.className = 'panel-header';
                                const h4 = document.createElement('h4');
                                const btnHeatSummary = document.createElement('button');
                                btnHeatSummary.className = 'no-style';
                                btnHeatSummary.textContent = 'Heat Summary';
                                h4.appendChild(btnHeatSummary);
                                heatSummaryPanelHeader.appendChild(h4);
                                heatSummaryPanel.appendChild(heatSummaryPanelHeader);

                                const heatSummaryPanelContent = document.createElement('div');
                                heatSummaryPanelContent.className = 'panel-content';
                                const heatLeaderboard = document.createElement('div');
                                heatLeaderboard.className = 'leaderboard';
                                heatLeaderboard.appendChild(
                                    this.build_leaderboard(
                                        heat.leaderboard[heat.leaderboard.meta.primary_leaderboard],
                                        'heat',
                                        heat.leaderboard.meta,
                                        true
                                    )
                                );
                                heatSummaryPanelContent.appendChild(heatLeaderboard);
                                heatSummaryPanel.appendChild(heatSummaryPanelContent);
                                panelContent.appendChild(heatSummaryPanel);
                            }

                            // Process each round in the heat
                            heat.rounds.forEach((round) => {
                                const roundDiv = document.createElement('div');
                                roundDiv.id =
                                    'class_' +
                                    classIndex +
                                    '_heat_' +
                                    heatIndex +
                                    '_round_' +
                                    round.id;
                                roundDiv.className = 'round panel collapsing open';

                                const roundHeader = document.createElement('div');
                                roundHeader.className = 'panel-header';
                                const h4 = document.createElement('h4');
                                const btnRound = document.createElement('button');
                                btnRound.className = 'no-style';
                                btnRound.textContent =
                                    'Round' +
                                    ' ' +
                                    round.id +
                                    ' (' +
                                    round.start_time_formatted +
                                    ')';
                                h4.appendChild(btnRound);
                                roundHeader.appendChild(h4);
                                roundDiv.appendChild(roundHeader);

                                const roundContent = document.createElement('div');
                                roundContent.className = 'panel-content';

                                const raceLeaderboard = document.createElement('div');
                                raceLeaderboard.className = 'leaderboard';
                                raceLeaderboard.appendChild(
                                    this.build_leaderboard(
                                        round.leaderboard[round.leaderboard.meta.primary_leaderboard],
                                        'round',
                                        round.leaderboard.meta
                                    )
                                );

                                const raceResults = document.createElement('div');
                                raceResults.className = 'race-results';

                                // Process each node (racer) in the round
                                round.nodes.forEach((node) => {
                                    if (node.callsign !== null) {
                                        const nodeDiv = document.createElement('div');
                                        nodeDiv.className = 'node';
                                        const h5 = document.createElement('h5');
                                        h5.textContent = node.callsign;
                                        nodeDiv.appendChild(h5);

                                        const table = document.createElement('table');
                                        table.className = 'laps';
                                        const tbody = document.createElement('tbody');
                                        let lapIndex =
                                            'start_behavior' in round.leaderboard.meta &&
                                                round.leaderboard.meta.start_behavior == 1
                                                ? 1
                                                : 0;
                                        node.laps.forEach((lap) => {
                                            if (!lap.deleted) {
                                                const tr = document.createElement('tr');
                                                tr.className = 'lap_' + lapIndex;
                                                const tdIndex = document.createElement('td');
                                                tdIndex.textContent = lapIndex;
                                                const tdTime = document.createElement('td');
                                                if (lapIndex) {
                                                    let timestamp;
                                                    if (
                                                        'start_behavior' in round.leaderboard.meta &&
                                                        round.leaderboard.meta.start_behavior == 2
                                                    ) {
                                                        timestamp =
                                                            this.formatTimeMillis(
                                                                lap.lap_time_stamp - node.laps[0].lap_time_stamp,
                                                                '{m}:{s}.{d}'
                                                            ) +
                                                            ' / ' +
                                                            this.formatTimeMillis(
                                                                lap.lap_time_stamp,
                                                                '{m}:{s}.{d}'
                                                            );
                                                    } else {
                                                        timestamp = this.formatTimeMillis(
                                                            lap.lap_time_stamp,
                                                            '{m}:{s}.{d}'
                                                        );
                                                    }
                                                    const span = document.createElement('span');
                                                    span.className = 'from_start';
                                                    span.textContent = timestamp;
                                                    tdTime.appendChild(span);
                                                    tdTime.insertAdjacentText(
                                                        'beforeend',
                                                        this.formatTimeMillis(lap.lap_time, '{m}:{s}.{d}')
                                                    );
                                                } else {
                                                    tdTime.textContent = this.formatTimeMillis(
                                                        lap.lap_time,
                                                        '{m}:{s}.{d}'
                                                    );
                                                }
                                                tr.appendChild(tdIndex);
                                                tr.appendChild(tdTime);
                                                tbody.appendChild(tr);
                                                lapIndex++;
                                            }
                                        });
                                        table.appendChild(tbody);
                                        nodeDiv.appendChild(table);
                                        raceResults.appendChild(nodeDiv);
                                    }
                                });

                                roundContent.appendChild(raceLeaderboard);
                                roundContent.appendChild(raceResults);
                                roundDiv.appendChild(roundContent);
                                panelContent.appendChild(roundDiv);
                            });
                            panel.appendChild(panelContent);
                            classPanelContent.appendChild(panel);
                        }
                    });
                    classPanel.appendChild(classPanelContent);
                    page.appendChild(classPanel);
                }
            });

            // Build the event leaderboard
            const eventPanel = document.createElement('div');
            eventPanel.id = 'event_leaderboard';
            eventPanel.className = 'panel collapsing';

            const eventPanelHeader = document.createElement('div');
            eventPanelHeader.className = 'panel-header';
            const h2Event = document.createElement('h2');
            const btnEvent = document.createElement('button');
            btnEvent.className = 'no-style';
            btnEvent.textContent = 'Event Totals';
            h2Event.appendChild(btnEvent);
            eventPanelHeader.appendChild(h2Event);
            eventPanel.appendChild(eventPanelHeader);

            const eventPanelContent = document.createElement('div');
            eventPanelContent.className = 'panel-content';
            eventPanelContent.style.display = 'none';

            const eventLeaderboard = document.createElement('div');
            eventLeaderboard.className = 'event-leaderboards';

            const h3RaceTotals = document.createElement('h3');
            h3RaceTotals.textContent = 'Race Totals';
            eventLeaderboard.appendChild(h3RaceTotals);
            eventLeaderboard.appendChild(
                this.build_leaderboard(
                    msg.event_leaderboard.by_race_time,
                    'by_race_time',
                    msg.event_leaderboard.meta,
                    true
                )
            );

            const h3FastestLaps = document.createElement('h3');
            h3FastestLaps.textContent = 'Fastest Laps';
            eventLeaderboard.appendChild(h3FastestLaps);
            eventLeaderboard.appendChild(
                this.build_leaderboard(
                    msg.event_leaderboard.by_fastest_lap,
                    'by_fastest_lap',
                    msg.event_leaderboard.meta
                )
            );

            const h3FastestConsecutives = document.createElement('h3');
            h3FastestConsecutives.textContent = 'Fastest Consecutive Laps';
            eventLeaderboard.appendChild(h3FastestConsecutives);
            eventLeaderboard.appendChild(
                this.build_leaderboard(
                    msg.event_leaderboard.by_consecutives,
                    'by_consecutives',
                    msg.event_leaderboard.meta
                )
            );

            eventPanelContent.appendChild(eventLeaderboard);
            eventPanel.appendChild(eventPanelContent);
            page.appendChild(eventPanel);

            // Restore panel state (using rotorhazard.panelstates, assumed global)
            for (const panelId in rotorhazard.panelstates) {
                const panelObj = document.getElementById(panelId);
                if (panelObj) {
                    const panelState = rotorhazard.panelstates[panelId];
                    const panelContent = panelObj.querySelector('.panel-content');
                    if (panelState) {
                        panelObj.classList.add('open');
                        if (panelContent) panelContent.style.display = 'block';
                    } else {
                        panelObj.classList.remove('open');
                        if (panelContent) panelContent.style.display = 'none';
                    }
                }
            }
        } else {
            const p = document.createElement('p');
            p.innerHTML = 'There is no saved race data available to view.';
            page.appendChild(p);
        }
    }
    formatTimeMillis(s, timeformat = '{m}:{s}.{d}') {
        s = Math.round(s);
        var ms = s % 1000;
        s = (s - ms) / 1000;
        var secs = s % 60;
        var mins = (s - secs) / 60;

        if (!formatted_time) {
            timeformat = '{m}:{s}.{d}';
        }
        var formatted_time = timeformat.replace('{d}', this.pad(ms, 3));
        formatted_time = formatted_time.replace('{s}', this.pad(secs));
        formatted_time = formatted_time.replace('{m}', mins)

        return formatted_time;
    }

    /**
     * Build a leaderboard table.
     * @param {Array} leaderboard - Array of leaderboard data.
     * @param {String} display_type - Type of leaderboard display.
     * @param {Object} meta - Meta data object.
     * @param {Boolean} display_starts - Whether to display the starts column.
     * @returns {HTMLElement} - A DIV element containing the responsive leaderboard table.
     */
    build_leaderboard(leaderboard, display_type = 'by_race_time', meta, display_starts = false) {
        if (typeof meta === 'undefined') {
            meta = {};
            meta.team_racing_mode = RACING_MODE_INDV;
            meta.start_behavior = 0;
            meta.consecutives_count = 0;
            meta.primary_leaderboard = null;
        }
        const show_points = (display_type === 'round');
        const total_label = (meta.start_behavior === 2) ? 'Laps Total' : 'Total';

        const twrap = document.createElement('div');
        twrap.className = 'responsive-wrap';

        const table = document.createElement('table');
        table.className = 'leaderboard';

        const header = document.createElement('thead');
        const headerRow = document.createElement('tr');

        // Rank column
        let th = document.createElement('th');
        th.className = 'pos';
        let span = document.createElement('span');
        span.className = 'screen-reader-text';
        span.textContent = 'Rank';
        th.appendChild(span);
        headerRow.appendChild(th);

        // Pilot column
        th = document.createElement('th');
        th.className = 'pilot';
        th.textContent = 'Pilot';
        headerRow.appendChild(th);

        // Team column if in team mode
        if (meta.team_racing_mode === RACING_MODE_TEAM) {
            th = document.createElement('th');
            th.className = 'team';
            th.textContent = 'Team';
            headerRow.appendChild(th);
        }

        // Starts column if requested
        if (display_starts === true) {
            th = document.createElement('th');
            th.className = 'starts';
            th.textContent = 'Starts';
            headerRow.appendChild(th);
        }

        // Laps, Total, and Avg columns for several display types
        if (['by_race_time', 'heat', 'round', 'current'].includes(display_type)) {
            th = document.createElement('th');
            th.className = 'laps';
            th.textContent = 'Laps';
            headerRow.appendChild(th);

            th = document.createElement('th');
            th.className = 'total';
            th.textContent = total_label;
            headerRow.appendChild(th);

            th = document.createElement('th');
            th.className = 'avg';
            th.textContent = 'Avg.';
            headerRow.appendChild(th);
        }

        // Fastest lap columns
        if (['by_fastest_lap', 'heat', 'round', 'current'].includes(display_type)) {
            th = document.createElement('th');
            th.className = 'fast';
            th.textContent = 'Fastest';
            headerRow.appendChild(th);
            if (display_type === 'by_fastest_lap') {
                th = document.createElement('th');
                th.className = 'source';
                th.textContent = 'Source';
                headerRow.appendChild(th);
            }
        }

        // Consecutives columns
        if (['by_consecutives', 'heat', 'round', 'current'].includes(display_type)) {
            th = document.createElement('th');
            th.className = 'consecutive';
            th.textContent = 'Consecutive';
            headerRow.appendChild(th);
            if (display_type === 'by_consecutives') {
                th = document.createElement('th');
                th.className = 'source';
                th.textContent = 'Source';
                headerRow.appendChild(th);
            }
        }

        // Points column if applicable
        if (show_points && 'primary_points' in meta) {
            th = document.createElement('th');
            th.className = 'points';
            th.textContent = 'Points';
            headerRow.appendChild(th);
        }

        header.appendChild(headerRow);
        table.appendChild(header);

        // Build table body
        const body = document.createElement('tbody');
        for (const i in leaderboard) {
            const data = leaderboard[i];
            const row = document.createElement('tr');

            // Position
            let td = document.createElement('td');
            td.className = 'pos';
            td.innerHTML = (data.position != null ? data.position : '-');
            row.appendChild(td);

            // Pilot
            td = document.createElement('td');
            td.className = 'pilot';
            td.textContent = data.callsign;
            row.appendChild(td);

            // Team (if in team mode)
            if (meta.team_racing_mode === RACING_MODE_TEAM) {
                td = document.createElement('td');
                td.className = 'team';
                td.textContent = data.team_name;
                row.appendChild(td);
            }

            // Starts (if requested)
            if (display_starts === true) {
                td = document.createElement('td');
                td.className = 'starts';
                td.textContent = data.starts;
                row.appendChild(td);
            }

            // Laps, Total, and Average
            if (['by_race_time', 'heat', 'round', 'current'].includes(display_type)) {
                let lap = data.laps;
                if (!lap || lap === '0:00.000') lap = '&#8212;';
                td = document.createElement('td');
                td.className = 'laps';
                td.innerHTML = lap;
                row.appendChild(td);

                lap = (meta.start_behavior === 2) ? data.total_time_laps : data.total_time;
                if (!lap || lap === '0:00.000') lap = '&#8212;';
                td = document.createElement('td');
                td.className = 'total';
                td.innerHTML = lap;
                row.appendChild(td);

                lap = data.average_lap;
                if (!lap || lap === '0:00.000') lap = '&#8212;';
                td = document.createElement('td');
                td.className = 'avg';
                td.innerHTML = lap;
                row.appendChild(td);
            }

            // Fastest lap and optional source
            if (['by_fastest_lap', 'heat', 'round', 'current'].includes(display_type)) {
                let lap = data.fastest_lap;
                if (!lap || lap === '0:00.000') lap = '&#8212;';
                td = document.createElement('td');
                td.className = 'fast';
                td.innerHTML = lap;
                let source_text;
                if (data.fastest_lap_source) {
                    const source = data.fastest_lap_source;
                    source_text = source.round
                        ? source.displayname + ' / ' + 'Round' + ' ' + source.round
                        : source.displayname;
                } else {
                    source_text = 'None';
                }
                if (display_type === 'heat') {
                    td.dataset.source = source_text;
                    td.title = source_text;
                }
                if ('min_lap' in rotorhazard &&
                    rotorhazard.min_lap > 0 &&
                    data.fastest_lap_raw > 0 &&
                    (rotorhazard.min_lap * 1000) > data.fastest_lap_raw) {
                    td.classList.add('min-lap-warning');
                }
                row.appendChild(td);
                if (display_type === 'by_fastest_lap') {
                    td = document.createElement('td');
                    td.className = 'source';
                    td.textContent = source_text;
                    row.appendChild(td);
                }
            }

            // Consecutives and optional source
            if (['by_consecutives', 'heat', 'round', 'current'].includes(display_type)) {
                let lap;
                if (!data.consecutives || data.consecutives === '0:00.000') {
                    lap = '&#8212;';
                } else {
                    lap = data.consecutives_base + '/' + data.consecutives;
                }
                td = document.createElement('td');
                td.className = 'consecutive';
                td.innerHTML = lap;
                let source_text;
                if (data.consecutives_source) {
                    const source = data.consecutives_source;
                    source_text = source.round
                        ? source.displayname + ' / ' + 'Round' + ' ' + source.round
                        : source.displayname;
                } else {
                    source_text = 'None';
                }
                if (display_type === 'heat') {
                    td.dataset.source = source_text;
                    td.title = source_text;
                }
                row.appendChild(td);
                if (display_type === 'by_consecutives') {
                    td = document.createElement('td');
                    td.className = 'source';
                    td.textContent = source_text;
                    row.appendChild(td);
                }
            }

            // Points (if applicable)
            if (show_points && 'primary_points' in meta) {
                td = document.createElement('td');
                td.className = 'points';
                td.textContent = data.points;
                row.appendChild(td);
            }
            body.appendChild(row);
        }
        table.appendChild(body);
        twrap.appendChild(table);
        return twrap;
    }

    /**
     * Build a team leaderboard table.
     * @param {Array} leaderboard - Array of team leaderboard data.
     * @param {String} display_type - Type of leaderboard display.
     * @param {Object} meta - Meta data object.
     * @returns {HTMLElement} - A DIV element containing the responsive team leaderboard table.
     */
    build_team_leaderboard(leaderboard, display_type = 'by_race_time', meta) {
        if (typeof meta === 'undefined') {
            meta = {};
            meta.team_racing_mode = RACING_MODE_TEAM;
            meta.consecutives_count = 0;
        }
        const coop_flag = (leaderboard.length === 1 && leaderboard[0].name === "Group");

        const twrap = document.createElement('div');
        twrap.className = 'responsive-wrap';

        const table = document.createElement('table');
        table.className = 'leaderboard';

        const header = document.createElement('thead');
        const headerRow = document.createElement('tr');

        let th;
        if (coop_flag) {
            th = document.createElement('th');
            th.className = 'team';
            th.textContent = 'Co-op';
            headerRow.appendChild(th);
        } else {
            th = document.createElement('th');
            th.className = 'pos';
            let span = document.createElement('span');
            span.className = 'screen-reader-text';
            span.textContent = 'Rank';
            th.appendChild(span);
            headerRow.appendChild(th);

            th = document.createElement('th');
            th.className = 'team';
            th.textContent = 'Team';
            headerRow.appendChild(th);
        }
        th = document.createElement('th');
        th.className = 'contribution';
        th.textContent = 'Contributors';
        headerRow.appendChild(th);

        if (display_type === 'by_race_time') {
            th = document.createElement('th');
            th.className = 'laps';
            th.textContent = 'Laps';
            headerRow.appendChild(th);

            th = document.createElement('th');
            th.className = 'total';
            th.textContent = 'Average Lap';
            headerRow.appendChild(th);
        }
        if (display_type === 'by_avg_fastest_lap') {
            th = document.createElement('th');
            th.className = 'fast';
            th.textContent = 'Average Fastest';
            headerRow.appendChild(th);
        }
        if (display_type === 'by_avg_consecutives') {
            th = document.createElement('th');
            th.className = 'consecutive';
            th.textContent = 'Average' + ' ' + meta.consecutives_count + ' ' + 'Consecutive';
            headerRow.appendChild(th);
        }
        header.appendChild(headerRow);
        table.appendChild(header);

        const body = document.createElement('tbody');
        for (const i in leaderboard) {
            const data = leaderboard[i];
            const row = document.createElement('tr');
            if (!coop_flag) {
                let td = document.createElement('td');
                td.className = 'pos';
                td.innerHTML = (data.position != null ? data.position : '-');
                row.appendChild(td);
            }
            let td = document.createElement('td');
            td.className = 'team';
            td.textContent = data.name;
            row.appendChild(td);

            td = document.createElement('td');
            td.className = 'contribution';
            td.textContent = data.contributing + '/' + data.members;
            row.appendChild(td);

            if (display_type === 'by_race_time') {
                let lap = data.laps;
                if (!lap || lap === '0:00.000') lap = '&#8212;';
                td = document.createElement('td');
                td.className = 'laps';
                td.innerHTML = lap;
                row.appendChild(td);

                lap = data.average_lap;
                if (!lap || lap === '0:00.000') lap = '&#8212;';
                td = document.createElement('td');
                td.className = 'total';
                td.innerHTML = lap;
                row.appendChild(td);
            }
            if (display_type === 'by_avg_fastest_lap') {
                let lap = data.average_fastest_lap;
                if (!lap || lap === '0:00.000') lap = '&#8212;';
                td = document.createElement('td');
                td.className = 'fast';
                td.innerHTML = lap;
                row.appendChild(td);
            }
            if (display_type === 'by_avg_consecutives') {
                let lap = data.average_consecutives;
                if (!lap || lap === '0:00.000') lap = '&#8212;';
                td = document.createElement('td');
                td.className = 'consecutive';
                td.innerHTML = lap;
                row.appendChild(td);
            }
            body.appendChild(row);
        }
        table.appendChild(body);
        twrap.appendChild(table);
        return twrap;
    }

    /**
     * Build a ranking table.
     * @param {Object} ranking - Ranking object containing ranking data and meta.
     * @returns {HTMLElement} - A DOM element (either a DIV wrapping the table or a paragraph if no ranking was produced).
     */
    build_ranking(ranking) {
        const leaderboard = ranking.ranking;
        const meta = ranking.meta;
        if (!leaderboard || !(meta && meta.rank_fields)) {
            const p = document.createElement('p');
            p.textContent = __(meta.method_label) + " " + 'did not produce a ranking.';
            return p;
        }

        const twrap = document.createElement('div');
        twrap.className = 'responsive-wrap';

        const table = document.createElement('table');
        table.className = 'leaderboard';

        const header = document.createElement('thead');
        const headerRow = document.createElement('tr');

        let th = document.createElement('th');
        th.className = 'pos';
        let span = document.createElement('span');
        span.className = 'screen-reader-text';
        span.textContent = 'Rank';
        th.appendChild(span);
        headerRow.appendChild(th);

        th = document.createElement('th');
        th.className = 'pilot';
        th.textContent = 'Pilot';
        headerRow.appendChild(th);

        if ('team_racing_mode' in meta && meta.team_racing_mode == RACING_MODE_TEAM) {
            th = document.createElement('th');
            th.className = 'team';
            th.textContent = 'Team';
            headerRow.appendChild(th);
        }

        for (const f in meta.rank_fields) {
            const field = meta.rank_fields[f];
            th = document.createElement('th');
            th.className = field.name;
            th.textContent = __(field.label);
            headerRow.appendChild(th);
        }
        header.appendChild(headerRow);
        table.appendChild(header);

        const body = document.createElement('tbody');
        for (const i in leaderboard) {
            const data = leaderboard[i];
            const row = document.createElement('tr');

            let td = document.createElement('td');
            td.className = 'pos';
            td.innerHTML = (data.position != null ? data.position : '-');
            row.appendChild(td);

            td = document.createElement('td');
            td.className = 'pilot';
            td.textContent = data.callsign;
            row.appendChild(td);

            if ('team_racing_mode' in meta && meta.team_racing_mode === RACING_MODE_TEAM) {
                td = document.createElement('td');
                td.className = 'team';
                td.textContent = data.team_name;
                row.appendChild(td);
            }
            for (const f in meta.rank_fields) {
                const field = meta.rank_fields[f];
                td = document.createElement('td');
                td.className = field.name;
                td.textContent = data[field.name];
                row.appendChild(td);
            }
            body.appendChild(row);
        }
        table.appendChild(body);
        twrap.appendChild(table);
        return twrap;
    }

    /**
     * Frequency table helper object.
     */
    freq = {
        frequencies: {
            R1: 5658,
            R2: 5695,
            R3: 5732,
            R4: 5769,
            R5: 5806,
            R6: 5843,
            R7: 5880,
            R8: 5917,
            F1: 5740,
            F2: 5760,
            F3: 5780,
            F4: 5800,
            F5: 5820,
            F6: 5840,
            F7: 5860,
            F8: 5880,
            E1: 5705,
            E2: 5685,
            E3: 5665,
            E4: 5645,
            E5: 5885,
            E6: 5905,
            E7: 5925,
            E8: 5945,
            B1: 5733,
            B2: 5752,
            B3: 5771,
            B4: 5790,
            B5: 5809,
            B6: 5828,
            B7: 5847,
            B8: 5866,
            A1: 5865,
            A2: 5845,
            A3: 5825,
            A4: 5805,
            A5: 5785,
            A6: 5765,
            A7: 5745,
            A8: 5725,
            L1: 5362,
            L2: 5399,
            L3: 5436,
            L4: 5473,
            L5: 5510,
            L6: 5547,
            L7: 5584,
            L8: 5621,
            U0: 5300,
            U1: 5325,
            U2: 5348,
            U3: 5366,
            U4: 5384,
            U5: 5402,
            U6: 5420,
            U7: 5438,
            U8: 5456,
            U9: 5985,
            D1: 5660,
            D2: 5695,
            D3: 5735,
            D4: 5770,
            D5: 5805,
            D6: 5880,
            D7: 5914,
            D8: 5839,
            J1: 5695,
            J2: 5770,
            J3: 5880,
            S1: 5660,
            S2: 5695,
            S3: 5735,
            S4: 5770,
            S5: 5805,
            S6: 5839,
            S7: 5878,
            S8: 5914,
            O1: 5669,
            O2: 5705,
            O3: 5768,
            O4: 5804,
            O5: 5839,
            O6: 5876,
            O7: 5912,
            Q1: 5677,
            Q2: 5794,
            Q3: 5902,
        },
        getFObjbyFData(fData) {
            const keyNames = Object.keys(this.frequencies);
            if (fData.frequency == 0) {
                return {
                    key: '—',
                    fString: 0,
                    band: null,
                    channel: null,
                    frequency: 0,
                };
            }
            const fKey = "" + fData.band + fData.channel;
            if (fKey in this.frequencies) {
                if (this.frequencies[fKey] == fData.frequency) {
                    return {
                        key: fKey,
                        fString: fKey + ':' + this.frequencies[fKey],
                        band: fData.band,
                        channel: fData.channel,
                        frequency: fData.frequency,
                    };
                }
            }
            return this.findByFreq(fData.frequency);
        },
        getFObjbyKey(key) {
            const regex = /([A-Za-z]*)([0-9]*)/;
            const parts = key.match(regex);
            if (parts && parts.length === 3) {
                return {
                    key: key,
                    fString: key + ':' + this.frequencies[key],
                    band: parts[1],
                    channel: parts[2],
                    frequency: this.frequencies[key],
                };
            }
            return false;
        },
        getFObjbyFString(fstring) {
            if (fstring == 0) {
                return {
                    key: '—',
                    fString: 0,
                    band: null,
                    channel: null,
                    frequency: 0,
                };
            }
            if (fstring == "n/a") {
                return {
                    key: "X",
                    fString: "n/a",
                    band: null,
                    channel: null,
                    frequency: 0,
                };
            }
            const regex = /([A-Za-z]*)([0-9]*):([0-9]{4})/;
            const parts = fstring.match(regex);
            if (parts && parts.length === 4) {
                return {
                    key: "" + parts[1] + parts[2],
                    fString: fstring,
                    band: parts[1],
                    channel: parts[2],
                    frequency: parts[3],
                };
            }
            return false;
        },
        findByFreq(frequency) {
            if (frequency == 0) {
                return {
                    key: '—',
                    fString: 0,
                    band: null,
                    channel: null,
                    frequency: 0,
                };
            }
            const keyNames = Object.keys(this.frequencies);
            for (const i in keyNames) {
                if (this.frequencies[keyNames[i]] == frequency) {
                    const fObj = this.getFObjbyKey(keyNames[i]);
                    if (fObj) return fObj;
                }
            }
            return {
                key: "X",
                fString: "n/a",
                band: null,
                channel: null,
                frequency: frequency,
            };
        },
        buildSelect() {
            let output = '<option value="0">' + 'Disabled' + '</option>';
            const keyNames = Object.keys(this.frequencies);
            for (const i in keyNames) {
                output += '<option value="' + keyNames[i] + ':' + this.frequencies[keyNames[i]] + '">' + keyNames[i] + ' ' + this.frequencies[keyNames[i]] + '</option>';
            }
            output += '<option value="n/a">' + 'N/A' + '</option>';
            return output;
        },
        updateBlock(fObj, node_idx) {
            const channelBlock = document.querySelector(`.channel-block[data-node="${node_idx}"]`);
            if (!channelBlock) return;
            const ch = channelBlock.querySelector('.ch');
            const fr = channelBlock.querySelector('.fr');
            if (fObj === null || fObj.frequency == 0) {
                if (ch) ch.innerHTML = '—';
                if (fr) fr.innerHTML = '';
                channelBlock.setAttribute('title', '');
            } else {
                if (ch) ch.innerHTML = fObj.key;
                if (fr) fr.innerHTML = fObj.frequency;
                channelBlock.setAttribute('title', fObj.frequency);
            }
        },
        updateBlocks() {
            for (const i in rotorhazard.nodes) {
                this.updateBlock(rotorhazard.nodes[i].fObj, i);
            }
            this.updateBlock(null, null);
        },
    };
    // Pad to 2 or 3 digits, default is 2
    pad(n, z = 2) {
        return ('000000' + n).slice(-z);
    }

}
export const displayStatsInstance = new DisplayStats();
// End of rm-m-displayStats.js
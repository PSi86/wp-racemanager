// rm-m-displayStats.js
// Code copied from RotorHazard
// https://github.com/RotorHazard/RotorHazard/blob/main/src/server/templates/results.html
// https://github.com/RotorHazard/RotorHazard/blob/main/src/server/static/rotorhazard.js

import { dataLoaderInstance } from './rm-m-dataLoader.js';
import { pilotSelectInstance } from './rm-m-pilotSelector.js';
const $ = window.jQuery;

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

        this.pilotSelectorId = pilotSelectInstance.pilotSelectorId; // Load pilotSelectorId from pilotSelector
        this.pilotSelectorElement = document.getElementById(`${this.pilotSelectorId}`);
        this.selectedPilotId = pilotSelectInstance.selectedPilotId;
        //console.log("displayStats: initialized with selected pilot ID:", typeof(this.selectedPilotId), this.selectedPilotId);

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

        this.pilotSelectorElement.addEventListener('change', this.handleFilterChange.bind(this));

        // Subscribe to the dataLoader (singleton)
        console.log("displayStats: Subscribed to DataLoader");
        dataLoaderInstance.subscribe(this.displayStats.bind(this));
    }

    handleFilterChange() {
        //this.selectedPilotId = event.target.value;
        this.selectedPilotId = parseInt(this.pilotSelectorElement.value) || 0; // could possibly be pulled from the pilotSelector instance
        
        if(this.filterCheckboxElement) {
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
    displayStats(msg) {
        const $ = window.jQuery;
        // Helper function to order leaderboard boards
        const orderBoards = (primary) => {
            const boards = ['by_race_time', 'by_fastest_lap', 'by_consecutives'];
            boards.sort((x, y) => x === primary ? -1 : y === primary ? 1 : 0);
            return boards;
        };
        const RACING_MODE_INDV = 0;   // INDIVIDUAL
        const RACING_MODE_TEAM = 1;   // TEAM_ENABLED
        const RACING_MODE_COOP = 2;   // COOP_ENABLED
        // Set default meta using values from msg (RACING_MODE_INDV assumed global or imported)
        const defaultMeta = {
            team_racing_mode: RACING_MODE_INDV,
            start_behavior: 0,
            consecutives_count: msg.consecutives_count
        };

        const page = $('#results');
        page.empty();

        if (!$.isEmptyObject(msg.heats)) {
            // Move unclassified heats to end of list (assumes Object.keys ordering as per ES6 or server addition order)
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
                    const classPanel = $('<div>', { id: 'class_' + classIndex, class: 'panel collapsing' });
                    const classPanelHeader = $('<div>', { class: 'panel-header' });
                    const classPanelContent = $('<div>', { class: 'panel-content', style: 'display: none' });

                    const currentClass = msg.classes[race_class];
                    if (currentClass) {
                        if (currentClass.name) {
                            classPanelHeader.append('<h2><button class="no-style">' + currentClass.name + '</button></h2>');
                        } else {
                            classPanelHeader.append('<h2><button class="no-style">' + __('Class') + ' ' + currentClass.id + '</button></h2>');
                        }

                        if (currentClass.ranking) {
                            const classSpecial = $('<div>', { id: 'class_' + classIndex + '_leaderboard', class: 'panel collapsing class-leaderboard' });
                            const classSpecialHeader = $('<div>', { class: 'panel-header' });
                            let classHeaderText = __('Class Ranking');
                            if (currentClass.ranking.meta?.method_label) {
                                classHeaderText += '&#8212;' + currentClass.ranking.meta.method_label;
                            }
                            classSpecialHeader.append('<h3><button class="no-style">' + classHeaderText + '</button></h3>');
                            classSpecial.append(classSpecialHeader);

                            const classSpecialContent = $('<div>', { class: 'panel-content', style: 'display: none' });
                            classSpecialContent.append(build_ranking(currentClass.ranking));
                            classSpecial.append(classSpecialContent);
                            classPanelContent.append(classSpecial);
                        }

                        const classLeaderboard = $('<div>', { id: 'class_' + classIndex + '_leaderboard', class: 'panel collapsing class-leaderboard' });
                        const classLeaderboardHeader = $('<div>', { class: 'panel-header' });
                        classLeaderboardHeader.append('<h3><button class="no-style">' + __('Class Summary') + '</button></h3>');
                        classLeaderboard.append(classLeaderboardHeader);

                        const classLeaderboardContent = $('<div>', { class: 'panel-content', style: 'display: none' });
                        const boards = orderBoards(currentClass.leaderboard.meta.primary_leaderboard);

                        boards.forEach((board) => {
                            if (board === 'by_race_time') {
                                classLeaderboardContent.append('<h4>' + __('Race Totals') + '</h4>');
                                classLeaderboardContent.append(
                                    build_leaderboard(
                                        currentClass.leaderboard.by_race_time,
                                        'by_race_time',
                                        currentClass.leaderboard.meta,
                                        true
                                    )
                                );
                            } else if (board === 'by_fastest_lap') {
                                classLeaderboardContent.append('<h4>' + __('Fastest Laps') + '</h4>');
                                classLeaderboardContent.append(
                                    build_leaderboard(
                                        currentClass.leaderboard.by_fastest_lap,
                                        'by_fastest_lap',
                                        currentClass.leaderboard.meta,
                                        true
                                    )
                                );
                            } else if (board === 'by_consecutives') {
                                classLeaderboardContent.append('<h4>' + __('Fastest Consecutive Laps') + '</h4>');
                                classLeaderboardContent.append(
                                    build_leaderboard(
                                        currentClass.leaderboard.by_consecutives,
                                        'by_consecutives',
                                        currentClass.leaderboard.meta,
                                        true
                                    )
                                );
                            }
                        });
                        classLeaderboard.append(classLeaderboardContent);
                        classPanelContent.append(classLeaderboard);
                    } else {
                        if ($.isEmptyObject(msg.classes)) {
                            classPanelHeader.append('<h2><button class="no-style">' + __('Heats') + '</button></h2>');
                        } else {
                            classPanelHeader.append('<h2><button class="no-style">' + __('Unclassified') + '</button></h2>');
                        }
                    }

                    classPanel.append(classPanelHeader);

                    msg.heats_by_class[race_class].forEach((heatId, heatIndex) => {
                        const heat = msg.heats[heatId];
                        if (heat) {
                            const panel = $('<div>', { id: 'class_' + classIndex + '_heat_' + heatIndex, class: 'panel collapsing' });
                            const panelHeader = $('<div>', { class: 'panel-header' });
                            panelHeader.append('<h3><button class="no-style">' + heat.displayname + '</button></h3>');
                            panel.append(panelHeader);

                            const panelContent = $('<div>', { class: 'panel-content', style: 'display: none' });

                            // Heat leaderboard (if more than one round)
                            if (heat.rounds.length > 1) {
                                const heatSummaryPanel = $('<div>', {
                                    id: 'class_' + classIndex + '_heat_' + heatIndex + '_leaderboard',
                                    class: 'panel collapsing open'
                                });
                                heatSummaryPanel.append(
                                    '<div class="panel-header"><h4><button class="no-style">' + __('Heat Summary') + '</button></h4></div>'
                                );
                                const heatSummaryPanelContent = $('<div>', { class: 'panel-content' });
                                const heatLeaderboard = $('<div>', { class: 'leaderboard' });
                                heatLeaderboard.append(
                                    build_leaderboard(
                                        heat.leaderboard[heat.leaderboard.meta.primary_leaderboard],
                                        'heat',
                                        heat.leaderboard.meta,
                                        true
                                    )
                                );
                                heatSummaryPanelContent.append(heatLeaderboard);
                                heatSummaryPanel.append(heatSummaryPanelContent);
                                panelContent.append(heatSummaryPanel);
                            }

                            // Rounds for this heat
                            heat.rounds.forEach((round) => {
                                const roundDiv = $('<div>', {
                                    id: 'class_' + classIndex + '_heat_' + heatIndex + '_round_' + round.id,
                                    class: 'round panel collapsing open'
                                });
                                roundDiv.append(
                                    '<div class="panel-header"><h4><button class="no-style">' +
                                    __('Round') +
                                    ' ' +
                                    round.id +
                                    ' (' +
                                    round.start_time_formatted +
                                    ')</button></h4></div>'
                                );
                                const roundContent = $('<div>', { class: 'panel-content' });
                                // Race leaderboard for the round
                                const raceLeaderboard = $('<div>', { class: 'leaderboard' });
                                raceLeaderboard.append(
                                    build_leaderboard(
                                        round.leaderboard[round.leaderboard.meta.primary_leaderboard],
                                        'round',
                                        round.leaderboard.meta
                                    )
                                );
                                const raceResults = $('<div>', { class: 'race-results' });

                                // Race laps for each node in the round
                                round.nodes.forEach((node) => {
                                    if (node.callsign !== null) {
                                        const nodeDiv = $('<div>', { class: 'node' });
                                        nodeDiv.append('<h5>' + node.callsign + '</h5>');
                                        const table = $('<table>', { class: 'laps' });
                                        const tbody = $('<tbody>');
                                        let lapIndex = ('start_behavior' in round.leaderboard.meta && round.leaderboard.meta.start_behavior == 1)
                                            ? 1
                                            : 0;
                                        node.laps.forEach((lap) => {
                                            if (!lap.deleted) {
                                                if (lapIndex) {
                                                    let timestamp;
                                                    if ('start_behavior' in round.leaderboard.meta && round.leaderboard.meta.start_behavior == 2) {
                                                        timestamp =
                                                            formatTimeMillis(lap.lap_time_stamp - node.laps[0].lap_time_stamp, "{m}:{s}.{d}") +
                                                            ' / ' +
                                                            formatTimeMillis(lap.lap_time_stamp, "{m}:{s}.{d}");
                                                    } else {
                                                        timestamp = formatTimeMillis(lap.lap_time_stamp, "{m}:{s}.{d}");
                                                    }
                                                    tbody.append(
                                                        '<tr class="lap_' +
                                                        lapIndex +
                                                        '"><td>' +
                                                        lapIndex +
                                                        '</td><td><span class="from_start">' +
                                                        timestamp +
                                                        '</span>' +
                                                        formatTimeMillis(lap.lap_time, "{m}:{s}.{d}") +
                                                        '</td></tr>'
                                                    );
                                                } else {
                                                    tbody.append(
                                                        '<tr class="lap_0"><td>0</td><td>' +
                                                        formatTimeMillis(lap.lap_time, "{m}:{s}.{d}") +
                                                        '</td></tr>'
                                                    );
                                                }
                                                lapIndex++;
                                            }
                                        });
                                        table.append(tbody);
                                        nodeDiv.append(table);
                                        raceResults.append(nodeDiv);
                                    }
                                });
                                roundContent.append(raceLeaderboard);
                                roundContent.append(raceResults);
                                roundDiv.append(roundContent);
                                panelContent.append(roundDiv);
                            });
                            panel.append(panelContent);
                            classPanelContent.append(panel);
                        }
                    });
                    classPanel.append(classPanelContent);
                    page.append(classPanel);
                }
            });

            // Event leaderboard
            const eventPanel = $('<div>', { id: 'event_leaderboard', class: 'panel collapsing' });
            const eventPanelHeader = $('<div>', { class: 'panel-header' });
            eventPanelHeader.append('<h2><button class="no-style">' + __('Event Totals') + '</button></h2>');
            eventPanel.append(eventPanelHeader);

            const eventPanelContent = $('<div>', { class: 'panel-content', style: 'display: none' });
            const eventLeaderboard = $('<div>', { class: 'event-leaderboards' });
            eventLeaderboard.append('<h3>' + __('Race Totals') + '</h3>');
            eventLeaderboard.append(
                build_leaderboard(msg.event_leaderboard.by_race_time, 'by_race_time', msg.event_leaderboard.meta, true)
            );
            eventLeaderboard.append('<h3>' + __('Fastest Laps') + '</h3>');
            eventLeaderboard.append(
                build_leaderboard(msg.event_leaderboard.by_fastest_lap, 'by_fastest_lap', msg.event_leaderboard.meta)
            );
            eventLeaderboard.append('<h3>' + __('Fastest Consecutive Laps') + '</h3>');
            eventLeaderboard.append(
                build_leaderboard(msg.event_leaderboard.by_consecutives, 'by_consecutives', msg.event_leaderboard.meta)
            );
            eventPanelContent.append(eventLeaderboard);
            eventPanel.append(eventPanelContent);
            page.append(eventPanel);

            // Load panel state based on rotorhazard.panelstates (assumed available globally)
            for (const panelId in rotorhazard.panelstates) {
                const panelObj = $('#' + panelId);
                const panelState = rotorhazard.panelstates[panelId];
                if (panelState) {
                    panelObj.addClass('open');
                    panelObj.children('.panel-content').stop().slideDown();
                } else {
                    panelObj.removeClass('open');
                    panelObj.children('.panel-content').stop().slideUp();
                }
            }
        } else {
            page.append('<p>' + __('There is no saved race data available to view.') + '</p>');
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
        var formatted_time = timeformat.replace('{d}', pad(ms, 3));
        formatted_time = formatted_time.replace('{s}', pad(secs));
        formatted_time = formatted_time.replace('{m}', mins)

        return formatted_time;
    }
    /* Leaderboards */
    build_leaderboard(leaderboard, display_type, meta, display_starts = false) {
        if (typeof (display_type) === 'undefined')
            var display_type = 'by_race_time';
        if (typeof (meta) === 'undefined') {
            var meta = new Object;
            meta.team_racing_mode = RACING_MODE_INDV;
            meta.start_behavior = 0;
            meta.consecutives_count = 0;
            meta.primary_leaderboard = null;
        }

        if (display_type == 'round') {
            var show_points = true;
        } else {
            var show_points = false;
        }

        if (meta.start_behavior == 2) {
            var total_label = __('Laps Total');
        } else {
            var total_label = __('Total');
        }

        var twrap = $('<div class="responsive-wrap">');
        var table = $('<table class="leaderboard">');
        var header = $('<thead>');
        var header_row = $('<tr>');
        header_row.append('<th class="pos"><span class="screen-reader-text">' + __('Rank') + '</span></th>');
        header_row.append('<th class="pilot">' + __('Pilot') + '</th>');
        if (meta.team_racing_mode == RACING_MODE_TEAM) {
            header_row.append('<th class="team">' + __('Team') + '</th>');
        }
        if (display_starts == true) {
            header_row.append('<th class="starts">' + __('Starts') + '</th>');
        }
        if (display_type == 'by_race_time' ||
            display_type == 'heat' ||
            display_type == 'round' ||
            display_type == 'current') {
            header_row.append('<th class="laps">' + __('Laps') + '</th>');
            header_row.append('<th class="total">' + total_label + '</th>');
            header_row.append('<th class="avg">' + __('Avg.') + '</th>');
        }
        if (display_type == 'by_fastest_lap' ||
            display_type == 'heat' ||
            display_type == 'round' ||
            display_type == 'current') {
            header_row.append('<th class="fast">' + __('Fastest') + '</th>');
            if (display_type == 'by_fastest_lap') {
                header_row.append('<th class="source">' + __('Source') + '</th>');
            }
        }
        if (display_type == 'by_consecutives' ||
            display_type == 'heat' ||
            display_type == 'round' ||
            display_type == 'current') {
            header_row.append('<th class="consecutive">' + __('Consecutive') + '</th>');
            if (display_type == 'by_consecutives') {
                header_row.append('<th class="source">' + __('Source') + '</th>');
            }
        }
        if (show_points && 'primary_points' in meta) {
            header_row.append('<th class="points">' + __('Points') + '</th>');
        }
        header.append(header_row);
        table.append(header);

        var body = $('<tbody>');

        for (var i in leaderboard) {
            var row = $('<tr>');

            row.append('<td class="pos">' + (leaderboard[i].position != null ? leaderboard[i].position : '-') + '</td>');
            row.append('<td class="pilot">' + leaderboard[i].callsign + '</td>');
            if (meta.team_racing_mode == RACING_MODE_TEAM) {
                row.append('<td class="team">' + leaderboard[i].team_name + '</td>');
            }
            if (display_starts == true) {
                row.append('<td class="starts">' + leaderboard[i].starts + '</td>');
            }
            if (display_type == 'by_race_time' ||
                display_type == 'heat' ||
                display_type == 'round' ||
                display_type == 'current') {
                var lap = leaderboard[i].laps;
                if (!lap || lap == '0:00.000')
                    lap = '&#8212;';
                row.append('<td class="laps">' + lap + '</td>');

                if (meta.start_behavior == 2) {
                    var lap = leaderboard[i].total_time_laps;
                } else {
                    var lap = leaderboard[i].total_time;
                }
                if (!lap || lap == '0:00.000')
                    lap = '&#8212;';
                row.append('<td class="total">' + lap + '</td>');

                var lap = leaderboard[i].average_lap;
                if (!lap || lap == '0:00.000')
                    lap = '&#8212;';
                row.append('<td class="avg">' + lap + '</td>');
            }
            if (display_type == 'by_fastest_lap' ||
                display_type == 'heat' ||
                display_type == 'round' ||
                display_type == 'current') {
                var lap = leaderboard[i].fastest_lap;
                if (!lap || lap == '0:00.000')
                    lap = '&#8212;';

                var el = $('<td class="fast">' + lap + '</td>');

                if (leaderboard[i].fastest_lap_source) {
                    var source = leaderboard[i].fastest_lap_source;
                    if (source.round) {
                        var source_text = source.displayname + ' / ' + __('Round') + ' ' + source.round;
                    } else {
                        var source_text = source.displayname;
                    }
                } else {
                    var source_text = 'None';
                }

                if (display_type == 'heat') {
                    el.data('source', source_text);
                    el.attr('title', source_text);
                }

                if ('min_lap' in rotorhazard
                    && rotorhazard.min_lap > 0
                    && leaderboard[i].fastest_lap_raw > 0
                    && (rotorhazard.min_lap * 1000) > leaderboard[i].fastest_lap_raw
                ) {
                    el.addClass('min-lap-warning');
                }

                row.append(el);

                if (display_type == 'by_fastest_lap') {
                    row.append('<td class="source">' + source_text + '</td>');
                }
            }
            if (display_type == 'by_consecutives' ||
                display_type == 'heat' ||
                display_type == 'round' ||
                display_type == 'current') {
                var data = leaderboard[i];
                if (!data.consecutives || data.consecutives == '0:00.000') {
                    lap = '&#8212;';
                } else {
                    lap = data.consecutives_base + '/' + data.consecutives;
                }

                var el = $('<td class="consecutive">' + lap + '</td>');

                if (leaderboard[i].consecutives_source) {
                    var source = leaderboard[i].consecutives_source;
                    if (source.round) {
                        var source_text = source.displayname + ' / ' + __('Round') + ' ' + source.round;
                    } else {
                        var source_text = source.displayname;
                    }
                } else {
                    var source_text = 'None';
                }

                if (display_type == 'heat') {
                    el.data('source', source_text);
                    el.attr('title', source_text);
                }

                row.append(el);

                if (display_type == 'by_consecutives') {
                    row.append('<td class="source">' + source_text + '</td>');
                }
            }

            if (show_points && 'primary_points' in meta) {
                row.append('<td class="points">' + leaderboard[i].points + '</td>');
            }
            body.append(row);
        }

        table.append(body);
        twrap.append(table);
        return twrap;
    }
    build_team_leaderboard(leaderboard, display_type, meta) {
        if (typeof (display_type) === 'undefined')
            display_type = 'by_race_time';
        if (typeof (meta) === 'undefined') {
            meta = new Object;
            meta.team_racing_mode = RACING_MODE_TEAM;
            meta.consecutives_count = 0;
        }
        var coop_flag = (leaderboard.length == 1 && leaderboard[0].name == "Group")

        var twrap = $('<div class="responsive-wrap">');
        var table = $('<table class="leaderboard">');
        var header = $('<thead>');
        var header_row = $('<tr>');
        if (coop_flag) {
            header_row.append('<th class="team">' + __('Co-op') + '</th>');
        } else {
            header_row.append('<th class="pos"><span class="screen-reader-text">' + __('Rank') + '</span></th>');
            header_row.append('<th class="team">' + __('Team') + '</th>');
        }
        header_row.append('<th class="contribution">' + __('Contributors') + '</th>');
        if (display_type == 'by_race_time') {
            header_row.append('<th class="laps">' + __('Laps') + '</th>');
            header_row.append('<th class="total">' + __('Average Lap') + '</th>');
        }
        if (display_type == 'by_avg_fastest_lap') {
            header_row.append('<th class="fast">' + __('Average Fastest') + '</th>');
        }
        if (display_type == 'by_avg_consecutives') {
            header_row.append('<th class="consecutive">' + __('Average') + ' ' + meta.consecutives_count + ' ' + __('Consecutive') + '</th>');
        }
        header.append(header_row);
        table.append(header);

        var body = $('<tbody>');

        for (var i in leaderboard) {
            var row = $('<tr>');
            if (!coop_flag) {
                row.append('<td class="pos">' + (leaderboard[i].position != null ? leaderboard[i].position : '-') + '</td>');
            }
            row.append('<td class="team">' + leaderboard[i].name + '</td>');
            row.append('<td class="contribution">' + leaderboard[i].contributing + '/' + leaderboard[i].members + '</td>');
            if (display_type == 'by_race_time') {
                var lap = leaderboard[i].laps;
                if (!lap || lap == '0:00.000')
                    lap = '&#8212;';
                row.append('<td class="laps">' + lap + '</td>');

                var lap = leaderboard[i].average_lap;
                if (!lap || lap == '0:00.000')
                    lap = '&#8212;';
                row.append('<td class="total">' + lap + '</td>');
            }
            if (display_type == 'by_avg_fastest_lap') {
                var lap = leaderboard[i].average_fastest_lap;
                if (!lap || lap == '0:00.000')
                    lap = '&#8212;';
                row.append('<td class="fast">' + lap + '</td>');
            }
            if (display_type == 'by_avg_consecutives') {
                var lap = leaderboard[i].average_consecutives;
                if (!lap || lap == '0:00.000')
                    lap = '&#8212;';
                row.append('<td class="consecutive">' + lap + '</td>');
            }

            body.append(row);
        }

        table.append(body);
        twrap.append(table);
        return twrap;
    }
    build_ranking(ranking) {
        var leaderboard = ranking.ranking;
        var meta = ranking.meta;

        if (!leaderboard || !meta?.rank_fields) {
            return $('<p>' + __(meta.method_label) + " " + __('did not produce a ranking.') + '</p>');
        }

        var twrap = $('<div class="responsive-wrap">');
        var table = $('<table class="leaderboard">');
        var header = $('<thead>');
        var header_row = $('<tr>');
        header_row.append('<th class="pos"><span class="screen-reader-text">' + __('Rank') + '</span></th>');
        header_row.append('<th class="pilot">' + __('Pilot') + '</th>');
        if ('team_racing_mode' in meta && meta.team_racing_mode == RACING_MODE_TEAM) {
            header_row.append('<th class="team">' + __('Team') + '</th>');
        }
        for (var f in meta.rank_fields) {
            field = meta.rank_fields[f];
            header_row.append('<th class="' + field.name + '">' + __(field.label) + '</th>');
        }
        header.append(header_row);
        table.append(header);

        var body = $('<tbody>');

        for (var i in leaderboard) {
            var row = $('<tr>');

            row.append('<td class="pos">' + (leaderboard[i].position != null ? leaderboard[i].position : '-') + '</td>');
            row.append('<td class="pilot">' + leaderboard[i].callsign + '</td>');
            if ('team_racing_mode' in meta && meta.team_racing_mode == RACING_MODE_TEAM) {
                row.append('<td class="team">' + leaderboard[i].team_name + '</td>');
            }
            for (var f in meta.rank_fields) {
                field = meta.rank_fields[f];
                row.append('<td class="' + field.name + '">' + leaderboard[i][field.name] + '</td>');
            }
            body.append(row);
        }

        table.append(body);
        twrap.append(table);
        return twrap;
    }
    /* Frequency Table */
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
        getFObjbyFData: function (fData) {
            var keyNames = Object.keys(this.frequencies);

            if (fData.frequency == 0) {
                return {
                    key: '—',
                    fString: 0,
                    band: null,
                    channel: null,
                    frequency: 0
                }
            }

            var fKey = "" + fData.band + fData.channel;
            if (fKey in this.frequencies) {
                if (this.frequencies[fKey] == fData.frequency) {
                    return {
                        key: fKey,
                        fString: fKey + ':' + this.frequencies[fKey],
                        band: fData.band,
                        channel: fData.channel,
                        frequency: fData.frequency
                    }
                }
            }

            return this.findByFreq(fData.frequency)
        },
        getFObjbyKey: function (key) {
            var regex = /([A-Za-z]*)([0-9]*)/;
            var parts = key.match(regex);
            if (parts && parts.length == 3) {
                return {
                    key: key,
                    fString: key + ':' + this.frequencies[key],
                    band: parts[1],
                    channel: parts[2],
                    frequency: this.frequencies[key]
                }
            }
            return false;
        },
        getFObjbyFString: function (fstring) {
            if (fstring == 0) {
                return {
                    key: '—',
                    fString: 0,
                    band: null,
                    channel: null,
                    frequency: 0
                }
            }

            if (fstring == "n/a") {
                return {
                    key: __("X"),
                    fString: "n/a",
                    band: null,
                    channel: null,
                    frequency: frequency
                }
            }
            var regex = /([A-Za-z]*)([0-9]*):([0-9]{4})/;
            var parts = fstring.match(regex);
            if (parts && parts.length == 4) {
                return {
                    key: "" + parts[1] + parts[2],
                    fString: fstring,
                    band: parts[1],
                    channel: parts[2],
                    frequency: parts[3]
                }
            }
            return false;
        },
        getFObjbyKey: function (key) {
            var regex = /([A-Za-z]*)([0-9]*)/;
            var parts = key.match(regex);
            return {
                key: key,
                fString: key + ':' + this.frequencies[key],
                band: parts[1],
                channel: parts[2],
                frequency: this.frequencies[key]
            }
        },
        findByFreq: function (frequency) {
            if (frequency == 0) {
                return {
                    key: '—',
                    fString: 0,
                    band: null,
                    channel: null,
                    frequency: 0
                }
            }
            var keyNames = Object.keys(this.frequencies);
            for (var i in keyNames) {
                if (this.frequencies[keyNames[i]] == frequency) {
                    var fObj = this.getFObjbyKey(keyNames[i]);
                    if (fObj) return fObj;
                }
            }
            return {
                key: __("X"),
                fString: "n/a",
                band: null,
                channel: null,
                frequency: frequency
            }
        },
        buildSelect: function () {
            var output = '<option value="0">' + __('Disabled') + '</option>';
            var keyNames = Object.keys(this.frequencies);
            for (var i in keyNames) {
                output += '<option value="' + keyNames[i] + ':' + this.frequencies[keyNames[i]] + '">' + keyNames[i] + ' ' + this.frequencies[keyNames[i]] + '</option>';
            }
            output += '<option value="n/a">' + __('N/A') + '</option>';
            return output;
        },
        /*
        updateSelects: function() {
            for (var i in rotorhazard.nodes) {
                var freqExists = $('#f_table_' + i + ' option[value=' + rotorhazard.nodes[i].frequency + ']').length;
                if (freqExists) {
                    $('#f_table_' + i).val(rotorhazard.nodes[i].frequency);
                } else {
                    $('#f_table_' + i).val('n/a');
                }
            }
        },
        */
        updateBlock: function (fObj, node_idx) {
            // populate channel block
            var channelBlock = $('.channel-block[data-node="' + node_idx + '"]');
            if (fObj === null || fObj.frequency == 0) {
                channelBlock.children('.ch').html('—');
                channelBlock.children('.fr').html('');
                channelBlock.attr('title', '');
            } else {
                channelBlock.children('.ch').html(fObj.key);
                channelBlock.children('.fr').html(fObj.frequency);
                channelBlock.attr('title', fObj.frequency);
            }
        },
        updateBlocks: function () {
            // populate channel blocks
            for (var i in rotorhazard.nodes) {
                this.updateBlock(rotorhazard.nodes[i].fObj, i);
            }
            this.updateBlock(null, null);
        }
    }
}
export const displayStatsInstance = new DisplayStats();
// End of rm-m-displayStats.js
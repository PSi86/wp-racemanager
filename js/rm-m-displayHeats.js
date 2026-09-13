// rm-m-displayHeats.js
//
// The bracket view: every class of the event, the newest on top, each drawn as the bracket its
// heats' seeding forms (rm-m-bracketModel.js) - winners and losers bracket, a column per round
// under its name, the grand final at the end, Chase the Ace in the final - or, for a class that
// forms none (training, qualifying, a ladder), as its heats in a row. Below them, the heats no
// class claims, in a row, as RotorHazard lists them last under "Unclassified". And the next-up row.
//
// A heat shows a line per pilot, and per slot its seeding will fill, in the order of the seats. Each
// line carries the seat's video channel ("R1") once RotorHazard has fixed the seats (1.13.0), and
// before that the channel the pilot will likely get, marked ("R1?"), where it can be told (1.15.0).
// A seat nobody takes gets no line: until 1.13.0 every slot had one, and the empty lines stood for
// the free channels - on a timer with more nodes than pilots per heat, most of a heat.
//
// This file also runs on the timer: the RotorHazard connector's /bracketview takes it over byte
// for byte, next to its own dataLoader, which reads RotorHazard's socket. A change has to work
// there as well.
//
// Config: RmJsConfig.displayHeats.filterCheckboxId; flagBaseUrl, where the flags are ({code}.svg),
// to show a pilot's flag by the callsign - WP RaceManager's pages set it, the timer does not.
// Exports const displayHeatsInstance = new DisplayHeats(); (at the bottom)
// Empty slots are flagged as slot.pilot_id = null (newer RotorHazard) or 0 (earlier versions).

import { dataLoaderInstance } from './rm-m-dataLoader.js';
import { pilotSelectInstance } from './rm-m-pilotSelector.js';
// The whole module, not named imports: it is reached without a version, so a returning visitor
// may hold an older copy, and a missing name would take this module down with it.
import * as bracketModel from './rm-m-bracketModel.js';

const NEXT_UP_COUNT = 5;
// The section of the heats without a class (1.12.2), under id 0. RotorHazard gives such a heat the
// class_id null since 4.4.0 and 0 before; either way no class of the event has it.
const UNCLASSIFIED = { id: 0, name: 'Unclassified Heats', displayname: 'Unclassified Heats', rounds: 1 };

class DisplayHeats {
    constructor() {
        const configData = (window.RmJsConfig && window.RmJsConfig["displayHeats"]) || null;
        if (!configData) {
            throw new Error("displayHeats: Missing configuration data");
        }

        // Read dependency configuration
        this.raceId = dataLoaderInstance.storageKey; // Load storageKey from dataLoader

        this.pilotSelectorId = pilotSelectInstance.pilotSelectorId; // Load pilotSelectorId from pilotSelector
        this.pilotSelectorElement = document.getElementById(`${this.pilotSelectorId}`);
        this.selectedPilotId = pilotSelectInstance.selectedPilotId;

        // Optional properties
        this.filterCheckboxId = configData.filterCheckboxId || 'filterCheckbox';
        this.flagBaseUrl = typeof configData.flagBaseUrl === 'string' ? configData.flagBaseUrl : '';
        this.filterCheckboxElement = document.getElementById(`${this.filterCheckboxId}`);

        // currently not using the race_id in the key (making it globally reusable)
        this.filterCheckboxKey = this.filterCheckboxId;
        this.filterCheckboxState = JSON.parse(sessionStorage.getItem(this.filterCheckboxKey)) || false;

        this.cr_rh_data = null; // no default data, upon subscribing to dataLoader this will be populated
        this.reported = new Set();

        // Zoom level change detection
        this.lastDevicePixelRatio = window.devicePixelRatio;

        if (document.readyState === 'complete') {
            console.log("DisplayHeats: Site is already loaded, initializing immediately");
            this.initialize();
        } else {
            console.log("DisplayHeats: Registering load event listener");
            window.addEventListener('load', () => this.initialize());
        }
    }

    initialize() {
        // Init UI elements and attach event handlers
        if (this.filterCheckboxElement !== null) {
            this.filterCheckboxElement.checked = this.filterCheckboxState;
            this.filterCheckboxElement.addEventListener('change', this.handleFilterChange.bind(this));
        }

        if (this.pilotSelectorElement) {
            this.pilotSelectorElement.addEventListener('change', this.handleFilterChange.bind(this));
        }

        console.log("DisplayHeats: Subscribed to DataLoader");
        dataLoaderInstance.subscribe(this.handleDataLoaderEvent.bind(this));

        // Only attach new mouse eventhandler once after the data is loaded
        this.attachPilotMouseEvents();
        window.addEventListener("resize", () => {
            if (window.devicePixelRatio !== this.lastDevicePixelRatio) {
                this.lastDevicePixelRatio = window.devicePixelRatio;
                // The lines are measured in pixels: draw again at the new zoom.
                this.updateAllClasses();
            }
        });
    }

    handleFilterChange() {
        this.selectedPilotId = (this.pilotSelectorElement && parseInt(this.pilotSelectorElement.value)) || 0;

        if (this.filterCheckboxElement) {
            this.filterCheckboxState = this.filterCheckboxElement.checked;
            sessionStorage.setItem(this.filterCheckboxKey, this.filterCheckboxState);
        }

        console.log('DisplayHeats: Filter changed:', this.selectedPilotId, this.filterCheckboxState);
        this.updateAllClasses();
    }

    handleDataLoaderEvent(data) {
        console.log('DisplayHeats: Received data:', data);
        this.cr_rh_data = data;
        this.updateAllClasses();
    }

    updateAllClasses() {
        const data = this.cr_rh_data;
        if (!data || !data.heat_data || !data.class_data) {
            // The timer's loader used to hand {} over until RotorHazard had sent every section.
            return;
        }
        this.syncSections(data);
        // Whether any pilot has a flag: then those without keep its place.
        const profiles = data.pilot_profiles;
        this.anyCountry = !!(this.flagBaseUrl && profiles && typeof profiles === 'object' && !Array.isArray(profiles) &&
            Object.values(profiles).some(p => p && typeof p.country === 'string'));
        for (const container of [...document.getElementsByClassName("raceclass-container")]) {
            if (container.style.display === "none") {
                continue; // only visible class displays
            }
            try {
                this.updateContainer(container);
            } catch (error) {
                // One class the view cannot draw must not take the others with it.
                this.reportOnce(container.id, error);
                try {
                    this.updateContainer(container, true);
                } catch (fallbackError) {
                    this.reportOnce(container.id + ":row", fallbackError);
                }
            }
        }
    }

    // A class that fails to draw fails on every update; say so once, not every ten seconds.
    reportOnce(key, error) {
        if (this.reported.has(key)) return;
        this.reported.add(key);
        console.error(`DisplayHeats: could not draw ${key}; drawing it as a row instead.`, error);
    }

    // The classes that have heats, the newest first: the timer's order turned round - by `order`
    // when every class has one, else by id. So the elimination is on top, then qualifying, then
    // training, as the fixed containers of the page had them before 1.10.0 (1.12.1).
    classesInOrder(data) {
        const heats = data.heat_data.heats || [];
        const classes = (data.class_data.classes || []).filter(c => heats.some(h => h.class_id === c.id));
        if (classes.every(c => typeof c.order === 'number')) {
            return classes.sort((a, b) => b.order - a.order || b.id - a.id);
        }
        return classes.sort((a, b) => b.id - a.id);
    }

    // The heats no class of the event claims - class null or 0, or a class that is gone - in the
    // timer's order. An event without classes has only these: before 1.12.2 its page stayed empty.
    unclassifiedHeats(data) {
        const classIds = new Set((data.class_data.classes || []).map(c => c.id));
        const heats = (data.heat_data.heats || []).filter(h => !classIds.has(h.class_id));
        const byId = (a, b) => a.id - b.id;
        if (heats.every(h => typeof h.order === 'number')) {
            return heats.sort((a, b) => a.order - b.order || byId(a, b));
        }
        return heats.sort(byId);
    }

    // With #raceclass-sections on the page, one container per class, the newest first, made and
    // removed as the classes come and go, and the heats without a class last. A page with fixed
    // containers ({name}-display) keeps them.
    syncSections(data) {
        const wrapper = document.getElementById('raceclass-sections');
        if (!wrapper) return;
        const classes = this.classesInOrder(data);
        if (this.unclassifiedHeats(data).length) {
            classes.push(UNCLASSIFIED);
        }
        const wanted = new Set(classes.map(c => `class-${c.id}-display`));
        for (const el of [...wrapper.children]) {
            if (el.classList.contains('raceclass-container') && !wanted.has(el.id)) {
                el.remove();
            }
        }
        classes.forEach((cls, index) => {
            let el = document.getElementById(`class-${cls.id}-display`);
            if (!el) {
                el = document.createElement('div');
                el.id = `class-${cls.id}-display`;
                el.className = 'raceclass-container';
                el.dataset.classId = String(cls.id);
            }
            if (wrapper.children[index] !== el) {
                wrapper.insertBefore(el, wrapper.children[index] || null);
            }
        });
    }

    // The class a container shows: by data-class-id, or for a fixed container by its name.
    classFor(container, data) {
        const classes = data.class_data.classes || [];
        if (container.dataset.classId) {
            return classes.find(c => String(c.id) === container.dataset.classId) || null;
        }
        const name = container.id.substring(0, container.id.indexOf("-display")).toLowerCase();
        return classes.find(c => (c.displayname || c.name || '').toLowerCase() === name) || null;
    }

    updateContainer(container, asRow = false) {
        const data = this.cr_rh_data;
        if (container.id === 'nextup-display') {
            this.render(container, this.nextUpView(data));
            return;
        }
        if (container.dataset.classId === String(UNCLASSIFIED.id)) {
            this.render(container, this.rowView(data, UNCLASSIFIED, this.unclassifiedHeats(data)));
            return;
        }
        const cls = this.classFor(container, data);
        if (!cls) {
            container.innerHTML = '';
            return;
        }
        const bracket = (!asRow && typeof bracketModel.buildBracket === 'function')
            ? bracketModel.buildBracket(data, cls.id)
            : null;
        const view = (bracket && bracket.ok && bracket.type !== 'none')
            ? this.bracketView(data, cls, bracket)
            : this.rowView(data, cls, this.heatsOf(data, cls.id));
        this.render(container, view);
    }

    heatsOf(data, classId) {
        if (typeof bracketModel.heatsOfClass === 'function') {
            return bracketModel.heatsOfClass(data, classId);
        }
        return (data.heat_data.heats || []).filter(h => h.class_id === classId).sort((a, b) => a.id - b.id);
    }

    /* -------------------------------------------------------------------------------------- *
     * What a heat shows: its title and a line per pilot, with the pilot's channel
     * -------------------------------------------------------------------------------------- */

    heatNode(data, heat, cls) {
        const currentHeat = data.current_heat && data.current_heat.current_heat;
        const result = bracketModel.heatResult(data, heat.id);
        let leaderboard = null;
        let time = "total_time";
        if (result && result.leaderboard) {
            switch (result.leaderboard.meta && result.leaderboard.meta.primary_leaderboard) {
                case "by_consecutives":
                    leaderboard = result.leaderboard.by_consecutives;
                    time = "consecutives";
                    break;
                case "by_fastest_lap":
                    leaderboard = result.leaderboard.by_fastest_lap;
                    time = "fastest_lap";
                    break;
                default:
                    leaderboard = result.leaderboard.by_race_time;
                    time = "total_time";
            }
        }

        let title = heat.displayname;
        if (cls && cls.rounds > 1) {
            const flownRounds = result && Array.isArray(result.rounds) ? result.rounds.length : 0;
            title += "\n" + flownRounds + " of " + cls.rounds;
        }

        // The slots come in the order of the seats. One that nobody takes and that nothing seeds -
        // a free seat, or a seed that brought nobody - gets no line.
        const seated = this.seatsFixed(data, heat);
        const pilots = [];
        let pending = false; // a pilot still to come from a seed
        for (const slot of heat.slots || []) {
            const entry = this.slotEntry(data, slot, leaderboard, time, !!result);
            pending = pending || entry.pending;
            if (!entry.id && !entry.name) continue;
            if (seated) entry.channel = this.channelOf(data, slot);
            pilots.push(entry);
        }
        const likely = !seated && !pending && this.likelyChannels(data, pilots);
        return { id: heat.id, title, pilots, seated, likely, active: heat.id === currentHeat, classes: [] };
    }

    // While the seats are not fixed: the channel each pilot will likely get (1.15.0), as
    // RotorHazard's own way of giving out the seats decides it (bracketModel.likelySeats()), put on
    // the pilot's entry marked as likely. Only where the data says which channel each seat has, and
    // only with every pilot of the heat known - the caller asks only then: one still to come from a
    // seed changes who flew where. Whether it told any.
    likelyChannels(data, pilots) {
        const fdata = data.frequency_data && data.frequency_data.fdata;
        const ids = pilots.filter(p => p.id).map(p => p.id);
        if (typeof bracketModel.likelySeats !== 'function' || !fdata || typeof fdata !== 'object' || !ids.length) {
            return false;
        }
        const seats = bracketModel.likelySeats(data, ids);
        let any = false;
        for (const pilot of pilots) {
            const channel = seats.has(pilot.id) ? this.channelOf(data, { node_index: seats.get(pilot.id) }) : null;
            if (channel && channel.label !== '–') {
                pilot.channel = { ...channel, likely: true };
                any = true;
            }
        }
        return any;
    }

    // Whether the seats are those the pilots will fly on, as RotorHazard's own event page decides
    // it: the heat has flown (locked), its plan is confirmed (status 2), or it assigns no frequencies
    // by itself. RotorHazard's heat generator switches that on for every heat it makes, and such a
    // heat gets its seats only when the race director calls it - until then a slot's node is the
    // plan's order, not a seat. And only where the data says which channel each seat has.
    seatsFixed(data, heat) {
        const fdata = data.frequency_data && data.frequency_data.fdata;
        if (!fdata || typeof fdata !== 'object') {
            return false;
        }
        return !!heat.locked || heat.status === 2 || heat.auto_frequency !== true;
    }

    // A seat's video channel as RotorHazard names it - band and channel, "R1" - or its frequency
    // where no band names it; "–" for a node switched off (frequency 0), as RotorHazard shows it.
    channelOf(data, slot) {
        const f = Number.isInteger(slot.node_index) ? data.frequency_data.fdata[slot.node_index] : null;
        const frequency = f && f.frequency != null ? Number(f.frequency) : null;
        if (!f || frequency === 0) {
            return { label: '–', frequency: null };
        }
        if (f.band && f.channel) {
            return { label: `${f.band}${f.channel}`, frequency };
        }
        if (frequency > 0) {
            return { label: String(frequency), frequency };
        }
        return { label: '–', frequency: null };
    }

    slotEntry(data, slot, leaderboard, time, flown) {
        let name = "";
        let result = "";
        let id = null;
        let pending = false;
        const empty = slot.pilot_id === null || slot.pilot_id === 0 || slot.node_index === null;

        if (empty && !flown && slot.method !== -1) {
            // Not filled yet: the pilot the seeding will bring once its source has a result,
            // else the seeding rule (source and rank).
            const seed = bracketModel.resolveSeed(data, slot);
            id = seed.pilotId;
            name = seed.pilotId ? seed.callsign : seed.label;
            // Somebody still to come: a seed whose source has no result yet (an older model does not
            // say, and counts as that).
            const seeded = slot.method === bracketModel.METHOD.HEAT_RESULT || slot.method === bracketModel.METHOD.CLASS_RESULT;
            pending = seeded && !seed.pilotId && seed.sourced !== true;
        } else if (!empty) {
            id = slot.pilot_id;
            const pilot = ((data.pilot_data && data.pilot_data.pilots) || []).find(p => p.pilot_id === slot.pilot_id);
            name = pilot ? pilot.callsign : "";
            const entry = leaderboard ? leaderboard.find(r => r.pilot_id === slot.pilot_id) : null;
            if (entry) {
                if (entry[time] === "0:00.000" || entry.position < 1) {
                    result = "DNF";
                } else {
                    result = String(entry[time] + " ") + String(" L" + entry.laps) + String(" #" + entry.position);
                }
            }
        }
        return { id, name, result, pending, classes: [], country: this.countryOf(data, id) };
    }

    // The pilot's country, for the flag: only where the page says where the flags are.
    countryOf(data, pilotId) {
        if (!this.flagBaseUrl || !pilotId || typeof bracketModel.pilotProfile !== 'function') {
            return null;
        }
        const profile = bracketModel.pilotProfile(data, pilotId);
        return profile ? profile.country : null;
    }

    // The heats the pilot filter keeps: those with the pilot, and the heats they feed.
    filterNodes(nodes, parentsOf) {
        const pilotId = this.selectedPilotId;
        if (!(pilotId > 0 && this.filterCheckboxState)) {
            return null;
        }
        const keep = new Set(nodes.filter(n => n.pilots.some(p => p.id === pilotId)).map(n => n.id));
        for (const node of nodes) {
            if ((parentsOf(node.id) || []).some(p => keep.has(p))) {
                keep.add(node.id);
            }
        }
        return keep;
    }

    /* -------------------------------------------------------------------------------------- *
     * The three views
     * -------------------------------------------------------------------------------------- */

    bracketView(data, cls, bracket) {
        const raw = new Map((data.heat_data.heats || []).map(h => [h.id, h]));
        const nodes = new Map(bracket.heats.map(h => [h.id, this.heatNode(data, raw.get(h.id), cls)]));
        const visible = this.filterNodes([...nodes.values()], id => bracket.byId.get(id).parents);
        const layout = bracketModel.layout(bracket, visible);
        const name = cls.displayname || cls.name || `Class ${cls.id}`;
        const double = bracket.type === 'double';

        const cta = typeof bracketModel.ctaState === 'function' ? bracketModel.ctaState(data, bracket) : null;
        if (cta && cta.enabled && nodes.has(bracket.finalId)) {
            const final = nodes.get(bracket.finalId);
            const rounds = cta.rounds === 1 ? '1 round' : `${cta.rounds} rounds`;
            final.note = `Chase the Ace · 1st to ${cta.needed} wins · ${rounds}${cta.decided ? ' · decided' : ''}`;
            final.classes.push('cta');
            for (const pilot of final.pilots) {
                if (pilot.id) {
                    pilot.result = `${cta.wins.get(pilot.id) || 0}/${cta.needed}`;
                    if (cta.decided && pilot.id === cta.winnerId) {
                        pilot.classes.push('cta-winner');
                    }
                }
            }
        }

        const view = { kind: 'bracket', sections: [], nodes: [], edges: layout.edges };
        let row = 1;
        for (const section of layout.sections) {
            const titleRow = row;
            const headerRow = row + 1;
            const winners = section.key === 'W';
            view.sections.push({
                id: `class-${cls.id}-${winners ? 'winners' : 'losers'}`,
                title: double ? `${name}: ${section.title}` : name,
                titleRow,
                headerRow,
                headers: section.headers,
            });
            let lastRow = headerRow;
            for (const cell of section.cells) {
                const node = nodes.get(cell.heatId);
                const heat = bracket.byId.get(cell.heatId);
                node.gridColumn = cell.col;
                node.gridRow = headerRow + cell.row;
                node.classes.push(winners && heat.group !== 'SF' ? 'winner' : 'looser');
                if (heat.id === bracket.finalId) node.classes.push('final');
                if (heat.group === 'SF') node.classes.push('small-final');
                view.nodes.push(node);
                lastRow = Math.max(lastRow, node.gridRow);
            }
            row = lastRow + 1;
        }
        return view;
    }

    rowView(data, cls, heats) {
        const nodes = heats.map(h => this.heatNode(data, h, cls));
        const visible = this.filterNodes(nodes, () => []);
        const shown = visible ? nodes.filter(n => visible.has(n.id)) : nodes;
        shown.forEach((node, index) => {
            node.gridColumn = index + 1;
            node.gridRow = 2;
            node.classes.push('singlebracket');
        });
        const name = (cls && (cls.displayname || cls.name)) || '';
        return {
            kind: 'row',
            sections: [{ id: cls ? `class-${cls.id}-title` : '', title: name, titleRow: 1, headerRow: null, headers: [] }],
            nodes: shown,
            edges: [],
        };
    }

    // The next five heats from the current one, in the timer's list.
    nextUpView(data) {
        const heats = data.heat_data.heats || [];
        const start = heats.findIndex(h => h.id === (data.current_heat && data.current_heat.current_heat));
        if (start === -1) {
            throw new Error('Current heat id not found in heats array.');
        }
        const classes = data.class_data.classes || [];
        const nodes = heats.slice(start, start + NEXT_UP_COUNT).map(heat =>
            this.heatNode(data, heat, classes.find(c => c.id === heat.class_id) || null));
        nodes.forEach((node, index) => {
            node.gridColumn = index + 1;
            node.gridRow = 2;
            node.classes.push('singlebracket');
        });
        return { kind: 'row', sections: [{ id: 'nextup-title', title: 'Next up', titleRow: 1, headerRow: null, headers: [] }], nodes, edges: [] };
    }

    /* -------------------------------------------------------------------------------------- *
     * Drawing
     * -------------------------------------------------------------------------------------- */

    render(container, view) {
        container.innerHTML = "";
        const key = container.id.replace(/-display$/, '');
        const grid = document.createElement("div");
        grid.id = `${key}-grid`;
        grid.className = "grid-container";

        const filterPilotId = this.selectedPilotId;

        // The titles span every column of the class. css/rm_viewer.css keeps a .pinned-title where
        // it is while the races and their lines scroll sideways under it, with position: sticky --
        // and a sticky element never leaves its grid area, so an area of two columns would have
        // let the title go after the second stage. At least two, as before, so a bracket of one
        // column keeps the layout it had.
        const lastColumn = Math.max(0, ...view.nodes.map(node => parseInt(node.gridColumn, 10) || 0));
        const titleColumns = `1 / span ${Math.max(2, lastColumn)}`;

        for (const section of view.sections) {
            const title = document.createElement("div");
            title.style.gridRow = String(section.titleRow);
            title.style.gridColumn = titleColumns;
            title.classList.add("class-title", "pinned-title");
            if (section.id) title.id = section.id;
            title.textContent = section.title;
            grid.appendChild(title);
            for (const header of section.headers) {
                const cell = document.createElement("div");
                cell.className = "round-title";
                cell.style.gridRow = String(section.headerRow);
                cell.style.gridColumn = String(header.col);
                cell.textContent = header.label;
                grid.appendChild(cell);
            }
        }

        const nodeElements = {};
        for (const node of view.nodes) {
            const nodeDiv = document.createElement("div");
            nodeDiv.style.gridColumn = `${node.gridColumn}`;
            nodeDiv.style.gridRow = `${node.gridRow}`;
            nodeDiv.classList.add("node", ...node.classes);
            if (node.active) nodeDiv.classList.add("activeHeat");
            if (node.seated) nodeDiv.classList.add("seated"); // its lines carry the channel
            if (node.likely) nodeDiv.classList.add("forecast"); // and here the likely one, where told

            const nodeTitleDiv = document.createElement("div");
            nodeTitleDiv.textContent = node.title;
            nodeTitleDiv.className = "title";
            nodeDiv.appendChild(nodeTitleDiv);

            let foundSelectedPilot = false;
            if (node.pilots && node.pilots.length > 0) {
                const pilotsContainerDiv = document.createElement("div");
                pilotsContainerDiv.className = "pilots-container";
                if (node.note) {
                    // A line about the heat above its pilots: the vertical title has no room for it.
                    const noteDiv = document.createElement("div");
                    noteDiv.className = "node-note";
                    noteDiv.textContent = node.note;
                    pilotsContainerDiv.appendChild(noteDiv);
                }

                for (const pilot of node.pilots) {
                    const pilotDataDiv = document.createElement("div");
                    // A class per pilot, for the hover across the page; none for an empty slot.
                    pilotDataDiv.className = (pilot.id !== null && pilot.id !== 0) ? `pilotid-${pilot.id}` : "pilot-notseeded";
                    pilotDataDiv.classList.add("pilot-entry", ...pilot.classes);
                    if (pilot.id === filterPilotId && filterPilotId !== 0) {
                        foundSelectedPilot = true;
                    }
                    if (node.seated || node.likely) {
                        // Its own element, not inside the name: the hover finds the pilot by the
                        // line's class, one level up from what the pointer is on. Empty for a pilot
                        // whose likely channel cannot be told, so that the callsigns stand in line.
                        const channelDiv = document.createElement("div");
                        channelDiv.className = "pilot-channel";
                        const channel = pilot.channel;
                        if (channel && channel.likely) {
                            channelDiv.classList.add("likely");
                            channelDiv.textContent = `${channel.label}?`;
                            channelDiv.title = `Likely${channel.frequency ? ` ${channel.frequency} MHz` : ''} - fixed when the heat is called`;
                        } else if (channel) {
                            channelDiv.textContent = channel.label;
                            if (channel.frequency) {
                                channelDiv.title = `${channel.frequency} MHz`;
                            }
                        }
                        pilotDataDiv.appendChild(channelDiv);
                    }
                    const nameDiv = document.createElement("div");
                    nameDiv.className = "pilot-name";
                    if (pilot.country) {
                        nameDiv.appendChild(this.flagImage(pilot.country));
                    } else if (pilot.id && this.anyCountry) {
                        // The flag's place, so that the callsigns stand in line.
                        const none = document.createElement("span");
                        none.className = "pilot-flag pilot-flag-none";
                        nameDiv.appendChild(none);
                    }
                    nameDiv.appendChild(document.createTextNode(pilot.name));
                    pilotDataDiv.appendChild(nameDiv);

                    const resultDiv = document.createElement("div");
                    resultDiv.textContent = pilot.result || "";
                    resultDiv.className = "pilot-result";
                    pilotDataDiv.appendChild(resultDiv);
                    pilotsContainerDiv.appendChild(pilotDataDiv);
                }
                nodeDiv.appendChild(pilotsContainerDiv);
            }
            // A heat without lines - nobody in it yet - is dimmed like any other without the pilot.
            if (!foundSelectedPilot && filterPilotId !== 0) {
                nodeDiv.classList.add("dimmed");
            }

            grid.appendChild(nodeDiv);
            nodeElements[node.id] = nodeDiv;
        }

        container.appendChild(grid);

        // The lines, measured once the grid is in the page.
        if (view.edges.length) {
            const svgContainer = document.createElementNS("http://www.w3.org/2000/svg", "svg");
            svgContainer.setAttribute("id", `${key}-svg`);
            svgContainer.setAttribute("class", "svg-container");
            for (const edge of view.edges) {
                const startNode = nodeElements[edge.from];
                const endNode = nodeElements[edge.to];
                if (!startNode || !endNode) continue;
                const startX = startNode.offsetLeft + startNode.offsetWidth / 2;
                const startY = startNode.offsetTop + startNode.offsetHeight / 2;
                const endX = endNode.offsetLeft + endNode.offsetWidth / 2;
                const endY = endNode.offsetTop + endNode.offsetHeight / 2;
                const gapX = endNode.offsetLeft - (startNode.offsetLeft + startNode.offsetWidth);
                const midX = startNode.offsetLeft + startNode.offsetWidth + (gapX / 2);

                const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
                path.setAttribute("d", `M${startX},${startY} H${midX} V${endY} H${endX}`);
                path.setAttribute("stroke", "black");
                path.setAttribute("fill", "none");
                path.setAttribute("stroke-width", "2");
                svgContainer.appendChild(path);
            }
            grid.appendChild(svgContainer);
        }
    }

    // A country's flag (flag-icons, 4:3), with its code for those who cannot see it.
    flagImage(country) {
        const img = document.createElement("img");
        img.className = "pilot-flag";
        img.src = `${this.flagBaseUrl}${country.toLowerCase()}.svg`;
        img.alt = country;
        img.title = country;
        img.width = 16;
        img.height = 12;
        img.loading = "lazy";
        img.decoding = "async";
        return img;
    }

    // Attach event listeners to each element
    attachPilotMouseEvents() {
        document.addEventListener('mouseover', (event) => {
            // Check if the target element's class matches "pilotid-<number>"
            const target = event.target;
            if (target.className && target.parentNode && target.parentNode.className && String(target.parentNode.className).match(/pilotid-\d+/)) {
                const className = String(target.parentNode.className).match(/pilotid-\d+/)[0];
                document.querySelectorAll(`.${className}`).forEach((element) => {
                    element.classList.add("hovered");
                });
            }
        });

        document.addEventListener('mouseout', (event) => {
            const target = event.target;
            if (target.className && target.parentNode && target.parentNode.className && String(target.parentNode.className).match(/pilotid-\d+/)) {
                const className = String(target.parentNode.className).match(/pilotid-\d+/)[0];
                document.querySelectorAll(`.${className}`).forEach((element) => {
                    element.classList.remove("hovered");
                });
            }
        });
    }
}
export const displayHeatsInstance = new DisplayHeats();

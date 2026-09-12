// pilotSelector.js
import { dataLoaderInstance } from './rm-m-dataLoader.js';

class PilotSelector {
    constructor() {
        const configData = (window.RmJsConfig && window.RmJsConfig["pilotSelector"]) || null;
        if (!configData) {
            throw new Error("pilotSelector: Missing configuration data");
        }

        // Configuration properties
        // Read dependency configuration
        this.raceId = dataLoaderInstance.storageKey;
        //this.raceId = window.RmJsConfig["dataLoader"].storageKey || 'dataCache';
        
        // Required properties
        // none

        // Optional properties
        this.pilotSelectionKey = `${this.raceId}_pilotSelection`;
        this.selectedPilotId = parseInt(sessionStorage.getItem(this.pilotSelectionKey)) || 0; //|| null;
        // The selected pilot's key, where the race data has one: the timer gives re-created
        // pilots new IDs, and the key is what still names the same person then.
        this.pilotKeyStorageKey = `${this.raceId}_pilotSelectionPilotKey`;
        this.selectedPilotKey = sessionStorage.getItem(this.pilotKeyStorageKey) || '';

        this.pilotSelectorId = window.RmJsConfig["pilotSelector"].pilotSelectorId || 'pilotSelector-id';
        this.pilotSelector = document.getElementById(`${this.pilotSelectorId}`);
        
        // Run initialization
        this.initialize();
    }

    initialize() {
        // The module is imported by every live page, but the control itself is
        // part of the shortcode markup. Throwing here would take the importing
        // page down with it, so an absent element only disables the selector.
        if (!this.pilotSelector) {
            console.warn(`PilotSelector: no element with id "${this.pilotSelectorId}" — selector stays inactive`);
            return;
        }

        // Subscribe to the dataLoader (singleton)
        console.log("PilotSelector: Subscribed to DataLoader");

        dataLoaderInstance.subscribe(this.populatePilotSelect.bind(this));

        // Attach event handlers
        this.pilotSelector.addEventListener('change', this.handlePilotSelectChange.bind(this));
    }

    handlePilotSelectChange(event) {
        console.log('PilotSelector: Pilot selected:', event.target.value);
        // Save the selected pilot to sessionStorage
        sessionStorage.setItem(this.pilotSelectionKey, event.target.value);
        // Keep this a number, the way the constructor reads it back out of
        // sessionStorage. displayHeats and displayStats parseInt() the element's
        // value themselves, so nothing depends on the difference today -- it is
        // just one less trap for whoever compares against it next.
        this.selectedPilotId = parseInt(event.target.value) || 0;
        const option = event.target.selectedOptions ? event.target.selectedOptions[0] : null;
        this.selectedPilotKey = (option && option.getAttribute('data-pilot-key')) || '';
        sessionStorage.setItem(this.pilotKeyStorageKey, this.selectedPilotKey);
    }

    populatePilotSelect(data) {
        console.log('PilotSelector: Populating pilot selector.');
        if (!this.pilotSelector) {
            return null;
        }
        if (!data) {
            console.error("populatePilotSelect: Missing data");
            return null;
        }
        // The timer's /bracketview (the RotorHazard connector) runs this module on a dataLoader
        // of its own, which hands a new subscriber what it has so far: an empty object until
        // every section has come in over the socket.
        if (!data.pilot_data || !Array.isArray(data.pilot_data.pilots)) {
            console.log('PilotSelector: no pilot data yet');
            return null;
        }

        // Extract basic Pilot data from the RHData
        const pilotsMap = data.pilot_data.pilots.map(pilot => ({
            id: pilot.pilot_id,
            callsign: pilot.callsign,
            // Sent by the RotorHazard connector from the version that came with WP RaceManager
            // 1.7.0 on; none for a pilot added by hand on the timer, or from an older one.
            key: typeof pilot.pilot_key === 'string' ? pilot.pilot_key : ''
        }));

        // Sort the pilotsMap alphabetically by callsign
        pilotsMap.sort((a, b) => a.callsign.localeCompare(b.callsign));

        // The dataLoader notifies on every upload during a live race -- and once
        // more on every failed poll -- so this runs many times on a page that
        // stays open. Replace the options rather than appending another full set:
        // four hours of racing used to leave a couple of thousand of them here.
        //
        // Only the ones this module added carry data-pilot-id. Anything without
        // it came from the shortcode markup -- the "-- Select a Pilot --"
        // placeholder -- and has to survive the rebuild.
        this.pilotSelector
            .querySelectorAll('option[data-pilot-id]')
            .forEach(option => option.remove());

        const options = document.createDocumentFragment();
        pilotsMap.forEach(pilot => {
            const option = document.createElement('option');
            option.value = pilot.id;
            option.textContent = pilot.callsign;
            option.setAttribute('data-pilot-id', pilot.id);
            option.setAttribute('data-pilot-callsign', pilot.callsign);
            option.setAttribute('data-pilot-key', pilot.key);
            option.setAttribute('data-race-id', this.raceId);
            options.appendChild(option);
        });
        this.pilotSelector.appendChild(options);

        // Restoring the selection is the other half of the fix, and it cannot be
        // left out. Appending used to keep a pilot who left the field selected
        // simply because their stale option was still in the list. Now that the
        // list is rebuilt, assigning a value no option carries leaves
        // selectedIndex at -1 and the control renders blank -- not even the
        // placeholder.
        //
        // Which pilot that is: the one with the selected pilot's key, where the data has keys.
        // The timer gives re-created pilots new IDs, and the ID selected before may belong to
        // someone else by then. By the ID otherwise, as before.
        let wanted = String(this.selectedPilotId);
        if (this.selectedPilotKey !== '' && pilotsMap.some(pilot => pilot.key !== '')) {
            const same = pilotsMap.find(pilot => pilot.key === this.selectedPilotKey);
            wanted = same ? String(same.id) : '';
        }
        this.pilotSelector.value = wanted;

        if (this.pilotSelector.selectedIndex === -1) {
            console.log(`PilotSelector: pilot ${this.selectedPilotId} is no longer in the field, falling back to the placeholder`);
            this.pilotSelector.selectedIndex = 0;
            // displayHeats and displayStats read the selection off this element
            // on its change event, so a silent assignment would leave them
            // filtering by a pilot the dropdown no longer offers.
            this.pilotSelector.dispatchEvent(new Event('change', { bubbles: true }));
        } else if (this.pilotSelector.value !== String(this.selectedPilotId)) {
            // The same pilot under a new ID: the other modules filter by the ID.
            this.pilotSelector.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
}
export const pilotSelectInstance = new PilotSelector();
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

        this.pilotSelectorId = window.RmJsConfig["pilotSelector"].pilotSelectorId || 'pilotSelector-id';
        this.pilotSelector = document.getElementById(`${this.pilotSelectorId}`);
        
        // Run initialization
        this.initialize();
    }

    initialize() {
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
        this.selectedPilotId = event.target.value;
        //const pilotOption = this.pilotSelect.selectedOptions[0];
        //this.selectedPilotId = pilotOption.getAttribute('data-pilot-id');
    }

    populatePilotSelect(data) {
        console.log('PilotSelector: Populating pilot selector.');
        if (!data) {
            console.error("populatePilotSelect: Missing data");
            return null;
        }

        // Extract basic Pilot data from the RHData
        const pilotsMap = data.pilot_data.pilots.map(pilot => ({
            id: pilot.pilot_id,
            callsign: pilot.callsign
        }));

        // Sort the pilotsMap alphabetically by callsign
        pilotsMap.sort((a, b) => a.callsign.localeCompare(b.callsign));

        // Example: Populate the pilot selector with data
        //const pilotSelector = document.getElementById('pilotSelector');
        pilotsMap.forEach(pilot => {
            const option = document.createElement('option');
            option.value = pilot.id;
            option.textContent = pilot.callsign;
            option.setAttribute('data-pilot-id', pilot.id); // a bit redundant
            option.setAttribute('data-race-id', this.raceId); // a bit redundant
            this.pilotSelector.appendChild(option);
        });

        //TODO: find better place for this
        this.pilotSelector.value = this.selectedPilotId;
    }
}
export const pilotSelectInstance = new PilotSelector();
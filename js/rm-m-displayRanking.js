// rm-m-displayRanking.js
// This module displays the ranking of a double elimination tournament in a container specified by the user in the config file.
// It listens for data from the dataLoader and updates the ranking accordingly.

import { dataLoaderInstance } from './rm-m-dataLoader.js';
import { computeLeaderboard } from './rm-m-calcRanking.js';

// Exports const displayRankingInstance = new DisplayRanking(); (at the bottom)

class DisplayRanking {
    constructor() {
        const configData = (window.RmJsConfig && window.RmJsConfig["displayRanking"]) || null;
        /* if (!configData) {
            throw new Error("displayRanking: Missing configuration data");
        } */

        // Configuration properties
        // Read dependency configuration
        this.raceId = dataLoaderInstance.storageKey; // Load storageKey from dataLoader

        // Required properties
        // none

        // Optional properties
        this.containerId = configData?.containerId || 'ranking-container'; // Default container ID

        //this.cr_rh_data = null; // no default data, upon subscribing to dataLoader this will be populated

        // document.readyState === 'loading'
        if (document.readyState === 'complete') {
            console.log("DisplayHeats: Site is already loaded, initializing immediately");
            this.initialize();
          } else {
            // Otherwise, wait for the window load event.
            console.log("DisplayHeats: Registering load event listener");
            window.addEventListener('load', () => this.initialize());
          }
    }

    initialize() {
        // Subscribe to the dataLoader
        dataLoaderInstance.subscribe(this.handleDataLoaderEvent.bind(this));
    }
    
    handleDataLoaderEvent(data) {
        //this.cr_rh_data = data;
        const leaderboard = computeLeaderboard(data);
        console.log(leaderboard);
        this.updateRankingDisplay(); // fixed: call the function
    }

    updateRankingDisplay() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            console.warn(`DisplayRanking: Container not found (${this.containerId})`);
            return;
        }
        // Clear previous contents
        container.innerHTML = '';
    }
}

export const displayRankingInstance = new DisplayRanking();
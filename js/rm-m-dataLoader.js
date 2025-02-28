// rm-m-dataLoader.js
// Singleton instance
// export const dataLoaderInstance = new DataLoader();
// 
// Modules can subscribe to receive data updates:
// dataLoaderInstance.subscribe((newData) => {
//   console.log('Data updated:', newData);
// });

export class DataLoader {
    constructor() {
        // Get config from the global inline script (generated by WordPress)
        const configData = (window.RmJsConfig && window.RmJsConfig["dataLoader"]) || null;
        if(!configData) {
            throw new Error("dataLoader: Missing configuration data");
        }

        // Configuration properties
        // Required properties
        this.timestampUrl = configData.timestampUrl;
        this.dataUrl = configData.dataUrl;
        // Optional properties
        this.refreshInterval = configData.refreshInterval || 0; // in ms
        this.storageKey = configData.storageKey || 'dataCache';
        this.timestampKey = `${this.storageKey}_timestamp`;
        this.timeout = configData.timeout || 9000; // default timeout for fetch in ms

        // Internal state flags
        this.isFetchingTimestamp = false;
        this.isFetchingData = false;

        // Subscribers (callbacks) that want the data update.
        this.subscribers = new Set();

        // Load cached values
        this.cachedTimestamp = sessionStorage.getItem(this.timestampKey) || null;
        this.data = this.getCachedData();

        // Notify any new subscriber immediately with cached data.
        // (Note: if no data is cached, then nothing is sent.)
        // TODO: maybe this needs to be put into the initialize method and call initialize on DOMContentLoaded
        if (this.data) {
            this.notifySubscribers(this.data);
        }

        // Begin update process
        this.initialize();
    }

    // Utility: fetch with timeout using AbortController.
    async fetchWithTimeout(url, options = {}) {
        const controller = new AbortController();
        const id = setTimeout(() => controller.abort(), this.timeout);
        try {
            const response = await fetch(url, {
                ...options,
                cache: 'no-store', // this disables caching entirely
                signal: controller.signal
            });
            clearTimeout(id);
            return response;
        } catch (error) {
            clearTimeout(id);
            throw error;
        }
    }

    // Subscribe for data updates.
    subscribe(callback) {
        if (typeof callback === 'function') {
            this.subscribers.add(callback);
            // Immediately send the latest data (if available).
            if (this.data) {
                callback(this.data);
            }
        }
    }

    // Unsubscribe a callback.
    unsubscribe(callback) {
        this.subscribers.delete(callback);
    }

    // Notify all subscribers with the updated data.
    notifySubscribers(data) {
        for (const callback of this.subscribers) {
            try {
                callback(data);
            } catch (error) {
                console.error('Error in subscriber callback:', error);
            }
        }
    }

    // Retrieve cached JSON data from sessionStorage.
    getCachedData() {
        try {
            const json = sessionStorage.getItem(this.storageKey);
            if (json) {
                return JSON.parse(json);
            }
        } catch (e) {
            console.error('Error parsing cached data:', e);
        }
        return null;
    }

    // Start the data loading and refresh cycle.
    async initialize() {
        if (this.refreshInterval > 0) {
            // Check for updates immediately.
            await this.checkForUpdates();
            // Schedule periodic timestamp checks.
            setInterval(() => this.checkForUpdates(), this.refreshInterval);
        }
        else {
            // If no refresh is configured and no data is cached, do an initial load.
            //await this.fetchAndUpdateData();
            await this.checkForUpdates();
            if (!this.data) {
                // If no refresh is configured and no data is cached, do an initial load.
                await this.fetchAndUpdateData();
            }
        }
    }

    // Check the timestamp from the server and update data if needed.
    async checkForUpdates() {
        if (this.isFetchingTimestamp) return;
        this.isFetchingTimestamp = true;

        try {
            const response = await this.fetchWithTimeout(this.timestampUrl);
            if (!response.ok) {
                throw new Error(`Timestamp fetch failed: ${response.statusText}`);
            }
            // Assume the timestamp is returned as plain text.
            const newTimestamp = await response.text();
            if (this.cachedTimestamp !== newTimestamp) {
                // New timestamp indicates updated data.
                this.cachedTimestamp = newTimestamp;
                sessionStorage.setItem(this.timestampKey, newTimestamp);
                // Fetch new JSON data.
                await this.fetchAndUpdateData();
            }
        } catch (error) {
            console.error('Error fetching timestamp:', error);
            // On error, fall back to cached data (but don't notify subscribers repeatedly)
            if (this.data) {
                this.notifySubscribers(this.data);
            }
        } finally {
            this.isFetchingTimestamp = false;
        }
    }

    // Fetch data from the dataUrl and update storage, then notify subscribers.
    async fetchAndUpdateData() {
        if (this.isFetchingData) return;
        this.isFetchingData = true;

        try {
            const response = await this.fetchWithTimeout(this.dataUrl);
            if (!response.ok) {
                throw new Error(`Data fetch failed: ${response.statusText}`);
            }
            const newData = await response.json();
            // Update cached data.
            this.data = newData;
            sessionStorage.setItem(this.storageKey, JSON.stringify(newData));
            // Notify subscribers only when new data is successfully loaded.
            this.notifySubscribers(this.data);
        } catch (error) {
            console.error('Error fetching data:', error);
            // If fetching new data fails, use the cached data if available.
            if (this.data) {
                this.notifySubscribers(this.data);
            }
        } finally {
            this.isFetchingData = false;
        }
    }
}

// Singleton instance
export const dataLoaderInstance = new DataLoader();
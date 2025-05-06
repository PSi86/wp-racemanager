// rm-m-displayLog.js
// This module displays the race log in a container specified by the user in the config file.
// It listens for data from the dataLoader and updates the log accordingly.
// The log is displayed in a scrollable div with a maximum height of 400px.
// Each log entry includes a timestamp, title, icon, and message body.
// If a URL is provided, it is displayed as a clickable link at the end of the message body.
// The log is cleared and rebuilt each time new data is received.

import { dataLoaderInstance } from './rm-m-dataLoader.js';

// Exports const displayLogInstance = new DisplayLog(); at the bottom

class DisplayLog {
    constructor() {
        const configData = (window.RmJsConfig && window.RmJsConfig["displayLog"]) || {};

        // Configuration properties
        this.containerId = configData.containerId || 'log-container';

        this.cr_rh_data = null;

        // Initialize on window load or immediately if already loaded
        if (document.readyState === 'complete') {
            this.initialize();
        } else {
            window.addEventListener('load', () => this.initialize());
        }
    }

    initialize() {
        // Subscribe to the dataLoader
        dataLoaderInstance.subscribe(this.handleDataLoaderEvent.bind(this));
    }
    
    handleDataLoaderEvent(data) {
        this.cr_rh_data = data;
        this.updateRaceLog(); // fixed: call the function
    }

    updateRaceLog() {
        const container = document.getElementById(this.containerId);
        if (!container) {
            console.warn(`DisplayLog: Container not found (${this.containerId})`);
            return;
        }
        // Clear previous contents
        container.innerHTML = '';

        const notifications = this.cr_rh_data?.notifications;

        if (!Array.isArray(notifications) || notifications.length === 0) {
            const emptyMsg = document.createElement('p');
            emptyMsg.innerHTML = '<em>The race log is currently empty.</em>';
            container.appendChild(emptyMsg);
            return;
        }

        // Log wrapper
        const logWrapper = document.createElement('div');
        logWrapper.className = 'race-notifications-log';
        Object.assign(logWrapper.style, {
            maxHeight: '400px',
            overflowY: 'auto',
            border: '1px solid #ddd',
            padding: '10px',
            background: '#fafafa',
        });

        // Build each notification
        notifications.forEach((n) => {
            const item = document.createElement('div');
            item.className = 'race-notification-item';
            Object.assign(item.style, {
                marginBottom: '20px',
                clear: 'both',
            });

            // Header: time + title
            const header = document.createElement('strong');
            header.textContent = `${n.msg_time} - ${n.msg_title}`;
            item.appendChild(header);

            // Body container
            const bodyCont = document.createElement('div');
            //bodyCont.style.marginTop = '5px';
            Object.assign(bodyCont.style, {
                display: 'flex',
                alignItems: 'center',
            });

            // Icon
            if (n.msg_icon) {
                const img = document.createElement('img');
                img.src = n.msg_icon;
                img.alt = '';
                Object.assign(img.style, {
                    maxWidth: '64px',
                    maxHeight: '64px',
                    float: 'left',
                    marginRight: '10px',
                    flexShrink: '0',
                });
                bodyCont.appendChild(img);
            }

            // Message text
            const textWrapper = document.createElement('div');
            textWrapper.style.overflow = 'hidden';
            const msgParagraph = document.createElement('p');
            n.msg_body.split('\n').forEach((line, idx, arr) => {
                msgParagraph.appendChild(document.createTextNode(line));
                if (idx < arr.length - 1) msgParagraph.appendChild(document.createElement('br'));
            });
            textWrapper.appendChild(msgParagraph);
            bodyCont.appendChild(textWrapper);
            item.appendChild(bodyCont);

            // Link
            if (n.msg_url) {
                const linkParagraph = document.createElement('p');
                Object.assign(linkParagraph.style, {
                    clear: 'both',
                    marginTop: '8px',
                });
                const linkLabel = document.createElement('strong');
                linkLabel.textContent = 'Link:';
                linkParagraph.appendChild(linkLabel);
                linkParagraph.appendChild(document.createTextNode(' '));
                const linkEl = document.createElement('a');
                linkEl.href = n.msg_url;
                linkEl.target = '_blank';
                linkEl.rel = 'noopener';
                linkEl.textContent = n.msg_url;
                linkParagraph.appendChild(linkEl);
                item.appendChild(linkParagraph);
            }

            logWrapper.appendChild(item);
        });

        container.appendChild(logWrapper);
    }
}

export const displayLogInstance = new DisplayLog();

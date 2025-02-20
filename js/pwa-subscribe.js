// file: my-pilot-push.js
(function () {
    // We'll read data from the localized object if available
    const restUrl = (typeof RmPushData !== 'undefined') ? RmPushData.restUrl : '';
    const publicVapid = (typeof RmPushData !== 'undefined') ? RmPushData.publicVapid : '';

    // References to elements on the page
    let subscribeButton, pilotSelect, subscriptionStatus;
    let swRegistration = null;

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        subscribeButton = document.getElementById('subscribe-button');
        pilotSelect = document.getElementById('pilot-select');
        subscriptionStatus = document.getElementById('subscription-status');

        // Check if browser supports push
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            setSubscriptionStatus('Push Not Supported In This Browser');
            subscribeButton.disabled = true;
            return;
        }

        // Register service worker
        //navigator.serviceWorker.register('/wp/pwa-sw.js?ver=1.0.1')
        navigator.serviceWorker.ready
            .then(function (registration) {
                swRegistration = registration;
                console.log('Service worker is ready:', registration);
                checkSubscriptionState();
            })
            .catch(function (error) {
                console.error('Error getting serviceWorker.ready:', error);
                setSubscriptionStatus('Cannot register Service Worker.');
            });

        // If the form exists, attach the submission event
        const form = document.getElementById('pilot-push-form');
        if (form) {
            form.addEventListener('submit', handleSubscriptionForm);
        }
    }

    // Check current subscription status
    async function checkSubscriptionState() {
        if (!swRegistration) return;

        const subscription = await swRegistration.pushManager.getSubscription();
        if (!subscription) {
            // Not subscribed
            setSubscriptionStatus('Not subscribed.');
            subscribeButton.textContent = 'Subscribe';
            subscribeButton.disabled = false;
        } else {
            // Already subscribed
            setSubscriptionStatus('Already subscribed.');
            subscribeButton.textContent = 'Unsubscribe';
            subscribeButton.disabled = false;
        }
    }

    // Handle the form submission (subscribe or unsubscribe)
    async function handleSubscriptionForm(evt) {
        evt.preventDefault();

        if (!swRegistration) {
            setSubscriptionStatus('Service Worker registration not available.');
            return;
        }

        let subscription = await swRegistration.pushManager.getSubscription();
        if (!subscription) {
            // Attempt to subscribe
            subscription = await subscribeUser();
            if (!subscription) return; // subscription failed or was blocked
        } else {
            // If user is "already subscribed," let's handle unsubscribe logic
            if (subscribeButton.textContent === 'Unsubscribe') {
                // Possibly call your remove subscription REST endpoint

                await unsubscribeUser(subscription);
                return;
            }
        }

        // If user is newly subscribed or was already subscribed, then we do the normal "subscribe" flow
        sendSubscriptionToServer(subscription);
    }

    // Subscribe user
    async function subscribeUser() {
        let sub = null;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                setSubscriptionStatus('Permission Not Granted.');
                return null;
            }

            const convertedVapidKey = urlBase64ToUint8Array(publicVapid);
            sub = await swRegistration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: convertedVapidKey
            });

            setSubscriptionStatus('Subscribed successfully.');
            subscribeButton.textContent = 'Unsubscribe';
        } catch (err) {
            console.error('Error during subscription', err);
            setSubscriptionStatus('Could not subscribe.');
            return null;
        }
        return sub;
    }

    // Unsubscribe user
    /*     async function unsubscribeUser(subscription) {
          try {
            await subscription.unsubscribe();
            setSubscriptionStatus('Unsubscribed successfully.');
            subscribeButton.textContent = 'Subscribe';
          } catch (err) {
            console.error('Failed to unsubscribe', err);
            setSubscriptionStatus('Unsubscribe error.');
          }
        } */

    async function unsubscribeUser(subscription) {
        try {
            // 1) Actually unsubscribe in the browser
            await subscription.unsubscribe();

            setSubscriptionStatus('Unsubscribed successfully.');
            subscribeButton.textContent = 'Subscribe';

            // 2) ALSO inform your WordPress REST API so the DB record is removed
            //    We'll fetch the race_id from your pilot-select or however you store it

            const subObj = subscription.toJSON();
            const endpoint = subObj.endpoint;

            // If you previously stored the selected race/pilot in local state, retrieve it:
            //const pilotOption = pilotSelect.selectedOptions[0];
            //const raceId = pilotOption.getAttribute('data-race-id');

            // If you also need pilot_callsign, you can pass it, 
            // but your remove_subscription method likely only needs race_id + endpoint
            const bodyData = {
                endpoint: endpoint,
            };

            const response = await fetch(restUrl, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(bodyData),
            });

            if (!response.ok) {
                const errJson = await response.json();
                console.error('Server responded with error on unsub:', errJson);
                setSubscriptionStatus('Error removing subscription on server.');
            } else {
                setSubscriptionStatus('Subscription removed on server as well.');
            }

        } catch (err) {
            console.error('Failed to unsubscribe', err);
            setSubscriptionStatus('Unsubscribe error.');
        }
    }

    // Send subscription data to server
    function sendSubscriptionToServer(subscription) {
        const subObj = subscription.toJSON();
        const p256dh = (subObj.keys && subObj.keys.p256dh) ? subObj.keys.p256dh : '';
        const auth = (subObj.keys && subObj.keys.auth) ? subObj.keys.auth : '';

        const pilotOption = pilotSelect.selectedOptions[0];
        const pilotId = pilotOption.getAttribute('data-pilot-id');
        const raceId = pilotOption.getAttribute('data-race-id');

        if (!raceId || !pilotId) {
            setSubscriptionStatus('Please select a valid pilot first.');
            return;
        }

        fetch(restUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                race_id: raceId,
                pilot_id: pilotId,
                endpoint: subscription.endpoint,
                keys: { p256dh, auth }
            })
        })
            .then((res) => {
                if (!res.ok) throw new Error('Server returned ' + res.status);
                return res.json();
            })
            .then((json) => {
                setSubscriptionStatus('Server subscription success: ' + json.message);
            })
            .catch((err) => {
                console.error('Sending subscription failed', err);
                setSubscriptionStatus('Could not send subscription to server.');
            });
    }

    // Utility: Convert VAPID key
    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function setSubscriptionStatus(message) {
        if (subscriptionStatus) {
            subscriptionStatus.textContent = message;
        } else {
            console.log('[SubscriptionStatus]', message);
        }
    }
})();

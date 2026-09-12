// rm-m-dataLoader.js
// The singleton every live module hangs off. It polls the race's timestamp file, downloads the
// result JSON when that changed, and hands the parsed object to its subscribers.
//
//   import { dataLoaderInstance } from './rm-m-dataLoader.js';
//   dataLoaderInstance.subscribe( ( data ) => { ... } );   // fires at once if data is known
//   dataLoaderInstance.onState( ( state ) => { ... } );    // fires at once with the current state
//
// Two files per race, and that split is the whole design: the timestamp file is 30 bytes and says
// when the data was produced, the data file is ~100 KB compressed (~1.2 MB of JSON). Polling the
// small one and downloading the big one only when it changed is what keeps a trackside phone
// affordable.
//
// Three things happen here beyond that, each measured against the real payload rather than a
// fixture:
//
//   - The cache lives in localStorage, so it survives a second tab and a cold start. Both of those
//     used to cost a full ~100 KB; they now cost the 30-byte timestamp check. A reload in the same
//     tab was already cheap -- sessionStorage covered that one, and only that one.
//   - The data request carries If-None-Match. Once the cache survives, this is the fallback for
//     when storage is unavailable or has been evicted rather than the main saving, but an
//     unchanged file then answers 304 with an empty body and nothing has to be parsed.
//   - Polling behaves like it is on a phone: it stops while the page is hidden, checks again on
//     return, backs off when the network fails, and jitters so a grandstand full of phones does
//     not all ask on the same second.
//
// The state this exposes through onState() is what js/rm-m-updateStatus.js turns into the line
// telling a viewer whether they are looking at the current standing.
//
// Since 1.8.0 a race is stored in parts as well -- one per section, per result heat and per
// class -- with an index naming each part's hash (L7 in docs/live-webapp-improvements.md). When
// the timestamp changed, the loader reads the index and downloads only the parts whose hash
// changed: 8-15 KB after a heat instead of 58-78 KB. The whole file stays what a first visit
// downloads, and what anything the parts cannot do falls back to; it carries the index, so the
// loader knows from it which parts it holds.

// Prefixed so eviction can find our entries and nothing else. Note that rm_last_race (set by
// js/rm-live-resume.js) lives in the same origin and must not be swept up: it does not carry this
// prefix, and that is deliberate rather than luck.
const STORAGE_PREFIX = 'rm_data_';

const DATA_TIMEOUT_MS = 30000;  // the payload's own deadline; see this.dataTimeout below
const MAX_BACKOFF_MS = 120000;  // ~2 min; past that a viewer taps rather than waits
const MIN_CHECK_GAP_MS = 2000;  // visibilitychange, focus and online can all fire at once
const JITTER = 0.2;             // +/- 20 % around the nominal interval

// The index, as includes/race-files.php writes it.
const INDEX_KEY = 'rm_index';   // the key the whole file carries it under
const INDEX_FORMAT = 1;         // the only format this loader reads; any other means the whole file
const INDEX_ATTEMPTS = 3;       // how often an index overtaken by a newer upload is read again
const MAX_PART_SHARE = 0.5;     // past half of the payload, the whole file is the cheaper download
// A part's key, as the server lets it into a file name. Anything else, and the index goes unused
// -- "__proto__" among them, which would reach Object.prototype as the parts are put together.
const PART_KEY = /^[A-Za-z0-9_]{1,64}$/;
const UNSAFE_KEYS = new Set( [ '__proto__', 'constructor', 'prototype' ] );

// A part's name, in its file and in storage: its path's keys joined by "-", which no key holds.
function partName( path ) {
    return path.join( '-' );
}

function usableIndex( index ) {
    return !! index && index.format === INDEX_FORMAT && typeof index.time === 'string' &&
        Array.isArray( index.parts ) && index.parts.length > 0 &&
        index.parts.every( ( part ) => !! part && typeof part.hash === 'string' &&
            Number.isFinite( part.bytes ) && Array.isArray( part.path ) && part.path.length > 0 &&
            part.path.every( ( key ) => typeof key === 'string' && PART_KEY.test( key ) && ! UNSAFE_KEYS.has( key ) ) );
}

// Take the index out of a whole file, so that no subscriber ever sees it. null when it has none
// this loader can use.
function takeIndex( data ) {
    if ( ! data || typeof data !== 'object' || ! Object.prototype.hasOwnProperty.call( data, INDEX_KEY ) ) {
        return null;
    }
    const index = data[ INDEX_KEY ];
    delete data[ INDEX_KEY ];
    return usableIndex( index ) ? index : null;
}

// Each part of `data` as text, by name. null when the index names a part the data does not have.
function textsOf( index, data ) {
    const texts = new Map();
    for ( const part of index.parts ) {
        let value = data;
        for ( const key of part.path ) {
            value = value && typeof value === 'object' && Object.prototype.hasOwnProperty.call( value, key )
                ? value[ key ] : undefined;
        }
        if ( value === undefined ) {
            return null;
        }
        texts.set( partName( part.path ), JSON.stringify( value ) );
    }
    return texts;
}

// The payload, put together from the text of every part the index names. Parsed anew each time,
// like the whole file always was: a subscriber may change what it is handed, and nothing it
// changes may reach the next update.
function assemble( index, texts ) {
    const data = {};
    for ( const part of index.parts ) {
        let target = data;
        const last = part.path.length - 1;
        for ( let i = 0; i < last; i++ ) {
            const key = part.path[ i ];
            if ( ! target[ key ] || typeof target[ key ] !== 'object' ) {
                target[ key ] = {};
            }
            target = target[ key ];
        }
        target[ part.path[ last ] ] = JSON.parse( texts.get( partName( part.path ) ) );
    }
    return data;
}

export class DataLoader {
    constructor() {
        // Get config from the global inline script (generated by WordPress)
        const configData = ( window.RmJsConfig && window.RmJsConfig[ 'dataLoader' ] ) || null;
        if ( ! configData ) {
            throw new Error( 'dataLoader: Missing configuration data' );
        }

        // Required
        this.timestampUrl = configData.timestampUrl;
        this.dataUrl = configData.dataUrl;

        // Optional
        // The index and the parts, the part's URL with "%s" for its name. Used only for a race
        // whose whole file carried an index; without them every change downloads the whole file,
        // as before 1.8.0.
        this.indexUrl = configData.indexUrl || null;
        this.partUrl = configData.partUrl || null;
        this.refreshInterval = configData.refreshInterval || 0; // in ms; 0 = the race is not live
        this.timeout = configData.timeout || 9000;
        // The payload gets its own, larger deadline: 30 bytes and 100 KB do not deserve the same
        // patience, and trackside reception is exactly where the difference decides whether the
        // page ever fills.
        this.dataTimeout = configData.dataTimeout || DATA_TIMEOUT_MS;
        this.maxInterval = configData.maxInterval || MAX_BACKOFF_MS;

        // Stays the bare race id: displayHeats, displayStats and pilotSelector read this and build
        // their own storage keys and data-race-id attributes out of it. Renaming it here would
        // break them silently, so the keys this module uses are derived separately below.
        this.storageKey = configData.storageKey || 'dataCache';
        this.dataStoreKey = `${ STORAGE_PREFIX }${ this.storageKey }`;
        this.metaStoreKey = `${ STORAGE_PREFIX }${ this.storageKey }_meta`;
        this.partStorePrefix = `${ STORAGE_PREFIX }${ this.storageKey }_part_`;

        // Internal state flags
        this.isFetchingTimestamp = false;
        this.isFetchingData = false;

        // Subscribers: data on one channel, loader state on the other. They are separate because
        // most modules only care that new data arrived, while the status line only cares what the
        // loader is doing and never re-renders a table.
        this.subscribers = new Set();
        this.stateSubscribers = new Set();

        this.timer = null;
        this.consecutiveFailures = 0;
        this.lastCheckStartedAt = 0;

        this.phase = 'idle';        // idle | checking | updating
        this.lastCheckedAt = null;  // when a check last succeeded
        this.lastChangedAt = null;  // when the payload last actually changed
        this.lastError = null;
        this.nextCheckAt = null;

        // Whatever the last visit left behind.
        const cached = this.readCache();
        this.cachedTimestamp = cached.timestamp;
        this.cachedEtag = cached.etag;
        this.dataTime = cached.time;
        this.data = cached.data;
        // Which parts this.data is made of, and the text of each: what an update puts the new data
        // together from. null for a race without an index.
        this.index = cached.index;
        this.partTexts = cached.partTexts;
        this.storedAsParts = cached.storedAsParts;
        // Parts downloaded but not yet part of a complete update. Kept across attempts, so that on
        // a fading link each one gets further than the last; never shown, never stored.
        this.pendingParts = new Map();

        // Cached data is on screen before anything has been asked, so it starts out unconfirmed:
        // nobody has yet checked whether it is still the current standing. The status line has to
        // say so rather than claim it is current.
        this.unconfirmed = !! this.data;

        this.bindEnvironment();
        this.initialize();
    }

    // ------------------------------------------------------------------ storage

    // Probed once. Private mode, blocked site data and a full quota all throw here, and none of
    // them is a reason to break the page: the cache is an optimisation, not the source of truth.
    storageAvailable() {
        if ( this.canStore === undefined ) {
            try {
                const probe = `${ STORAGE_PREFIX }probe`;
                window.localStorage.setItem( probe, '1' );
                window.localStorage.removeItem( probe );
                this.canStore = true;
            } catch ( e ) {
                this.canStore = false;
            }
        }
        return this.canStore;
    }

    // Two layouts. A race with an index is stored in parts, each under its own key, and the meta
    // entry carries the index that names them; a race without one is stored as the whole file, as
    // before 1.8.0.
    readCache() {
        const empty = { data: null, timestamp: null, etag: null, time: null, index: null, partTexts: null, storedAsParts: false };
        if ( ! this.storageAvailable() ) {
            return empty;
        }
        try {
            const metaRaw = window.localStorage.getItem( this.metaStoreKey );
            if ( ! metaRaw ) {
                return empty;
            }
            const meta = JSON.parse( metaRaw );
            const found = { timestamp: meta.timestamp || null, etag: meta.etag || null, time: meta.time || null };

            if ( meta.index ) {
                // Every part the index names has to be there, or none of it counts.
                if ( ! usableIndex( meta.index ) ) {
                    throw new Error( 'the stored index is unusable' );
                }
                const texts = new Map();
                for ( const part of meta.index.parts ) {
                    const text = window.localStorage.getItem( this.partStorePrefix + partName( part.path ) );
                    if ( text === null ) {
                        throw new Error( `the stored part ${ partName( part.path ) } is missing` );
                    }
                    texts.set( partName( part.path ), text );
                }
                return { ...found, data: assemble( meta.index, texts ), index: meta.index, partTexts: texts, storedAsParts: true };
            }

            const dataRaw = window.localStorage.getItem( this.dataStoreKey );
            if ( ! dataRaw ) {
                return empty;
            }
            const data = JSON.parse( dataRaw );
            // A whole file stored since the race got its index -- by a loader from before 1.8.0,
            // too -- carries it, and the next update can go by the parts.
            const index = takeIndex( data );
            const texts = index ? textsOf( index, data ) : null;
            return { ...found, data, index: texts ? index : null, partTexts: texts, storedAsParts: false };
        } catch ( e ) {
            // A half-written or corrupt entry is worse than none: drop it and download again.
            console.error( 'dataLoader: discarding unreadable cache', e );
            this.dropCache();
            return empty;
        }
    }

    isOwnKey( key ) {
        return key === this.dataStoreKey || key === this.metaStoreKey || key.indexOf( this.partStorePrefix ) === 0;
    }

    // One race's payload is around 1.2 MB as text and the budget for the whole origin is a few
    // megabytes, so two of them do not both fit. The race being viewed wins; the others would be
    // downloaded again anyway the moment the visitor switches back.
    evictOtherRaces() {
        try {
            const doomed = [];
            for ( let i = 0; i < window.localStorage.length; i++ ) {
                const key = window.localStorage.key( i );
                if ( key && key.indexOf( STORAGE_PREFIX ) === 0 && ! this.isOwnKey( key ) ) {
                    doomed.push( key );
                }
            }
            for ( const key of doomed ) {
                window.localStorage.removeItem( key );
            }
        } catch ( e ) {
            // Eviction failing costs space, never correctness.
        }
    }

    // This race's stored parts whose name `doomed` picks, removed.
    removeStoredParts( doomed ) {
        const keys = [];
        for ( let i = 0; i < window.localStorage.length; i++ ) {
            const key = window.localStorage.key( i );
            if ( key && key.indexOf( this.partStorePrefix ) === 0 && doomed( key.slice( this.partStorePrefix.length ) ) ) {
                keys.push( key );
            }
        }
        for ( const key of keys ) {
            window.localStorage.removeItem( key );
        }
    }

    dropCache() {
        try {
            window.localStorage.removeItem( this.dataStoreKey );
            window.localStorage.removeItem( this.metaStoreKey );
            this.removeStoredParts( () => true );
        } catch ( e ) {}
        this.storedAsParts = false;
    }

    writeMeta() {
        if ( ! this.storageAvailable() ) {
            return;
        }
        try {
            window.localStorage.setItem( this.metaStoreKey, JSON.stringify( {
                timestamp: this.cachedTimestamp,
                etag: this.cachedEtag,
                time: this.dataTime,
                // Only while the parts are what is stored: it says which keys hold them.
                index: this.storedAsParts ? this.index : null,
                savedAt: Date.now()
            } ) );
        } catch ( e ) {}
    }

    // Takes the response text rather than the parsed object on purpose: storing what came off the
    // wire skips a JSON.stringify of 1.2 MB on every update, which is measurable on a phone.
    writeWhole( text ) {
        if ( ! this.storageAvailable() ) {
            return;
        }
        this.evictOtherRaces();
        try {
            window.localStorage.setItem( this.dataStoreKey, text );
        } catch ( e ) {
            // Over quota. Clear our own entries and try once more before giving up on storage for
            // the rest of this page view.
            this.dropCache();
            try {
                window.localStorage.setItem( this.dataStoreKey, text );
            } catch ( e2 ) {
                console.error( 'dataLoader: cache does not fit, continuing without it', e2 );
                this.canStore = false;
                return;
            }
        }
        this.storedAsParts = false;
        this.writeMeta();
        try {
            this.removeStoredParts( () => true );
        } catch ( e ) {}
    }

    // The parts named in `changed`, or every part when that is null or storage does not hold this
    // race's parts yet. The parts go first and the index that names them after, as on the server:
    // a stored index never names a part that is not there, only one that is newer than it says,
    // which the next update puts right.
    writeParts( changed ) {
        if ( ! this.storageAvailable() ) {
            return;
        }
        this.evictOtherRaces();
        const all = this.index.parts.map( ( part ) => partName( part.path ) );
        try {
            this.putParts( changed && this.storedAsParts ? changed : all );
        } catch ( e ) {
            // Over quota. Clear our own entries and write every part once more before giving up on
            // storage for the rest of this page view.
            this.dropCache();
            try {
                this.putParts( all );
            } catch ( e2 ) {
                console.error( 'dataLoader: cache does not fit, continuing without it', e2 );
                this.dropCache();
                this.canStore = false;
                return;
            }
        }
        this.storedAsParts = true;
        this.writeMeta();
        try {
            const kept = new Set( all );
            this.removeStoredParts( ( name ) => ! kept.has( name ) );
            window.localStorage.removeItem( this.dataStoreKey );
        } catch ( e ) {}
    }

    putParts( names ) {
        for ( const name of names ) {
            window.localStorage.setItem( this.partStorePrefix + name, this.partTexts.get( name ) );
        }
    }

    // -------------------------------------------------------------- subscribers

    // Subscribe for data updates.
    subscribe( callback ) {
        if ( typeof callback === 'function' ) {
            this.subscribers.add( callback );
            // Immediately send the latest data (if available). This runs inside the subscribing
            // module's start-up, so a throw here would end that start-up half done: caught like
            // every later update in notifySubscribers().
            if ( this.data ) {
                try {
                    callback( this.data );
                } catch ( error ) {
                    console.error( 'Error in subscriber callback:', error );
                }
            }
        }
    }

    // Unsubscribe a callback.
    unsubscribe( callback ) {
        this.subscribers.delete( callback );
    }

    // Notify all subscribers with the updated data.
    notifySubscribers( data ) {
        for ( const callback of this.subscribers ) {
            try {
                callback( data );
            } catch ( error ) {
                console.error( 'Error in subscriber callback:', error );
            }
        }
    }

    // A late subscriber gets the current state at once, the same way subscribe() hands over the
    // current data. The status line is usually constructed after the first check has started.
    onState( callback ) {
        if ( typeof callback === 'function' ) {
            this.stateSubscribers.add( callback );
            callback( this.getState() );
        }
    }

    offState( callback ) {
        this.stateSubscribers.delete( callback );
    }

    getState() {
        return {
            phase: this.phase,
            online: typeof navigator === 'undefined' || navigator.onLine !== false,
            hasData: !! this.data,
            unconfirmed: this.unconfirmed,
            dataTime: this.dataTime,   // the wall clock the timer wrote, e.g. "2025-12-14 17:18:16"
            lastCheckedAt: this.lastCheckedAt,
            lastChangedAt: this.lastChangedAt,
            nextCheckAt: this.nextCheckAt,
            refreshInterval: this.refreshInterval,
            consecutiveFailures: this.consecutiveFailures,
            lastError: this.lastError
        };
    }

    emit() {
        const snapshot = this.getState();
        for ( const callback of this.stateSubscribers ) {
            try {
                callback( snapshot );
            } catch ( error ) {
                console.error( 'Error in state subscriber callback:', error );
            }
        }
    }

    setPhase( phase ) {
        if ( this.phase !== phase ) {
            this.phase = phase;
            this.emit();
        }
    }

    // --------------------------------------------------------------- scheduling

    // 10 s -> 20 s -> 40 s -> 80 s -> capped. Reception at a race site fails in bursts, and asking
    // harder does not make it come back sooner.
    currentDelay() {
        if ( ! this.consecutiveFailures ) {
            return this.refreshInterval;
        }
        return Math.min(
            this.refreshInterval * Math.pow( 2, this.consecutiveFailures ),
            this.maxInterval
        );
    }

    clearTimer() {
        if ( this.timer ) {
            clearTimeout( this.timer );
            this.timer = null;
        }
        this.nextCheckAt = null;
    }

    // setTimeout rather than setInterval, because no two of these delays are the same: the backoff
    // changes it and the jitter changes it again. Everyone opens the page when the heat starts, so
    // without that spread everyone also polls on the same second.
    scheduleNext() {
        this.clearTimer();
        if ( this.refreshInterval <= 0 ) {
            return;
        }
        if ( typeof document !== 'undefined' && document.visibilityState === 'hidden' ) {
            return;
        }
        const spread = 1 + ( Math.random() * 2 - 1 ) * JITTER;
        const delay = Math.max( 1000, Math.round( this.currentDelay() * spread ) );
        this.nextCheckAt = Date.now() + delay;
        this.timer = setTimeout( () => this.checkForUpdates(), delay );
    }

    bindEnvironment() {
        if ( typeof document !== 'undefined' ) {
            document.addEventListener( 'visibilitychange', () => {
                if ( document.visibilityState === 'visible' ) {
                    this.requestCheck();
                } else {
                    // A phone in a pocket used to poll all day.
                    this.clearTimer();
                    this.emit();
                }
            } );
        }
        if ( typeof window !== 'undefined' ) {
            window.addEventListener( 'online', () => {
                this.consecutiveFailures = 0;  // the backoff was about the connection that just left
                this.requestCheck();
                this.emit();
            } );
            window.addEventListener( 'offline', () => this.emit() );
            window.addEventListener( 'focus', () => this.requestCheck() );
        }
    }

    // visibilitychange, focus and online all fire when a tab comes back, within milliseconds of
    // each other. Without a floor that is three timestamp requests in the same instant.
    requestCheck() {
        if ( this.refreshInterval <= 0 ) {
            return;
        }
        if ( Date.now() - this.lastCheckStartedAt < MIN_CHECK_GAP_MS ) {
            this.scheduleNext();
            return;
        }
        this.checkForUpdates();
    }

    // Public, and deliberately not gated on refreshInterval: the status line is tappable, and
    // "check now" has to do something even on a race that has already finished.
    checkNow() {
        if ( Date.now() - this.lastCheckStartedAt < 1000 ) {
            return;
        }
        this.checkForUpdates();
    }

    // ------------------------------------------------------------------ network

    // One request, start to finish, under one deadline.
    //
    // `consume` reads the response, and it is awaited **inside** the timeout rather than after it.
    // That is the whole point of this shape. A fading mobile link does not usually refuse the
    // connection outright: the headers arrive and the body then stops coming. Clearing the timer
    // once the response object exists -- the obvious way to write this, and how it was written --
    // leaves that read running for ever. The abort never fires, the in-flight flag never clears,
    // every later check returns at the guard that reads it, and the page is wedged until it is
    // closed. That is the same dead end the timestamp used to create, reached from the other side.
    //
    // cache: 'no-store' stays, and it does not contradict the If-None-Match sent below. It keeps
    // the browser's own cache out of the way so that a 304 arrives here as a 304, instead of being
    // turned back into a 200 from cache -- the distinction the freshness indicator needs.
    async request( url, options, consume, budget ) {
        const controller = new AbortController();
        const id = setTimeout( () => controller.abort(), budget || this.timeout );
        try {
            const response = await fetch( url, {
                ...options,
                cache: 'no-store',
                signal: controller.signal
            } );
            return await consume( response );
        } finally {
            clearTimeout( id );
        }
    }

    // The file is compared as raw text, exactly as it always was -- that is the change detector,
    // and it must not become sensitive to key order or whitespace. The time is pulled out
    // separately, for display only.
    parseTime( text ) {
        try {
            const parsed = JSON.parse( text );
            return ( parsed && parsed.time ) || null;
        } catch ( e ) {
            return null;
        }
    }

    recordFailure( error ) {
        this.consecutiveFailures += 1;
        this.lastError = ( error && error.message ) || String( error );
        console.error( 'dataLoader:', this.lastError );
    }

    // Start the data loading and refresh cycle.
    async initialize() {
        await this.checkForUpdates();
        if ( ! this.data ) {
            // The timestamp check did not leave us with data, which normally means it failed. Ask
            // for the payload directly rather than show an empty page until the next tick.
            await this.fetchAndUpdateData( this.cachedTimestamp );
        }
    }

    // Check the timestamp from the server and update data if needed.
    async checkForUpdates() {
        if ( this.isFetchingTimestamp ) {
            return;
        }
        this.isFetchingTimestamp = true;
        this.lastCheckStartedAt = Date.now();
        this.setPhase( 'checking' );

        try {
            const newTimestamp = await this.request( this.timestampUrl, {}, async ( response ) => {
                if ( ! response.ok ) {
                    throw new Error( `Timestamp fetch failed: ${ response.status } ${ response.statusText }` );
                }
                return response.text();
            } );

            this.lastCheckedAt = Date.now();
            this.lastError = null;
            this.consecutiveFailures = 0;

            if ( this.cachedTimestamp !== newTimestamp ) {
                await this.fetchAndUpdateData( newTimestamp );
            } else {
                // Confirmed: what is on screen is what the timer last produced.
                this.unconfirmed = false;
            }
        } catch ( error ) {
            this.recordFailure( error );
            // Fall back to cached data, and tell subscribers again so a view constructed after the
            // failure still has something to render.
            if ( this.data ) {
                this.notifySubscribers( this.data );
            }
        } finally {
            this.isFetchingTimestamp = false;
            this.phase = 'idle';
            this.scheduleNext();
            this.emit();
        }
    }

    // Fetch what changed, update storage, then notify subscribers: by the parts where the race has
    // them and this loader knows which it holds, by the whole file otherwise and whenever the parts
    // will not do.
    async fetchAndUpdateData( newTimestamp ) {
        if ( this.isFetchingData ) {
            return;
        }
        this.isFetchingData = true;
        this.setPhase( 'updating' );

        try {
            const done = this.canUseParts() && await this.updateFromParts( newTimestamp );
            if ( ! done ) {
                await this.updateFromWhole( newTimestamp );
            }
        } catch ( error ) {
            this.recordFailure( error );
            // If fetching new data fails, use the cached data if available.
            if ( this.data ) {
                this.notifySubscribers( this.data );
            }
        } finally {
            this.isFetchingData = false;
            // checkForUpdates() clears the phase in its own finally, but this method is also
            // called straight from initialize() when the timestamp check left us with nothing.
            // Without this, a failure on that path left the phase reading 'updating' for good --
            // and on a race that is not live nothing ever runs again to correct it, so the
            // indicator sat on "Loading race data..." instead of reporting the failure.
            this.setPhase( 'idle' );
        }
    }

    canUseParts() {
        return !! ( this.indexUrl && this.partUrl && this.index && this.partTexts && this.data );
    }

    // The whole file, as every update was fetched before 1.8.0: what a first visit downloads, what
    // a race without an index always does, and what the parts fall back to.
    async updateFromWhole( newTimestamp ) {
        const options = {};
        if ( this.cachedEtag && this.data ) {
            options.headers = { 'If-None-Match': this.cachedEtag };
        }

        // A longer deadline than the timestamp check gets. That one is 30 bytes and either
        // arrives at once or not at all; this is ~100 KB, and over the kind of link this
        // whole change exists for it can legitimately take much longer than nine seconds.
        // Aborting a download that was about to succeed, over and over, is its own way of
        // never loading anything.
        const outcome = await this.request( this.dataUrl, options, async ( response ) => {
            // 304 is not response.ok, so it has to be recognised before the error branch.
            if ( response.status === 304 && this.data ) {
                return { notModified: true };
            }
            if ( ! response.ok ) {
                throw new Error( `Data fetch failed: ${ response.status } ${ response.statusText }` );
            }
            // text() and then parse, rather than json(): the text is what gets cached, so
            // taking it this way avoids stringifying 1.2 MB again on every update.
            return { text: await response.text(), etag: response.headers.get( 'ETag' ) };
        }, this.dataTimeout );

        if ( outcome.notModified ) {
            this.cachedTimestamp = newTimestamp;
            this.dataTime = this.parseTime( newTimestamp );
            this.unconfirmed = false;
            this.writeMeta();
            this.notifySubscribers( this.data );
            return;
        }

        const newData = JSON.parse( outcome.text );
        // The index rides along in the whole file, and says which parts it is made of. Its text
        // per part is what later updates put the data together from.
        const index = takeIndex( newData );
        const texts = index && this.indexUrl && this.partUrl ? textsOf( index, newData ) : null;

        // The timestamp is committed here and nowhere earlier, and that ordering is the whole
        // defence against the failure this was reported for. Recording it before the payload
        // arrives marks a version as seen that was never received: every later check then
        // finds the timestamp unchanged, skips the download, and the page stays empty for
        // good -- through reload after reload, because the note outlives them.
        this.data = newData;
        this.cachedEtag = outcome.etag;
        this.cachedTimestamp = newTimestamp;
        this.dataTime = this.parseTime( newTimestamp );
        this.lastChangedAt = Date.now();
        this.unconfirmed = false;
        this.index = texts ? index : null;
        this.partTexts = texts;
        this.pendingParts.clear();

        if ( texts ) {
            this.writeParts( null );
        } else {
            this.writeWhole( outcome.text );
        }
        // Notify subscribers only when new data is successfully loaded.
        this.notifySubscribers( this.data );
    }

    // The parts whose hash changed since this.index, and nothing else. Answers false when the
    // whole file is what to download instead: no index, one this loader does not read, one older
    // than the timestamp that sent it here, too much changed, a part that is no part, or the index
    // overtaken by newer uploads INDEX_ATTEMPTS times running. A request that fails throws, as the
    // whole file's would: on a dead link, downloading more is no answer.
    async updateFromParts( newTimestamp ) {
        const announced = this.parseTime( newTimestamp );
        for ( let attempt = 0; attempt < INDEX_ATTEMPTS; attempt++ ) {
            const index = await this.fetchIndex();
            if ( ! index ) {
                return false;
            }
            // The server writes the index before the timestamp, so it is never older than the
            // timestamp -- unless something between here and there keeps an old copy, and then
            // the parts it names may be old as well.
            if ( announced && index.time < announced ) {
                continue;
            }

            const known = new Map( this.index.parts.map( ( part ) => [ partName( part.path ), part.hash ] ) );
            const missing = index.parts.filter( ( part ) => {
                const name = partName( part.path );
                const pending = this.pendingParts.get( name );
                return known.get( name ) !== part.hash && ! ( pending && pending.hash === part.hash );
            } );
            const total = index.parts.reduce( ( sum, part ) => sum + part.bytes, 0 );
            const needed = missing.reduce( ( sum, part ) => sum + part.bytes, 0 );
            if ( needed > total * MAX_PART_SHARE ) {
                return false;
            }

            // All at once, each under the payload's deadline, its body included.
            const outcomes = await Promise.allSettled( missing.map( ( part ) => this.fetchPart( part ) ) );
            let overtaken = false;
            for ( let i = 0; i < missing.length; i++ ) {
                const outcome = outcomes[ i ];
                if ( outcome.status === 'rejected' ) {
                    continue;
                }
                if ( outcome.value === null ) {
                    overtaken = true;   // removed by a newer upload
                    continue;
                }
                if ( outcome.value.unreadable ) {
                    return false;
                }
                // Kept whatever its hash: a newer one is what the next index will name.
                this.pendingParts.set( partName( missing[ i ].path ), outcome.value );
                if ( outcome.value.hash !== missing[ i ].hash ) {
                    overtaken = true;   // replaced by a newer upload since the index was read
                }
            }
            const failed = outcomes.find( ( outcome ) => outcome.status === 'rejected' );
            if ( failed ) {
                throw failed.reason;
            }
            if ( overtaken ) {
                continue;
            }

            this.commitParts( index, newTimestamp );
            return true;
        }
        return false;
    }

    // Every part the index names has arrived: take the index, put the data together, store it.
    commitParts( index, newTimestamp ) {
        const texts = new Map();
        const changed = [];
        for ( const part of index.parts ) {
            const name = partName( part.path );
            const pending = this.pendingParts.get( name );
            if ( pending && pending.hash === part.hash ) {
                texts.set( name, pending.text );
                changed.push( name );
            } else {
                texts.set( name, this.partTexts.get( name ) );
            }
        }
        // Nothing changed but the time -- the same upload sent twice: a confirmation, as a 304 is.
        const unchanged = changed.length === 0 && index.parts.length === this.index.parts.length;
        const newData = unchanged ? this.data : assemble( index, texts );

        // Committed only now that every part has arrived: the same ordering as the whole file's,
        // and for the same reason.
        this.data = newData;
        this.index = index;
        this.partTexts = texts;
        this.cachedTimestamp = newTimestamp;
        this.dataTime = this.parseTime( newTimestamp );
        this.unconfirmed = false;
        this.pendingParts.clear();
        if ( unchanged ) {
            this.writeMeta();
        } else {
            this.lastChangedAt = Date.now();
            // The whole file this ETag was sent with is no longer what is on screen.
            this.cachedEtag = null;
            this.writeParts( changed );
        }
        this.notifySubscribers( this.data );
    }

    // The index, or null when there is none this loader can use.
    async fetchIndex() {
        const text = await this.request( this.indexUrl, {}, async ( response ) => {
            if ( response.status === 404 ) {
                return null;
            }
            if ( ! response.ok ) {
                throw new Error( `Index fetch failed: ${ response.status } ${ response.statusText }` );
            }
            return response.text();
        } );
        if ( text === null ) {
            return null;
        }
        try {
            const index = JSON.parse( text );
            return usableIndex( index ) ? index : null;
        } catch ( e ) {
            return null;
        }
    }

    // One part: { hash, text }, with the hash the part itself carries, which may be newer than
    // the index's. null when it is gone, { unreadable: true } when what came is no part.
    fetchPart( part ) {
        return this.request( this.partUrl.replace( '%s', partName( part.path ) ), {}, async ( response ) => {
            if ( response.status === 404 ) {
                return null;
            }
            if ( ! response.ok ) {
                throw new Error( `Part fetch failed: ${ response.status } ${ response.statusText }` );
            }
            // Outside the try: a body that stalls is a failed request, not a broken part.
            const text = await response.text();
            try {
                const wrapper = JSON.parse( text );
                if ( wrapper && typeof wrapper.hash === 'string' && Object.prototype.hasOwnProperty.call( wrapper, 'data' ) ) {
                    return { hash: wrapper.hash, text: JSON.stringify( wrapper.data ) };
                }
            } catch ( e ) {}
            return { unreadable: true };
        }, this.dataTimeout );
    }
}

// Singleton instance
export const dataLoaderInstance = new DataLoader();

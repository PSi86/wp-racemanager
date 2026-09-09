// rm-m-updateStatus.js
// The pill at the foot of the live views, answering "am I looking at the current standing, or is
// my phone stuck?".
//
// It renders nothing of the race itself. It reports what js/rm-m-dataLoader.js is doing, which is
// the one thing no table on the page can show: a stale ranking looks exactly like a current one.
//
// Two different times matter and both are kept, but only one is ever in the pill:
//
//   - when the data was produced -- the wall clock the timer wrote into the timestamp file
//   - when we last asked         -- a clock reading from this browser
//
// Conflating those is what makes a status line untrustworthy. "Updated 3 seconds ago" is a lie
// when it means "we asked 3 seconds ago and got the same half-hour-old file back". So the pill
// shows whichever one the current state makes relevant -- the data time while things are fine,
// the check time as soon as they are not -- and the other one lives in the title attribute.
//
// The times are also not comparable, and that is why they are never subtracted from one another:
// the data time is site-local wall clock with no timezone in it (PHP current_time('mysql')),
// while the check time comes from the visitor's own clock. Displaying each as what it is stays
// honest even when the two clocks disagree; arithmetic across them would not.
//
// The element is a <button> and the whole pill is the control: tapping it forces a check, which
// is the only honest answer to "is it stuck?".

import { dataLoaderInstance } from './rm-m-dataLoader.js';

const DEFAULT_CONTAINER_ID = 'rm-update-status';

// After three missed intervals the data is old enough that saying "up to date" would be a claim
// rather than an observation.
const STALE_AFTER_INTERVALS = 3;

// Long enough to notice out of the corner of an eye, short enough not to be a distraction while
// a heat is running. Must match the animation in css/rm-update-status.css.
const CHANGED_FLASH_MS = 1600;

class UpdateStatus {
    constructor() {
        const config = ( window.RmJsConfig && window.RmJsConfig[ 'updateStatus' ] ) || {};
        this.containerId = config.containerId || DEFAULT_CONTAINER_ID;

        this.container = null;
        this.textEl = null;
        this.dotEl = null;
        this.tickTimer = null;
        this.flashTimer = null;
        this.state = null;
        this.lastRendered = null;
        this.seenChangeAt = null;

        if ( document.readyState === 'loading' ) {
            document.addEventListener( 'DOMContentLoaded', () => this.mount() );
        } else {
            this.mount();
        }
    }

    mount() {
        this.container = document.getElementById( this.containerId );
        if ( ! this.container ) {
            // The shortcodes emit it, once per page. A page without one simply has no indicator,
            // which is a fine outcome and not worth an error.
            return;
        }

        // Emptied rather than appended to. A module evaluated twice -- two URLs for the same file
        // that differ only in a query string is enough, and that is exactly what a version
        // parameter is -- would otherwise stack a second dot and a second sentence inside the
        // same pill, each rendered by its own instance.
        this.container.textContent = '';

        this.dotEl = document.createElement( 'span' );
        this.dotEl.className = 'rm-update-status__dot';
        this.dotEl.setAttribute( 'aria-hidden', 'true' );

        this.textEl = document.createElement( 'span' );
        this.textEl.className = 'rm-update-status__text';
        // polite, not assertive: the standing updating is worth announcing, but never worth
        // interrupting whatever the visitor is reading at that moment.
        this.textEl.setAttribute( 'role', 'status' );
        this.textEl.setAttribute( 'aria-live', 'polite' );

        // A fixed label rather than the changing text: the button's job does not change even
        // though its contents do. The state itself is announced through the live region above,
        // so a screen reader hears both without hearing either twice.
        this.container.setAttribute( 'aria-label', 'Check for new race data now' );
        this.container.addEventListener( 'click', () => dataLoaderInstance.checkNow() );

        this.container.appendChild( this.dotEl );
        this.container.appendChild( this.textEl );
        // Left hidden. render() decides, because on a race that is not live the answer is
        // usually "not at all" -- see isRelevant().

        dataLoaderInstance.onState( ( state ) => {
            this.noticeChange( state );
            this.state = state;
            this.render();
            this.scheduleTick();
        } );

        // The relative time keeps moving while nothing else does, so it needs its own tick --
        // but only while anyone can see it.
        document.addEventListener( 'visibilitychange', () => {
            if ( document.visibilityState === 'visible' ) {
                this.render();
                this.scheduleTick();
            } else {
                this.clearTick();
            }
        } );

        this.scheduleTick();
    }

    // New data landed. Not the first load -- arriving at a page is not an update, and flashing at
    // someone the moment they get there would train them to ignore it.
    noticeChange( state ) {
        const previous = this.seenChangeAt;
        this.seenChangeAt = state.lastChangedAt;
        if ( previous === null || previous === undefined || state.lastChangedAt === previous ) {
            return;
        }
        if ( ! this.container ) {
            return;
        }
        this.container.classList.remove( 'is-changed' );
        // Reading offsetWidth restarts the animation; without it a second change inside the
        // window would not replay it.
        void this.container.offsetWidth;
        this.container.classList.add( 'is-changed' );
        clearTimeout( this.flashTimer );
        this.flashTimer = setTimeout(
            () => this.container.classList.remove( 'is-changed' ),
            CHANGED_FLASH_MS
        );
    }

    clearTick() {
        if ( this.tickTimer ) {
            clearTimeout( this.tickTimer );
            this.tickTimer = null;
        }
    }

    // One timer, and a slow one once the numbers stop moving quickly. A second-by-second interval
    // running all afternoon on a phone is exactly the kind of thing L4 removed from the loader.
    scheduleTick() {
        this.clearTick();
        if ( document.visibilityState === 'hidden' ) {
            return;
        }
        // Nothing on screen has a relative time in it, so there is nothing to keep current. On a
        // race that is not live this is the normal case, and a timer running all afternoon to
        // re-render a hidden element would be the same waste L4 took out of the loader.
        if ( this.container && this.container.hidden ) {
            return;
        }
        const busy = this.state && this.state.phase !== 'idle';
        const since = this.state && this.state.lastCheckedAt ? Date.now() - this.state.lastCheckedAt : 0;
        const every = busy || since < 60000 ? 1000 : 15000;
        this.tickTimer = setTimeout( () => {
            this.render();
            this.scheduleTick();
        }, every );
    }

    render() {
        if ( ! this.textEl || ! this.state ) {
            return;
        }
        const view = describe( this.state, Date.now() );
        // Writing an unchanged string into a live region makes some screen readers announce it
        // again, so nothing is touched unless it actually changed. Visibility is part of that
        // signature: the text can stay identical while the pill appears or disappears.
        const signature = `${ view.visible }|${ view.tone }|${ view.text }`;
        if ( this.lastRendered === signature ) {
            return;
        }
        this.lastRendered = signature;
        // Revealed before the text is written, so the live region is already exposed when its
        // content changes and a screen reader announces the arrival rather than missing it.
        this.container.hidden = ! view.visible;
        this.container.dataset.tone = view.tone;
        this.textEl.textContent = view.text;
        if ( view.title ) {
            this.container.setAttribute( 'title', view.title );
        } else {
            this.container.removeAttribute( 'title' );
        }
    }
}

// ---------------------------------------------------------------------------- formatting

// Site-local wall clock, "2025-12-14 17:18:16", with no timezone in it -- so it is read, never
// converted. Same calendar day as the viewer's: just the time. Another day: the date as well,
// because "17:18" on its own would look current when it is a week old.
export function formatDataTime( raw, now ) {
    if ( typeof raw !== 'string' ) {
        return null;
    }
    const match = raw.match( /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/ );
    if ( ! match ) {
        return null;
    }
    const [ , year, month, day, hour, minute ] = match;
    const today = now || new Date();
    const sameDay = today.getFullYear() === Number( year ) &&
        today.getMonth() + 1 === Number( month ) &&
        today.getDate() === Number( day );
    return sameDay ? `${ hour }:${ minute }` : `${ year }-${ month }-${ day } ${ hour }:${ minute }`;
}

export function formatElapsed( ms ) {
    const seconds = Math.max( 0, Math.round( ms / 1000 ) );
    if ( seconds < 10 ) {
        return 'just now';
    }
    if ( seconds < 60 ) {
        return `${ seconds } s ago`;
    }
    const minutes = Math.round( seconds / 60 );
    if ( minutes < 60 ) {
        return `${ minutes } min ago`;
    }
    const hours = Math.round( minutes / 60 );
    return `${ hours } h ago`;
}

// Whether the pill belongs on screen at all.
//
// A live race is being watched, and reporting that watch is the whole job -- so it is always
// there. A race that is not live is a different situation: the data is final, the loader will
// not check again, and a permanent "From 17:18" is furniture rather than information.
//
// So for those it stays out of the way, and comes back only when the viewer might not be
// looking at the newest state. That is a narrower condition than "something is unusual":
//
//   - A check that failed, or data that was never confirmed -- we do not know that what is on
//     screen is what the timer finished with. Show it.
//   - Being offline *after* a successful check is not a problem: the race is over, the data is
//     final, and the viewer has it. Staying quiet there is the point of the rule.
//   - Still loading is not a problem either, only a moment. A pill that appears and vanishes on
//     every page load would be noise; if the load actually fails, the failure branch catches it.
function isRelevant( state ) {
    if ( state.refreshInterval ) {
        return true;
    }
    if ( state.phase !== 'idle' ) {
        return false;
    }
    return state.consecutiveFailures > 0 || ! state.hasData || state.unconfirmed;
}

// The whole state machine in one place, and pure, so it can be exercised without a browser.
// Returns what the pill says, and whether it is shown at all.
export function describe( state, nowMs ) {
    return Object.assign( classify( state, nowMs || Date.now() ), { visible: isRelevant( state ) } );
}

// What the pill says. Order matters: every branch below assumes the ones above it did not apply.
//
// The shape of each answer follows the design of the pill: while things are fine it says the
// least it can and shows the data's own time, because that is what a viewer glances at. The
// moment anything is wrong it spells the situation out and the check time moves into the text,
// because that is then the number that answers the question being asked.
function classify( state, nowMs ) {
    const now = nowMs || Date.now();
    const dataAt = formatDataTime( state.dataTime, new Date( now ) );
    const checked = state.lastCheckedAt ? formatElapsed( now - state.lastCheckedAt ) : null;

    // Whichever time is not in the text ends up here, so nothing is ever lost -- only demoted.
    const title = [
        state.dataTime ? `Race data produced ${ state.dataTime }` : null,
        checked ? `last successful check ${ checked }` : null,
    ].filter( Boolean ).join( ' · ' ) || null;

    // In flight. These beat everything below because they describe right now, not a moment ago.
    if ( state.phase === 'updating' ) {
        return { tone: 'busy', text: state.hasData ? 'Loading new data…' : 'Loading race data…', title };
    }
    if ( state.phase === 'checking' ) {
        return { tone: 'busy', text: 'Checking…', title };
    }

    // Known-bad. Never show a freshness claim on top of a failing check.
    if ( ! state.online ) {
        return {
            tone: 'error',
            text: dataAt ? `Offline · data from ${ dataAt }` : 'Offline',
            title,
        };
    }
    if ( state.consecutiveFailures > 0 ) {
        const detail = checked ? `last reached ${ checked }` : 'not reachable';
        return {
            tone: 'error',
            text: dataAt ? `From ${ dataAt } · ${ detail }` : `Cannot load data · ${ detail }`,
            title,
        };
    }

    if ( ! state.hasData ) {
        return { tone: 'idle', text: 'No data yet', title: null };
    }

    // On screen, but nobody has confirmed it yet -- this is what a cold start out of the cache
    // looks like for the moment before the first check answers.
    if ( state.unconfirmed ) {
        return { tone: 'warn', text: `From ${ dataAt } · not checked yet`, title };
    }

    // The race is over, or was never flagged live: there is no next check to promise, so no
    // freshness is claimed either. What is shown is when the data was made.
    if ( ! state.refreshInterval ) {
        return { tone: 'idle', text: `From ${ dataAt }`, title };
    }

    if ( state.lastCheckedAt && now - state.lastCheckedAt > state.refreshInterval * STALE_AFTER_INTERVALS ) {
        return { tone: 'warn', text: `From ${ dataAt } · last checked ${ checked }`, title };
    }

    return { tone: 'live', text: `Up to date · ${ dataAt }`, title };
}

export const updateStatusInstance = new UpdateStatus();

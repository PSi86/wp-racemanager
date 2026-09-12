// js/rm-live-resume.js
// Remembers the race a visitor last looked at and offers to resume it.
//
// This replaces what the PHP session used to do, but on the client, so that every live URL
// stays static and cacheable. The server never needs to know which race a visitor picked.
//
// Two jobs:
//   1. On a race page, store that race.
//   2. On the selection page, mark the race the visitor was on and offer to go back to it --
//      or go straight there when opened with ?resume=1 (the PWA start URL). Without a stored
//      race, or without JavaScript, the plain selection list is shown, which is the correct
//      fallback either way.

( function () {
    var STORAGE_KEY = 'rm_last_race';
    var config = window.RmLiveResume || {};

    function read() {
        try {
            var raw = window.localStorage.getItem( STORAGE_KEY );
            return raw ? JSON.parse( raw ) : null;
        } catch ( e ) {
            // Private mode, disabled site data, or corrupt JSON: resume is a convenience, not a
            // requirement, so degrade to the selection page.
            return null;
        }
    }

    function write( entry ) {
        try {
            window.localStorage.setItem( STORAGE_KEY, JSON.stringify( entry ) );
        } catch ( e ) {
            // Nothing to do -- the visitor simply will not be offered a resume next time.
        }
    }

    // The server fills these in whenever it can resolve a race at all, so having them does not
    // mean this is a race page.
    function remember() {
        if ( ! config.raceUrl || ! config.raceSlug ) {
            return;
        }
        write( {
            url: config.raceUrl,
            slug: config.raceSlug,
            title: config.raceTitle || '',
            seen: Date.now()
        } );
    }

    // Add the marking the server puts on the entry when the URL names a race, for the case where
    // it could not: the list links to each race's default view, while the visitor may have been
    // on another one, so the race slug is what gets compared and not the whole URL.
    function markCurrent( list, slug ) {
        if ( ! slug ) {
            return;
        }
        var items = list.querySelectorAll( '.race-select-item' );
        for ( var i = 0; i < items.length; i++ ) {
            var link = items[ i ].querySelector( 'a[href]' );
            if ( ! link ) {
                continue;
            }
            var path;
            try {
                path = new URL( link.href, window.location.origin ).pathname;
            } catch ( e ) {
                continue;
            }
            if ( path.split( '/' ).indexOf( slug ) !== -1 ) {
                items[ i ].classList.add( 'is-current' );
                link.setAttribute( 'aria-current', 'true' );
                return;
            }
        }
    }

    // Which page is this? isSelection is the authoritative answer. Asking "did the server give me
    // a race?" first was the bug: the header's link back to the selection page carries the race as
    // ?rm_race=<slug>, so the selection page resolves one too. It therefore took the race-page
    // branch, stored the race, returned -- and never offered to resume, which is why arriving with
    // the marker and arriving without it behaved like two different features.
    if ( ! config.isSelection ) {
        remember();
        return;
    }

    // On the selection page the marker still says where the visitor came from, so the stored
    // entry is kept in step with it before anything is offered.
    remember();

    var stored = read();
    if ( ! stored || ! stored.url ) {
        return;
    }

    var params = new URLSearchParams( window.location.search );

    // Straight there only when the race is still live. The app installed at one event and opened
    // at the next used to land in the race long over; now it stays here, where the running race
    // is marked and the last one is still offered below. A page from before 1.9.0 names no live
    // races, and goes as it always went.
    var live = config.liveRaces;
    if ( params.get( 'resume' ) === '1' && ( ! Array.isArray( live ) || live.indexOf( stored.slug ) !== -1 ) ) {
        // replace() so the selection page does not end up in the back stack -- otherwise
        // "back" from the race would bounce straight into another resume.
        window.location.replace( stored.url );
        return;
    }

    document.addEventListener( 'DOMContentLoaded', function () {
        var list = document.querySelector( '.race-select-list' );
        if ( ! list ) {
            return;
        }

        // The page must look the same whether the race came from the URL or from storage, so
        // nothing here is conditional on the source. Marking an entry the server already marked
        // is a no-op, and the offer is made in both cases rather than only when the URL was
        // silent -- suppressing it in one of the two was itself an inconsistency.
        markCurrent( list, stored.slug );

        if ( ! stored.title ) {
            return;
        }

        var link = document.createElement( 'a' );
        link.className = 'rm-resume-link';
        link.href = stored.url;
        link.textContent = 'Continue with ' + stored.title;

        var wrapper = document.createElement( 'p' );
        wrapper.className = 'rm-resume';
        wrapper.appendChild( link );

        list.parentNode.insertBefore( wrapper, list );
    } );
}() );

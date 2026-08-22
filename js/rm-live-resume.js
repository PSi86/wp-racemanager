// js/rm-live-resume.js
// Remembers the race a visitor last looked at and offers to resume it.
//
// This replaces what the PHP session used to do, but on the client, so that every live URL
// stays static and cacheable. The server never needs to know which race a visitor picked.
//
// Two jobs:
//   1. On a race page, store that race.
//   2. On the selection page opened with ?resume=1 (the PWA start URL), go straight back to
//      the stored race. Without a stored race, or without JavaScript, the selection list is
//      shown -- which is the correct fallback either way.

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

    // 1. On a race page: remember it.
    if ( config.raceUrl && config.raceSlug ) {
        write( {
            url: config.raceUrl,
            slug: config.raceSlug,
            title: config.raceTitle || '',
            seen: Date.now()
        } );
        return;
    }

    // 2. On the selection page: resume if asked to.
    if ( ! config.isSelection ) {
        return;
    }

    var stored = read();
    if ( ! stored || ! stored.url ) {
        return;
    }

    var params = new URLSearchParams( window.location.search );

    if ( params.get( 'resume' ) === '1' ) {
        // replace() so the selection page does not end up in the back stack -- otherwise
        // "back" from the race would bounce straight into another resume.
        window.location.replace( stored.url );
        return;
    }

    // Otherwise offer it, rather than redirecting behind the visitor's back.
    document.addEventListener( 'DOMContentLoaded', function () {
        var list = document.querySelector( '.race-select-list' );
        if ( ! list || ! stored.title ) {
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

/**
 * rm-top-bar.js - the site's top bar on a phone: out of the way while the visitor scrolls down,
 * back as soon as they scroll up (1.17.0).
 *
 * The theme's header, logo and burger, stays at the top of the screen - a group around it is
 * sticky, a site-editor setting - and on a phone its 88 px are a tenth of the screen that the
 * bracket, next up and the rest do not get. droneracingslovakia.com lets its header go; measured
 * there on 2026-09-13 at 390 x 844: it stays while the page is scrolled 120 px or less, goes on the
 * first scroll down after that - 5 px will do - and comes back on the first scroll up, 2 px will
 * do, sliding in 0.3 s. So does this.
 *
 * Only where it is needed and safe: a header the page pins (sticky or fixed - the header, a block
 * around it or one directly in it), and only while its navigation shows the burger, which is the
 * phone view whatever width the site sets for it. Never while the burger's menu is open, and back
 * when the keyboard moves into it. css/rm-top-bar.css does the sliding. No header pinned, no burger:
 * nothing happens.
 */
( function () {
	'use strict';

	// Down to here the bar stays, whichever way the visitor scrolls.
	var KEEP = 120;
	var BAR = 'rm-top-bar';
	var HIDDEN = 'rm-top-bar-hidden';

	function pinned( el ) {
		var position = window.getComputedStyle( el ).position;
		return 'sticky' === position || 'fixed' === position;
	}

	// The element that keeps the header at the top: the header, a block around it, or one in it.
	function findBar() {
		var header = document.querySelector( '.wp-site-blocks header.wp-block-template-part' ) ||
			document.querySelector( 'header.wp-block-template-part' );
		var el;
		var i;
		if ( ! header ) {
			return null;
		}
		for ( el = header; el && el !== document.body; el = el.parentElement ) {
			if ( pinned( el ) ) {
				return el;
			}
		}
		for ( i = 0; i < header.children.length; i++ ) {
			if ( pinned( header.children[ i ] ) ) {
				return header.children[ i ];
			}
		}
		return null;
	}

	function keyboardFocus( el ) {
		try {
			return el.matches( ':focus-visible' );
		} catch ( e ) {
			return false; // a browser that does not know :focus-visible
		}
	}

	function start() {
		var bar = findBar();
		var burger = bar && bar.querySelector( '.wp-block-navigation__responsive-container-open' );
		var last = window.scrollY;
		var waiting = false;

		if ( ! burger ) {
			return;
		}
		bar.classList.add( BAR );

		function onPhone() {
			return 'none' !== window.getComputedStyle( burger ).display;
		}

		function menuOpen() {
			return !! bar.querySelector( '.wp-block-navigation__responsive-container.is-menu-open' );
		}

		function update() {
			var y = window.scrollY;
			waiting = false;
			if ( ! onPhone() || menuOpen() || y <= KEEP || y < last ) {
				bar.classList.remove( HIDDEN );
			} else if ( y > last ) {
				bar.classList.add( HIDDEN );
			}
			last = y;
		}

		window.addEventListener( 'scroll', function () {
			if ( ! waiting ) {
				waiting = true;
				window.requestAnimationFrame( update );
			}
		}, { passive: true } );
		window.addEventListener( 'resize', update );
		bar.addEventListener( 'focusin', function ( event ) {
			if ( keyboardFocus( event.target ) ) {
				bar.classList.remove( HIDDEN );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );

/**
 * js/rm-top-bar.js and css/rm-top-bar.css: the site's top bar on a phone, out of the way while the
 * visitor scrolls down (1.17.0).
 *
 *   npm run test:top-bar
 *
 * No WordPress and no DDEV: the page is built here as production's is - a sticky group around the
 * header template part (a site-editor setting), core's navigation with its burger, which core's
 * own stylesheet hides from 600 px on - and serves the plugin's two files as they are.
 *
 * What has to hold, as droneracingslovakia.com does it (measured there on 2026-09-13, 390 x 844):
 *   - the bar stays while the page is scrolled 120 px or less, goes on the first scroll down after
 *     that, and comes back on the first scroll up, sliding by its height and its shadow;
 *   - never while the burger's menu is open, whose overlay still fills the screen; back when the
 *     keyboard moves into it;
 *   - only while the burger shows - not on a wide screen - and only where the page pins the
 *     header: the group around it, or the header itself; a header that scrolls away is left alone;
 *   - no sliding for a visitor who asks for reduced motion.
 *
 * Exit codes follow the other suites: 0 passed, 1 failed, 2 skipped.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const PLUGIN_DIR = path.resolve( __dirname, '..', '..' );
const read = ( rel ) => fs.readFileSync( path.join( PLUGIN_DIR, rel ), 'utf8' );

const results = [];
function check( label, ok, detail ) {
	results.push( { label, ok } );
	process.stdout.write( `  ${ ok ? '\x1b[32mok\x1b[0m  ' : '\x1b[31mFAIL\x1b[0m' }  ${ label }\n` );
	if ( detail && ! ok ) {
		process.stdout.write( `          ${ String( detail ).slice( 0, 500 ) }\n` );
	}
}
function section( title ) {
	process.stdout.write( `\n${ title }\n` );
}
function skip( reason ) {
	process.stdout.write( `\x1b[33mSKIP\x1b[0m  ${ reason }\n` );
	process.exit( 2 );
}

let chromium;
try {
	( { chromium } = require( 'playwright' ) );
} catch ( e ) {
	skip( 'playwright is not installed -- run "npm ci" in the plugin directory' );
}

// pin: where the page pins its header - 'group' (production), 'header', or 'none'.
const page = ( pin ) => `<!doctype html>
<html><head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/css/rm-top-bar.css">
<style>
	body { margin: 0; font: 16px sans-serif; }
	.pinned { position: sticky; top: 0; z-index: 10; background: #fff; box-shadow: 0 2px 8px rgba( 0, 0, 0, 0.3 ); }
	header { height: 88px; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; }
	.wp-block-navigation__responsive-container-open { display: flex; width: 30px; height: 30px; }
	.wp-block-navigation__responsive-container { display: none; }
	.wp-block-navigation__responsive-container.is-menu-open { display: block; position: fixed; inset: 0; background: #fff; }
	@media ( min-width: 600px ) {
		.wp-block-navigation__responsive-container-open { display: none; }
		.wp-block-navigation__responsive-container { display: block; }
	}
	main { height: 4000px; }
</style>
</head><body>
<div class="wp-site-blocks">
	<div class="wp-block-group${ 'group' === pin ? ' pinned' : '' }">
		<header class="wp-block-template-part${ 'header' === pin ? ' pinned' : '' }">
			<a class="logo" href="/">Logo</a>
			<nav class="wp-block-navigation">
				<button class="wp-block-navigation__responsive-container-open" aria-label="Open menu">=</button>
				<div class="wp-block-navigation__responsive-container"><a href="/bracket/">Bracket</a></div>
			</nav>
		</header>
	</div>
	<main>The bracket, next up, the rest.</main>
</div>
<script src="/js/rm-top-bar.js" defer></script>
</body></html>`;

( async () => {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		skip( `no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${ e.message.split( '\n' )[ 0 ] }` );
	}

	async function open( pin, viewport = { width: 390, height: 844 }, options = {} ) {
		const context = await browser.newContext( { viewport, ...options } );
		const tab = await context.newPage();
		tab.__errors = [];
		tab.on( 'pageerror', ( e ) => tab.__errors.push( e.message ) );
		tab.on( 'console', ( m ) => {
			if ( 'error' === m.type() ) tab.__errors.push( m.text() );
		} );
		await tab.route( '**/*', ( route ) => {
			const url = route.request().url();
			if ( url.endsWith( '/js/rm-top-bar.js' ) ) return route.fulfill( { contentType: 'text/javascript', body: read( 'js/rm-top-bar.js' ) } );
			if ( url.endsWith( '/css/rm-top-bar.css' ) ) return route.fulfill( { contentType: 'text/css', body: read( 'css/rm-top-bar.css' ) } );
			return route.fulfill( { contentType: 'text/html', body: page( pin ) } );
		} );
		await tab.goto( 'http://rm.test/' );
		await tab.waitForLoadState( 'load' );
		tab.__context = context;
		return tab;
	}

	// Scroll to y and let the frame and the slide go by.
	async function scrollTo( tab, y ) {
		await tab.evaluate( ( to ) => window.scrollTo( 0, to ), y );
		await tab.waitForTimeout( 450 );
	}

	// Where the pinned element is on the screen: shown when it starts at the top, gone when it ends
	// above it.
	const bar = ( tab ) => tab.evaluate( () => {
		const el = document.querySelector( '.pinned' );
		const r = el.getBoundingClientRect();
		return { cls: el.className, top: Math.round( r.top ), bottom: Math.round( r.bottom ), transform: getComputedStyle( el ).transform, transition: getComputedStyle( el ).transition };
	} );
	const shown = ( b ) => 0 === b.top && 'none' === b.transform;
	const gone = ( b ) => b.bottom <= 0;

	section( 'On a phone, the header pinned by a group around it (production)' );
	{
		const tab = await open( 'group' );
		let b = await bar( tab );
		check( 'the group is the bar, shown at the start', b.cls.includes( 'rm-top-bar' ) && shown( b ), JSON.stringify( b ) );
		await scrollTo( tab, 120 );
		check( 'scrolled 120 px down: still there', shown( await bar( tab ) ), JSON.stringify( await bar( tab ) ) );
		await scrollTo( tab, 125 );
		b = await bar( tab );
		check( 'further down: gone, its shadow with it', gone( b ) && b.bottom < -20, JSON.stringify( b ) );
		await scrollTo( tab, 123 );
		check( 'two px up: back', shown( await bar( tab ) ), JSON.stringify( await bar( tab ) ) );
		await scrollTo( tab, 128 );
		check( 'five px down: gone again', gone( await bar( tab ) ), JSON.stringify( await bar( tab ) ) );
		await scrollTo( tab, 2000 );
		check( 'far down: still gone', gone( await bar( tab ) ), JSON.stringify( await bar( tab ) ) );
		b = await bar( tab );
		check( 'sliding in 0.3 s', /transform 0\.3s/.test( b.transition ), b.transition );

		// The menu open: the bar stays, and the overlay fills the screen, not the bar.
		await scrollTo( tab, 1500 );
		await tab.evaluate( () => document.querySelector( '.wp-block-navigation__responsive-container' ).classList.add( 'is-menu-open' ) );
		await scrollTo( tab, 1600 );
		const overlay = await tab.evaluate( () => {
			const r = document.querySelector( '.wp-block-navigation__responsive-container' ).getBoundingClientRect();
			return { top: Math.round( r.top ), height: Math.round( r.height ) };
		} );
		check( 'the menu open: the bar stays, the menu fills the screen', shown( await bar( tab ) ) && 0 === overlay.top && 844 === overlay.height,
			JSON.stringify( { bar: await bar( tab ), overlay } ) );
		await tab.evaluate( () => document.querySelector( '.wp-block-navigation__responsive-container' ).classList.remove( 'is-menu-open' ) );
		await scrollTo( tab, 1700 );
		check( 'the menu closed: gone on the next scroll down', gone( await bar( tab ) ), JSON.stringify( await bar( tab ) ) );

		await tab.keyboard.press( 'Tab' );
		await tab.waitForTimeout( 450 );
		check( 'the keyboard moves into it: back', shown( await bar( tab ) ), JSON.stringify( await bar( tab ) ) );
		await scrollTo( tab, 0 );
		check( 'at the top: there', shown( await bar( tab ) ), JSON.stringify( await bar( tab ) ) );
		check( 'no error', ! tab.__errors.length, tab.__errors.join( ' | ' ) );
		await tab.__context.close();
	}

	section( 'Where it does nothing' );
	{
		const wide = await open( 'group', { width: 1024, height: 768 } );
		await scrollTo( wide, 300 );
		await scrollTo( wide, 900 );
		check( 'a wide screen, no burger: the bar stays', shown( await bar( wide ) ), JSON.stringify( await bar( wide ) ) );
		await wide.__context.close();

		const header = await open( 'header' );
		await scrollTo( header, 300 );
		await scrollTo( header, 600 );
		const h = await bar( header );
		check( 'the header itself pinned: it is the bar, and goes', h.cls.includes( 'rm-top-bar' ) && gone( h ), JSON.stringify( h ) );
		await header.__context.close();

		const loose = await open( 'none' );
		await scrollTo( loose, 300 );
		await scrollTo( loose, 600 );
		const l = await loose.evaluate( () => ( {
			marked: document.querySelectorAll( '.rm-top-bar' ).length,
			transforms: [ 'header', '.wp-block-group' ].map( ( s ) => getComputedStyle( document.querySelector( s ) ).transform ),
		} ) );
		check( 'a header that scrolls away: left alone', 0 === l.marked && l.transforms.every( ( t ) => 'none' === t ), JSON.stringify( l ) );
		await loose.__context.close();

		const calm = await open( 'group', { width: 390, height: 844 }, { reducedMotion: 'reduce' } );
		const c = await bar( calm );
		check( 'reduced motion: no sliding', ! /transform 0\.3s/.test( c.transition ), c.transition );
		await scrollTo( calm, 300 );
		await scrollTo( calm, 600 );
		check( '  and still gone on the way down', gone( await bar( calm ) ), JSON.stringify( await bar( calm ) ) );
		await calm.__context.close();
	}

	await browser.close();
	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write( `\n${ '-'.repeat( 62 ) }\n  ${ results.length - failed } passed, ${ failed } failed\n` );
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	process.stderr.write( `\nHARNESS ERROR: ${ e && e.stack ? e.stack : e }\n` );
	process.exit( 1 );
} );

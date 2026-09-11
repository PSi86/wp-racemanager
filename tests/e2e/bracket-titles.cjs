/**
 * The bracket view scrolling sideways: the class titles stay, the races and their lines move.
 *
 *   npm run test:bracket-titles
 *
 * Each race class -- elimination, qualifying, training -- is its own horizontally scrolling
 * container, and on a phone the elimination bracket is far wider than the screen. Its titles
 * ("Elimination: Winner Bracket", "Elimination: Looser Bracket", "Qualifying", "Training") used to
 * scroll away with it, so a viewer deep in the right-hand stages no longer saw which bracket they
 * were looking at. Asked for on 2026-09-11: the titles stay in place on the screen, only the race
 * boxes scroll, together with the lines between them.
 *
 * What is checked, for every class container whose content is wider than the screen:
 *
 *   1. Scrolled all the way to the right, every title is where it was, to the pixel -- left and top.
 *   2. The races moved by exactly the distance scrolled, and the connecting lines by the same.
 *   3. The row a title sits in holds no race, so a title that stays never lies on one.
 *   4. The title is fully visible in its container, before and after.
 *
 * Needs a started DDEV site with a race that carries result data. Exit codes follow the PHP
 * suites: 0 passed, 1 failed, 2 skipped.
 */

const BASE = ( process.env.RM_E2E_URL || 'https://racemanager.ddev.site' ).replace( /\/$/, '' );

const results = [];
function check( label, ok, detail ) {
	results.push( { label, ok } );
	process.stdout.write( `  ${ ok ? '\x1b[32mok\x1b[0m  ' : '\x1b[31mFAIL\x1b[0m' }  ${ label }\n` );
	if ( detail && ! ok ) {
		process.stdout.write( `          ${ String( detail ).slice( 0, 400 ) }\n` );
	}
}
function note( text ) {
	process.stdout.write( `          ${ text }\n` );
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

// Every class container with its titles, its first race and its lines, as laid out on screen.
const measure = ( page ) =>
	page.evaluate( () =>
		[ ...document.querySelectorAll( '.raceclass-container' ) ].map( ( container ) => {
			const rect = ( el ) => {
				const r = el.getBoundingClientRect();
				return { left: r.left, right: r.right, top: r.top, bottom: r.bottom };
			};
			const nodes = [ ...container.querySelectorAll( '.node' ) ];
			const titles = [ ...container.querySelectorAll( '.class-title' ) ].map( ( t ) => {
				const box = rect( t );
				// A race in the title's row is one whose box reaches into the title's band.
				const sharing = nodes.filter( ( n ) => {
					const r = n.getBoundingClientRect();
					return r.top < box.bottom - 1 && r.bottom > box.top + 1;
				} ).length;
				return { text: t.textContent.trim(), ...box, sharing };
			} );
			const svg = container.querySelector( 'svg' );
			return {
				id: container.id,
				box: rect( container ),
				scrollLeft: container.scrollLeft,
				overflow: container.scrollWidth - container.clientWidth,
				titles,
				firstNode: nodes.length ? rect( nodes[ 0 ] ) : null,
				svg: svg ? rect( svg ) : null,
			};
		} ) );

( async () => {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		skip(
			`no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${
				e.message.split( '\n' )[ 0 ]
			}`
		);
	}

	const scout = await browser.newContext( { ignoreHTTPSErrors: true, serviceWorkers: 'block' } );
	const scoutPage = await scout.newPage();
	try {
		await scoutPage.goto( `${ BASE }/live/`, { waitUntil: 'networkidle', timeout: 20000 } );
	} catch ( e ) {
		await browser.close();
		skip( `${ BASE } is not reachable -- is the DDEV project started?` );
	}
	const path = await scoutPage.evaluate( () => {
		const link = document.querySelector( '.race-select-item a[href]' );
		return link ? new URL( link.href ).pathname : null;
	} );
	await scout.close();
	if ( ! path ) {
		await browser.close();
		skip( 'the selection page lists no race with result data' );
	}
	const bracketUrl = `${ BASE }${ path.replace( /[^/]+\/$/, '' ) }bracket/`;

	const ctx = await browser.newContext( {
		viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, ignoreHTTPSErrors: true, serviceWorkers: 'block',
	} );
	const page = await ctx.newPage();
	await page.goto( bracketUrl, { waitUntil: 'networkidle' } );
	await page.waitForSelector( '.raceclass-container .class-title', { timeout: 15000 } ).catch( () => {} );
	await page.waitForTimeout( 500 );

	const before = await measure( page );
	const wide = before.filter( ( c ) => c.titles.length && c.overflow > 20 );
	section( 'The bracket view on a phone, scrolled all the way to the right' );
	note( before.map( ( c ) => `${ c.id }: ${ c.titles.map( ( t ) => `"${ t.text }"` ).join( ', ' ) || 'no title' }, ${ c.overflow } px wider than the screen` ).join( '\n          ' ) );
	if ( ! wide.length ) {
		await browser.close();
		skip( 'no class on this race is wider than a phone screen, so nothing scrolls' );
	}

	// Scroll every wide container to its end, as a thumb would.
	await page.evaluate( () => {
		document.querySelectorAll( '.raceclass-container' ).forEach( ( c ) => { c.scrollLeft = c.scrollWidth; } );
	} );
	await page.waitForTimeout( 300 );
	const after = await measure( page );

	for ( const b of wide ) {
		const a = after.find( ( c ) => c.id === b.id );
		const scrolled = a.scrollLeft - b.scrollLeft;
		section( `${ b.id } (scrolled ${ Math.round( scrolled ) } px)` );
		b.titles.forEach( ( t, i ) => {
			const now = a.titles[ i ];
			check( `"${ t.text }" stays where it was`,
				Math.abs( now.left - t.left ) <= 1 && Math.abs( now.top - t.top ) <= 1,
				`left ${ Math.round( t.left ) } -> ${ Math.round( now.left ) }, top ${ Math.round( t.top ) } -> ${ Math.round( now.top ) }` );
			check( `"${ t.text }" is whole inside the container, before and after`,
				t.left >= b.box.left - 1 && t.right <= b.box.right + 1 && now.left >= a.box.left - 1 && now.right <= a.box.right + 1,
				`title ${ Math.round( now.left ) }..${ Math.round( now.right ) }, container ${ Math.round( a.box.left ) }..${ Math.round( a.box.right ) }` );
			check( `its row holds no race`, t.sharing === 0, `${ t.sharing } race(s) in the title's row` );
		} );
		check( 'the races moved by the distance scrolled',
			!! b.firstNode && Math.abs( ( b.firstNode.left - a.firstNode.left ) - scrolled ) <= 1,
			`first race ${ Math.round( b.firstNode && b.firstNode.left ) } -> ${ Math.round( a.firstNode && a.firstNode.left ) }, scrolled ${ scrolled }` );
		if ( b.svg ) {
			check( 'and the connecting lines with them',
				Math.abs( ( b.svg.left - a.svg.left ) - scrolled ) <= 1,
				`lines ${ Math.round( b.svg.left ) } -> ${ Math.round( a.svg.left ) }, scrolled ${ scrolled }` );
		} else {
			note( 'no connecting lines in this class' );
		}
	}

	await browser.close();

	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write(
		`\n--------------------------------------------------------------\n` +
		`  ${ results.length - failed } passed, ${ failed } failed\n`
	);
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	process.stderr.write( `\nHARNESS ERROR: ${ e && e.stack ? e.stack : e }\n` );
	process.exit( 1 );
} );

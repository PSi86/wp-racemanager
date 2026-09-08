/**
 * js/rm-m-pilotSelector.js, against the real module in a real DOM.
 *
 *   npm run test:pilot-selector
 *
 * This one needs no WordPress and no DDEV: the page is assembled here and the
 * dataLoader the module imports is served as a stub, so the subscriber callback
 * can be driven directly. Only a browser is required, because the behaviour
 * under test is what a <select> does with its options and selectedIndex -- which
 * is precisely what a hand-rolled DOM stub would get wrong.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const PLUGIN_DIR = path.resolve( __dirname, '..', '..' );
const MODULE_PATH = path.join( PLUGIN_DIR, 'js', 'rm-m-pilotSelector.js' );

const results = [];
function check( label, ok, detail ) {
	results.push( { label, ok } );
	process.stdout.write( `  ${ ok ? '\x1b[32mok\x1b[0m  ' : '\x1b[31mFAIL\x1b[0m' }  ${ label }\n` );
	if ( detail ) {
		process.stdout.write( `          ${ String( detail ).slice( 0, 300 ) }\n` );
	}
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

// The module reads dataLoaderInstance.storageKey and subscribes to it. The stub
// keeps the subscribers reachable so a data update can be simulated on demand.
const DATA_LOADER_STUB = `
	export const dataLoaderInstance = {
		storageKey: 'rmTestRace',
		subscribe( cb ) { window.__rmSubscribers.push( cb ); },
	};
`;

const fixture = ( withSelect ) => `<!doctype html>
<html><body>
${ withSelect ? '<select id="pilotSelector"><option value="0">-- Select a Pilot --</option></select>' : '<p>no selector here</p>' }
<script>
	window.__rmSubscribers = [];
	window.__rmChangeEvents = 0;
	window.__rmModuleError = null;
	window.RmJsConfig = { pilotSelector: { pilotSelectorId: 'pilotSelector' } };
	const el = document.getElementById( 'pilotSelector' );
	if ( el ) { el.addEventListener( 'change', () => { window.__rmChangeEvents++; } ); }
</script>
<script type="module">
	// A module-level throw has to be observable, not just a red console line.
	try {
		await import( '/js/rm-m-pilotSelector.js' );
		window.__rmModuleLoaded = true;
	} catch ( e ) {
		window.__rmModuleError = String( e && e.message ? e.message : e );
		window.__rmModuleLoaded = true;
	}
</script>
</body></html>`;

// Pilots arrive in the shape the module reads out of the race JSON.
const raceData = ( ids ) => ( {
	pilot_data: {
		pilots: ids.map( ( id ) => ( { pilot_id: id, callsign: `P${ id }` } ) ),
	},
} );

( async () => {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		skip( `no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${ e.message.split( '\n' )[ 0 ] }` );
	}

	const context = await browser.newContext();

	async function openPage( withSelect ) {
		const page = await context.newPage();
		await page.route( '**/*', ( route ) => {
			const url = route.request().url();
			if ( url.endsWith( '/js/rm-m-dataLoader.js' ) ) {
				return route.fulfill( { contentType: 'text/javascript', body: DATA_LOADER_STUB } );
			}
			if ( url.endsWith( '/js/rm-m-pilotSelector.js' ) ) {
				return route.fulfill( {
					contentType: 'text/javascript',
					body: fs.readFileSync( MODULE_PATH, 'utf8' ),
				} );
			}
			return route.fulfill( { contentType: 'text/html', body: fixture( withSelect ) } );
		} );
		await page.goto( 'http://rm.test/index.html' );
		await page.waitForFunction( () => window.__rmModuleLoaded === true, null, { timeout: 15000 } );
		return page;
	}

	// Push one data update through every subscriber, the way dataLoader does.
	const update = ( page, ids ) =>
		page.evaluate( ( data ) => {
			window.__rmSubscribers.forEach( ( cb ) => cb( data ) );
		}, raceData( ids ) );

	const state = ( page ) =>
		page.evaluate( () => {
			const el = document.getElementById( 'pilotSelector' );
			return {
				total: el.options.length,
				pilots: el.querySelectorAll( 'option[data-pilot-id]' ).length,
				placeholders: el.querySelectorAll( 'option:not([data-pilot-id])' ).length,
				value: el.value,
				selectedIndex: el.selectedIndex,
				changeEvents: window.__rmChangeEvents,
			};
		} );

	// ------------------------------------------------------------------ the bug
	let page = await openPage( true );

	await update( page, [ 3, 1, 2 ] );
	let s = await state( page );
	check( 'first update builds the list once', s.pilots === 3 && s.total === 4, JSON.stringify( s ) );

	for ( let i = 0; i < 20; i++ ) {
		await update( page, [ 3, 1, 2 ] );
	}
	s = await state( page );
	check(
		'21 updates leave the same 3 options, not 63',
		s.pilots === 3 && s.total === 4,
		`pilots=${ s.pilots }, total=${ s.total }`
	);
	check( 'the server-rendered placeholder survives the rebuild', s.placeholders === 1, `placeholders=${ s.placeholders }` );

	const sorted = await page.evaluate( () =>
		Array.from( document.querySelectorAll( '#pilotSelector option[data-pilot-id]' ) ).map( ( o ) => o.textContent )
	);
	check( 'the list stays sorted by callsign', sorted.join( ',' ) === 'P1,P2,P3', sorted.join( ',' ) );

	// ------------------------------------------------- the selection must hold
	await page.selectOption( '#pilotSelector', '2' );
	await update( page, [ 3, 1, 2 ] );
	s = await state( page );
	check( 'a selection survives the rebuild', s.value === '2' && s.selectedIndex > 0, JSON.stringify( s ) );

	// --------------------------------------------- and the other half of the fix
	const changesBefore = s.changeEvents;
	await update( page, [ 3, 1 ] ); // pilot 2 leaves the field
	s = await state( page );
	check(
		'a pilot leaving the field falls back to the placeholder, not to a blank control',
		s.selectedIndex === 0 && s.value === '0',
		`selectedIndex=${ s.selectedIndex }, value="${ s.value }"`
	);
	check(
		'and the fallback is announced, so the other modules stop filtering by them',
		s.changeEvents === changesBefore + 1,
		`change events: ${ changesBefore } -> ${ s.changeEvents }`
	);

	await page.close();

	// ------------------------------------------------------ the missing element
	page = await openPage( false );
	const err = await page.evaluate( () => window.__rmModuleError );
	check(
		'a page without the control does not take the module down',
		err === null,
		err ? `threw: ${ err }` : ''
	);
	await page.close();

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

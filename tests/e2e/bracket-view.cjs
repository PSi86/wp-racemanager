/**
 * js/rm-m-displayHeats.js, the bracket view, against the real module in a real DOM.
 *
 *   npm run test:bracket-view
 *
 * No WordPress and no DDEV: the page is assembled here, the dataLoader and the pilot selector the
 * module imports are served as stubs, and every race is built here from the shapes the upload
 * carries -- no real pilot names. The module also runs on the timer's /bracketview, where
 * RotorHazard's socket feeds it the same sections.
 *
 * What has to hold:
 *   - one class the view cannot draw leaves the others drawn, and a bracket class it has no
 *     template for is drawn as a row instead of throwing (an FAI 32 bracket in an event of 12
 *     pilots threw a TypeError at its 15th heat, and qualifying and training stayed empty);
 *   - a slot the timer fills from a class's result (method 2) is labelled with that class, not
 *     with the heat that happens to carry the same number;
 *   - a slot filled from a heat's result takes the entry at seed_rank - 1, as RotorHazard seeds;
 *   - a heat's results show whenever the heat has results, not only up to the current heat's id.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
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
		process.stdout.write( `          ${ String( detail ).slice( 0, 400 ) }\n` );
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

// The module reads dataLoaderInstance.storageKey and subscribes; the stub keeps the subscribers
// reachable so an upload can be simulated on demand, the way the loader hands one over.
const DATA_LOADER_STUB = `
	export const dataLoaderInstance = {
		storageKey: 'rmTestRace',
		subscribe( cb ) { window.__rmSubscribers.push( cb ); },
	};
`;
const PILOT_SELECTOR_STUB = `
	export const pilotSelectInstance = { pilotSelectorId: 'pilotSelector', selectedPilotId: 0 };
`;

const PAGE = `<!doctype html>
<html><body>
<select id="pilotSelector"><option value="0">-- Select a Pilot --</option></select>
<input type="checkbox" id="filterCheckbox">
<div id="elimination-display" class="raceclass-container"></div>
<div id="qualifying-display" class="raceclass-container"></div>
<div id="training-display" class="raceclass-container"></div>
<script>
	window.__rmSubscribers = [];
	window.__rmModuleError = null;
	window.RmJsConfig = { displayHeats: { filterCheckboxId: 'filterCheckbox' } };
</script>
<script src="/js/class_templates_V1.js"></script>
<script type="module">
	try {
		await import( '/js/rm-m-displayHeats.js' );
	} catch ( e ) {
		window.__rmModuleError = String( e && e.message ? e.message : e );
	}
	window.__rmModuleLoaded = true;
</script>
</body></html>`;

/* ------------------------------------------------------------------------------------------ *
 * Races, built in the upload's shapes
 * ------------------------------------------------------------------------------------------ */

const PRIMARY = 'by_race_time';

function entry( pilotId, position ) {
	return { pilot_id: pilotId, callsign: `P${ pilotId }`, position, laps: position ? 3 : 0, total_time: position ? `1:0${ position }.000` : '0:00.000' };
}

function heatResult( heatId, entries ) {
	const leaderboard = { [ PRIMARY ]: entries, meta: { primary_leaderboard: PRIMARY } };
	return { heat_id: heatId, displayname: `Heat ${ heatId }`, rounds: [ { id: 1, nodes: [], leaderboard } ], leaderboard };
}

// classes: [{id, name, heats: [{id, slots: [{pilot_id, method, seed_rank, seed_id}]}]}]
function race( { pilots, current, classes, heatResults = {}, classResults = {} } ) {
	const heats = [];
	for ( const cls of classes ) {
		for ( const h of cls.heats ) {
			heats.push( {
				id: h.id,
				displayname: h.name || `Heat ${ h.id }`,
				class_id: cls.id,
				slots: ( h.slots || [] ).map( ( s, i ) => ( {
					id: h.id * 10 + i,
					node_index: i,
					pilot_id: null,
					method: 0,
					seed_rank: null,
					seed_id: null,
					...s,
				} ) ),
			} );
		}
	}
	return {
		current_heat: { current_heat: current },
		pilot_data: { pilots: Array.from( { length: pilots }, ( _, i ) => ( { pilot_id: i + 1, callsign: `P${ i + 1 }` } ) ) },
		class_data: { classes: classes.map( ( c ) => ( { id: c.id, name: c.name, displayname: c.name, rounds: 1 } ) ) },
		heat_data: { heats },
		result_data: { heats: heatResults, classes: classResults },
	};
}

const training = { id: 1, name: 'Training', heats: [ { id: 1, slots: [ { pilot_id: 1 }, { pilot_id: 2 } ] }, { id: 2, slots: [ { pilot_id: 3 } ] } ] };
const qualifying = { id: 2, name: 'Qualifying', heats: [ { id: 3, slots: [ { pilot_id: 1 }, { pilot_id: 2 } ] }, { id: 4, slots: [ { pilot_id: 3 } ] } ] };

// An elimination class of `count` heats, ids from `firstId`, all slots empty.
function elimination( count, firstId = 5, extra = {} ) {
	return {
		id: 3,
		name: 'Elimination',
		heats: Array.from( { length: count }, ( _, i ) => ( { id: firstId + i, slots: [ {}, {}, {}, {} ], ...( extra[ firstId + i ] || {} ) } ) ),
	};
}

/* ------------------------------------------------------------------------------------------ */

( async () => {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		skip( `no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${ e.message.split( '\n' )[ 0 ] }` );
	}
	const context = await browser.newContext();

	async function openPage() {
		const page = await context.newPage();
		page.__errors = [];
		page.on( 'pageerror', ( e ) => page.__errors.push( e.message ) );
		await page.route( '**/*', ( route ) => {
			const url = route.request().url();
			const js = ( body ) => route.fulfill( { contentType: 'text/javascript', body } );
			if ( url.endsWith( '/js/rm-m-dataLoader.js' ) ) return js( DATA_LOADER_STUB );
			if ( url.endsWith( '/js/rm-m-pilotSelector.js' ) ) return js( PILOT_SELECTOR_STUB );
			if ( url.endsWith( '/js/rm-m-displayHeats.js' ) ) return js( read( 'js/rm-m-displayHeats.js' ) );
			if ( url.endsWith( '/js/class_templates_V1.js' ) ) return js( read( 'js/class_templates_V1.js' ) );
			return route.fulfill( { contentType: 'text/html', body: PAGE } );
		} );
		await page.goto( 'http://rm.test/index.html' );
		await page.waitForFunction( () => window.__rmModuleLoaded === true, null, { timeout: 15000 } );
		await page.waitForFunction( () => window.__rmSubscribers.length > 0, null, { timeout: 15000 } );
		return page;
	}

	// Hand one upload to every subscriber, as the loader does; a throw is reported, not fatal.
	async function deliver( page, data ) {
		return page.evaluate( ( d ) => {
			try {
				window.__rmSubscribers.forEach( ( cb ) => cb( d ) );
				return null;
			} catch ( e ) {
				return String( e && e.message ? e.message : e );
			}
		}, data );
	}

	const nodeCount = ( page, id ) => page.$$eval( `#${ id } .node`, ( els ) => els.length );
	const pilotNames = ( page, id ) => page.$$eval( `#${ id } .pilot-name`, ( els ) => els.map( ( el ) => el.textContent ) );
	const pilotResults = ( page, id ) => page.$$eval( `#${ id } .pilot-result`, ( els ) => els.map( ( el ) => el.textContent ) );

	{
		process.stdout.write( '\nAn FAI 32 bracket in an event of 12 pilots\n' );
		const page = await openPage();
		const thrown = await deliver( page, race( { pilots: 12, current: 5, classes: [ training, qualifying, elimination( 30 ) ] } ) );
		check( 'handing the data over does not throw', thrown === null, thrown );
		check( 'no page error', page.__errors.length === 0, page.__errors.join( ' | ' ) );
		check( 'all 30 elimination heats are drawn', ( await nodeCount( page, 'elimination-display' ) ) === 30, `${ await nodeCount( page, 'elimination-display' ) } nodes` );
		check( 'qualifying is drawn', ( await nodeCount( page, 'qualifying-display' ) ) === 2 );
		check( 'training is drawn', ( await nodeCount( page, 'training-display' ) ) === 2 );
		await page.close();
	}

	{
		process.stdout.write( '\nA class whose data the view cannot read\n' );
		// The first class on the page carries a heat without slots, which the view cannot draw.
		const data = race( { pilots: 12, current: 5, classes: [ training, qualifying, elimination( 2 ) ] } );
		data.heat_data.heats.find( ( h ) => h.id === 5 ).slots = null;
		const page = await openPage();
		const thrown = await deliver( page, data );
		check( 'handing the data over does not throw', thrown === null, thrown );
		check( 'qualifying is drawn', ( await nodeCount( page, 'qualifying-display' ) ) === 2 );
		check( 'training is drawn', ( await nodeCount( page, 'training-display' ) ) === 2 );
		await page.close();
	}

	{
		process.stdout.write( '\nA slot filled from a class result\n' );
		// Elimination heat 5's first slot takes Qualifying's (class 2) third; heat 2 is a Training heat.
		const data = race( {
			pilots: 12,
			current: 5,
			classes: [ training, qualifying, elimination( 1, 5, { 5: { slots: [ { method: 2, seed_id: 2, seed_rank: 3 } ] } } ) ],
			heatResults: { 2: heatResult( 2, [ entry( 3, 1 ) ] ) },
		} );
		const page = await openPage();
		await deliver( page, data );
		const names = await pilotNames( page, 'elimination-display' );
		check( 'labelled with the class', names.includes( 'Qualifying #3' ), JSON.stringify( names ) );
		await page.close();
	}

	{
		process.stdout.write( '\nA slot filled from a heat result\n' );
		// Heat 5 was flown: P1 and P2 placed, P3 and P4 did not start. Heat 6's slot takes the
		// third entry -- P3 -- as RotorHazard does, before the timer has filled the slot.
		const data = race( {
			pilots: 12,
			current: 6,
			classes: [ elimination( 2, 5, { 6: { slots: [ { method: 1, seed_id: 5, seed_rank: 3 } ] } } ) ],
			heatResults: { 5: heatResult( 5, [ entry( 1, 1 ), entry( 2, 2 ), entry( 3, null ), entry( 4, null ) ] ) },
		} );
		const page = await openPage();
		await deliver( page, data );
		const names = await pilotNames( page, 'elimination-display' );
		check( 'the third entry, P3', names.includes( 'P3' ), JSON.stringify( names ) );
		await page.close();
	}

	{
		process.stdout.write( '\nResults of a heat after the current one\n' );
		// The timer went back to heat 5 after heat 6 was flown (or the race is archived with the
		// current heat anywhere): heat 6's result is shown all the same.
		const data = race( {
			pilots: 12,
			current: 5,
			classes: [ elimination( 2, 5, { 6: { slots: [ { pilot_id: 7 }, { pilot_id: 8 } ] } } ) ],
			heatResults: { 6: heatResult( 6, [ entry( 7, 1 ), entry( 8, 2 ) ] ) },
		} );
		const page = await openPage();
		await deliver( page, data );
		const res = ( await pilotResults( page, 'elimination-display' ) ).filter( ( r ) => r.includes( '#' ) );
		check( "heat 6's two results are shown", res.length === 2, JSON.stringify( res ) );
		await page.close();
	}

	await browser.close();

	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write( `\n${ '-'.repeat( 62 ) }\n  ${ results.length - failed } passed, ${ failed } failed\n` );
	process.exit( failed ? 1 : 0 );
} )();

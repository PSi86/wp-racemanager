/**
 * js/rm-m-displayHeats.js, the bracket view, against the real module in a real DOM.
 *
 *   npm run test:bracket-view
 *
 * No WordPress and no DDEV: the page is assembled here, the dataLoader and the pilot selector the
 * module imports are served as stubs, rm-m-bracketModel.js as it is, and every race is built here
 * from the shapes the upload carries - RotorHazard's own heat plans among them
 * (tests/fixtures/brackets) - with no real pilot names. The module also runs on the timer's
 * /bracketview, where RotorHazard's socket feeds it the same sections.
 *
 * What has to hold:
 *   - every class of the event gets its own section, the newest on top, whatever its name - and
 *     the standings under them likewise; the heats no class claims get one last, and an event
 *     without classes shows its heats there;
 *   - a class whose heats form a bracket is drawn as that bracket: winners and losers bracket
 *     with their titles, a column per round under its name, a line per link; a single
 *     elimination in one section with its small final; the others as a row;
 *   - a Chase the Ace final shows the rule, the rounds flown and each pilot's wins, the winner
 *     marked;
 *   - the pilot filter keeps the pilot's heats and the heats they feed;
 *   - nothing throws: not on {} before the timer has sent everything, not without results, not for
 *     a class the view cannot draw (an FAI 32 bracket in an event of 12 pilots threw at its 15th
 *     heat in 1.9.0, and every class after it stayed empty);
 *   - seeds are labelled and resolved as RotorHazard seeds: a class seed names the class, a heat
 *     seed takes the entry at seed_rank - 1; a heat's results show whenever it has any;
 *   - a page with the old fixed containers ({name}-display) still works.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const races = require( '../fixtures/brackets/races.cjs' );

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

const page = ( containers, config = { displayHeats: { filterCheckboxId: 'filterCheckbox' } } ) => `<!doctype html>
<html><body>
<select id="pilotSelector"><option value="0">-- Select a Pilot --</option></select>
<input type="checkbox" id="filterCheckbox">
${ containers }
<script>
	window.__rmSubscribers = [];
	window.__rmModuleError = null;
	window.RmJsConfig = ${ JSON.stringify( config ) };
</script>
<script type="module">
	try {
		await import( '/js/rm-m-displayHeats.js' );
		if ( document.getElementById( 'standings-display' ) ) {
			await import( '/js/rm-m-displayStandings.js' );
		}
	} catch ( e ) {
		window.__rmModuleError = String( e && e.message ? e.message : e );
	}
	window.__rmModuleLoaded = true;
</script>
</body></html>`;
const SECTIONS_PAGE = page( '<div id="raceclass-sections"></div><div id="standings-display"></div>' );
const FIXED_PAGE = page( `
<div id="elimination-display" class="raceclass-container"></div>
<div id="qualifying-display" class="raceclass-container"></div>
<div id="training-display" class="raceclass-container"></div>` );
// As WP RaceManager's bracket page configures it since 1.11.0: where the flags are.
const FLAGS = 'http://rm.test/assets/flag-icons-7.5.0/flags/4x3/';
const FLAGS_PAGE = page( '<div id="raceclass-sections"></div><div id="standings-display"></div>', {
	displayHeats: { filterCheckboxId: 'filterCheckbox', flagBaseUrl: FLAGS },
	displayStandings: { containerId: 'standings-display', flagBaseUrl: FLAGS },
} );
// A photo that loads: a PNG of one pixel.
const PHOTO_PNG = Buffer.from( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64' );

/* ------------------------------------------------------------------------------------------ *
 * Hand-built races, in the upload's shapes
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
		class_data: { classes: classes.map( ( c ) => ( { id: c.id, name: c.name, displayname: c.name, rounds: 1, order: c.order ?? null } ) ) },
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

// A plan's race with a training class in front of its Qualifying and bracket classes.
function planRace( key, options ) {
	const data = races.raceFromPlan( key, options );
	data.class_data.classes.unshift( { id: 1, name: 'Training', displayname: 'Training', rounds: 1, order: 0 } );
	data.heat_data.heats.unshift( { id: 1, displayname: 'A Training', class_id: 1, slots: [ { id: 11, node_index: 0, pilot_id: 1, method: 0, seed_rank: null, seed_id: null } ] } );
	return data;
}

/* ------------------------------------------------------------------------------------------ */

( async () => {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		skip( `no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${ e.message.split( '\n' )[ 0 ] }` );
	}
	const context = await browser.newContext( { viewport: { width: 1600, height: 1200 } } );

	async function openPage( html = SECTIONS_PAGE ) {
		const tab = await context.newPage();
		tab.__errors = [];
		tab.on( 'pageerror', ( e ) => tab.__errors.push( e.message ) );
		tab.on( 'console', ( msg ) => {
			if ( msg.type() === 'error' ) tab.__errors.push( msg.text() );
		} );
		await tab.route( '**/*', ( route ) => {
			const url = route.request().url();
			const js = ( body ) => route.fulfill( { contentType: 'text/javascript', body } );
			if ( url.endsWith( '/js/rm-m-dataLoader.js' ) ) return js( DATA_LOADER_STUB );
			if ( url.endsWith( '/js/rm-m-pilotSelector.js' ) ) return js( PILOT_SELECTOR_STUB );
			const file = url.match( /\/js\/([\w.-]+\.js)$/ );
			if ( file ) return js( read( `js/${ file[ 1 ] }` ) );
			const flag = url.match( /\/assets\/flag-icons-7\.5\.0\/flags\/4x3\/([a-z]{2})\.svg$/ );
			if ( flag ) return route.fulfill( { contentType: 'image/svg+xml', body: read( `assets/flag-icons-7.5.0/flags/4x3/${ flag[ 1 ] }.svg` ) } );
			if ( /\/photos\/ok\.png/.test( url ) ) return route.fulfill( { contentType: 'image/png', body: PHOTO_PNG } );
			if ( /\/photos\//.test( url ) ) return route.fulfill( { status: 404, body: '' } );
			return route.fulfill( { contentType: 'text/html', body: html } );
		} );
		await tab.goto( 'http://rm.test/index.html' );
		await tab.waitForFunction( () => window.__rmModuleLoaded === true, null, { timeout: 15000 } );
		await tab.waitForFunction( () => window.__rmSubscribers.length > 0, null, { timeout: 15000 } );
		return tab;
	}

	// Hand one upload to every subscriber, as the loader does; a throw is reported, not fatal.
	async function deliver( tab, data ) {
		return tab.evaluate( ( d ) => {
			try {
				window.__rmSubscribers.forEach( ( cb ) => cb( d ) );
				return null;
			} catch ( e ) {
				return String( e && e.message ? e.message : e );
			}
		}, data );
	}

	const containers = ( tab ) => tab.$$eval( '.raceclass-container', ( els ) => els.map( ( el ) => el.id ) );
	const nodeCount = ( tab, id ) => tab.$$eval( `#${ id } .node`, ( els ) => els.length );
	const texts = ( tab, selector ) => tab.$$eval( selector, ( els ) => els.map( ( el ) => el.textContent ) );
	const pilotNames = ( tab, id ) => texts( tab, `#${ id } .pilot-name` );
	const pilotResults = ( tab, id ) => texts( tab, `#${ id } .pilot-result` );

	section( 'Every class in its own section' );
	{
		const tab = await openPage();
		const data = planRace( 'double-fai16' );
		const thrown = await deliver( tab, data );
		check( 'no throw, no error', thrown === null && ! tab.__errors.length, thrown || tab.__errors.join( ' | ' ) );
		// Qualifying has no heats in this race, so it gets no section. The newest class on top, as the
		// fixed Elimination - Qualifying - Training containers had it before 1.10.0 (1.12.1).
		check( 'a section per class with heats, the newest first',
			JSON.stringify( await containers( tab ) ) === JSON.stringify( [ 'class-3-display', 'class-1-display' ] ),
			JSON.stringify( await containers( tab ) ) );
		check( 'the bracket drawn: 14 races', ( await nodeCount( tab, 'class-3-display' ) ) === 14 );
		check( 'titles for both brackets',
			JSON.stringify( await texts( tab, '#class-3-display .class-title' ) ) === JSON.stringify( [ 'Elimination: Winners Bracket', 'Elimination: Losers Bracket' ] ),
			JSON.stringify( await texts( tab, '#class-3-display .class-title' ) ) );
		check( 'a header per round',
			( await texts( tab, '#class-3-display .round-title' ) ).join( ', ' ) === 'Quarterfinals, Semifinals, Winners Final, Grand Final, LB Round 1, LB Round 2, LB Round 3, LB Round 4',
			( await texts( tab, '#class-3-display .round-title' ) ).join( ', ' ) );
		const paths = await tab.$$eval( '#class-3-display svg path', ( els ) => els.map( ( el ) => el.getAttribute( 'd' ) ) );
		// Lines along each bracket and into the grand final: 6 in the winners bracket, 7 in the losers
		// bracket (its first round is fed from the winners side only), 2 into the grand final.
		check( 'a line per link, in the shape the titles suite measures', paths.length === 15 && paths.every( ( d ) => /^M[\d.]+,[\d.]+ H[\d.]+ V[\d.]+ H[\d.]+$/.test( d ) ), `${ paths.length } paths` );
		check( 'the grand final is marked', ( await tab.$$eval( '#class-3-display .node.final', ( els ) => els.length ) ) === 1 );
		check( 'training as a row', ( await tab.$$eval( '#class-1-display .node.singlebracket', ( els ) => els.length ) ) === 1 );
		// A class renamed on the timer is still drawn as the bracket it is.
		data.class_data.classes[ 2 ].displayname = 'Eliminations';
		await deliver( tab, data );
		check( 'whatever the class is called', ( await texts( tab, '#class-3-display .class-title' ) )[ 0 ] === 'Eliminations: Winners Bracket' );
		await tab.close();
	}

	section( 'Heats without a class' );
	{
		const tab = await openPage();
		const heat = ( id, classId, pilotIds ) => ( {
			id,
			displayname: `Heat ${ id }`,
			class_id: classId,
			slots: pilotIds.map( ( pilotId, i ) => ( { id: id * 10 + i, node_index: i, pilot_id: pilotId, method: 0, seed_rank: null, seed_id: null } ) ),
		} );
		// A practice evening on the timer: heats and pilots, no class at all. 1.12.1 showed nothing.
		// RotorHazard 4.4.0 sends such a heat's class as null (the club's timer does), 4.3 as 0.
		const heatsOnly = race( { pilots: 4, current: 1, classes: [] } );
		heatsOnly.heat_data.heats.push( heat( 1, null, [ 1, 2 ] ), heat( 2, 0, [ 3, 4 ] ) );
		const thrown = await deliver( tab, heatsOnly );
		check( 'an event without classes: its heats in a section of their own',
			thrown === null && ( await containers( tab ) ).join() === 'class-0-display' && ( await nodeCount( tab, 'class-0-display' ) ) === 2,
			thrown || `${ ( await containers( tab ) ).join() } / ${ await nodeCount( tab, 'class-0-display' ) } nodes` );
		check( 'under RotorHazard\'s name for them', ( await texts( tab, '#class-0-display .class-title' ) ).join() === 'Unclassified Heats',
			( await texts( tab, '#class-0-display .class-title' ) ).join() );
		check( 'with their pilots', ( await pilotNames( tab, 'class-0-display' ) ).join() === 'P1,P2,P3,P4', ( await pilotNames( tab, 'class-0-display' ) ).join() );
		// Beside classes: those of class null or 0 and those of a class the timer no longer has, last.
		const mixed = race( { pilots: 12, current: 5, classes: [ training, elimination( 2 ) ] } );
		mixed.heat_data.heats.push( heat( 22, null, [ 3 ] ), heat( 21, 0, [ 2 ] ), heat( 20, 9, [ 1 ] ) );
		await deliver( tab, mixed );
		check( 'beside the classes: last, class null, 0 and a class that is gone alike, in the timer\'s order',
			( await containers( tab ) ).join() === 'class-3-display,class-1-display,class-0-display' &&
			( await texts( tab, '#class-0-display .node .title' ) ).join() === 'Heat 20,Heat 21,Heat 22',
			`${ ( await containers( tab ) ).join() } / ${ ( await texts( tab, '#class-0-display .node .title' ) ).join() }` );
		// Given a class on the timer, they move there, and the section goes.
		mixed.heat_data.heats.filter( ( h ) => h.id >= 20 ).forEach( ( h ) => {
			h.class_id = 1;
		} );
		await deliver( tab, mixed );
		check( 'given a class, they move there and the section goes',
			( await containers( tab ) ).join() === 'class-3-display,class-1-display' && ( await nodeCount( tab, 'class-1-display' ) ) === 5,
			`${ ( await containers( tab ) ).join() } / ${ await nodeCount( tab, 'class-1-display' ) } nodes` );
		check( 'no error', ! tab.__errors.length, tab.__errors.join( ' | ' ) );
		await tab.close();
	}

	section( 'Single elimination' );
	{
		const tab = await openPage();
		await deliver( tab, planRace( 'single-fai16' ) );
		check( 'one section under the class name', JSON.stringify( await texts( tab, '#class-3-display .class-title' ) ) === JSON.stringify( [ 'Elimination' ] ),
			JSON.stringify( await texts( tab, '#class-3-display .class-title' ) ) );
		check( 'Quarterfinals, Semifinals, Final', ( await texts( tab, '#class-3-display .round-title' ) ).join( ', ' ) === 'Quarterfinals, Semifinals, Final',
			( await texts( tab, '#class-3-display .round-title' ) ).join( ', ' ) );
		check( 'the small final marked, below the final', await tab.evaluate( () => {
			const final = document.querySelector( '#class-3-display .node.final' );
			const small = document.querySelector( '#class-3-display .node.small-final' );
			return final && small && final.style.gridColumn === small.style.gridColumn && Number( small.style.gridRowStart || small.style.gridRow ) > Number( final.style.gridRowStart || final.style.gridRow );
		} ) );
		await tab.close();
	}

	section( 'Chase the Ace' );
	{
		const tab = await openPage();
		const data = races.fly( planRace( 'double-fai16' ) );
		races.chaseTheAce( data, 23, [ [ 1, 2, 3, 4 ], [ 2, 1, 3, 4 ], [ 1, 3, 2, 4 ] ] );
		await deliver( tab, data );
		const note = await tab.$eval( '#class-3-display .node.final .node-note', ( el ) => el.textContent );
		check( 'the final names the rule, the rounds flown and that it is decided', /Chase the Ace/.test( note ) && /1st to 2 wins/.test( note ) && /3 rounds/.test( note ) && /decided/.test( note ), note );
		const wins = await tab.$$eval( '#class-3-display .node.final .pilot-entry', ( els ) => els.map( ( el ) => `${ el.querySelector( '.pilot-name' ).textContent }=${ el.querySelector( '.pilot-result' ).textContent }${ el.classList.contains( 'cta-winner' ) ? '*' : '' }` ) );
		check( "each pilot's wins, the winner marked", [ ...wins ].sort().join( ' ' ) === 'P1=2/2* P2=1/2 P3=0/2 P4=0/2', wins.join( ' ' ) );
		await tab.close();
	}

	section( 'The standing under the brackets (js/rm-m-displayStandings.js)' );
	{
		const tab = await openPage();
		const rows = () => tab.$$eval( '#standings-display tbody tr', ( trs ) => trs.map( ( tr ) => [ ...tr.cells ].map( ( td ) => td.textContent ).join( ' | ' ) ) );
		await deliver( tab, races.fly( planRace( 'double-fai32' ), { untilHeatId: 27 } ) );
		let shown = await rows();
		check( 'while LB Round 2 runs: places 25 to 32 and the shared range of those out so far',
			shown.filter( ( r ) => r.startsWith( '17–24' ) ).length === 4 && shown.filter( ( r ) => / \| LB Round 1$/.test( r ) ).length === 8,
			shown.join( ' / ' ) );
		check( 'under the class name', ( await texts( tab, '#standings-display h2' ) ).join() === 'Elimination: Standing' );
		check( 'no result column when nothing stands in it', ( await texts( tab, '#standings-display th' ) ).join( ',' ) === 'Place,Pilot,Out in',
			( await texts( tab, '#standings-display th' ) ).join( ',' ) );
		const data = races.fly( planRace( 'double-fai16' ) );
		races.chaseTheAce( data, 23, [ [ 1, 2, 3, 4 ], [ 2, 1, 3, 4 ] ] );
		await deliver( tab, data );
		shown = await rows();
		check( 'Chase the Ace undecided: the final four without places, with their wins', shown.filter( ( r ) => /^– \| P\d+ \| Grand Final \| [01]\/2 wins$/.test( r ) ).length === 4, shown.join( ' / ' ) );
		races.chaseTheAce( data, 23, [ [ 1, 2, 3, 4 ], [ 2, 1, 3, 4 ], [ 1, 2, 3, 4 ] ] );
		await deliver( tab, data );
		check( 'decided: the winner first, gold', ( await rows() )[ 0 ] === '1 | P1 | Grand Final | 2/2 wins' &&
			( await tab.$eval( '#standings-display tbody tr', ( tr ) => tr.classList.contains( 'rm-place-first' ) ) ), ( await rows() )[ 0 ] );
		// A second bracket, "Pro", flown after the Elimination: its section and its standing come first.
		const two = races.fly( planRace( 'double-fai16' ) );
		const pro = races.fly( races.raceFromPlan( 'single-fai16', { firstHeatId: 60 } ) );
		pro.heat_data.heats.forEach( ( h ) => {
			two.heat_data.heats.push( { ...h, class_id: 4 } );
		} );
		Object.assign( two.result_data.heats, pro.result_data.heats );
		two.class_data.classes.push( { id: 4, name: 'Pro', displayname: 'Pro', win_condition: '', ranksettings: null, rounds: 1, order: 3, generate_args: null } );
		await deliver( tab, two );
		check( 'two brackets: sections and standings, the newest first',
			( await containers( tab ) ).join() === 'class-4-display,class-3-display,class-1-display' &&
			( await texts( tab, '#standings-display h2' ) ).join() === 'Pro: Standing,Elimination: Standing',
			`${ ( await containers( tab ) ).join() } / ${ ( await texts( tab, '#standings-display h2' ) ).join() }` );
		// Without an order on every class, by id: the highest first.
		two.class_data.classes.forEach( ( c ) => {
			c.order = null;
		} );
		await deliver( tab, two );
		check( 'without an order on every class, the highest id first', ( await containers( tab ) ).join() === 'class-4-display,class-3-display,class-1-display',
			( await containers( tab ) ).join() );
		await deliver( tab, planRace( 'ranked-fill-12' ) );
		check( 'no bracket, no standing', await tab.$eval( '#standings-display', ( el ) => el.style.display === 'none' && ! el.children.length ) );
		check( 'no error', ! tab.__errors.length, tab.__errors.join( ' | ' ) );
		await tab.close();
	}

	section( 'The pilot filter' );
	{
		const tab = await openPage();
		await deliver( tab, races.fly( planRace( 'double-fai16' ) ) );
		await tab.evaluate( () => {
			const select = document.getElementById( 'pilotSelector' );
			select.innerHTML += '<option value="16">P16</option>';
			select.value = '16';
			select.dispatchEvent( new Event( 'change' ) );
			const box = document.getElementById( 'filterCheckbox' );
			box.checked = true;
			box.dispatchEvent( new Event( 'change' ) );
		} );
		const shown = await tab.$$eval( '#class-3-display .node .title', ( els ) => els.map( ( el ) => el.textContent ) );
		// P16 flies Race 1 (out 4th), Race 5 (LB, out 4th): those two and what they feed.
		check( "the pilot's heats and the heats they feed", shown.includes( 'Race 1' ) && shown.includes( 'Race 5' ) && ! shown.includes( 'Race 2' ) && shown.length < 14, shown.join( ', ' ) );
		check( 'no error', ! tab.__errors.length, tab.__errors.join( ' | ' ) );
		await tab.close();
	}

	section( 'Flags and photos' );
	{
		// Pilot keys as the connector sends them, P1's in upper case; profiles as WP RaceManager's
		// race files carry them (1.11.0): P1 flag and photo, P2 a flag, P3 a photo that is gone.
		const key = ( n ) => `aaaaaaaa-0000-5000-8000-${ String( n ).padStart( 12, '0' ) }`;
		const withProfiles = ( profiles ) => {
			const data = races.fly( planRace( 'double-fai16' ) );
			data.pilot_data.pilots.forEach( ( p ) => {
				p.pilot_key = p.pilot_id === 1 ? key( 1 ).toUpperCase() : key( p.pilot_id );
			} );
			data.pilot_profiles = profiles;
			return data;
		};
		const profiles = {
			[ key( 1 ) ]: { country: 'AT', photo: 'http://rm.test/photos/ok.png?v=0a1b2c3d' },
			[ key( 2 ) ]: { country: 'DE' },
			[ key( 3 ) ]: { photo: 'http://rm.test/photos/gone.jpg?v=1a2b3c4d' },
			[ key( 4 ) ]: { country: 'at<script>', photo: 'javascript:alert(1)' },
		};

		let tab = await openPage();
		await deliver( tab, withProfiles( profiles ) );
		check( 'no flag where the page does not say where the flags are - the timer\'s case', ( await tab.$$( '.pilot-flag' ) ).length === 0 );
		await tab.close();

		tab = await openPage( FLAGS_PAGE );
		await deliver( tab, withProfiles( profiles ) );
		const flagsOf = ( selector ) => tab.$$eval( selector, ( els ) => els.map( ( el ) => {
			const img = el.querySelector( 'img.pilot-flag' );
			return { text: el.textContent, src: img ? img.getAttribute( 'src' ) : null, alt: img ? img.alt : null };
		} ) );
		const names = await flagsOf( '#class-3-display .pilotid-1 .pilot-name' );
		check( 'the flag by the callsign in every heat of the pilot, found by key whatever its case',
			names.length > 0 && names.every( ( n ) => n.src === `${ FLAGS }at.svg` && n.alt === 'AT' && n.text === 'P1' ), JSON.stringify( names ) );
		check( 'no flag for a pilot without a country', ( await flagsOf( '#class-3-display .pilotid-3 .pilot-name' ) ).every( ( n ) => n.src === null ) );
		check( 'nor for one that is no code', ( await flagsOf( '#class-3-display .pilotid-4 .pilot-name' ) ).every( ( n ) => n.src === null ) );
		await tab.waitForTimeout( 300 ); // the photos load, or fail to
		const cells = await tab.$$eval( '#standings-display tbody .pilot', ( tds ) => Object.fromEntries( tds.map( ( td ) => {
			const avatar = td.querySelector( '.rm-avatar' );
			const photo = avatar && avatar.querySelector( 'img' );
			const flag = td.querySelector( 'img.pilot-flag' );
			return [ td.textContent.replace( avatar ? avatar.textContent : '', '' ), {
				initials: avatar ? avatar.textContent : null,
				photo: photo ? photo.getAttribute( 'src' ) : null,
				loaded: photo ? photo.complete && photo.naturalWidth > 0 : null,
				flag: flag ? flag.getAttribute( 'src' ) : null,
			} ];
		} ) ) );
		check( 'the standing: photo and flag', cells.P1 && cells.P1.photo === 'http://rm.test/photos/ok.png?v=0a1b2c3d' && cells.P1.loaded && cells.P1.flag === `${ FLAGS }at.svg`, JSON.stringify( cells.P1 ) );
		check( 'a pilot without a photo: the initials', cells.P2 && cells.P2.photo === null && cells.P2.initials === 'P2' && cells.P2.flag === `${ FLAGS }de.svg`, JSON.stringify( cells.P2 ) );
		check( 'a photo that fails to load: the initials too', cells.P3 && cells.P3.photo === null && cells.P3.initials === 'P3', JSON.stringify( cells.P3 ) );
		check( 'no photo from an address that is no web address', cells.P4 && cells.P4.photo === null && cells.P4.flag === null, JSON.stringify( cells.P4 ) );
		check( 'the flag of every pilot with a country loads', await tab.$$eval( 'img.pilot-flag', ( imgs ) => imgs.length > 0 && imgs.every( ( i ) => i.complete && i.naturalWidth > 0 ) ) );

		await deliver( tab, withProfiles( [] ) );
		check( 'none at all - PHP writes that as [] - no flag, no photo, no initials',
			( await tab.$$( '.pilot-flag' ) ).length === 0 && ( await tab.$$( '.rm-avatar' ) ).length === 0 );
		// Chromium logs the photo that is gone, which this section answers with 404 on purpose.
		const errors = tab.__errors.filter( ( e ) => ! /^Failed to load resource: .*404/.test( e ) );
		check( 'no error', ! errors.length, errors.join( ' | ' ) );
		await tab.close();
	}

	section( 'Nothing throws' );
	{
		const tab = await openPage();
		let thrown = await deliver( tab, {} );
		check( 'on {} before the timer has sent everything', thrown === null && ! tab.__errors.length, thrown || tab.__errors.join( ' | ' ) );
		const data = planRace( 'double-fai32' );
		delete data.result_data;
		thrown = await deliver( tab, data );
		check( 'without results', thrown === null && ( await nodeCount( tab, 'class-3-display' ) ) === 30, thrown || `${ await nodeCount( tab, 'class-3-display' ) } nodes` );
		thrown = await deliver( tab, races.fly( planRace( 'double-fai32', { pilots: 12 } ) ) );
		check( 'an FAI 32 bracket flown by 12 pilots', thrown === null && ! tab.__errors.length && ( await nodeCount( tab, 'class-3-display' ) ) === 30, thrown || tab.__errors.join( ' | ' ) );
		await tab.close();
	}

	section( 'The old fixed containers' );
	{
		const tab = await openPage( FIXED_PAGE );
		const thrown = await deliver( tab, race( { pilots: 12, current: 5, classes: [ training, qualifying, elimination( 30 ) ] } ) );
		check( 'handing the data over does not throw', thrown === null, thrown );
		check( 'all 30 elimination heats are drawn', ( await nodeCount( tab, 'elimination-display' ) ) === 30, `${ await nodeCount( tab, 'elimination-display' ) } nodes` );
		check( 'qualifying is drawn', ( await nodeCount( tab, 'qualifying-display' ) ) === 2 );
		check( 'training is drawn', ( await nodeCount( tab, 'training-display' ) ) === 2 );
		const plan = planRace( 'double-fai16' );
		plan.class_data.classes[ 2 ].displayname = 'Elimination';
		await deliver( tab, plan );
		check( 'a bracket in the old elimination container', ( await texts( tab, '#elimination-display .class-title' ) )[ 0 ] === 'Elimination: Winners Bracket' );
		await tab.close();
	}

	section( 'A class whose data the view cannot read' );
	{
		// The first class on the page carries a heat without slots, which the view cannot draw.
		const data = race( { pilots: 12, current: 5, classes: [ training, qualifying, elimination( 2 ) ] } );
		data.heat_data.heats.find( ( h ) => h.id === 5 ).slots = null;
		const tab = await openPage( FIXED_PAGE );
		const thrown = await deliver( tab, data );
		check( 'handing the data over does not throw', thrown === null, thrown );
		check( 'qualifying is drawn', ( await nodeCount( tab, 'qualifying-display' ) ) === 2 );
		check( 'training is drawn', ( await nodeCount( tab, 'training-display' ) ) === 2 );
		await tab.close();
	}

	section( 'Seeds' );
	{
		// Elimination heat 5's first slot takes Qualifying's (class 2) third; heat 2 is a Training heat.
		const data = race( {
			pilots: 12,
			current: 5,
			classes: [ training, qualifying, elimination( 1, 5, { 5: { slots: [ { method: 2, seed_id: 2, seed_rank: 3 } ] } } ) ],
			heatResults: { 2: heatResult( 2, [ entry( 3, 1 ) ] ) },
		} );
		const tab = await openPage();
		await deliver( tab, data );
		check( 'a class seed labelled with the class', ( await pilotNames( tab, 'class-3-display' ) ).includes( 'Qualifying #3' ), JSON.stringify( await pilotNames( tab, 'class-3-display' ) ) );
		// Heat 5 was flown: P1 and P2 placed, P3 and P4 did not start. Heat 6's slot takes the
		// third entry - P3 - as RotorHazard does, before the timer has filled the slot.
		await deliver( tab, race( {
			pilots: 12,
			current: 6,
			classes: [ elimination( 2, 5, { 6: { slots: [ { method: 1, seed_id: 5, seed_rank: 3 } ] } } ) ],
			heatResults: { 5: heatResult( 5, [ entry( 1, 1 ), entry( 2, 2 ), entry( 3, null ), entry( 4, null ) ] ) },
		} ) );
		check( 'a heat seed takes the entry at seed_rank - 1, P3', ( await pilotNames( tab, 'class-3-display' ) ).includes( 'P3' ), JSON.stringify( await pilotNames( tab, 'class-3-display' ) ) );
		// The timer went back to heat 5 after heat 6 was flown: heat 6's result shows all the same.
		await deliver( tab, race( {
			pilots: 12,
			current: 5,
			classes: [ elimination( 2, 5, { 6: { slots: [ { pilot_id: 7 }, { pilot_id: 8 } ] } } ) ],
			heatResults: { 6: heatResult( 6, [ entry( 7, 1 ), entry( 8, 2 ) ] ) },
		} ) );
		const res = ( await pilotResults( tab, 'class-3-display' ) ).filter( ( r ) => r.includes( '#' ) );
		check( "results of a heat after the current one shown", res.length === 2, JSON.stringify( res ) );
		await tab.close();
	}

	await browser.close();

	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write( `\n${ '-'.repeat( 62 ) }\n  ${ results.length - failed } passed, ${ failed } failed\n` );
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	process.stderr.write( `\nHARNESS ERROR: ${ e && e.stack ? e.stack : e }\n` );
	process.exit( 1 );
} );

/**
 * The pilot filter on the stats view — js/rm-m-displayStats.js.
 *
 *   npm run test:stats-filter
 *
 * The stats view now offers what the bracket view always did: a dropdown that marks one pilot,
 * and a checkbox that drops everyone else. The two levels matter separately. Marking is the more
 * useful of them on a leaderboard, because a position only means something read against the ones
 * around it; filtering is for following a single pilot through an event.
 *
 * What makes this worth a suite of its own is that the filter reaches further than the tables it
 * obviously touches. Every leaderboard on the page — class summaries, per heat, per round, event
 * totals — comes out of one method, and underneath them sit the per-round lap tables, which carry
 * the same pilot_id. Filtering the standings but leaving everyone else's lap times under them is
 * the kind of half-applied filter that makes people stop trusting the control. So is a page of
 * table headers with no rows beneath them, which is what pruning exists to prevent.
 *
 * Needs a started DDEV site with a race that has result data. It skips rather than fails when
 * that is missing.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
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

const snapshot = ( page ) =>
	page.evaluate( () => {
		const rows = Array.from( document.querySelectorAll( 'table.leaderboard tbody tr' ) );
		const marked = rows.filter( ( r ) => r.classList.contains( 'selected-pilot' ) );
		const selector = document.getElementById( 'pilotSelector' );
		const box = document.getElementById( 'filterCheckbox' );
		const emptyTables = Array.from( document.querySelectorAll( 'table.leaderboard' ) )
			.filter( ( t ) => {
				const body = t.querySelector( 'tbody' );
				return ! body || body.rows.length === 0;
			} ).length;
		return {
			hasControls: !! document.querySelector( '.web-controls' ),
			options: selector ? selector.querySelectorAll( 'option[data-pilot-id]' ).length : -1,
			hasPlaceholder: selector
				? !! selector.querySelector( 'option:not([data-pilot-id])' )
				: false,
			selected: selector ? selector.value : null,
			checked: box ? box.checked : null,
			tables: document.querySelectorAll( 'table.leaderboard' ).length,
			rows: rows.length,
			marked: marked.length,
			lapTables: document.querySelectorAll( 'table.laps' ).length,
			panels: document.querySelectorAll( '.panel' ).length,
			emptyTables,
			markedBackground: marked.length ? getComputedStyle( marked[ 0 ] ).backgroundColor : null,
			note: ( document.querySelector( '.rm-no-filter-results' ) || {} ).textContent || null,
		};
	} );

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

	const context = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await context.newPage();
	const consoleErrors = [];
	page.on( 'console', ( m ) => m.type() === 'error' && consoleErrors.push( m.text() ) );
	page.on( 'pageerror', ( e ) => consoleErrors.push( `pageerror: ${ e.message }` ) );

	try {
		await page.goto( `${ BASE }/live/`, { waitUntil: 'networkidle', timeout: 20000 } );
	} catch ( e ) {
		await browser.close();
		skip( `${ BASE } is not reachable -- is the DDEV project started?` );
	}
	const slug = await page.evaluate( () => {
		const link = document.querySelector( '.race-select-item a[href]' );
		return link ? new URL( link.href ).pathname.split( '/' ).filter( Boolean )[ 1 ] : null;
	} );
	if ( ! slug ) {
		await browser.close();
		skip( 'the selection page lists no race with result data' );
	}
	const statsUrl = `${ BASE }/live/${ slug }/stats/`;

	await page.goto( statsUrl, { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 900 );

	// -------------------------------------------------------------- 1 · the controls
	section( 'The controls are on the page' );
	const plain = await snapshot( page );
	if ( plain.tables === 0 ) {
		await browser.close();
		skip( 'this race renders no leaderboards, so there is nothing to filter' );
	}
	check( 'the stats view offers the same controls as the bracket view', plain.hasControls,
		JSON.stringify( plain ) );
	check( 'the dropdown is filled from the race data', plain.options > 1, `${ plain.options } pilots` );
	check( 'and keeps its placeholder', plain.hasPlaceholder === true );
	check( 'nothing is marked before a pilot is chosen', plain.marked === 0, JSON.stringify( plain ) );
	note( `unfiltered: ${ plain.tables } leaderboards, ${ plain.rows } rows, ${ plain.lapTables } lap tables` );

	const pilot = await page.evaluate( () => {
		const option = document.querySelector( '#pilotSelector option[data-pilot-id]' );
		return { id: option.value, name: option.textContent };
	} );

	// ----------------------------------------------- 2 · choosing marks, and only marks
	section( 'Choosing a pilot marks their rows and hides nothing' );
	await page.selectOption( '#pilotSelector', pilot.id );
	await page.waitForTimeout( 700 );
	const marked = await snapshot( page );
	check( 'their rows are marked wherever they appear', marked.marked > 0,
		`${ marked.marked } row(s) marked for ${ pilot.name }` );
	check( 'the marking is visible rather than only a class name',
		!! marked.markedBackground && marked.markedBackground !== 'rgba(0, 0, 0, 0)',
		`background: ${ marked.markedBackground }` );
	// The distinction between the two levels: a position read on its own says nothing.
	check( 'everyone else is still there, so a position can be read in context',
		marked.rows === plain.rows && marked.tables === plain.tables,
		`${ marked.rows } rows (was ${ plain.rows }), ${ marked.tables } tables (was ${ plain.tables })` );

	// ------------------------------------------------------- 3 · the checkbox filters
	section( 'The checkbox drops everyone else' );
	await page.check( '#filterCheckbox' );
	await page.waitForTimeout( 900 );
	const filtered = await snapshot( page );
	check( 'the leaderboards keep only that pilot', filtered.rows === filtered.marked && filtered.rows > 0,
		`${ filtered.rows } rows, ${ filtered.marked } of them marked` );
	check( 'and there are fewer of them than before', filtered.tables < plain.tables,
		`${ filtered.tables } leaderboards (was ${ plain.tables })` );
	// The half-applied filter this guards against: standings filtered, everyone else's lap times
	// still sitting underneath them.
	check( 'the per-round lap tables are filtered with them',
		filtered.lapTables < plain.lapTables && filtered.lapTables > 0,
		`${ filtered.lapTables } lap tables (was ${ plain.lapTables })` );
	check( 'no leaderboard is left standing with no rows under its headers',
		filtered.emptyTables === 0, `${ filtered.emptyTables } empty table(s)` );
	check( 'and panels emptied by the filter are gone too', filtered.panels < plain.panels,
		`${ filtered.panels } panels (was ${ plain.panels })` );
	note( `filtered: ${ filtered.tables } leaderboards, ${ filtered.rows } rows, ${ filtered.lapTables } lap tables` );

	// ------------------------------------------------------------- 4 · it comes back
	section( 'Turning it off restores the page' );
	await page.uncheck( '#filterCheckbox' );
	await page.waitForTimeout( 800 );
	const restored = await snapshot( page );
	check( 'every row is back', restored.rows === plain.rows && restored.tables === plain.tables,
		`${ restored.rows } rows, ${ restored.tables } tables` );
	check( 'and the pilot is still marked', restored.marked === marked.marked,
		`${ restored.marked } marked` );

	// --------------------------------------------------- 5 · the choice is remembered
	section( 'The choice survives a reload' );
	await page.check( '#filterCheckbox' );
	await page.waitForTimeout( 800 );
	await page.reload( { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 1000 );
	const reloaded = await snapshot( page );
	check( 'the pilot is still selected', reloaded.selected === pilot.id,
		`selected ${ reloaded.selected }, expected ${ pilot.id }` );
	check( 'the checkbox is still ticked', reloaded.checked === true );
	check( 'and the page comes back filtered rather than whole',
		reloaded.tables === filtered.tables && reloaded.rows === filtered.rows,
		`${ reloaded.tables } tables, ${ reloaded.rows } rows` );

	// ------------------------------------------- 6 · a pilot who is in none of it
	section( 'A pilot with no results gets an answer, not a blank page' );
	await page.evaluate( () => {
		const selector = document.getElementById( 'pilotSelector' );
		const ghost = document.createElement( 'option' );
		ghost.value = '999999';
		ghost.textContent = 'Nobody';
		ghost.setAttribute( 'data-pilot-id', '999999' );
		selector.appendChild( ghost );
		selector.value = '999999';
		selector.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	} );
	await page.waitForTimeout( 800 );
	const nobody = await snapshot( page );
	check( 'nothing is left over from the other pilots', nobody.tables === 0 && nobody.rows === 0,
		JSON.stringify( nobody ) );
	check( 'and the page says so instead of going blank',
		!! nobody.note && /Nobody/.test( nobody.note ), `note: ${ nobody.note }` );

	check( 'the console stayed clean', consoleErrors.length === 0,
		consoleErrors.slice( 0, 3 ).join( ' | ' ) );

	// ------------------------------------------------- 7 · the bracket is untouched
	section( 'The bracket view still filters the way it did' );
	// displayStats now imports pilotSelector, which the bracket has always imported. Both views
	// share one selection, and the bracket's own two levels have to keep working unchanged.
	const bracket = await context.newPage();
	bracket.on( 'pageerror', ( e ) => consoleErrors.push( `bracket: ${ e.message }` ) );
	await bracket.goto( `${ BASE }/live/${ slug }/bracket/`, { waitUntil: 'networkidle' } );
	await bracket.waitForTimeout( 900 );
	const nodesOf = () =>
		bracket.evaluate( () => ( {
			nodes: document.querySelectorAll( '.node' ).length,
			dimmed: document.querySelectorAll( '.node.dimmed' ).length,
		} ) );
	const bracketPlain = await nodesOf();
	await bracket.selectOption( '#pilotSelector', pilot.id );
	await bracket.waitForTimeout( 900 );
	const bracketMarked = await nodesOf();
	await bracket.check( '#filterCheckbox' );
	await bracket.waitForTimeout( 900 );
	const bracketFiltered = await nodesOf();
	check( 'nothing is dimmed until a pilot is chosen', bracketPlain.dimmed === 0,
		JSON.stringify( bracketPlain ) );
	check( 'choosing one dims the races without them',
		bracketMarked.dimmed > 0 && bracketMarked.nodes === bracketPlain.nodes,
		JSON.stringify( bracketMarked ) );
	check( 'and the checkbox removes those races outright',
		bracketFiltered.nodes < bracketPlain.nodes, JSON.stringify( bracketFiltered ) );

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

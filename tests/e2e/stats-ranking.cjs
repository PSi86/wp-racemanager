/**
 * js/rm-m-displayStats.js: the class ranking panel, against the real module in a real browser.
 *
 *   npm run test:stats-ranking
 *
 * No WordPress and no DDEV: the page is assembled here, the dataLoader and pilot selector the
 * module imports are stubs, and buildRanking() is called on the module's own instance with the
 * shapes RotorHazard uploads in result_data.classes[].ranking.
 *
 * A class gets a ranking once the timer ranks it with a ranking method -- the community plugin
 * "Class Rank: Brackets" for a Chase the Ace final, for one. The panel called a translation
 * function the module never imported, so the first real ranking would have thrown.
 *
 * What has to hold: a ranking is a table with the method's own columns; a method that produced
 * nothing (the plugin answers {} and {}) or a ranking without meta says so in a sentence.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const PLUGIN_DIR = path.resolve( __dirname, '..', '..' );
const MODULE = fs.readFileSync( path.join( PLUGIN_DIR, 'js', 'rm-m-displayStats.js' ), 'utf8' );

const results = [];
function check( label, ok, detail ) {
	results.push( { label, ok } );
	process.stdout.write( `  ${ ok ? '\x1b[32mok\x1b[0m  ' : '\x1b[31mFAIL\x1b[0m' }  ${ label }\n` );
	if ( detail && ! ok ) {
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

const DATA_LOADER_STUB = `export const dataLoaderInstance = { storageKey: 'rmTest', subscribe() {} };`;
const PILOT_SELECTOR_STUB = `export const pilotSelectInstance = { pilotSelectorId: 'pilotSelector', selectedPilotId: 0 };`;

const PAGE = `<!doctype html>
<html><body>
<select id="pilotSelector"><option value="0">--</option></select>
<input type="checkbox" id="filterCheckbox">
<div id="results"></div>
<script>window.RmJsConfig = { displayStats: { filterCheckboxId: 'filterCheckbox' } };</script>
<script type="module">
	window.__rmStats = await import( '/js/rm-m-displayStats.js' );
</script>
</body></html>`;

// What "Class Rank: Brackets" hands RotorHazard once a Chase the Ace final is decided.
const BRACKETS_RANKING = {
	ranking: [
		{ pilot_id: 4, callsign: 'P4', team_name: '', position: 1, result: 'CTA [4] [1]' },
		{ pilot_id: 2, callsign: 'P2', team_name: '', position: 2, result: '[6] [2]' },
	],
	meta: { method_label: 'Brackets', rank_fields: [ { name: 'result', label: 'Result' } ] },
};

( async () => {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		skip( `no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${ e.message.split( '\n' )[ 0 ] }` );
	}
	const page = await browser.newPage();
	await page.route( '**/*', ( route ) => {
		const url = route.request().url();
		const js = ( body ) => route.fulfill( { contentType: 'text/javascript', body } );
		if ( url.endsWith( '/js/rm-m-dataLoader.js' ) ) return js( DATA_LOADER_STUB );
		if ( url.endsWith( '/js/rm-m-pilotSelector.js' ) ) return js( PILOT_SELECTOR_STUB );
		if ( url.endsWith( '/js/rm-m-displayStats.js' ) ) return js( MODULE );
		return route.fulfill( { contentType: 'text/html', body: PAGE } );
	} );
	await page.goto( 'http://rm.test/index.html' );
	await page.waitForFunction( () => window.__rmStats !== undefined, null, { timeout: 15000 } );

	// buildRanking() on the module's instance; the text of what it builds, or the error.
	const build = ( ranking ) =>
		page.evaluate( ( r ) => {
			try {
				const el = window.__rmStats.displayStatsInstance.buildRanking( r );
				return { tag: el.tagName, text: el.textContent, headers: [ ...el.querySelectorAll( 'th' ) ].map( ( th ) => th.textContent ) };
			} catch ( e ) {
				return { error: String( e && e.message ? e.message : e ) };
			}
		}, ranking );

	process.stdout.write( '\nA Chase the Ace ranking from the timer\n' );
	let out = await build( BRACKETS_RANKING );
	check( 'builds without an error', ! out.error, out.error );
	check( 'a table with the Result column', out.tag === 'DIV' && ( out.headers || [] ).includes( 'Result' ), JSON.stringify( out ) );
	check( 'the winner and the result the timer gave', ( out.text || '' ).includes( 'P4' ) && ( out.text || '' ).includes( 'CTA [4] [1]' ), out.text );

	process.stdout.write( '\nA ranking method that produced nothing\n' );
	out = await build( { ranking: {}, meta: {} } );
	check( 'builds without an error', ! out.error, out.error );
	check( 'says so in a sentence', out.tag === 'P' && /did not produce a ranking/.test( out.text || '' ), JSON.stringify( out ) );

	process.stdout.write( '\nA ranking without meta\n' );
	out = await build( { ranking: BRACKETS_RANKING.ranking, meta: null } );
	check( 'builds without an error', ! out.error, out.error );
	check( 'says so in a sentence', out.tag === 'P' && /did not produce a ranking/.test( out.text || '' ), JSON.stringify( out ) );

	await browser.close();

	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write( `\n${ '-'.repeat( 62 ) }\n  ${ results.length - failed } passed, ${ failed } failed\n` );
	process.exit( failed ? 1 : 0 );
} )();

/**
 * js/rm-m-dataLoader.js: a subscriber that throws, against the real module in a real browser.
 *
 *   npm run test:loader-subscribe
 *
 * No WordPress and no DDEV: the page is assembled here and every URL the loader asks for is
 * answered here. A returning visitor has the race in localStorage, so subscribe() hands it over
 * at once, inside the subscribing module's own start-up. A throw there used to escape subscribe()
 * and end that start-up half done (the bracket view lost its hover and zoom handling); every
 * later update already went through a try/catch.
 *
 * What has to hold: subscribe() does not throw when the subscriber does, and the next subscriber
 * still gets the data.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const PLUGIN_DIR = path.resolve( __dirname, '..', '..' );
const MODULE = fs.readFileSync( path.join( PLUGIN_DIR, 'js', 'rm-m-dataLoader.js' ), 'utf8' );

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

const PAGE = `<!doctype html>
<html><body>
<script>
	// What an earlier visit left behind: the whole file of a race without an index.
	localStorage.setItem( 'rm_data_rmTest_meta', JSON.stringify( { timestamp: '2026-09-12 10:00:00', etag: null, time: null } ) );
	localStorage.setItem( 'rm_data_rmTest', JSON.stringify( { race_name: 'cached' } ) );
	window.RmJsConfig = { dataLoader: {
		timestampUrl: '/races/1-timestamp.json',
		dataUrl: '/races/1-data.json',
		refreshInterval: 0,
		storageKey: 'rmTest',
	} };
</script>
<script type="module">
	const result = { threw: null, got: null, loaded: false };
	try {
		const { dataLoaderInstance } = await import( '/js/rm-m-dataLoader.js' );
		try {
			dataLoaderInstance.subscribe( () => { throw new Error( 'subscriber failed' ); } );
		} catch ( e ) {
			result.threw = String( e && e.message ? e.message : e );
		}
		dataLoaderInstance.subscribe( ( data ) => { result.got = data; } );
		result.loaded = true;
	} catch ( e ) {
		result.threw = 'module: ' + String( e && e.message ? e.message : e );
	}
	window.__rmResult = result;
</script>
</body></html>`;

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
		if ( url.endsWith( '/js/rm-m-dataLoader.js' ) ) {
			return route.fulfill( { contentType: 'text/javascript', body: MODULE } );
		}
		if ( url.endsWith( '/races/1-timestamp.json' ) ) {
			// The same timestamp as the cache (compared as text): nothing new to download.
			return route.fulfill( { contentType: 'application/json', body: '2026-09-12 10:00:00' } );
		}
		if ( url.includes( '/races/' ) ) {
			return route.fulfill( { contentType: 'application/json', body: JSON.stringify( { race_name: 'cached' } ) } );
		}
		return route.fulfill( { contentType: 'text/html', body: PAGE } );
	} );
	await page.goto( 'http://rm.test/index.html' );
	await page.waitForFunction( () => window.__rmResult !== undefined, null, { timeout: 15000 } );
	const result = await page.evaluate( () => window.__rmResult );

	process.stdout.write( '\nA late subscriber that throws\n' );
	check( 'the module loads', result.loaded, result.threw );
	check( 'subscribe() does not throw', result.threw === null, result.threw );
	check( 'the next subscriber still gets the cached data', result.got && result.got.race_name === 'cached', JSON.stringify( result.got ) );

	await browser.close();

	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write( `\n${ '-'.repeat( 62 ) }\n  ${ results.length - failed } passed, ${ failed } failed\n` );
	process.exit( failed ? 1 : 0 );
} )();

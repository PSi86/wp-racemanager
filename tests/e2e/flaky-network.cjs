/**
 * The live app on a bad mobile link.
 *
 *   npm run test:flaky-network
 *
 * This suite exists because of something that happened at real events on the pre-2026 code: for
 * some spectators the app came up empty and stayed empty. Reloading did not help. Reloading
 * again did not help. It only came back when the app was killed outright and reopened.
 *
 * The cause, reproduced and then fixed, was an ordering mistake in the loader. It recorded the
 * timestamp it had just fetched *before* downloading the payload that timestamp pointed at. When
 * the download then failed -- which on a fading connection it does -- that version was marked as
 * already seen. Every later check found the timestamp unchanged, concluded there was nothing new,
 * and never asked for the data again. The note lived in sessionStorage, so it survived every
 * reload and died only with the tab. Hence the hard kill.
 *
 * Measured against the old loader, the sequence below was: 1 data request on the first load, then
 * 0, 0, and 0 -- the fourth with the network fully healthy again.
 *
 * What is checked here:
 *
 *   1. A payload that never arrives must leave nothing behind that stops the next attempt.
 *   2. A response whose body stalls after the headers -- the characteristic mobile failure, and a
 *      second way into the same dead end -- must not wedge the loader either.
 *   3. An impatient viewer hammering the refresh control must not make anything worse.
 *   4. A slow but working link must still deliver, rather than being cut off every time.
 *   5. With a warm cache, even a total outage must show the last known standing rather than an
 *      empty page. This is the part that turns "the app is broken" into "the app is behind".
 *
 * Needs a started DDEV site with a race that carries result data and is flagged live
 * (`ddev wp post meta update <id> _race_live 1`). It skips rather than fails when that is missing.
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

// The link, under our control. `mode` is read on every request, so it can be changed between
// steps without re-routing: 'ok', 'dead' (nothing gets through), 'no-payload' (the timestamp
// arrives, the payload does not -- the exact shape of the original failure), or 'slow'.
const link = { mode: 'ok', delayMs: 0, timestamps: 0, payloads: 0 };

async function connect( page ) {
	await page.route( '**/uploads/races/**', async ( route ) => {
		const isPayload = /-data\.json/.test( route.request().url() );
		if ( isPayload ) {
			link.payloads++;
		} else {
			link.timestamps++;
		}
		if ( link.mode === 'dead' || ( link.mode === 'no-payload' && isPayload ) ) {
			return route.abort( 'connectionfailed' );
		}
		if ( link.delayMs ) {
			await new Promise( ( r ) => setTimeout( r, link.delayMs ) );
		}
		return route.continue();
	} );
}

// What a spectator would actually see: is there a standing on the page, and what does the pill say.
const view = ( page ) =>
	page.evaluate( () => {
		const filled = Array.from( document.querySelectorAll( '.raceclass-container, #results, #pilot-stats' ) )
			.filter( ( el ) => el.children.length ).length;
		const pill = document.getElementById( 'rm-update-status' );
		const text = pill && pill.querySelector( '.rm-update-status__text' );
		return {
			hasStanding: filled > 0,
			pillShown: !! pill && ! pill.hidden,
			tone: pill ? pill.dataset.tone || null : null,
			says: text ? text.textContent : null,
		};
	} );

const loaderState = ( page ) =>
	page.evaluate( async () => {
		const tag = document.querySelector( 'script[type="module"][src*="rm-m-updateStatus"]' );
		const loader = ( await import( new URL( './rm-m-dataLoader.js', tag.src ).href ) ).dataLoaderInstance;
		return {
			busyTimestamp: loader.isFetchingTimestamp,
			busyData: loader.isFetchingData,
			phase: loader.phase,
			failures: loader.consecutiveFailures,
			cachedTimestamp: loader.cachedTimestamp,
			hasData: !! loader.data,
			timeout: loader.timeout,
			dataTimeout: loader.dataTimeout,
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

	// Find a race the way a visitor would, over a healthy link.
	const scout = await browser.newContext( { ignoreHTTPSErrors: true } );
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
	const raceUrl = `${ BASE }${ path }`;

	// ================================================== 1 · the failure from the field
	section( 'A payload that never arrives must not poison the next attempt' );
	const ctx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await ctx.newPage();
	await connect( page );

	link.mode = 'no-payload';
	link.payloads = 0;
	await page.goto( raceUrl, { waitUntil: 'domcontentloaded' } );
	await page.waitForTimeout( 1500 );
	const first = await view( page );
	const afterFirst = await loaderState( page );
	check( 'the first load asks for the payload and fails', link.payloads > 0 && ! first.hasStanding,
		`${ link.payloads } payload request(s), standing: ${ first.hasStanding }` );
	check( 'and says so instead of pretending',
		first.pillShown && first.tone === 'error', JSON.stringify( first ) );
	// The heart of it. A timestamp remembered here is a version marked as seen that never arrived.
	check( 'the timestamp is NOT recorded as seen -- this is the whole defect',
		! afterFirst.cachedTimestamp,
		`cachedTimestamp: ${ JSON.stringify( afterFirst.cachedTimestamp ) }` );

	// The visitor gets impatient. On the old loader every one of these cost zero requests.
	let reloadRequests = [];
	for ( let i = 0; i < 2; i++ ) {
		link.payloads = 0;
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 1500 );
		reloadRequests.push( link.payloads );
	}
	check( 'every impatient reload tries again rather than giving up silently',
		reloadRequests.every( ( n ) => n > 0 ), `payload requests per reload: ${ reloadRequests.join( ', ' ) }` );

	// Reception returns. Nothing else changes -- no new tab, no cleared storage.
	link.mode = 'ok';
	link.payloads = 0;
	await page.reload( { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 1200 );
	const healed = await view( page );
	check( 'and when reception returns the standing appears, without killing the app',
		healed.hasStanding, JSON.stringify( healed ) );
	check( 'the pill goes back to reporting it as current',
		healed.tone === 'live', JSON.stringify( healed ) );
	note( `payload requests: 1st load ${ reloadRequests.length ? '>0' : '?' }, reloads ${ reloadRequests.join( '/' ) }, recovery ${ link.payloads }` );

	// ================================================= 2 · headers arrive, body does not
	section( 'A response whose body stalls must not wedge the loader' );
	// The other way into the same dead end, and the one a fading link produces most often. If the
	// deadline stops covering the body read, the in-flight flag is never cleared and every later
	// check returns at the guard that reads it -- the pill sits on "Checking..." for good.
	const stalled = await page.evaluate( async () => {
		const tag = document.querySelector( 'script[type="module"][src*="rm-m-updateStatus"]' );
		const loader = ( await import( new URL( './rm-m-dataLoader.js', tag.src ).href ) ).dataLoaderInstance;
		loader.clearTimer();

		const realFetch = window.fetch;
		// Headers immediately, a body that never completes, and an abort that errors it -- which
		// is what a real connection does when it fades after the response has started.
		window.fetch = ( url, opts ) =>
			Promise.resolve(
				new Response(
					new ReadableStream( {
						start( controller ) {
							const signal = opts && opts.signal;
							if ( signal ) {
								signal.addEventListener( 'abort', () =>
									controller.error( new DOMException( 'aborted', 'AbortError' ) ) );
							}
						},
					} ),
					{ status: 200 }
				)
			);

		loader.timeout = 600;
		loader.dataTimeout = 600;
		loader.isFetchingTimestamp = false;
		loader.isFetchingData = false;
		loader.lastCheckStartedAt = 0;
		loader.consecutiveFailures = 0;

		loader.checkNow();  // deliberately not awaited: on the unfixed code it never settles
		await new Promise( ( r ) => setTimeout( r, 2500 ) );

		const out = {
			busyTimestamp: loader.isFetchingTimestamp,
			busyData: loader.isFetchingData,
			phase: loader.phase,
			failures: loader.consecutiveFailures,
		};
		window.fetch = realFetch;
		loader.timeout = 9000;
		loader.dataTimeout = 30000;
		loader.consecutiveFailures = 0;
		return out;
	} );
	check( 'the stalled read is abandoned rather than waited on for ever',
		stalled.busyTimestamp === false && stalled.busyData === false, JSON.stringify( stalled ) );
	check( 'the loader returns to idle, so the next check is not blocked at the door',
		stalled.phase === 'idle', JSON.stringify( stalled ) );
	check( 'and it counts as a failure, which is what drives the backoff',
		stalled.failures > 0, JSON.stringify( stalled ) );

	// ==================================================== 3 · the impatient viewer again
	section( 'Hammering the refresh control cannot make it worse' );
	await page.reload( { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 800 );
	link.timestamps = 0;
	await page.evaluate( () => {
		const pill = document.getElementById( 'rm-update-status' );
		for ( let i = 0; i < 12; i++ ) {
			pill.click();
		}
	} );
	await page.waitForTimeout( 1200 );
	check( 'twelve taps do not become twelve requests',
		link.timestamps <= 2, `${ link.timestamps } timestamp request(s) for 12 taps` );
	const afterTaps = await view( page );
	check( 'and the standing is still on screen afterwards',
		afterTaps.hasStanding, JSON.stringify( afterTaps ) );

	// ============================================================ 4 · slow, but working
	section( 'A slow link still delivers' );
	const budgets = await loaderState( page );
	// 100 KB over a bad link can legitimately take far longer than a 30-byte timestamp check.
	// Giving both the same deadline means aborting downloads that were about to succeed.
	check( 'the payload gets a longer deadline than the timestamp check',
		budgets.dataTimeout > budgets.timeout,
		`timestamp ${ budgets.timeout } ms, payload ${ budgets.dataTimeout } ms` );

	const slowCtx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const slowPage = await slowCtx.newPage();
	await connect( slowPage );
	link.mode = 'slow';
	link.delayMs = 2500;
	await slowPage.goto( raceUrl, { waitUntil: 'domcontentloaded' } );
	await slowPage.waitForTimeout( 1200 );
	const during = await view( slowPage );
	check( 'while it is loading the pill says so rather than claiming to be current',
		during.tone !== 'live', JSON.stringify( during ) );
	await slowPage.waitForTimeout( 6000 );
	const after = await view( slowPage );
	check( 'and the standing arrives once the bytes do',
		after.hasStanding && after.tone === 'live', JSON.stringify( after ) );
	link.delayMs = 0;
	link.mode = 'ok';
	await slowCtx.close();

	// ================================== 5 · the guarantee that matters most to a spectator
	section( 'A warm cache turns an outage into old data rather than no data' );
	// A visitor who has seen this race before has the payload in localStorage. Losing the network
	// then costs them freshness, not the page -- which is the difference between "it is broken"
	// and "it is behind", and the difference the indicator has to make legible.
	const warmCtx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const warm = await warmCtx.newPage();
	await connect( warm );
	link.mode = 'ok';
	await warm.goto( raceUrl, { waitUntil: 'networkidle' } );
	await warm.waitForTimeout( 800 );
	const warmed = await view( warm );
	check( 'the first visit works, so there is something to fall back on', warmed.hasStanding );

	link.mode = 'dead';
	await warm.reload( { waitUntil: 'domcontentloaded' } );
	await warm.waitForTimeout( 2000 );
	const outage = await view( warm );
	check( 'with the network gone the last known standing is still on screen',
		outage.hasStanding, JSON.stringify( outage ) );
	check( 'and the pill says the data could not be checked, not that it is current',
		outage.pillShown && outage.tone === 'error' && ! /Up to date/i.test( outage.says || '' ),
		JSON.stringify( outage ) );
	await warmCtx.close();
	await ctx.close();
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

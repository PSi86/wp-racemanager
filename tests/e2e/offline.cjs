/**
 * The live app without a connection -- the service worker's side of it (L6).
 *
 *   npm run test:offline
 *
 * flaky-network.cjs shows that a warm localStorage turns an outage into old data rather than no
 * data. That guarantee stopped at the page itself: until L6 the service worker had no fetch
 * handler, so a reload or a relaunch of the installed app without reception got the browser's own
 * error page, and the standing that sat in localStorage never had a page to appear on.
 *
 * What is checked here:
 *
 *   1. The first visit is kept, although the worker was installed *during* it and never saw the
 *      page load. That is the visit a spectator at the trackside is most likely to have had.
 *   2. What is kept is the page and its scripts and styles -- and not the race JSON, which the
 *      loader keeps itself and has to fetch from the network for the freshness pill to be honest.
 *   3. A reload without a connection shows the page, styled, with the standing from localStorage,
 *      and the pill does not claim to be current.
 *  3b. Launching the installed app without a connection -- start_url, the selection page, which
 *      the visitor never opened -- goes on to the race last viewed.
 *   4. A page never opened on this device gets a stated answer instead of the browser's error.
 *   5. Back online, the page comes from the network again, not from the cache.
 *   6. Caches left by an older worker are deleted; a cache that is not ours is left alone.
 *   7. A link that is up but not delivering gets the kept copy after the worker's deadline,
 *      rather than a page that waits for as long as the connection does -- and the page's files
 *      do not then each wait out a deadline of their own.
 *   8. A page the server marks no-store -- what WordPress sends a logged-in user -- is not kept.
 *   9. Online, the network decides, even for a file whose URL carries ?ver=: a file changed
 *      without a version bump is served as it is now.
 *
 * The sections run in the order 1, 2, 6, 3, 3b, 4, 5, 9, 7, 8. After a failure the worker answers a
 * page's *files* from its cache for a while, so the checks that need the network to win come
 * before the one that holds every request.
 *
 * Needs a started DDEV site with a race that carries result data. It skips rather than fails when
 * that is missing. Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
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

// Everything the worker's caches hold, as { cacheName: [url, ...] }.
const cacheContents = ( page ) =>
	page.evaluate( async () => {
		const out = {};
		for ( const name of await caches.keys() ) {
			const cache = await caches.open( name );
			out[ name ] = ( await cache.keys() ).map( ( r ) => r.url );
		}
		return out;
	} );

// What a spectator would see: a standing, the pill, and whether the plugin's styles applied.
const view = ( page ) =>
	page.evaluate( () => {
		const filled = Array.from( document.querySelectorAll( '.raceclass-container, #results, #pilot-stats' ) )
			.filter( ( el ) => el.children.length ).length;
		const pill = document.getElementById( 'rm-update-status' );
		const text = pill && pill.querySelector( '.rm-update-status__text' );
		return {
			hasStanding: filled > 0,
			hasPill: !! pill,
			// css/rm-update-status.css positions the pill; without the stylesheet it is static.
			styled: !! pill && getComputedStyle( pill ).position === 'fixed',
			pillShown: !! pill && ! pill.hidden,
			tone: pill ? pill.dataset.tone || null : null,
			says: text ? text.textContent : null,
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

	// Find a race the way a visitor would.
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
	const raceUrl = `${ BASE }${ path }`;
	// Another view of the same race, never opened in the context below.
	const otherView = /\/bracket\/$/.test( path ) ? raceUrl.replace( /\/bracket\/$/, '/stats/' ) : raceUrl.replace( /\/[^/]+\/$/, '/bracket/' );

	const ctx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await ctx.newPage();

	// ============================================== 6 (set up first) · caches left behind
	// Planted before the worker ever runs, on a page outside its scope: one cache named the way an
	// older worker of ours would have named it, one that belongs to something else on the origin.
	await page.goto( `${ BASE }/`, { waitUntil: 'domcontentloaded' } );
	await page.evaluate( async () => {
		await ( await caches.open( 'rm-live-0.0.0-stale' ) ).put( '/stale-entry', new Response( 'old' ) );
		await ( await caches.open( 'another-app' ) ).put( '/theirs', new Response( 'theirs' ) );
	} );

	// ======================================================= 1 · the first visit is kept
	section( 'The first visit is kept, although the worker never saw it load' );
	await page.goto( raceUrl, { waitUntil: 'networkidle' } );
	const controlled = await page
		.waitForFunction( () => !! navigator.serviceWorker.controller, null, { timeout: 10000 } )
		.then( () => true, () => false );
	check( 'the worker takes over the page it was installed from', controlled );

	// Keeping happens after load, in the worker; give it a moment rather than racing it.
	let kept = {};
	for ( let i = 0; i < 20; i++ ) {
		kept = await cacheContents( page );
		const ours = Object.entries( kept ).filter( ( [ name ] ) => /^rm-live-/.test( name ) && ! /stale/.test( name ) );
		if ( ours.some( ( [ , urls ] ) => urls.includes( raceUrl ) ) ) {
			break;
		}
		await page.waitForTimeout( 500 );
	}
	const ourNames = Object.keys( kept ).filter( ( n ) => /^rm-live-/.test( n ) && ! /stale/.test( n ) );
	const ourUrls = ourNames.flatMap( ( n ) => kept[ n ] );
	note( `caches: ${ Object.keys( kept ).join( ', ' ) || '(none)' }` );
	check( 'the page is in the worker\'s cache', ourUrls.includes( raceUrl ), ourUrls.slice( 0, 5 ).join( ' ' ) );

	// ============================================================ 2 · what is kept, and not
	section( 'Scripts and styles are kept; the race JSON is not' );
	check( 'a versioned view module', ourUrls.some( ( u ) => /rm-m-displayHeats\.js\?ver=/.test( u ) ) );
	check( 'the loader, which is imported without a version', ourUrls.some( ( u ) => /rm-m-dataLoader\.js$/.test( u ) ) );
	check( 'the pill\'s stylesheet', ourUrls.some( ( u ) => /rm-update-status\.css/.test( u ) ) );
	// The loader keeps the payload in localStorage and has to see the network for the timestamp;
	// a second copy here would put a cache between the pill and the truth.
	const raceJson = ourUrls.filter( ( u ) => /\/uploads\/races\//.test( u ) );
	check( 'no race JSON at all -- neither the timestamp nor the payload', raceJson.length === 0, raceJson.join( ' ' ) );
	const cachedPageDate = await page.evaluate( async ( url ) => {
		for ( const name of await caches.keys() ) {
			const hit = await ( await caches.open( name ) ).match( url );
			if ( hit ) {
				return hit.headers.get( 'date' );
			}
		}
		return null;
	}, raceUrl );

	// ==================================================================== 6 · housekeeping
	section( 'An older worker\'s caches are deleted, nobody else\'s are' );
	check( 'the stale rm-live- cache is gone', ! ( 'rm-live-0.0.0-stale' in kept ), Object.keys( kept ).join( ', ' ) );
	check( 'the other app\'s cache is still there', 'another-app' in kept, Object.keys( kept ).join( ', ' ) );

	const before = await view( page );
	check( 'online, the page shows the standing to begin with', before.hasStanding, JSON.stringify( before ) );

	// ========================================================== 3 · reload, no connection
	section( 'A reload without a connection still shows the race' );
	await ctx.setOffline( true );
	let offlineResponse = null;
	let offlineError = '';
	try {
		offlineResponse = await page.reload( { waitUntil: 'domcontentloaded', timeout: 15000 } );
	} catch ( e ) {
		offlineError = e.message.split( '\n' )[ 0 ];
	}
	await page.waitForTimeout( 2500 );
	const offline = await view( page ).catch( () => ( {} ) );
	note( `the pill says: ${ offline.says }` );
	check( 'the page loads instead of the browser\'s error',
		!! offlineResponse && offlineResponse.ok() && offline.hasPill, offlineError || JSON.stringify( offline ) );
	check( 'with its styles', !! offline.styled, JSON.stringify( offline ) );
	check( 'and the last known standing, out of the loader\'s own cache', !! offline.hasStanding, JSON.stringify( offline ) );
	check( 'while the pill does not claim it is current',
		offline.tone !== 'live' && ! /Up to date/i.test( offline.says || '' ), JSON.stringify( offline ) );

	// ============================================== 3b · launching the installed app offline
	section( 'Launching the installed app without a connection lands on the race' );
	// The app starts at the manifest's start_url, /live/?resume=1 -- the selection page, which
	// js/rm-live-resume.js turns into the race last viewed. This visitor never opened the
	// selection page, which is the ordinary case for someone who installed the app from a race.
	const launch = await ctx.newPage();
	let launchError = '';
	try {
		await launch.goto( `${ BASE }/live/?resume=1`, { waitUntil: 'domcontentloaded', timeout: 15000 } );
		await launch.waitForURL( raceUrl, { timeout: 5000 } );
		await launch.waitForTimeout( 1500 );
	} catch ( e ) {
		launchError = e.message.split( '\n' )[ 0 ];
	}
	const launched = await view( launch ).catch( () => ( {} ) );
	check( 'it goes on to the race last viewed', launch.url() === raceUrl, launchError || launch.url() );
	check( 'and shows its standing', !! launched.hasStanding, JSON.stringify( launched ) );
	await launch.close();

	// ====================================================== 4 · a page never opened here
	section( 'A page never opened on this device gets a stated answer' );
	const other = await ctx.newPage();
	let otherResponse = null;
	let otherError = '';
	try {
		otherResponse = await other.goto( otherView, { waitUntil: 'domcontentloaded', timeout: 15000 } );
	} catch ( e ) {
		otherError = e.message.split( '\n' )[ 0 ];
	}
	const otherText = otherResponse ? await other.evaluate( () => document.body.innerText ) : '';
	check( 'it says there is no connection, rather than the browser erroring',
		!! otherResponse && otherResponse.status() === 503 && /no connection/i.test( otherText ),
		otherError || `${ otherResponse && otherResponse.status() }: ${ otherText.slice( 0, 120 ) }` );
	await other.close();

	// ========================================================= 5 · back online, back fresh
	section( 'Back online, the page comes from the network again' );
	await ctx.setOffline( false );
	await page.waitForTimeout( 1100 ); // so a fresh Date header cannot equal the cached one
	const onlineResponse = await page.reload( { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 1000 );
	const onlineDate = onlineResponse ? onlineResponse.headers()[ 'date' ] : null;
	check( 'its Date is newer than the cached copy\'s',
		!! onlineDate && !! cachedPageDate && Date.parse( onlineDate ) > Date.parse( cachedPageDate ),
		`cached ${ cachedPageDate }, now ${ onlineDate }` );
	const back = await view( page );
	check( 'and the standing is on screen', back.hasStanding, JSON.stringify( back ) );
	check( 'with the pill no longer reporting an error', back.tone !== 'error', JSON.stringify( back ) );

	// ================================ 9 · online, the network decides -- even with ?ver=
	section( 'Online, a file changed without a version bump is served as it is now' );
	// A hotfix deployed under the same version, or a stylesheet edited on the local site. A worker
	// that answered versioned files from its cache first would serve the old one for as long as
	// the cache lives; the browser's own cache at least revalidates on a reload.
	const cssUrl = ourUrls.find( ( u ) => /rm-update-status\.css\?ver=/.test( u ) );
	const MARK = '/* changed without a version bump */';
	await ctx.route( cssUrl, async ( route ) => {
		const real = await route.fetch();
		const headers = { ...real.headers(), etag: '"changed-in-test"' };
		delete headers[ 'content-encoding' ];
		delete headers[ 'content-length' ];
		await route.fulfill( { status: 200, headers, body: ( await real.text() ) + '\n' + MARK + '\n' } );
	} );
	const served = await page.evaluate( async ( u ) => ( await fetch( u ) ).text(), cssUrl );
	await page.waitForTimeout( 500 );
	const keptCss = await page.evaluate( async ( u ) => {
		for ( const name of await caches.keys() ) {
			const hit = await ( await caches.open( name ) ).match( u );
			if ( hit ) {
				return hit.text();
			}
		}
		return '';
	}, cssUrl );
	await ctx.unroute( cssUrl );
	check( 'the page gets the file as the server has it now', served.includes( MARK ), served.slice( -120 ) );
	check( 'and the kept copy is replaced with it', keptCss.includes( MARK ), keptCss.slice( -120 ) );

	// ============================================ 7 · a link that has stopped delivering
	section( 'A link that stops delivering gets the kept copy after the deadline' );
	// The fading link: the device believes it is online, and nothing comes back. context.route
	// reaches the worker's own fetches in Chromium, so every request to the site is held for longer
	// than the worker's deadline. The page has to wait that deadline out once -- and its files must
	// not each wait it out again, or a head script alone would double the time.
	const keptDate = await page.evaluate( async ( url ) => {
		for ( const name of await caches.keys() ) {
			const hit = await ( await caches.open( name ) ).match( url );
			if ( hit ) {
				return hit.headers.get( 'date' );
			}
		}
		return null;
	}, raceUrl );
	const HOLD = 15000;
	const everything = `${ BASE }/**`;
	await ctx.route( everything, async ( route ) => {
		await new Promise( ( r ) => setTimeout( r, HOLD ) );
		await route.continue().catch( () => {} );
	} );
	const started = Date.now();
	const heldResponse = await page.reload( { waitUntil: 'domcontentloaded', timeout: HOLD + 10000 } ).catch( () => null );
	const took = Date.now() - started;
	await ctx.unroute( everything );
	const held = await view( page ).catch( () => ( {} ) );
	note( `the page and its scripts took ${ took } ms against requests held for ${ HOLD } ms` );
	check( 'the page is there, from the kept copy',
		!! heldResponse && heldResponse.headers()[ 'date' ] === keptDate,
		`served ${ heldResponse && heldResponse.headers()[ 'date' ] }, kept ${ keptDate }` );
	check( 'but not at once: the network gets its chance first', took >= 4000, `${ took } ms` );
	check( 'and its files do not each wait out the deadline again', took < 8000, `${ took } ms` );
	check( 'styled', !! held.styled, JSON.stringify( held ) );

	// ============================================== 8 · what the server says not to store
	section( 'A page marked no-store is shown but not kept' );
	// WordPress sends this header to every logged-in user (wp_get_nocache_headers(), verbatim
	// below), and their pages carry the admin bar with their name in it. Keeping one would leave
	// it in a cache that outlives the login. Faked here on the response rather than by logging in,
	// which tests the rule itself and needs no credentials.
	const NO_STORE = 'no-cache, must-revalidate, max-age=0, no-store, private';
	await ctx.route( otherView, async ( route ) => {
		const real = await route.fetch();
		await route.fulfill( { response: real, headers: { ...real.headers(), 'cache-control': NO_STORE } } );
	} );
	const privatePage = await ctx.newPage();
	const privateResponse = await privatePage.goto( otherView, { waitUntil: 'networkidle' } ).catch( () => null );
	await privatePage.waitForTimeout( 2000 ); // the keep message, had it been going to keep it
	const afterPrivate = await cacheContents( privatePage );
	await ctx.unroute( otherView );
	check( 'the page itself is shown', !! privateResponse && privateResponse.ok() );
	check( 'but not kept',
		! Object.values( afterPrivate ).flat().includes( otherView ),
		Object.values( afterPrivate ).flat().filter( ( u ) => /\/live\//.test( u ) ).join( ' ' ) );
	await privatePage.close();

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

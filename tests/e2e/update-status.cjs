/**
 * The data path and the line that reports on it -- js/rm-m-dataLoader.js and
 * js/rm-m-updateStatus.js.
 *
 *   npm run test:update-status
 *
 * Needs a started DDEV site with a race that has result data and is flagged live
 * (`ddev wp post meta update <id> _race_live 1`), because half of what is under test is a
 * polling loop that does not exist otherwise. It skips rather than fails when that is missing.
 *
 * Four things are checked, and they are different kinds of claim:
 *
 *   1. The cache goes where it is supposed to and sweeps up only its own keys.
 *   2. A visitor who closes the browser and comes back does not download the payload again.
 *      This is the entire point of moving the cache to localStorage, and it is measured in
 *      bytes off the wire rather than inferred from the code.
 *   3. The status line never claims freshness it does not have -- checked against the pure
 *      describe() function over a table of states, so every branch is reachable here even
 *      though most of them need a broken network to occur naturally.
 *   4. Offline is reported as offline.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
 */

const path = require( 'path' );
const fs = require( 'fs' );

const BASE = ( process.env.RM_E2E_URL || 'https://racemanager.ddev.site' ).replace( /\/$/, '' );
const PROFILE = path.join( require( 'os' ).tmpdir(), 'rm-e2e-update-status-profile' );

const results = [];
function check( label, ok, detail ) {
	results.push( { label, ok } );
	process.stdout.write( `  ${ ok ? '\x1b[32mok\x1b[0m  ' : '\x1b[31mFAIL\x1b[0m' }  ${ label }\n` );
	if ( detail && ! ok ) {
		process.stdout.write( `          ${ String( detail ).slice( 0, 400 ) }\n` );
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

// Bytes actually transferred for the race JSON, which is the only honest way to tell a cache
// hit from a cache miss. content-length is absent on the compressed response, so it cannot be
// used here.
function trackRaceJson( page, bag ) {
	page.on( 'requestfinished', async ( req ) => {
		if ( ! /\/uploads\/races\//.test( req.url() ) ) {
			return;
		}
		try {
			const sizes = await req.sizes();
			const response = await req.response();
			bag.push( {
				file: req.url().split( '/' ).pop(),
				status: response ? response.status() : 0,
				bytes: sizes.responseBodySize + sizes.responseHeadersSize,
			} );
		} catch ( e ) {
			// A request that outlives its page cannot be sized; it is not part of any claim here.
		}
	} );
}
const totalBytes = ( bag ) => bag.reduce( ( sum, r ) => sum + r.bytes, 0 );

const statusLine = ( page ) =>
	page.evaluate( () => {
		const container = document.getElementById( 'rm-update-status' );
		if ( ! container ) {
			return { present: false };
		}
		const text = container.querySelector( '.rm-update-status__text' );
		const style = getComputedStyle( container );
		const box = container.getBoundingClientRect();
		return {
			present: true,
			hidden: container.hidden,
			tone: container.dataset.tone || null,
			text: text ? text.textContent : null,
			live: text ? text.getAttribute( 'aria-live' ) : null,
			// The whole pill is the control, so there is no separate button to look for.
			tag: container.tagName,
			label: container.getAttribute( 'aria-label' ),
			position: style.position,
			// Distance from the pill's bottom edge to the bottom of the viewport.
			fromBottom: Math.round( window.innerHeight - box.bottom ),
			duplicates: document.querySelectorAll( '#rm-update-status, .rm-update-status' ).length,
			dots: container.querySelectorAll( '.rm-update-status__dot' ).length,
			texts: container.querySelectorAll( '.rm-update-status__text' ).length,
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

	try {
		await page.goto( `${ BASE }/live/`, { waitUntil: 'networkidle', timeout: 20000 } );
	} catch ( e ) {
		await browser.close();
		skip( `${ BASE } is not reachable -- is the DDEV project started?` );
	}

	const first = await page.evaluate( () => {
		const link = document.querySelector( '.race-select-item a[href]' );
		return link ? new URL( link.href ).pathname : null;
	} );
	if ( ! first ) {
		await browser.close();
		skip( 'the selection page lists no race with result data' );
	}
	const raceUrl = `${ BASE }${ first }`;

	const consoleErrors = [];
	page.on( 'console', ( m ) => m.type() === 'error' && consoleErrors.push( m.text() ) );
	page.on( 'pageerror', ( e ) => consoleErrors.push( `pageerror: ${ e.message }` ) );

	await page.goto( raceUrl, { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 500 );

	// ------------------------------------------------------- 1 · the indicator is there
	section( 'The freshness indicator' );
	const line = await statusLine( page );
	check( 'it is rendered and revealed', line.present && line.hidden === false,
		JSON.stringify( line ) );
	check( 'it says something', !! line.text, JSON.stringify( line ) );
	check( 'it is a polite live region', line.live === 'polite', `aria-live: ${ line.live }` );
	check( 'the whole pill is the control', line.tag === 'BUTTON' && !! line.label,
		`<${ line.tag }> aria-label: ${ line.label }` );
	check( 'it floats at the foot of the viewport rather than sitting in the flow',
		line.position === 'fixed' && line.fromBottom >= 0 && line.fromBottom < 80,
		`position ${ line.position }, ${ line.fromBottom }px from the bottom` );
	check( 'there is exactly one of it', line.duplicates === 1, `${ line.duplicates } found` );
	// A second evaluation of the module -- two URLs for the same file differing only by a query
	// string is enough -- used to stack a second dot and sentence inside the same pill. It read
	// as a rendering bug and was invisible to every check that only counted elements.
	check( 'and it holds one dot and one sentence, not two',
		line.dots === 1 && line.texts === 1, `${ line.dots } dots, ${ line.texts } texts` );
	check( 'after a successful check it reads as current',
		line.tone === 'live' && /Up to date/.test( line.text || '' ),
		`tone ${ line.tone }, text ${ line.text }` );

	// The stylesheet sets display on the element, and an author style beats the browser's own
	// [hidden] { display: none }. Without a rule restoring it, everything that hides the pill --
	// which is how it ships from PHP, and how it spends its life on a race that is not live --
	// silently does nothing.
	const hiddenHeight = await page.evaluate( () => {
		const container = document.getElementById( 'rm-update-status' );
		const was = container.hidden;
		container.hidden = true;
		const height = container.getBoundingClientRect().height;
		container.hidden = was;
		return height;
	} );
	check( 'the hidden attribute really hides it', hiddenHeight === 0,
		`${ hiddenHeight }px tall while hidden` );

	const config = await page.evaluate( () => window.RmJsConfig && window.RmJsConfig.dataLoader );
	if ( ! config || ! config.refreshInterval ) {
		process.stdout.write(
			'\n\x1b[33mNOTE\x1b[0m  this race is not flagged live, so the polling checks below are skipped.\n' +
			'      ddev wp post meta update <race-id> _race_live 1\n'
		);
	}

	// ------------------------------------------------------------- 2 · where the cache goes
	section( 'The cache' );
	const store = await page.evaluate( () => {
		const out = {};
		for ( let i = 0; i < localStorage.length; i++ ) {
			const key = localStorage.key( i );
			out[ key ] = ( localStorage.getItem( key ) || '' ).length;
		}
		return out;
	} );
	const raceId = String( config && config.storageKey );
	check( 'the payload is in localStorage under a prefixed key',
		!! store[ `rm_data_${ raceId }` ] && store[ `rm_data_${ raceId }` ] > 1000,
		JSON.stringify( store ) );
	check( 'with its metadata beside it', !! store[ `rm_data_${ raceId }_meta` ], JSON.stringify( store ) );
	check( 'and nothing is left in sessionStorage',
		await page.evaluate( ( id ) => ! sessionStorage.getItem( id ), raceId ) );

	// Eviction has to be able to find our entries without finding anyone else's. rm_last_race
	// belongs to js/rm-live-resume.js and sits one careless prefix away from being swept up.
	await page.evaluate( () => localStorage.setItem( 'rm_data_999999', 'another race' ) );
	await page.evaluate( async () => {
		const tag = document.querySelector( 'script[type="module"][src*="rm-m-"]' );
		const mod = await import( new URL( './rm-m-dataLoader.js', tag.src ).href );
		mod.dataLoaderInstance.evictOtherRaces();
	} );
	const afterEviction = await page.evaluate( ( id ) => ( {
		other: localStorage.getItem( 'rm_data_999999' ),
		ours: !! localStorage.getItem( `rm_data_${ id }` ),
		resume: localStorage.getItem( 'rm_last_race' ),
	} ), raceId );
	check( 'another race is evicted', afterEviction.other === null );
	check( 'this race is not', afterEviction.ours === true );
	check( 'and rm_last_race, which is not ours, is left alone', afterEviction.resume !== null,
		'the resume entry was swept up by the eviction prefix' );

	// ------------------------------------------------------- 3 · scheduling, without waiting
	if ( config && config.refreshInterval ) {
		section( 'Polling (L4)' );
		const timing = await page.evaluate( async () => {
			const tag = document.querySelector( 'script[type="module"][src*="rm-m-"]' );
			const mod = await import( new URL( './rm-m-dataLoader.js', tag.src ).href );
			const loader = mod.dataLoaderInstance;
			const nominal = loader.refreshInterval;

			const gaps = [];
			for ( let i = 0; i < 12; i++ ) {
				loader.scheduleNext();
				gaps.push( loader.nextCheckAt - Date.now() );
			}
			loader.clearTimer();

			const failures = loader.consecutiveFailures;
			const backoff = [ 0, 1, 2, 3, 10 ].map( ( n ) => {
				loader.consecutiveFailures = n;
				return loader.currentDelay();
			} );
			loader.consecutiveFailures = failures;

			return { nominal, gaps, backoff, cap: loader.maxInterval };
		} );
		const withinJitter = timing.gaps.every(
			( g ) => g >= timing.nominal * 0.79 && g <= timing.nominal * 1.21
		);
		check( 'every scheduled delay stays within +/- 20 % of the interval', withinJitter,
			JSON.stringify( timing.gaps ) );
		check( 'and they are not all the same, so a grandstand does not poll in lockstep',
			new Set( timing.gaps ).size > 6, JSON.stringify( timing.gaps ) );
		check( 'failures double the delay',
			timing.backoff[ 0 ] === timing.nominal &&
				timing.backoff[ 1 ] === timing.nominal * 2 &&
				timing.backoff[ 2 ] === timing.nominal * 4 &&
				timing.backoff[ 3 ] === timing.nominal * 8,
			JSON.stringify( timing.backoff ) );
		check( 'but never past the cap', timing.backoff[ 4 ] === timing.cap,
			`${ timing.backoff[ 4 ] } vs cap ${ timing.cap }` );

		const hidden = await page.evaluate( async () => {
			const tag = document.querySelector( 'script[type="module"][src*="rm-m-"]' );
			const mod = await import( new URL( './rm-m-dataLoader.js', tag.src ).href );
			const loader = mod.dataLoaderInstance;
			// The property is read-only, so the only way to exercise the branch is to stand in
			// for it. What is under test is the loader's reaction, not the browser's reporting.
			const original = Object.getOwnPropertyDescriptor( Document.prototype, 'visibilityState' );
			Object.defineProperty( document, 'visibilityState', { configurable: true, get: () => 'hidden' } );
			loader.scheduleNext();
			const whileHidden = { timer: loader.timer, next: loader.nextCheckAt };
			delete document.visibilityState;
			if ( original ) {
				Object.defineProperty( Document.prototype, 'visibilityState', original );
			}
			loader.scheduleNext();
			const whileVisible = { timer: loader.timer, next: loader.nextCheckAt };
			loader.clearTimer();
			return { whileHidden, whileVisible };
		} );
		check( 'a hidden page schedules nothing -- a phone in a pocket stops polling',
			hidden.whileHidden.timer === null && hidden.whileHidden.next === null,
			JSON.stringify( hidden.whileHidden ) );
		check( 'and a visible one schedules again',
			hidden.whileVisible.timer !== null && hidden.whileVisible.next !== null,
			JSON.stringify( hidden.whileVisible ) );
	}

	// ------------------------------------------- 4 · what the line says, over every state
	section( 'What the line says (the pure state machine)' );
	const table = await page.evaluate( async () => {
		// Its own script tag, query string and all. WordPress appends ?ver= to what it enqueues,
		// and a URL that differs only by a query is a different module to the browser -- importing
		// the bare path here would construct a second UpdateStatus that mounts into the same
		// element. (The loader is a different case: the modules import it relatively, so the
		// unversioned path really is the URL the page used.)
		const tag = document.querySelector( 'script[type="module"][src*="rm-m-updateStatus"]' );
		const mod = await import( tag.src );
		const now = new Date( '2026-03-01T12:00:00' ).getTime();
		const base = {
			phase: 'idle', online: true, hasData: true, unconfirmed: false,
			dataTime: '2026-03-01 11:58:00', lastCheckedAt: now - 3000,
			lastChangedAt: now - 3000, nextCheckAt: now + 10000,
			refreshInterval: 10000, consecutiveFailures: 0, lastError: null,
		};
		const at = ( over ) => mod.describe( Object.assign( {}, base, over ), now );
		return {
			fresh: at( {} ),
			checking: at( { phase: 'checking' } ),
			updating: at( { phase: 'updating' } ),
			offline: at( { online: false } ),
			failing: at( { consecutiveFailures: 2 } ),
			unconfirmed: at( { unconfirmed: true } ),
			stale: at( { lastCheckedAt: now - 95000 } ),
			finished: at( { refreshInterval: 0 } ),
			nothing: at( { hasData: false, dataTime: null, lastCheckedAt: null } ),
			sameDay: mod.formatDataTime( '2026-03-01 11:58:00', new Date( now ) ),
			otherDay: mod.formatDataTime( '2026-02-24 11:58:00', new Date( now ) ),
			nonsense: mod.formatDataTime( 'not a timestamp', new Date( now ) ),

			// A race that is not live: refreshInterval 0, and nothing left to watch.
			doneQuiet: at( { refreshInterval: 0 } ),
			doneLoading: at( { refreshInterval: 0, phase: 'updating', hasData: false, lastCheckedAt: null } ),
			doneChecking: at( { refreshInterval: 0, phase: 'checking' } ),
			doneOffline: at( { refreshInterval: 0, online: false } ),
			doneFailing: at( { refreshInterval: 0, consecutiveFailures: 2 } ),
			doneUnconfirmed: at( { refreshInterval: 0, unconfirmed: true } ),
			doneEmpty: at( { refreshInterval: 0, hasData: false, dataTime: null } ),
		};
	} );

	check( 'a checked, unchanged file reads as current',
		table.fresh.tone === 'live' && /Up to date/.test( table.fresh.text ), JSON.stringify( table.fresh ) );
	check( 'a check in flight says so', table.checking.tone === 'busy' && /checking/i.test( table.checking.text ),
		JSON.stringify( table.checking ) );
	check( 'a download in flight says so', table.updating.tone === 'busy' && /Loading/i.test( table.updating.text ),
		JSON.stringify( table.updating ) );

	// The whole reason the line exists: these four must never read as "up to date".
	for ( const [ name, state ] of [
		[ 'offline', table.offline ],
		[ 'a failing check', table.failing ],
		[ 'data nobody has confirmed yet', table.unconfirmed ],
		[ 'a check that is overdue', table.stale ],
	] ) {
		check( `${ name } never claims to be up to date`,
			! /Up to date/i.test( state.text ) && state.tone !== 'live', JSON.stringify( state ) );
	}
	check( 'offline is named as such', /offline/i.test( table.offline.text ), table.offline.text );
	check( 'a stale line still shows when the data was made',
		/11:58/.test( table.stale.text ), table.stale.text );
	check( 'a race that is not live makes no freshness claim',
		table.finished.tone === 'idle' && ! /Up to date/i.test( table.finished.text ),
		JSON.stringify( table.finished ) );
	check( 'with no data at all it does not invent a time',
		! /\d\d:\d\d/.test( table.nothing.text ), table.nothing.text );

	check( 'a timestamp from today shows the time alone', table.sameDay === '11:58', table.sameDay );
	check( 'one from another day carries its date, so it cannot read as current',
		table.otherDay === '2026-02-24 11:58', table.otherDay );
	check( 'an unparseable timestamp yields nothing rather than a guess', table.nonsense === null,
		String( table.nonsense ) );

	// ------------------------------ 4b · a race that is not live shows nothing to say nothing
	section( 'A finished race keeps quiet unless the data could not be loaded' );
	check( 'while a race is live the pill is always there',
		table.fresh.visible === true && table.checking.visible === true && table.stale.visible === true,
		JSON.stringify( [ table.fresh.visible, table.checking.visible, table.stale.visible ] ) );
	check( 'a finished race with confirmed data shows nothing',
		table.doneQuiet.visible === false, JSON.stringify( table.doneQuiet ) );
	check( 'nor while it is still loading -- that is a moment, not a problem',
		table.doneLoading.visible === false && table.doneChecking.visible === false,
		JSON.stringify( [ table.doneLoading, table.doneChecking ] ) );
	// The narrow part of the rule: after a successful check the data is final, so having gone
	// offline since does not mean the viewer is missing anything.
	check( 'nor when the browser went offline after a successful check',
		table.doneOffline.visible === false, JSON.stringify( table.doneOffline ) );
	check( 'but a failed check brings it back',
		table.doneFailing.visible === true && table.doneFailing.tone === 'error',
		JSON.stringify( table.doneFailing ) );
	check( 'so does data that was never confirmed',
		table.doneUnconfirmed.visible === true, JSON.stringify( table.doneUnconfirmed ) );
	check( 'and so does having no data at all',
		table.doneEmpty.visible === true, JSON.stringify( table.doneEmpty ) );

	// ------------------------------------- 4c · a load that never succeeds, end to end
	section( 'A load that fails settles on the failure' );
	// The phase used to be left reading 'updating' when the fetch inside initialize() threw,
	// because only checkForUpdates() cleared it. The pill then sat on "Loading race data..."
	// for good -- and on a race that is not live nothing ever runs again to correct it, so the
	// one state a viewer most needs to see was the one state that never arrived.
	const blockedCtx = await browser.newContext( { ignoreHTTPSErrors: true } );
	const blocked = await blockedCtx.newPage();
	await blocked.route( '**/uploads/races/**', ( route ) => route.abort() );
	await blocked.goto( raceUrl, { waitUntil: 'networkidle' } );
	await blocked.waitForTimeout( 1200 );
	const blockedLine = await statusLine( blocked );
	await blockedCtx.close();
	check( 'it does not sit on "loading" forever',
		blockedLine.tone !== 'busy', JSON.stringify( blockedLine ) );
	check( 'it says the data could not be loaded',
		blockedLine.tone === 'error' && blockedLine.hidden === false,
		JSON.stringify( blockedLine ) );

	// Taken before the offline section below, which makes the browser fail a request on purpose
	// and so is meant to produce console output.
	check( 'the console stayed clean', consoleErrors.length === 0, consoleErrors.slice( 0, 3 ).join( ' | ' ) );
	const errorsBeforeOffline = consoleErrors.length;

	// ------------------------------------------------------------------ 5 · going offline
	section( 'Offline' );
	await context.setOffline( true );
	await page.evaluate( async () => {
		const tag = document.querySelector( 'script[type="module"][src*="rm-m-"]' );
		const mod = await import( new URL( './rm-m-dataLoader.js', tag.src ).href );
		mod.dataLoaderInstance.lastCheckStartedAt = 0;
		await mod.dataLoaderInstance.checkNow();
	} );
	await page.waitForTimeout( 300 );
	const offlineLine = await statusLine( page );
	check( 'a failed check is reported, not hidden',
		offlineLine.tone === 'error' && ! /Up to date/i.test( offlineLine.text || '' ),
		JSON.stringify( offlineLine ) );
	check( 'and the data already on screen is still named',
		/data from/i.test( offlineLine.text || '' ) || /offline/i.test( offlineLine.text || '' ),
		offlineLine.text );
	// A silent failure would be the worse bug: it is what leaves a viewer staring at an old
	// standing with no way to tell.
	check( 'the failure is logged rather than swallowed',
		consoleErrors.length > errorsBeforeOffline &&
			consoleErrors.slice( errorsBeforeOffline ).some( ( m ) => /dataLoader/.test( m ) ),
		consoleErrors.slice( errorsBeforeOffline ).join( ' | ' ) );
	await context.setOffline( false );

	await browser.close();

	// --------------------------------------- 6 · the claim this whole change was made for
	section( 'A returning visitor does not download the payload again' );
	// A fresh browser context is a first-ever visit, not a return. Only a persistent profile
	// keeps localStorage across a browser restart, which is what a viewer actually does.
	fs.rmSync( PROFILE, { recursive: true, force: true } );
	const visit = async () => {
		const ctx = await chromium.launchPersistentContext( PROFILE, { ignoreHTTPSErrors: true } );
		const p = ctx.pages()[ 0 ] || ( await ctx.newPage() );
		const bag = [];
		trackRaceJson( p, bag );
		await p.goto( raceUrl, { waitUntil: 'networkidle' } );
		await p.waitForTimeout( 600 );
		await ctx.close();
		return bag;
	};
	const firstVisit = await visit();
	const returnVisit = await visit();
	fs.rmSync( PROFILE, { recursive: true, force: true } );

	check( 'the first visit downloads the payload once',
		firstVisit.some( ( r ) => /-data\.json$/.test( r.file ) ) && totalBytes( firstVisit ) > 20000,
		JSON.stringify( firstVisit ) );
	check( 'coming back downloads it again never',
		! returnVisit.some( ( r ) => /-data\.json$/.test( r.file ) ),
		JSON.stringify( returnVisit ) );
	check( 'and costs only the timestamp check', totalBytes( returnVisit ) < 2000,
		`${ totalBytes( returnVisit ) } bytes: ${ JSON.stringify( returnVisit ) }` );
	process.stdout.write(
		`          first visit ${ totalBytes( firstVisit ).toLocaleString( 'en-US' ) } B, ` +
		`return ${ totalBytes( returnVisit ).toLocaleString( 'en-US' ) } B\n`
	);

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

/**
 * js/rm-live-resume.js — remembering the race, and what the selection page does with it.
 *
 *   npm run test:live-resume
 *
 * Needs a started DDEV site with at least two races that have result data, because the whole
 * point is what happens when the visitor comes back. It skips rather than fails when that is
 * not the case.
 *
 * The behaviour under test is a state machine spread across the server and localStorage, and
 * the two halves disagreeing is exactly the defect this covers: the selection page resolves a
 * race of its own (the header link carries ?rm_race=<slug>), so "the server gave me a race" is
 * not the same question as "this is a race page". Getting that order wrong made the selection
 * page store the race and return, and the resume offer never appeared there.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
 */

const BASE = ( process.env.RM_E2E_URL || 'https://racemanager.ddev.site' ).replace( /\/$/, '' );

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

// What the selection page shows: which entry is marked, and whether a resume link is offered.
const selectionState = ( page ) =>
	page.evaluate( () => {
		const list = document.querySelector( '.race-select-list' );
		if ( ! list ) {
			return { list: false };
		}
		const items = Array.from( list.querySelectorAll( '.race-select-item' ) );
		const current = items.find( ( li ) => li.classList.contains( 'is-current' ) );
		const link = current && current.querySelector( 'a[href]' );
		const resume = document.querySelector( '.rm-resume-link' );
		return {
			list: true,
			count: items.length,
			marked: link ? new URL( link.href ).pathname : null,
			ariaCurrent: link ? link.getAttribute( 'aria-current' ) : null,
			resumeText: resume ? resume.textContent : null,
			resumeHref: resume ? new URL( resume.href ).pathname : null,
		};
	} );

( async () => {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		skip( `no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${ e.message.split( '\n' )[ 0 ] }` );
	}

	const context = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await context.newPage();

	try {
		await page.goto( `${ BASE }/live/`, { waitUntil: 'networkidle', timeout: 20000 } );
	} catch ( e ) {
		await browser.close();
		skip( `${ BASE } is not reachable -- is the DDEV project started?` );
	}

	const races = await page.evaluate( () =>
		Array.from( document.querySelectorAll( '.race-select-item a[href]' ) ).map( ( a ) => ( {
			path: new URL( a.href ).pathname,
			slug: new URL( a.href ).pathname.split( '/' ).filter( Boolean )[ 1 ],
			title: a.textContent.trim(),
			live: a.closest( '.race-select-item' ).classList.contains( 'is-live' ),
		} ) )
	);
	// Which races are live, asked of the site rather than taken from the list under test.
	const liveSlugs = await page.evaluate( () => ( window.RmLiveResume && window.RmLiveResume.liveRaces ) || null );

	if ( races.length < 2 ) {
		await browser.close();
		skip( `the selection page lists ${ races.length } race(s); this needs at least two with result data` );
	}

	const [ first, second ] = races;

	// ------------------------------------------------ 1 · a race page is remembered
	await page.goto( `${ BASE }${ second.path }`, { waitUntil: 'networkidle' } );
	const storedAfterRace = await page.evaluate( () => {
		try {
			return JSON.parse( window.localStorage.getItem( 'rm_last_race' ) || 'null' );
		} catch ( e ) {
			return null;
		}
	} );
	check(
		'visiting a race stores it',
		!! storedAfterRace && storedAfterRace.slug === second.slug,
		storedAfterRace ? `stored ${ storedAfterRace.slug }` : 'nothing stored'
	);

	// -------------- 2 · the same race, reached both ways, must present identically
	// This is the requirement the whole file exists for: where the information came from --
	// the URL or localStorage -- must not be visible in the result.
	await page.goto( `${ BASE }/live/`, { waitUntil: 'networkidle' } );
	const fromStorage = await selectionState( page );
	check(
		'with no marker in the URL, the stored race is marked',
		fromStorage.marked === second.path && fromStorage.ariaCurrent === 'true',
		`marked: ${ fromStorage.marked || '(none)' }, aria-current: ${ fromStorage.ariaCurrent || '(none)' }`
	);
	check(
		'and offered',
		!! fromStorage.resumeText && fromStorage.resumeHref === second.path,
		`link: ${ fromStorage.resumeText || '(none)' } -> ${ fromStorage.resumeHref || '-' }`
	);

	await page.goto( `${ BASE }/live/?rm_race=${ second.slug }`, { waitUntil: 'networkidle' } );
	const fromUrl = await selectionState( page );
	check(
		'the same race named in the URL presents identically',
		fromUrl.marked === fromStorage.marked &&
			fromUrl.ariaCurrent === fromStorage.ariaCurrent &&
			fromUrl.resumeText === fromStorage.resumeText &&
			fromUrl.resumeHref === fromStorage.resumeHref,
		`from storage: ${ JSON.stringify( fromStorage ) }\n          from URL:     ${ JSON.stringify( fromUrl ) }`
	);

	// ----------------------------- 3 · the marker also updates what is remembered
	await page.goto( `${ BASE }/live/?rm_race=${ first.slug }`, { waitUntil: 'networkidle' } );
	const state = await selectionState( page );
	check(
		'arriving with ?rm_race= marks the race the visitor came from',
		state.marked === first.path && state.ariaCurrent === 'true',
		`marked: ${ state.marked || '(none)' }`
	);
	const storedAfterMarker = await page.evaluate( () => {
		try {
			return JSON.parse( window.localStorage.getItem( 'rm_last_race' ) || 'null' );
		} catch ( e ) {
			return null;
		}
	} );
	check(
		'the marker keeps the stored race in step',
		!! storedAfterMarker && storedAfterMarker.slug === first.slug,
		storedAfterMarker ? `stored ${ storedAfterMarker.slug }` : 'nothing stored'
	);

	// --------------------------------- 4 · the list marks the live races, by their flag (1.9.0)
	// It marked a race with an upload in the last two hours, and a cached copy of the page kept
	// whatever it said.
	check(
		'the page names the live races',
		Array.isArray( liveSlugs ),
		`liveRaces: ${ JSON.stringify( liveSlugs ) }`
	);
	const liveRace = races.find( ( r ) => ( liveSlugs || [] ).includes( r.slug ) );
	const pastRace = races.find( ( r ) => ! ( liveSlugs || [] ).includes( r.slug ) );
	check(
		'"Live:" on exactly the live races',
		races.every( ( r ) => r.live === ( liveSlugs || [] ).includes( r.slug ) && r.live === r.title.startsWith( 'Live:' ) ),
		JSON.stringify( races.map( ( r ) => ( { slug: r.slug, live: r.live, title: r.title } ) ) )
	);

	// ------------------------ 5 · ?resume=1 goes straight to a race that is still live
	if ( ! liveRace || ! pastRace ) {
		process.stdout.write( '          (the list needs a live race and an archived one for the checks below)\n' );
	} else {
		await page.goto( `${ BASE }${ liveRace.path }`, { waitUntil: 'networkidle' } );
		await page.goto( `${ BASE }/live/?resume=1`, { waitUntil: 'networkidle' } );
		check(
			'?resume=1 goes straight to the stored race when it is live',
			new URL( page.url() ).pathname === liveRace.path,
			`landed on ${ new URL( page.url() ).pathname }, expected ${ liveRace.path }`
		);

		// The app installed at one event and opened at the next landed in the race long over.
		await page.goto( `${ BASE }${ pastRace.path }`, { waitUntil: 'networkidle' } );
		await page.goto( `${ BASE }/live/?resume=1`, { waitUntil: 'networkidle' } );
		const stayed = await selectionState( page );
		check(
			'and stays on the selection page when it is over',
			new URL( page.url() ).pathname === new URL( `${ BASE }/live/` ).pathname,
			`landed on ${ new URL( page.url() ).pathname }`
		);
		check(
			'where the race is still offered',
			stayed.resumeHref === pastRace.path && stayed.marked === pastRace.path,
			JSON.stringify( stayed )
		);
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

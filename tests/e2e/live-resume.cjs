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
		} ) )
	);

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

	// -------------------------- 2 · the selection page without the marker: offer + mark
	await page.goto( `${ BASE }/live/`, { waitUntil: 'networkidle' } );
	let state = await selectionState( page );
	check(
		'arriving at the selection page with no marker still offers the stored race',
		!! state.resumeText && state.resumeHref === second.path,
		`link: ${ state.resumeText || '(none)' } -> ${ state.resumeHref || '-' }`
	);
	check(
		'and marks it in the list, so the page reads the same either way',
		state.marked === second.path && state.ariaCurrent === 'true',
		`marked: ${ state.marked || '(none)' }, aria-current: ${ state.ariaCurrent || '(none)' }`
	);

	// ------------------------- 3 · the selection page with the marker: mark, do not offer
	await page.goto( `${ BASE }/live/?rm_race=${ first.slug }`, { waitUntil: 'networkidle' } );
	state = await selectionState( page );
	check(
		'arriving with ?rm_race= marks the race the visitor came from',
		state.marked === first.path && state.ariaCurrent === 'true',
		`marked: ${ state.marked || '(none)' }`
	);
	check(
		'and does not also offer to continue with the race already shown as current',
		state.resumeText === null,
		state.resumeText ? `offered anyway: ${ state.resumeText }` : ''
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

	// --------------------------------------------------- 4 · ?resume=1 goes straight there
	await page.goto( `${ BASE }/live/?resume=1`, { waitUntil: 'networkidle' } );
	check(
		'?resume=1 goes straight to the stored race',
		new URL( page.url() ).pathname === first.path,
		`landed on ${ new URL( page.url() ).pathname }, expected ${ first.path }`
	);

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

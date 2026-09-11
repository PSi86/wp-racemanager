/**
 * The live views on a phone: the view tabs, the controls a thumb has to hit, and the theme's
 * navigation (L9).
 *
 *   npm run test:view-tabs
 *
 * Measured on production at 390 x 844 before any of this existed: the theme's navigation folded
 * the view links into its burger, so every switch between views took two taps and nothing on the
 * page showed that the other views were there. The overlay's items were 32 px tall, the burger 30
 * px square, the pilot dropdown 29 px, the filter checkbox 13 px. The current view was marked in
 * the markup and drawn like every other item.
 *
 * What is checked here:
 *
 *   1. A phone gets a row of view tabs fixed to the foot of the screen: one tab per view, each at
 *      least 44 px tall, the current one marked by more than colour, the pill floating above the
 *      row rather than on it, and room kept at the foot of the page so nothing hides behind it.
 *   2. One tap on a tab opens that view of the same race.
 *   3. The pilot dropdown and the checkbox's label are 44 px targets, and the label toggles.
 *   4. The theme's navigation, where there is one with view links: the burger answers a tap
 *      within 44 px of its centre, the overlay's items are 44 px tall, the current view is
 *      marked, and the open overlay covers the tabs.
 *   5. A wide screen has no tabs and keeps no room for them; the theme's navigation shows the
 *      views there.
 *   6. No race, no tabs. A finished race's next-up view keeps them.
 *   7. The tabs work without JavaScript -- they are plain links.
 *
 * Needs a started DDEV site with a race that carries result data. The navigation checks need a
 * navigation block with links to the live views, which bin/bootstrap-devenv.sh creates; they skip
 * rather than fail without one. Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
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

const PHONE = { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, ignoreHTTPSErrors: true };
const WIDE = { viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true };

// The tabs, the pill and the page's bottom padding, as laid out.
const layout = ( page ) =>
	page.evaluate( () => {
		const nav = document.getElementById( 'rm-view-tabs' );
		const box = ( el ) => {
			if ( ! el ) {
				return null;
			}
			const r = el.getBoundingClientRect();
			return { top: r.top, bottom: r.bottom, left: r.left, right: r.right, height: r.height, width: r.width };
		};
		const tabs = nav ? [ ...nav.querySelectorAll( '.rm-view-tabs__tab' ) ].map( ( a ) => {
			const cs = getComputedStyle( a );
			return {
				text: a.textContent.trim(),
				href: a.href,
				current: a.getAttribute( 'aria-current' ) === 'page',
				height: a.getBoundingClientRect().height,
				weight: cs.fontWeight,
				bar: getComputedStyle( a, '::before' ).content !== 'none',
			};
		} ) : [];
		const pill = document.getElementById( 'rm-update-status' );
		return {
			exists: !! nav,
			shown: !! nav && getComputedStyle( nav ).display !== 'none',
			position: nav ? getComputedStyle( nav ).position : null,
			nav: box( nav ),
			tabs,
			pill: pill && ! pill.hidden ? box( pill ) : null,
			bodyPadding: parseFloat( getComputedStyle( document.body ).paddingBottom ) || 0,
			viewport: { width: innerWidth, height: innerHeight },
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

	// Find the races the way a visitor would.
	const scout = await browser.newContext( { ignoreHTTPSErrors: true, serviceWorkers: 'block' } );
	const scoutPage = await scout.newPage();
	try {
		await scoutPage.goto( `${ BASE }/live/`, { waitUntil: 'networkidle', timeout: 20000 } );
	} catch ( e ) {
		await browser.close();
		skip( `${ BASE } is not reachable -- is the DDEV project started?` );
	}
	const paths = await scoutPage.evaluate( () =>
		[ ...document.querySelectorAll( '.race-select-item a[href]' ) ].map( ( a ) => new URL( a.href ).pathname ) );
	await scout.close();
	if ( ! paths.length ) {
		await browser.close();
		skip( 'the selection page lists no race with result data' );
	}
	const raceUrl = `${ BASE }${ paths[ 0 ] }`;
	const raceBase = raceUrl.replace( /[^/]+\/$/, '' ); // /live/{race}/

	const phone = await browser.newContext( { ...PHONE, serviceWorkers: 'block' } );
	const page = await phone.newPage();

	// ============================================================== 1 · the row itself
	section( 'A phone gets the view tabs at the foot of the screen' );
	await page.goto( raceUrl, { waitUntil: 'networkidle' } );
	await page.waitForTimeout( 1500 ); // the pill appears once the loader has checked
	const at = await layout( page );
	check( 'the row is there and shown', at.shown, JSON.stringify( at ) );
	check( 'fixed to the bottom edge, across the full width',
		at.position === 'fixed' && at.nav && Math.abs( at.nav.bottom - at.viewport.height ) <= 1
		&& at.nav.left <= 0 && at.nav.right >= at.viewport.width, JSON.stringify( at.nav ) );
	check( 'one tab per view -- at least two', at.tabs.length >= 2, JSON.stringify( at.tabs.map( ( t ) => t.text ) ) );
	note( `tabs: ${ at.tabs.map( ( t ) => ( t.current ? `[${ t.text }]` : t.text ) ).join( ' | ' ) }` );
	// Both need tabs to be about; with none, "every" would be true of nothing.
	check( 'every tab at least 44 px tall', at.tabs.length >= 2 && at.tabs.every( ( t ) => t.height >= 44 ),
		JSON.stringify( at.tabs.map( ( t ) => t.height ) ) );
	check( 'each one opens a view of this race', at.tabs.length >= 2 && at.tabs.every( ( t ) => t.href.startsWith( raceBase ) ),
		JSON.stringify( at.tabs.map( ( t ) => t.href ) ) );
	const current = at.tabs.filter( ( t ) => t.current );
	check( 'exactly one tab is current, and it is this view',
		current.length === 1 && current[ 0 ].href === raceUrl, JSON.stringify( current ) );
	const others = at.tabs.filter( ( t ) => ! t.current );
	check( 'the current tab is marked by weight and a bar, not by colour alone',
		current.length === 1 && current[ 0 ].bar && others.every( ( t ) => ! t.bar && t.weight !== current[ 0 ].weight ),
		JSON.stringify( at.tabs ) );
	check( 'the foot of the page keeps room for the row', at.nav && at.bodyPadding >= at.nav.height - 1,
		`padding ${ at.bodyPadding }, row ${ at.nav && at.nav.height }` );
	if ( at.pill ) {
		check( 'the pill floats above the row, not on it', !! at.nav && at.pill.bottom <= at.nav.top,
			`pill bottom ${ at.pill.bottom }, row top ${ at.nav && at.nav.top }` );
	} else {
		note( 'the pill is not shown on this race (not flagged live), so its place above the row is not checked' );
	}

	// ======================================================================= 2 · one tap
	section( 'One tap opens another view of the same race' );
	const target = others[ others.length - 1 ];
	if ( target ) {
		await page.locator( '.rm-view-tabs__tab', { hasText: target.text } ).tap();
		await page.waitForURL( target.href, { timeout: 10000 } ).catch( () => {} );
	}
	check( `tapping another tab opens its view`, !! target && page.url() === target.href, `landed on ${ page.url() }` );
	const after = await layout( page );
	check( 'and there it is the current tab',
		!! target && after.tabs.filter( ( t ) => t.current ).map( ( t ) => t.href ).join() === target.href,
		JSON.stringify( after.tabs.filter( ( t ) => t.current ) ) );

	// ================================================================ 3 · the controls
	section( 'The pilot filter is sized for a thumb' );
	await page.goto( raceUrl, { waitUntil: 'networkidle' } );
	const controls = await page.evaluate( () => {
		const select = document.getElementById( 'pilotSelector' );
		const box = document.getElementById( 'filterCheckbox' );
		const label = box && box.closest( 'label' );
		return {
			select: select ? select.getBoundingClientRect().height : 0,
			label: label ? label.getBoundingClientRect().height : 0,
			labelBox: label ? label.getBoundingClientRect().toJSON() : null,
			checked: box ? box.checked : null,
		};
	} );
	check( 'the pilot dropdown is at least 44 px tall', controls.select >= 44, `${ controls.select } px` );
	check( 'the checkbox\'s label is at least 44 px tall', controls.label >= 44, `${ controls.label } px` );
	if ( controls.labelBox ) {
		const b = controls.labelBox;
		await page.touchscreen.tap( b.x + b.width - 12, b.y + b.height / 2 );
		await page.waitForTimeout( 300 );
		check( 'and a tap on its text, away from the box, checks it',
			await page.evaluate( () => document.getElementById( 'filterCheckbox' ).checked ) === ! controls.checked );
	}

	// ======================================================= 4 · the theme's navigation
	section( 'The theme\'s navigation on a phone' );
	await page.goto( raceUrl, { waitUntil: 'networkidle' } );
	const hasNav = await page.locator( '.rm-live-nav .wp-block-navigation__responsive-container-open' ).count();
	if ( ! hasNav ) {
		note( 'SKIPPED: no navigation with links to the live views on this site -- bin/bootstrap-devenv.sh creates one' );
	} else {
		const burger = page.locator( '.rm-live-nav .wp-block-navigation__responsive-container-open' ).first();
		const b = await burger.boundingBox();
		// 20 px from the centre is outside anything smaller than 40 px, and inside a 44 px hit area.
		const reached = await page.evaluate( ( [ x, y ] ) => {
			const el = document.elementFromPoint( x, y );
			return !! ( el && el.closest( '.wp-block-navigation__responsive-container-open' ) );
		}, [ b.x + b.width / 2 + 20, b.y + b.height / 2 ] );
		note( `burger drawn at ${ Math.round( b.width ) } x ${ Math.round( b.height ) } px` );
		check( 'the burger answers a tap 20 px from its centre', reached );
		await burger.tap();
		await page.waitForTimeout( 600 );
		const items = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.rm-live-nav .wp-block-navigation__responsive-container.is-menu-open .wp-block-navigation-item__content' ) ]
				.map( ( a ) => {
					const cs = getComputedStyle( a );
					return {
						text: a.textContent.trim(),
						height: a.getBoundingClientRect().height,
						current: !! a.closest( '.current-menu-item' ),
						look: `${ cs.fontWeight } ${ cs.textDecorationLine }`,
					};
				} ) );
		check( 'the open overlay lists the views', items.length >= 2, JSON.stringify( items ) );
		check( 'every item in it at least 44 px tall', items.length && items.every( ( i ) => i.height >= 44 ),
			JSON.stringify( items.map( ( i ) => `${ i.text }:${ i.height }` ) ) );
		const here = items.filter( ( i ) => i.current );
		check( 'the current view looks different from the rest',
			here.length === 1 && items.filter( ( i ) => ! i.current ).every( ( i ) => i.look !== here[ 0 ].look ),
			JSON.stringify( items ) );
		const covered = await page.evaluate( () => {
			const nav = document.getElementById( 'rm-view-tabs' );
			if ( ! nav ) {
				return false;
			}
			const r = nav.getBoundingClientRect();
			const el = document.elementFromPoint( r.left + r.width / 2, r.top + r.height / 2 );
			return ! ( el && el.closest( '#rm-view-tabs' ) );
		} );
		check( 'and the open overlay covers the tabs rather than the other way round', covered );
	}

	// ================================================================ 5 · a wide screen
	section( 'A wide screen keeps the theme\'s navigation and no tabs' );
	const wide = await browser.newContext( { ...WIDE, serviceWorkers: 'block' } );
	const widePage = await wide.newPage();
	await widePage.goto( raceUrl, { waitUntil: 'networkidle' } );
	await widePage.waitForTimeout( 1500 );
	const w = await layout( widePage );
	check( 'no tabs shown', w.exists && ! w.shown, JSON.stringify( { exists: w.exists, shown: w.shown } ) );
	check( 'and no room kept for them', w.bodyPadding === 0, `padding ${ w.bodyPadding }` );
	if ( w.pill ) {
		check( 'the pill keeps its own place near the bottom edge', w.viewport.height - w.pill.bottom < 30,
			`gap ${ w.viewport.height - w.pill.bottom }` );
	}
	await wide.close();

	// ==================================================== 6 · no race; a finished race
	section( 'No race, no tabs; a finished race keeps them' );
	await page.goto( `${ BASE }/live/bracket/`, { waitUntil: 'networkidle' } );
	const noRace = await layout( page );
	check( 'a view without a race has no tabs, and keeps no room for them',
		! noRace.exists && noRace.bodyPadding === 0, JSON.stringify( { exists: noRace.exists, padding: noRace.bodyPadding } ) );
	let finished = null;
	for ( const path of paths ) {
		const nextUp = `${ BASE }${ path.replace( /[^/]+\/$/, '' ) }next-up/`;
		await page.goto( nextUp, { waitUntil: 'domcontentloaded' } );
		if ( await page.getByText( 'This race is over.' ).count() ) {
			finished = nextUp;
			break;
		}
	}
	if ( finished ) {
		const over = await layout( page );
		check( 'a finished race\'s next-up view still has the tabs', over.shown && over.tabs.length >= 2, finished );
	} else {
		note( 'every listed race is flagged live, so the finished-race case is not checked' );
	}

	// ============================================================== 7 · no JavaScript
	section( 'The tabs need no JavaScript' );
	const noJs = await browser.newContext( { ...PHONE, javaScriptEnabled: false } );
	const noJsPage = await noJs.newPage();
	await noJsPage.goto( raceUrl, { waitUntil: 'domcontentloaded' } );
	const plain = await layout( noJsPage );
	check( 'they are there and shown with scripts off', plain.shown && plain.tabs.length >= 2, JSON.stringify( plain.tabs ) );
	await noJs.close();

	await phone.close();
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

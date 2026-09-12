/**
 * Block editor checks, driven through a real browser.
 *
 *   npm run test:e2e
 *   RM_E2E_URL=https://other.ddev.site npm run test:e2e
 *
 * This is deliberately not part of `php tests/run.php`. That suite is plain PHP
 * and runs anywhere; this one needs a started DDEV site, node_modules, and a
 * Chromium that Playwright has downloaded. It exists because some questions
 * cannot be answered any other way -- whether a block survives the editor's
 * iframe is behaviour, not something you can read out of the source.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
 *
 * Preconditions are checked rather than assumed, and a missing one skips
 * instead of failing, so this can sit in a pipeline that has no browser.
 */

const BASE = ( process.env.RM_E2E_URL || 'https://racemanager.ddev.site' ).replace( /\/$/, '' );
const USER = process.env.RM_E2E_USER || 'admin';
const PASS = process.env.RM_E2E_PASS || 'admin';

const results = [];
let skipped = null;

function check( label, ok, detail ) {
	results.push( { label, ok } );
	const mark = ok ? '\x1b[32mok\x1b[0m  ' : '\x1b[31mFAIL\x1b[0m';
	process.stdout.write( `  ${ mark }  ${ label }\n` );
	if ( detail ) {
		process.stdout.write( `          ${ String( detail ).slice( 0, 300 ) }\n` );
	}
}

function skip( reason ) {
	skipped = reason;
	process.stdout.write( `\x1b[33mSKIP\x1b[0m  ${ reason }\n` );
	process.exit( 2 );
}

let chromium;
try {
	( { chromium } = require( 'playwright' ) );
} catch ( e ) {
	skip( 'playwright is not installed -- run "npm ci" in the plugin directory' );
}

( async () => {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		// Playwright pins a browser build per release, so an upgrade leaves the
		// previously downloaded one behind and the message names what is missing.
		skip( `no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${ e.message.split( '\n' )[ 0 ] }` );
	}

	const context = await browser.newContext( { ignoreHTTPSErrors: true } );
	const page = await context.newPage();

	const consoleLines = [];
	page.on( 'console', ( m ) => consoleLines.push( `${ m.type() }: ${ m.text() }` ) );
	page.on( 'pageerror', ( e ) => consoleLines.push( `pageerror: ${ e.message }` ) );

	// ------------------------------------------------------------------ log in
	try {
		await page.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded', timeout: 20000 } );
	} catch ( e ) {
		await browser.close();
		skip( `${ BASE } is not reachable -- is the DDEV project started?` );
	}

	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await page.click( '#wp-submit' );
	try {
		await page.waitForURL( /wp-admin/, { timeout: 30000 } );
	} catch ( e ) {
		await browser.close();
		skip( `could not log in as "${ USER }" -- set RM_E2E_USER / RM_E2E_PASS` );
	}

	// -------------------------------------------------------------- the editor
	await page.goto( `${ BASE }/wp-admin/post-new.php`, { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction(
		() => window.wp && window.wp.data && window.wp.data.select( 'core/block-editor' ),
		null,
		{ timeout: 60000 }
	);
	// The welcome guide covers the canvas on a fresh install.
	await page.evaluate( () => {
		try {
			window.wp.data.dispatch( 'core/preferences' ).set( 'core/edit-post', 'welcomeGuide', false );
		} catch ( e ) {}
	} );

	const canvasFrame = page.frameLocator( 'iframe[name="editor-canvas"]' );

	check(
		'the editor canvas is an iframe',
		( await page.locator( 'iframe[name="editor-canvas"]' ).count() ) === 1,
		'since 7.1 this is unconditional; a block that is not iframe-safe simply misbehaves'
	);

	// --------------------------------------------------- every block registers
	const blocks = await page.evaluate( () =>
		window.wp.blocks
			.getBlockTypes()
			.filter( ( b ) => b.name.startsWith( 'wp-racemanager/' ) )
			.map( ( b ) => ( {
				name: b.name,
				apiVersion: b.apiVersion,
				// A block that declares a parent or an ancestor cannot be
				// inserted at the document root, so the render check below has
				// to leave it alone rather than report a failure it caused
				// itself. nav-latest-races is one: it only lives inside a
				// core/navigation-submenu.
				confined: ( b.parent || b.ancestor || null ) && [].concat( b.parent || b.ancestor ).join( ', ' ),
			} ) )
	);

	check( 'the plugin registers its blocks', blocks.length > 0, `${ blocks.length } found` );

	const stale = blocks.filter( ( b ) => ! b.apiVersion || b.apiVersion < 3 );
	check(
		'every block is on apiVersion 3',
		stale.length === 0,
		stale.map( ( b ) => `${ b.name } is on ${ b.apiVersion }` ).join( ', ' )
	);

	// ------------------------------------------- each block renders in the frame
	for ( const { name, confined } of blocks ) {
		if ( confined ) {
			process.stdout.write(
				`  \x1b[33mskip\x1b[0m  ${ name } renders inside the iframe` +
				` -- only insertable inside ${ confined }\n`
			);
			continue;
		}

		const clientId = await page.evaluate( ( blockName ) => {
			const block = window.wp.blocks.createBlock( blockName );
			window.wp.data.dispatch( 'core/block-editor' ).insertBlock( block );
			return block.clientId;
		}, name );

		let rendered = false;
		try {
			await canvasFrame.locator( `#block-${ clientId }` ).waitFor( { timeout: 20000 } );
			rendered = true;
		} catch ( e ) {}

		check( `${ name } renders inside the iframe`, rendered );
	}

	// ------------------------------------------------ race-gallery in particular
	// It is the only block that drives the Backbone media library, which lives in
	// the parent document while the block renders in the iframe.
	const attachmentId = await page.evaluate( async () => {
		try {
			const media = await window.wp.apiFetch( { path: '/wp/v2/media?per_page=1&media_type=image' } );
			return media && media.length ? media[ 0 ].id : null;
		} catch ( e ) {
			return null;
		}
	} );

	if ( ! attachmentId ) {
		process.stdout.write(
			'  \x1b[33mskip\x1b[0m  race-gallery media checks -- the library has no image\n' +
			'          add one with: ddev wp media import <file>\n'
		);
	} else {
		await page.evaluate( ( id ) => {
			const sel = window.wp.data.select( 'core/block-editor' );
			const block = sel.getBlocks().find( ( b ) => b.name === 'wp-racemanager/race-gallery' );
			window.wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, { mediaItems: [ id ] } );
		}, attachmentId );

		let thumbOk = true;
		try {
			await canvasFrame.locator( '.rm-gallery-thumb img' ).first().waitFor( { timeout: 30000 } );
		} catch ( e ) {
			thumbOk = false;
		}
		check( 'race-gallery renders thumbnails for a selected attachment', thumbOk );

		// The block ships its CSS as an inline <style> in its own output rather
		// than through block.json, so what has to be true is that the element
		// travels into the iframe document and still applies there.
		const thumbWidth = await page.evaluate( () => {
			const frame = document.querySelector( 'iframe[name="editor-canvas"]' );
			const img = frame && frame.contentDocument && frame.contentDocument.querySelector( '.rm-gallery-thumb img' );
			return img ? Math.round( img.getBoundingClientRect().width ) : null;
		} );
		check( 'its inline <style> reaches the iframe and applies', thumbWidth === 150, `thumbnail width: ${ thumbWidth }px, expected 150` );

		let frameOpened = false;
		let frameTitle = '';
		try {
			// The block inserted last stays selected, and its floating toolbar can sit over the
			// gallery's button - race-winner's did (1.12.0). Nothing selected, nothing in the way.
			await page.evaluate( () => window.wp.data.dispatch( 'core/block-editor' ).clearSelectedBlock() );
			await canvasFrame.getByRole( 'button', { name: /Edit Gallery \/ Add Media/i } ).first().click( { timeout: 15000 } );
			// wp.media renders into the PARENT document -- the block's JavaScript
			// runs there, only its DOM lives in the iframe.
			await page.locator( '.media-modal' ).waitFor( { state: 'visible', timeout: 20000 } );
			frameOpened = true;
			frameTitle = await page.locator( '.media-frame-title' ).first().innerText().catch( () => '' );
		} catch ( e ) {
			frameTitle = e.message.split( '\n' )[ 0 ];
		}
		check( 'its wp.media frame opens over the iframe', frameOpened, frameTitle );
	}

	// ------------------------------------------- the race announcement (1.12.0)
	// A pattern of core blocks, as markup: whatever the editor would save differently from it, it
	// reports as changed ("This block contains unexpected or invalid content").
	const pattern = await page.evaluate( async () => {
		const all = await window.wp.apiFetch( { path: '/wp/v2/block-patterns/patterns' } );
		const found = ( all || [] ).find( ( p ) => p.name === 'wp-racemanager/race-announcement' );
		if ( ! found ) {
			return null;
		}
		const invalid = [];
		const walk = ( blocks ) => blocks.forEach( ( b ) => {
			if ( ! b.isValid ) invalid.push( b.name );
			walk( b.innerBlocks || [] );
		} );
		const blocks = window.wp.blocks.parse( found.content );
		walk( blocks );
		return { names: blocks.map( ( b ) => b.name ), invalid };
	} );
	check( 'the race announcement pattern is registered', !! pattern );
	check( 'its blocks are what the editor saves - none reported as changed',
		pattern && pattern.invalid.length === 0 && pattern.names.includes( 'core/table' ) && pattern.names.includes( 'core/list' ),
		JSON.stringify( pattern ) );
	const styles = await page.evaluate( () => {
		const blocks = window.wp.data.select( 'core/blocks' );
		return {
			table: ( blocks.getBlockStyles( 'core/table' ) || [] ).map( ( s ) => s.name ),
			list: ( blocks.getBlockStyles( 'core/list' ) || [] ).map( ( s ) => s.name ),
		};
	} );
	check( 'its styles are offered: schedule, checklist, allowed, not allowed',
		styles.table.includes( 'rm-schedule' ) && [ 'rm-checklist', 'rm-allowed', 'rm-banned' ].every( ( n ) => styles.list.includes( n ) ),
		JSON.stringify( styles ) );

	// A new race starts with it, in the Details block: the template's core/pattern is replaced by the
	// pattern's blocks, which the organiser edits. In a tab of its own, since leaving the editor above
	// with unsaved blocks asks first.
	const race = await context.newPage();
	race.on( 'pageerror', ( e ) => consoleLines.push( `pageerror: ${ e.message }` ) );
	await race.goto( `${ BASE }/wp-admin/post-new.php?post_type=race`, { waitUntil: 'domcontentloaded' } );
	let started = null;
	try {
		await race.waitForFunction( () => {
			const sel = window.wp && window.wp.data && window.wp.data.select( 'core/block-editor' );
			const all = [];
			const walk = ( blocks ) => blocks.forEach( ( b ) => {
				all.push( b );
				walk( b.innerBlocks || [] );
			} );
			walk( sel ? sel.getBlocks() : [] );
			return all.some( ( b ) => b.name === 'core/table' ) && ! all.some( ( b ) => b.name === 'core/pattern' );
		}, null, { timeout: 30000 } );
		started = await race.evaluate( () => {
			const details = window.wp.data.select( 'core/block-editor' ).getBlocks().find( ( b ) => b.name === 'core/details' );
			const inside = details ? details.innerBlocks : [];
			return {
				inside: inside.map( ( b ) => b.name ),
				schedule: inside.some( ( b ) => b.name === 'core/table' && /is-style-rm-schedule/.test( b.attributes.className || '' ) ),
				invalid: window.wp.data.select( 'core/block-editor' ).getBlocks().filter( ( b ) => ! b.isValid ).map( ( b ) => b.name ),
			};
		} );
	} catch ( e ) {
		started = { error: e.message.split( '\n' )[ 0 ] };
	}
	check( 'a new race starts with the announcement in its Details block', !! ( started && started.schedule ), JSON.stringify( started ) );
	check( 'and every block of it valid', !! ( started && started.invalid && started.invalid.length === 0 ), JSON.stringify( started && started.invalid ) );
	await race.close();

	// -------------------------------------------------------------- the console
	const deprecations = consoleLines.filter( ( l ) => /API version 2 or lower/i.test( l ) );
	check( 'no apiVersion deprecation is logged', deprecations.length === 0, deprecations[ 0 ] );

	const ourErrors = consoleLines.filter(
		( l ) => /^(error|pageerror)/i.test( l ) && /racemanager|rm-gallery/i.test( l )
	);
	check( 'no console error mentions this plugin', ourErrors.length === 0, ourErrors[ 0 ] );

	if ( process.env.RM_E2E_SHOT ) {
		await page.screenshot( { path: process.env.RM_E2E_SHOT } );
		process.stdout.write( `\n  screenshot: ${ process.env.RM_E2E_SHOT }\n` );
	}

	await browser.close();

	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write(
		`\n--------------------------------------------------------------\n` +
		`  ${ results.length - failed } passed, ${ failed } failed\n`
	);
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	if ( skipped ) {
		return;
	}
	process.stderr.write( `\nHARNESS ERROR: ${ e && e.stack ? e.stack : e }\n` );
	process.exit( 1 );
} );

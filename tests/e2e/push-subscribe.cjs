/**
 * js/rm-m-pwa-subscribe.js: which pilot a push subscription is for, against the real module.
 *
 *   npm run test:push-subscribe
 *
 * No WordPress and no push service: the page is assembled here, the pilot selector it imports is a
 * stub, and the service worker, its push manager and admin-ajax.php are stood in for, so what the
 * module sends and what its button offers can be read directly.
 *
 * A subscription follows the pilot key where the race data has one (WP RaceManager 1.7.0): the
 * timer gives re-created pilots new IDs, and an ID may name someone else by then. So the module
 * sends the key along, and tells "this is the pilot you follow" by the key when both have one.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed, 2 skipped.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const MODULE_PATH = path.resolve( __dirname, '..', '..', 'js', 'rm-m-pwa-subscribe.js' );

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

const KEY_BEN = 'bbbbbbbb-0000-5000-8000-000000000000';
const KEY_CLEO = 'cccccccc-0000-5000-8000-000000000000';

const PILOT_SELECTOR_STUB = `export const pilotSelectInstance = { pilotSelectorId: 'pilotSelector' };`;

// One option per pilot, as rm-m-pilotSelector.js builds them.
const option = ( [ id, name, key ] ) =>
	`<option value="${ id }" data-pilot-id="${ id }" data-pilot-callsign="${ name }" data-pilot-key="${ key }" data-race-id="5">${ name }</option>`;

const fixture = ( pilots, cached ) => `<!doctype html>
<html><body>
<select id="pilotSelector"><option value="0">-- Select a Pilot --</option>${ pilots.map( option ).join( '' ) }</select>
<input type="button" id="subscribe-button" value="Subscribe">
<p id="subscription-status"></p>
<script>
	window.RmJsConfig = { pushSubscription: { nonce: 'n', publicVapid: 'BAAA', ajaxUrl: '/wp-admin/admin-ajax.php' } };
	window.__rmPosts = [];
	// What the browser already has: a push subscription, and the status the server gave for it.
	const subscription = {
		toJSON: () => ( { endpoint: 'https://push.example.test/1', keys: { p256dh: 'p', auth: 'a' } } ),
		unsubscribe: async () => true,
	};
	Object.defineProperty( navigator, 'serviceWorker', { configurable: true, value: {
		ready: Promise.resolve( { pushManager: { getSubscription: async () => subscription, subscribe: async () => subscription } } ),
	} } );
	window.PushManager = window.PushManager || function () {};
	localStorage.setItem( 'rm_push_subscription_status', JSON.stringify( { data: ${ JSON.stringify( cached ) }, timestamp: Date.now() } ) );
	window.fetch = async ( url, init ) => {
		const body = new URLSearchParams( String( init.body ) );
		window.__rmPosts.push( Object.fromEntries( body ) );
		const echo = { subscribed: true, race_id: body.get( 'race_id' ), race_title: 'Autumn Cup', pilot_id: body.get( 'pilot_id' ),
			pilot_callsign: body.get( 'pilot_callsign' ), pilot_key: body.get( 'pilot_key' ) || '' };
		return new Response( JSON.stringify( { success: true, data: echo } ), { status: 200 } );
	};
</script>
<script type="module">
	await import( '/js/rm-m-pwa-subscribe.js' );
	window.__rmModuleLoaded = true;
</script>
</body></html>`;

( async () => {
	let browser;
	try {
		browser = await chromium.launch();
	} catch ( e ) {
		skip( `no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${ e.message.split( '\n' )[ 0 ] }` );
	}
	const context = await browser.newContext();

	async function openPage( pilots, cached, selected ) {
		const page = await context.newPage();
		await page.route( '**/*', ( route ) => {
			const url = route.request().url();
			if ( url.endsWith( '/js/rm-m-pilotSelector.js' ) ) {
				return route.fulfill( { contentType: 'text/javascript', body: PILOT_SELECTOR_STUB } );
			}
			if ( url.endsWith( '/js/rm-m-pwa-subscribe.js' ) ) {
				return route.fulfill( { contentType: 'text/javascript', body: fs.readFileSync( MODULE_PATH, 'utf8' ) } );
			}
			return route.fulfill( { contentType: 'text/html', body: fixture( pilots, cached ) } );
		} );
		await page.goto( 'http://rm.test/live/' );
		await page.waitForFunction( () => window.__rmModuleLoaded === true, null, { timeout: 15000 } );
		await page.selectOption( '#pilotSelector', String( selected ) );
		// The module answers the change with the subscription's state; wait for the button.
		await page.waitForFunction( () => ! document.getElementById( 'subscribe-button' ).disabled, null, { timeout: 15000 } );
		return page;
	}
	const button = ( page ) => page.evaluate( () => document.getElementById( 'subscribe-button' ).value );

	// Subscribed to Ben while he was pilot 2. The timer re-created its pilots: Ben is 7 now, and
	// 2 is Cleo.
	const subscribedToBen = { subscribed: true, race_id: '5', race_title: 'Autumn Cup', pilot_id: '2', pilot_callsign: 'Ben', pilot_key: KEY_BEN };
	const recreated = [ [ 2, 'Cleo', KEY_CLEO ], [ 7, 'Ben', KEY_BEN ] ];

	let page = await openPage( recreated, subscribedToBen, 7 );
	let value = await button( page );
	check( 'Ben, now pilot 7, selected: the button offers to unsubscribe from him', value === 'Unsubscribe', value );
	await page.close();

	page = await openPage( recreated, subscribedToBen, 2 );
	value = await button( page );
	check( 'Cleo, now pilot 2, selected: the button offers to move the subscription to her', value === 'Update Subscription', value );

	await page.click( '#subscribe-button' );
	await page.waitForFunction( () => window.__rmPosts.length > 0, null, { timeout: 15000 } );
	const post = await page.evaluate( () => window.__rmPosts[ 0 ] );
	check(
		'moving it sends her pilot key with her ID and callsign',
		post.action === 'update_subscription' && post.pilot_id === '2' && post.pilot_callsign === 'Cleo' && post.pilot_key === KEY_CLEO,
		JSON.stringify( post )
	);
	await page.close();

	// Race data without keys, from a timer with an older connector: by ID, as before.
	const noKeys = { subscribed: true, race_id: '5', race_title: 'Autumn Cup', pilot_id: '2', pilot_callsign: 'Ben' };
	page = await openPage( [ [ 2, 'Ben', '' ], [ 7, 'Cleo', '' ] ], noKeys, 2 );
	value = await button( page );
	check( 'without keys: the subscription\'s ID selected, it offers to unsubscribe', value === 'Unsubscribe', value );
	await page.close();

	page = await openPage( [ [ 2, 'Ben', '' ], [ 7, 'Cleo', '' ] ], noKeys, 7 );
	value = await button( page );
	check( '  another ID selected, it offers to move', value === 'Update Subscription', value );
	await page.click( '#subscribe-button' );
	await page.waitForFunction( () => window.__rmPosts.length > 0, null, { timeout: 15000 } );
	const plain = await page.evaluate( () => window.__rmPosts[ 0 ] );
	check( '  and sends an empty pilot key', plain.pilot_id === '7' && plain.pilot_key === '', JSON.stringify( plain ) );
	await page.close();

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

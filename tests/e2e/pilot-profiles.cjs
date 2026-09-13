/**
 * A pilot's nationality and photo (1.11.0), sent through the registration form on the development
 * site, as a pilot sends them.
 *
 *   npm run test:pilot-profiles
 *
 * Needs the DDEV site and ddev on the PATH: tests/e2e/pilot-profiles-site.php makes a race open for
 * registration and a page with the example registration form, hands over a photo as a phone stores
 * it, reports what the plugin made of the registration, and removes it all again. The form is Contact
 * Form 7's, sent from a real browser - the photo has to be taken from Contact Form 7 before it deletes
 * its uploads, which only a real submission shows.
 *
 * What has to hold:
 *   - the form offers the countries and takes a photo; it is sent;
 *   - the registration is stored with the country's code;
 *   - the profile has the country and a photo: square, at most 256 pixels, a JPEG, turned the way
 *     the phone meant it (red on top, blue at the bottom), without the camera's metadata - neither
 *     the EXIF block nor the Artist it carried;
 *   - the photos' directory lists nothing;
 *   - a race's files carry country and photo, the photo's URL with its version;
 *   - deleting the registration takes profile and photo away;
 *   - the confirmation mail names the race - its title, not its ID - with its dates, where it takes
 *     place and a map, its page, its calendar entry and its next-up view, and leaves no tag unfilled;
 *     the calendar entry is there to open (1.19.0; read from DDEV's Mailpit).
 *
 * Exit codes follow the other suites: 0 passed, 1 failed, 2 skipped.
 */

const path = require( 'path' );
const { execFile } = require( 'child_process' );

// ddev finds its project by walking up from the working directory; the repository sits one level
// below the project (docs/development-setup.md), and the container sees it at the path below.
const PROJECT = path.resolve( __dirname, '..', '..', '..' );
const HELPER = '/var/www/html/wp-racemanager/tests/e2e/pilot-profiles-site.php';
const MAIL = 'rm-e2e-profile@example.test';
// DDEV's Mailpit, which every mail of the development site goes to.
const MAILPIT = 'https://racemanager.ddev.site:8026';

const results = [];
function check( label, ok, detail ) {
	results.push( { label, ok } );
	process.stdout.write( `  ${ ok ? '\x1b[32mok\x1b[0m  ' : '\x1b[31mFAIL\x1b[0m' }  ${ label }\n` );
	if ( detail && ! ok ) {
		process.stdout.write( `          ${ String( detail ).slice( 0, 600 ) }\n` );
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
let request;
try {
	( { chromium, request } = require( 'playwright' ) );
} catch ( e ) {
	skip( 'playwright is not installed -- run "npm ci" in the plugin directory' );
}

// The site's half. Answers the JSON line it printed.
function site( ...args ) {
	return new Promise( ( resolve, reject ) => {
		execFile(
			'ddev',
			[ 'wp', 'eval-file', HELPER, ...args.map( String ) ],
			{ cwd: PROJECT, env: { ...process.env, MSYS_NO_PATHCONV: '1' }, maxBuffer: 1 << 22 },
			( error, stdout, stderr ) => {
				const line = String( stdout ).trim().split( /\r?\n/ ).reverse().find( ( l ) => l.startsWith( '{' ) );
				if ( error || ! line ) {
					reject( new Error( `ddev wp eval-file ${ args.join( ' ' ) }: ${ String( stderr || ( error && error.message ) ).trim() }` ) );
					return;
				}
				resolve( JSON.parse( line ) );
			}
		);
	} );
}

const red = ( [ r, g, b ] ) => r > 180 && g < 80 && b < 80;
const blue = ( [ r, g, b ] ) => b > 180 && r < 80 && g < 80;

( async () => {
	let state;
	try {
		state = await site( 'begin' );
	} catch ( e ) {
		skip( `the site cannot be prepared through ddev -- ${ e.message.split( '\n' )[ 0 ] }` );
	}

	let browser;
	try {
		try {
			browser = await chromium.launch();
		} catch ( e ) {
			skip( `no Chromium for this Playwright build -- run "npx playwright install chromium --only-shell"\n      ${ e.message.split( '\n' )[ 0 ] }` );
		}
		const tab = await ( await browser.newContext( { ignoreHTTPSErrors: true } ) ).newPage();
		const errors = [];
		tab.on( 'pageerror', ( e ) => errors.push( e.message ) );

		section( 'The photo, as a phone stores it' );
		const photo = await site( 'photo' );
		check( 'sideways, and saying so: EXIF orientation 6', 6 === photo.orientation, String( photo.orientation ) );
		check( 'with an Artist in its metadata', 'rm-e2e-canary' === photo.artist );

		section( 'Sent through the registration form' );
		await tab.goto( `${ state.url }?race_id=${ state.race }`, { waitUntil: 'networkidle' } );
		const countries = await tab.$$eval( 'select[name="pilot_country_1"] option', ( os ) => os.map( ( o ) => [ o.value, o.textContent ] ) );
		check( 'the form offers the countries, none chosen', countries.length === 251 && countries[ 0 ][ 0 ] === '' && countries.some( ( [ v, t ] ) => v === 'AT' && t ),
			`${ countries.length } options` );
		check( 'and takes a photo', ( await tab.$$( 'input[type="file"][name="pilot_photo_1"]' ) ).length === 1 );
		check( 'the race is the one chosen', String( state.race ) === await tab.$eval( 'select[name="race_id"]', ( s ) => s.value ) );
		await tab.fill( 'input[name="pilot_name_1"]', 'E2E Pilot' );
		await tab.fill( 'input[name="pilot_nickname_1"]', 'E2E-Photo' );
		await tab.fill( 'input[name="pilot_phone_1"]', '+43 660 0000000' );
		await tab.fill( 'input[name="pilot_mail_1"]', MAIL );
		await tab.selectOption( 'select[name="pilot_country_1"]', 'AT' );
		await tab.setInputFiles( 'input[name="pilot_photo_1"]', { name: 'phone.jpg', mimeType: 'image/jpeg', buffer: Buffer.from( photo.jpeg, 'base64' ) } );
		await tab.check( 'input[name="acceptance-pay"]' );
		await tab.check( 'input[name="acceptance-media"]' );
		const sentAfter = new Date( Date.now() - 2000 );
		await tab.click( 'form.wpcf7-form input[type="submit"]' );
		await tab.waitForFunction( () => {
			const form = document.querySelector( 'form.wpcf7-form' );
			return form && /\b(sent|failed|invalid|spam|aborted)\b/.test( form.className );
		}, null, { timeout: 30000 } );
		const status = await tab.$eval( 'form.wpcf7-form', ( f ) => `${ f.className } | ${ ( f.querySelector( '.wpcf7-response-output' ) || {} ).textContent || '' }` );
		check( 'it is sent', /\bsent\b/.test( status ), status );

		section( 'The confirmation mail (1.19.0)' );
		const mailpit = await request.newContext( { baseURL: MAILPIT, ignoreHTTPSErrors: true } );
		let message = null;
		for ( let i = 0; i < 20 && ! message; i++ ) {
			const found = await ( await mailpit.get( `/api/v1/search?query=${ encodeURIComponent( `to:${ MAIL }` ) }&limit=1` ) ).json().catch( () => ( {} ) );
			const hit = ( found.messages || [] )[ 0 ];
			if ( hit && new Date( hit.Created ) >= sentAfter ) {
				message = await ( await mailpit.get( `/api/v1/message/${ hit.ID }` ) ).json();
			} else {
				await tab.waitForTimeout( 500 );
			}
		}
		const text = message ? String( message.Text ).replace( /\r\n/g, '\n' ) : '';
		const m = state.mail;
		check( 'it came', !! message, `no mail to ${ MAIL } in Mailpit` );
		check( 'the race by its title, in the subject too, not by its ID',
			text.includes( `Rennen: ${ m.title }\n` ) && String( message && message.Subject ).includes( m.title ) && ! text.includes( `Rennen: ${ state.race }` ),
			`${ message && message.Subject } / ${ text.slice( 0, 400 ) }` );
		check( 'its dates', text.includes( `Datum: ${ m.dates }\n` ), `wanted "Datum: ${ m.dates }" in ${ text.slice( 0, 400 ) }` );
		check( 'where, and on a map',
			text.includes( 'Ort: E2E Halle\nTeststraße 1, 1010 Wien\n' ) && text.includes( 'Karte: https://www.google.com/maps/search/?api=1&query=E2E%20Halle%20Teststra%C3%9Fe%201%2C%201010%20Wien\n' ),
			text.slice( 0, 600 ) );
		check( 'its page, its calendar entry, its next-up view',
			text.includes( `Ausschreibung und Zeitplan: ${ m.url }\n` ) && text.includes( `In den Kalender: ${ m.calendar }\n` ) && text.includes( `Am Renntag: ${ m.nextup }\n` ),
			text.slice( 0, 900 ) );
		check( 'no tag left unfilled', ! /\[_?race/.test( text ), text );
		const ics = await mailpit.get( m.calendar );
		const body = await ics.text();
		check( 'the calendar entry opens: the race, its place, in UTC',
			200 === ics.status() && /^text\/calendar/.test( ics.headers()[ 'content-type' ] || '' ) && body.startsWith( 'BEGIN:VCALENDAR\r\n' )
				&& body.includes( `SUMMARY:${ m.title }\r\n` ) && body.includes( 'LOCATION:E2E Halle\\nTeststraße 1\\, 1010 Wien\r\n' ) && /DTSTART:\d{8}T\d{6}Z\r\n/.test( body ),
			`${ ics.status() } ${ ics.headers()[ 'content-type' ] } ${ body.slice( 0, 400 ) }` );
		await mailpit.dispose();

		section( 'What the plugin made of it' );
		const got = await site( 'inspect', MAIL );
		check( 'the registration is stored, with the country\'s code', 1 === got.registrations && 'AT' === got.stored.country, JSON.stringify( got.stored ) );
		check( 'the profile has the country and a photo', got.profile && 'AT' === got.profile.country && /^[0-9a-f]{8}$/.test( got.profile.photo || '' ), JSON.stringify( got.profile ) );
		const p = got.photo || {};
		check( 'the photo: a square JPEG of 256 pixels', 256 === p.width && 256 === p.height && 'image/jpeg' === p.mime, JSON.stringify( { w: p.width, h: p.height, mime: p.mime } ) );
		check( 'turned the way the phone meant it: red on top, blue at the bottom', red( p.top || [] ) && blue( p.bottom || [] ),
			JSON.stringify( { top: p.top, bottom: p.bottom, left: p.left, right: p.right } ) );
		check( 'without the camera\'s metadata: no EXIF block, no Artist', false === p.exif && false === p.artist, JSON.stringify( { exif: p.exif, artist: p.artist } ) );
		check( 'the profile names this very photo', got.profile && got.profile.photo === p.version, `${ got.profile && got.profile.photo } / ${ p.version }` );
		check( 'the photos\' directory lists nothing', true === got.listing );
		const carried = got.race[ got.key ] || {};
		check( 'a race\'s files carry country and photo, with its version',
			'AT' === carried.country && `${ got.photo_url }${ got.key }.jpg?v=${ p.version }` === carried.photo, JSON.stringify( carried ) );
		check( 'no page error', ! errors.length, errors.join( ' | ' ) );

		section( 'Deleting the registration' );
		const gone = await site( 'delete', MAIL );
		check( 'takes profile and photo away', 1 === gone.deleted && null === gone.profile && false === gone.photo, JSON.stringify( gone ) );

		// The site above uses one image editor; a host may have the other. WordPress's Imagick keeps
		// EXIF on purpose, which only the plugin's own filter takes off - found here, on 1.11.0's
		// first run.
		for ( const editor of [ 'gd', 'imagick' ] ) {
			section( `The same photo with ${ editor }` );
			const run = await site( 'editor', editor );
			if ( ! run.available ) {
				process.stdout.write( `          not on this site\n` );
				continue;
			}
			const q = run.photo || {};
			check( 'stored: a square JPEG of 256 pixels', /^[0-9a-f]{8}$/.test( run.version ) && 256 === q.width && 256 === q.height && 'image/jpeg' === q.mime, JSON.stringify( run ) );
			check( 'upright', red( q.top || [] ) && blue( q.bottom || [] ), JSON.stringify( { top: q.top, bottom: q.bottom } ) );
			check( 'without metadata', false === q.exif && false === q.artist, JSON.stringify( { exif: q.exif, artist: q.artist } ) );
		}
	} finally {
		const end = await site( 'end' ).catch( ( e ) => ( { error: e.message } ) );
		check( 'the race, the form and the page removed again', end.removed && end.removed.race && end.removed.form && end.removed.page, JSON.stringify( end ) );
		if ( browser ) {
			await browser.close();
		}
	}

	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write( `\n${ '-'.repeat( 62 ) }\n  ${ results.length - failed } passed, ${ failed } failed\n` );
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	process.stderr.write( `\nHARNESS ERROR: ${ e && e.stack ? e.stack : e }\n` );
	process.exit( 1 );
} );

/**
 * js/class_templates_V1.js: the bracket templates rm-m-displayHeats.js lays a class out on.
 *
 *   npm run test:class-templates
 *
 * No browser and no WordPress: the file is plain data, evaluated here.
 *
 * A template gives the races their places, their parents and what fills them: each entry a seeding
 * label, a seed position ("16th") or a result ("2nd race 1"), numbered through the template. The
 * heats write their slots over those entries one by one, so an entry beyond a heat's slots stays.
 * Two races of the 32-pilot template carried four test pilots instead, "TestPilot1" to "4", both
 * under the IDs 49-52: on the live pages a race with three slots showed "TestPilot4", and on the
 * timer's /bracketview, whose copy had the names blanked, the leftover still counted as pilot 49
 * to 52 when filtering by a pilot and on hover.
 *
 * Exit codes follow the PHP suites: 0 passed, 1 failed.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const FILE = path.resolve( __dirname, '..', '..', 'js', 'class_templates_V1.js' );

const results = [];
function check( label, ok, detail ) {
	results.push( { label, ok } );
	process.stdout.write( `  ${ ok ? '\x1b[32mok\x1b[0m  ' : '\x1b[31mFAIL\x1b[0m' }  ${ label }\n` );
	if ( detail ) {
		process.stdout.write( `          ${ String( detail ).slice( 0, 300 ) }\n` );
	}
}

// The file declares consts for a classic script; hand them back out of the sandbox.
const templates = vm.runInNewContext(
	fs.readFileSync( FILE, 'utf8' ) + '\n;({ de32_template, de16_template, default_template })'
);

for ( const name of [ 'de16_template', 'de32_template' ] ) {
	const nodes = templates[ name ];
	check( `${ name } is a list of races`, Array.isArray( nodes ) && nodes.length > 0, typeof nodes );
	const ids = nodes.map( ( node ) => node.id );
	check( `${ name }: every race has an ID of its own`, new Set( ids ).size === ids.length );
	const entries = nodes.flatMap( ( node ) => ( Array.isArray( node.pilots ) ? node.pilots : [ null ] ).map( ( entry ) => ( { race: node.id, entry } ) ) );
	const unlabelled = entries.filter( ( { entry } ) => ! entry || ! /^\d+(st|nd|rd|th)( race \d+)?$/.test( entry.name ) );
	check(
		`${ name }: every entry is a seeding label, no pilot`,
		unlabelled.length === 0,
		unlabelled.map( ( { race, entry } ) => `race ${ race }: ${ JSON.stringify( entry ) }` ).join( '; ' )
	);
	const entryIds = entries.filter( ( { entry } ) => entry ).map( ( { entry } ) => entry.id );
	check( `${ name }: every entry has an ID of its own`, new Set( entryIds ).size === entryIds.length, `${ entryIds.length } entries, ${ new Set( entryIds ).size } IDs` );
}
check( 'default_template is empty', Array.isArray( templates.default_template ) && templates.default_template.length === 0 );

const failed = results.filter( ( r ) => ! r.ok ).length;
process.stdout.write(
	`\n--------------------------------------------------------------\n` +
	`  ${ results.length - failed } passed, ${ failed } failed\n`
);
process.exit( failed ? 1 : 0 );

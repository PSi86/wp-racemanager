/**
 * js/rm-m-bracketStandings.js: a bracket's final standing, with the round each pilot went out in.
 *
 *   npm run test:bracket-standings
 *
 * Node only. The races are RotorHazard's own plans (tests/fixtures/brackets), flown here with a
 * lower pilot number as the faster pilot unless a heat's order is given; and, when the local site
 * has them, the three real events of 2025, against the ranking this plugin computed for them
 * before 1.10.0 (tests/fixtures/brackets/old-ranking.cjs) as the oracle.
 *
 * What has to hold:
 *   - every pilot of a flown bracket gets one place, 1..N, N the pilots in its first round - also
 *     for a bracket that is not full;
 *   - the ranges are those of the rulebooks: 1-4 final, 5-6, 7-8, 9-12, 13-16, 17-24, 25-32 for
 *     FAI 32 double elimination; 5-8 small final in single elimination;
 *   - a round still running gives those already out its range, counted from the bottom;
 *   - inside a round of several heats FAI orders by qualifying rank, MultiGP by the rank in the
 *     heat first;
 *   - Chase the Ace: the timer's ranking as it is; without one two round wins, then points;
 *     undecided, the final four without places;
 *   - a bracket changed by hand gets a notice instead of a standing;
 *   - on the real events, places 5 to N are those the old ranking gave - except where two pilots
 *     nothing tells apart share a place (no qualifying result, the same rank in their heats),
 *     which the old ranking split by heat order.
 *
 * Exit codes follow the other suites: 0 passed, 1 failed, 2 skipped.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { pathToFileURL } = require( 'url' );
const races = require( '../fixtures/brackets/races.cjs' );
const { computeLeaderboard } = require( '../fixtures/brackets/old-ranking.cjs' );

const PLUGIN_DIR = path.resolve( __dirname, '..', '..' );
const REAL_RACES = path.resolve( PLUGIN_DIR, '..', 'wp-app', 'wp-content', 'uploads', 'races' );

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

const place = ( row ) => ( row.placeFrom === row.placeTo ? `${ row.placeFrom }` : `${ row.placeFrom }-${ row.placeTo }` );
const byPilot = ( standing ) => new Map( standing.rows.map( ( r ) => [ r.pilotId, r ] ) );
// "place:label" per range, in order, e.g. "5-6 LB Round 6"
const ranges = ( standing ) => {
	const out = [];
	for ( const row of standing.rows ) {
		const key = `${ row.roundLabel }`;
		const last = out[ out.length - 1 ];
		if ( last && last.label === key ) {
			last.to = row.placeTo;
		} else {
			out.push( { label: key, from: row.placeFrom, to: row.placeTo } );
		}
	}
	return out.map( ( r ) => `${ r.from }-${ r.to } ${ r.label }` ).join( ', ' );
};

( async () => {
	const model = await import( pathToFileURL( path.join( PLUGIN_DIR, 'js', 'rm-m-bracketModel.js' ) ).href );
	const { computeStandings } = await import( pathToFileURL( path.join( PLUGIN_DIR, 'js', 'rm-m-bracketStandings.js' ) ).href );
	const standingOf = ( data ) => computeStandings( data, model.buildBracket( data, races.BRACKET ) );
	// Every pilot once, places as competitions count them: 1, 2, 2, 4 for a shared second.
	const validStanding = ( standing, n ) => {
		const places = standing.rows.map( ( r ) => r.placeFrom ).sort( ( a, b ) => a - b );
		return places.length === n && new Set( standing.rows.map( ( r ) => r.pilotId ) ).size === n &&
			standing.rows.every( ( r ) => r.placeFrom === r.placeTo ) &&
			places.every( ( p, i ) => p === i + 1 || ( i > 0 && p === places[ i - 1 ] ) );
	};
	const everyPlaceOnce = ( standing, n ) => {
		const places = standing.rows.map( ( r ) => r.placeFrom ).sort( ( a, b ) => a - b );
		return places.length === n && places.every( ( p, i ) => p === i + 1 ) && standing.rows.every( ( r ) => r.placeFrom === r.placeTo );
	};

	section( 'A flown FAI 32 double elimination' );
	{
		const s = standingOf( races.fly( races.raceFromPlan( 'double-fai32' ) ) );
		check( 'all 32 pilots, places 1 to 32, each once', everyPlaceOnce( s, 32 ), s.rows.map( place ).join( ' ' ) );
		check( 'the rulebook ranges, with the round each went out in',
			ranges( s ) === '1-4 Grand Final, 5-6 LB Round 6, 7-8 LB Round 5, 9-12 LB Round 4, 13-16 LB Round 3, 17-24 LB Round 2, 25-32 LB Round 1',
			ranges( s ) );
	}

	section( 'A bracket that is not full' );
	for ( const pilots of [ 22, 12 ] ) {
		const s = standingOf( races.fly( races.raceFromPlan( 'double-fai32', { pilots } ) ) );
		check( `FAI 32 with ${ pilots } pilots: places 1 to ${ pilots }, each once`, everyPlaceOnce( s, pilots ), s.rows.map( place ).join( ' ' ) );
	}

	section( 'Single elimination' );
	{
		const s = standingOf( races.fly( races.raceFromPlan( 'single-fai16' ) ) );
		check( 'FAI 16: final 1-4, small final 5-8, quarterfinals 9-16', ranges( s ) === '1-4 Final, 5-8 Small Final, 9-16 Quarterfinals' && everyPlaceOnce( s, 16 ), ranges( s ) );
	}

	section( 'While the bracket runs' );
	{
		// FAI 32: LB Round 1 is heats 22-25 (Race 13-16); LB Round 2 is heats 26-29 (Race 17-20).
		const s = standingOf( races.fly( races.raceFromPlan( 'double-fai32' ), { untilHeatId: 27 } ) );
		const r1 = s.rows.filter( ( r ) => r.roundLabel === 'LB Round 1' );
		const r2 = s.rows.filter( ( r ) => r.roundLabel === 'LB Round 2' );
		check( 'LB Round 1 flown: eight single places 25 to 32', r1.length === 8 && r1.every( ( r ) => r.placeFrom === r.placeTo && r.placeFrom >= 25 ), r1.map( place ).join( ' ' ) );
		check( 'LB Round 2 half flown: those out so far share 17-24', r2.length === 4 && r2.every( ( r ) => r.placeFrom === 17 && r.placeTo === 24 ), r2.map( place ).join( ' ' ) );
		check( 'nobody above is placed yet', s.rows.length === 12, `${ s.rows.length } rows` );
	}

	section( 'A timer with more nodes than the heats seat' );
	{
		// RotorHazard gives every heat a slot per node; an 8-node timer's 4-up heats carry four
		// empty slots without a method. They hold nobody, so they are no places.
		const running = standingOf( races.fly( races.raceFromPlan( 'double-fai32', { nodes: 8 } ), { untilHeatId: 27 } ) );
		const r2 = running.rows.filter( ( r ) => r.roundLabel === 'LB Round 2' );
		check( 'LB Round 2 half flown: still 17-24', r2.length === 4 && r2.every( ( r ) => r.placeFrom === 17 && r.placeTo === 24 ), r2.map( place ).join( ' ' ) );
		const done = standingOf( races.fly( races.raceFromPlan( 'double-fai32', { nodes: 8 } ) ) );
		check( 'flown: places 1 to 32, each once', everyPlaceOnce( done, 32 ), done.rows.map( place ).join( ' ' ) );
	}

	section( 'Order inside a round of several heats' );
	{
		// LB Round 4 (Race 25/26 = heats 34/35) of FAI 32: reverse heat 34's order so that its fourth
		// has the better qualifying rank than heat 35's third.
		const build = ( key ) => {
			const data = races.raceFromPlan( key );
			races.fly( data, { untilHeatId: 33 } );
			const heat34 = data.heat_data.heats.find( ( h ) => h.id === 34 );
			for ( const slot of heat34.slots ) {
				slot.pilot_id = null;
			}
			races.fly( data, { untilHeatId: 34 } );
			const order = [ ...data.result_data.heats[ 34 ].leaderboard.by_race_time.map( ( e ) => e.pilot_id ) ].reverse();
			return races.fly( data, { finish: { 34: order } } );
		};
		const fai = standingOf( build( 'double-fai32' ) );
		const group = ( s ) => s.rows.filter( ( r ) => r.roundLabel === 'LB Round 4' );
		const faiGroup = group( fai );
		check( 'FAI: by qualifying rank', faiGroup.map( ( r ) => r.pilotId ).join( ',' ) === [ ...faiGroup ].sort( ( a, b ) => a.pilotId - b.pilotId ).map( ( r ) => r.pilotId ).join( ',' ),
			faiGroup.map( ( r ) => `${ place( r ) } P${ r.pilotId } rank ${ r.rank }` ).join( ' | ' ) );
		// The same bracket under MultiGP rules: the rulebook from Class Rank: Brackets' setting, as
		// without a generator's record.
		const mgpData = build( 'double-fai32' );
		mgpData.class_data.classes[ 1 ].generate_args = null;
		mgpData.class_data.classes[ 1 ].win_condition = 'Brackets';
		mgpData.class_data.classes[ 1 ].ranksettings = { bracket_type: 'MultiGP' };
		const mgp = standingOf( mgpData );
		const mgpGroup = group( mgp );
		check( 'MultiGP: thirds before fourths, then qualifying rank',
			mgpGroup.map( ( r ) => r.rank ).join( ',' ) === '3,3,4,4',
			mgpGroup.map( ( r ) => `${ place( r ) } P${ r.pilotId } rank ${ r.rank }` ).join( ' | ' ) );
	}

	section( 'Chase the Ace' );
	{
		const flown = () => races.fly( races.raceFromPlan( 'double-fai16' ) );
		const finalId = model.buildBracket( flown(), races.BRACKET ).finalId;
		const undecided = standingOf( races.chaseTheAce( flown(), finalId, [ [ 1, 2, 3, 4 ], [ 2, 1, 3, 4 ] ] ) );
		const top = undecided.rows.filter( ( r ) => r.roundLabel === 'Grand Final' );
		check( 'undecided: the final four without places, the rest placed', top.length === 4 && top.every( ( r ) => r.placeFrom === null ) && undecided.rows.filter( ( r ) => r.placeFrom !== null ).length === 12,
			undecided.rows.map( ( r ) => `${ place( r ) } P${ r.pilotId }` ).join( ' | ' ) );
		const byRounds = standingOf( races.chaseTheAce( flown(), finalId, [ [ 1, 2, 3, 4 ], [ 2, 1, 4, 3 ], [ 2, 3, 1, 4 ] ] ) );
		// P2 wins twice. Points 1/2/3/4: P1 1+2+3 = 6, P3 3+4+2 = 9, P4 4+3+4 = 11.
		check( 'two wins decide, then points', byRounds.rows.slice( 0, 4 ).map( ( r ) => r.pilotId ).join( ',' ) === '2,1,3,4' && everyPlaceOnce( byRounds, 16 ),
			byRounds.rows.slice( 0, 4 ).map( ( r ) => `${ place( r ) } P${ r.pilotId }` ).join( ' | ' ) );
		// P1 wins rounds 1 and 2. P2 and P3 then have 5 points each, and the deciding round puts P3
		// first. A third round flown after it would put P2 ahead (7 points to 8); Class Rank: Brackets
		// stops at the round that decided.
		const after = standingOf( races.chaseTheAce( flown(), finalId, [ [ 1, 2, 3, 4 ], [ 1, 3, 2, 4 ], [ 4, 2, 3, 1 ] ] ) );
		check( 'a round flown after the decision does not count', after.rows.slice( 0, 4 ).map( ( r ) => r.pilotId ).join( ',' ) === '1,3,2,4',
			after.rows.slice( 0, 4 ).map( ( r ) => `${ place( r ) } P${ r.pilotId }` ).join( ' | ' ) );
		const timer = standingOf( races.chaseTheAce( flown(), finalId, [ [ 4, 3, 2, 1 ] ], { ranking: [ 4, 3, 2, 1 ] } ) );
		check( "the timer's ranking as it is", timer.source === 'timer' && timer.rows.slice( 0, 4 ).map( ( r ) => r.pilotId ).join( ',' ) === '4,3,2,1',
			JSON.stringify( timer.rows.slice( 0, 4 ) ) );
		check( 'with the round each went out in', timer.rows[ 0 ].roundLabel === 'Grand Final', timer.rows[ 0 ].roundLabel );
	}

	section( 'A bracket changed by hand' );
	{
		const data = races.fly( races.raceFromPlan( 'double-fai16' ), { untilHeatId: 15 } );
		const heat = data.heat_data.heats.find( ( h ) => h.id === 16 );
		heat.slots[ 0 ] = { ...heat.slots[ 0 ], method: 0, pilot_id: 3, seed_id: null, seed_rank: null };
		const s = standingOf( data );
		check( 'a notice, no standing', s.rows.length === 0 && /by hand/.test( s.notice || '' ), JSON.stringify( s ) );
	}

	section( 'The events on the local site, against the ranking of 1.9' );
	const real = [ 32, 33, 34 ].map( ( id ) => path.join( REAL_RACES, `${ id }-data.json` ) ).filter( ( f ) => fs.existsSync( f ) );
	if ( ! real.length ) {
		process.stdout.write( '  (none here - skipped)\n' );
	}
	for ( const file of real ) {
		const data = JSON.parse( fs.readFileSync( file, 'utf8' ) );
		const cls = data.class_data.classes.find( ( c ) => c.name === 'Elimination' );
		const bracket = model.buildBracket( data, cls.id );
		const s = computeStandings( data, bracket );
		const old = computeLeaderboard( data );
		const pilots = new Set( bracket.heats.filter( ( h ) => ! h.parents.length ).flatMap( ( h ) => ( model.heatBoard( data, h.id ) || [] ).map( ( e ) => e.pilot_id ) ) );
		const mine = byPilot( s );
		const sharing = ( at ) => s.rows.filter( ( r ) => r.placeFrom === at ).length;
		const same = ( e ) => {
			const row = mine.get( e.pilot_id );
			return row && ( row.placeFrom === e.place || ( sharing( row.placeFrom ) > 1 && e.place >= row.placeFrom && e.place < row.placeFrom + sharing( row.placeFrom ) ) );
		};
		const diff = old.filter( ( e ) => e.place >= 5 ).filter( ( e ) => ! same( e ) );
		check( `${ path.basename( file ) }: places 5 to N as the old ranking gave them (${ old.length } places)`, old.length > 0 && ! diff.length,
			diff.map( ( e ) => `P${ e.pilot_id } old ${ e.place } new ${ mine.has( e.pilot_id ) ? place( mine.get( e.pilot_id ) ) : '-' }` ).join( ' | ' ) );
		check( `${ path.basename( file ) }: every pilot of the first round placed once, 1 to ${ pilots.size }`, validStanding( s, pilots.size ), s.rows.map( place ).join( ' ' ) );
	}

	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write( `\n${ '-'.repeat( 62 ) }\n  ${ results.length - failed } passed, ${ failed } failed\n` );
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	process.stderr.write( `\nHARNESS ERROR: ${ e && e.stack ? e.stack : e }\n` );
	process.exit( 1 );
} );

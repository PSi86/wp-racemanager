/**
 * js/rm-m-bracketModel.js: the bracket a class's heats form, worked out from their seeding.
 *
 *   npm run test:bracket-model
 *
 * Node only - no browser, no WordPress. The races come from RotorHazard's own heat plans
 * (tests/fixtures/brackets/plans.json, RotorHazard 4.4.0) built into heats as its generator
 * does; and, when the local site has them, from the three real events in
 * ../wp-app/wp-content/uploads/races (never committed - they carry real names).
 *
 * What has to hold:
 *   - every regulation bracket RotorHazard ships comes out as the bracket it is: single or
 *     double, winners, losers, grand final and small final where they are, round names as
 *     DRSK and the rulebooks call them (Round 1, Quarterfinals, Semifinals, Winners Final,
 *     LB Round n, Grand Final);
 *   - a ladder, a ranked fill and a class without seeding are no bracket;
 *   - the layout never puts two heats on one grid position, and every line runs left to right;
 *   - a hand-edited, circular, doubled or mismatched bracket is reported, never thrown;
 *   - seeds resolve by index, as RotorHazard seeds;
 *   - Chase the Ace: the timer's ranking decides, else two round wins; off unless the class
 *     ranks with "Brackets";
 *   - the seat a pilot will likely get is the one RotorHazard's automatic frequency assignment
 *     (its default, adaptive calibration) gives without drawing lots, from the seats flown on
 *     before, by start time - as rm_likely_seats() in PHP, whose suite (nextup-schedule) has the
 *     same cases (1.15.0).
 *
 * Exit codes follow the other suites: 0 passed, 1 failed, 2 skipped.
 */

const fs = require( 'fs' );
const path = require( 'path' );
const { pathToFileURL } = require( 'url' );
const races = require( '../fixtures/brackets/races.cjs' );

const PLUGIN_DIR = path.resolve( __dirname, '..', '..' );
const REAL_RACES = path.resolve( PLUGIN_DIR, '..', 'wp-app', 'wp-content', 'uploads', 'races' );

const results = [];
function check( label, ok, detail ) {
	results.push( { label, ok } );
	process.stdout.write( `  ${ ok ? '\x1b[32mok\x1b[0m  ' : '\x1b[31mFAIL\x1b[0m' }  ${ label }\n` );
	if ( detail && ! ok ) {
		process.stdout.write( `          ${ String( detail ).slice( 0, 500 ) }\n` );
	}
}
function section( title ) {
	process.stdout.write( `\n${ title }\n` );
}

const pattern = ( bracket ) => bracket.heats.map( ( h ) => h.group ).join( ' ' );
const names = ( bracket ) => bracket.heats.map( ( h ) => h.roundName );

// Every heat on its own grid position, and every line left to right (a line from the losers
// side only into the grand final).
function layoutProblems( model, bracket ) {
	const problems = [];
	const { sections, edges } = model.layout( bracket );
	const where = new Map();
	for ( const s of sections ) {
		const seen = new Set();
		for ( const cell of s.cells ) {
			const key = `${ cell.col }:${ cell.row }`;
			if ( seen.has( key ) ) {
				problems.push( `${ s.key } ${ key } twice` );
			}
			seen.add( key );
			where.set( cell.heatId, { section: s.key, col: cell.col } );
		}
	}
	if ( where.size !== bracket.heats.length ) {
		problems.push( `${ where.size } of ${ bracket.heats.length } heats placed` );
	}
	for ( const edge of edges ) {
		const a = where.get( edge.from );
		const b = where.get( edge.to );
		if ( ! a || ! b ) {
			problems.push( `edge ${ edge.from }->${ edge.to } without a cell` );
		} else if ( a.col >= b.col ) {
			problems.push( `edge ${ edge.from }->${ edge.to } runs right to left (${ a.col } -> ${ b.col })` );
		} else if ( a.section !== b.section && bracket.byId.get( edge.to ).group !== 'F' ) {
			problems.push( `edge ${ edge.from }->${ edge.to } crosses sections` );
		}
	}
	return problems;
}

( async () => {
	const model = await import( pathToFileURL( path.join( PLUGIN_DIR, 'js', 'rm-m-bracketModel.js' ) ).href );
	const build = ( data ) => model.buildBracket( data, races.BRACKET );

	section( 'Every regulation bracket RotorHazard ships' );
	const expected = {
		'single-fai16': { type: 'single', pattern: 'W W W W W W SF W' },
		'single-fai32': { type: 'single', pattern: `${ 'W '.repeat( 14 ) }SF W` },
		'single-fai64': { type: 'single', pattern: `${ 'W '.repeat( 30 ) }SF W` },
		'double-fai16': { type: 'double', pattern: 'W W W W L L W W L L L W L F' },
		'double-fai32': { type: 'double', pattern: 'W W W W W W W W W W W W L L L L L L L L L L W W L L L W L F' },
		'double-multigp16': { type: 'double', pattern: 'W W W W L W L W L L W L L F' },
	};
	for ( const [ key, want ] of Object.entries( expected ) ) {
		const bracket = build( races.raceFromPlan( key ) );
		check( `${ key }: ${ want.type }, groups as the plan has them`,
			bracket.ok && bracket.type === want.type && pattern( bracket ) === want.pattern && ! bracket.irregular.length,
			`${ bracket.type } ${ pattern( bracket ) } ${ bracket.irregular.join( '; ' ) } ${ bracket.reason || '' }` );
		const last = bracket.heats[ bracket.heats.length - 1 ];
		check( `${ key }: the last heat is the final`, bracket.finalId === last.id, `finalId ${ bracket.finalId }, last ${ last.id }` );
		const problems = layoutProblems( model, bracket );
		check( `${ key }: laid out without overlap, lines left to right`, ! problems.length, problems.join( '; ' ) );
	}
	{
		const bracket = build( races.raceFromPlan( 'double-fai64' ) );
		const count = ( g ) => bracket.heats.filter( ( h ) => h.group === g ).length;
		check( 'double-fai64: 62 heats, 31 winners, 30 losers, one grand final',
			bracket.type === 'double' && count( 'W' ) === 31 && count( 'L' ) === 30 && count( 'F' ) === 1, pattern( bracket ) );
		check( 'double-fai64: laid out without overlap', ! layoutProblems( model, bracket ).length, layoutProblems( model, bracket ).join( '; ' ) );
	}

	section( 'Round names' );
	{
		const bracket = build( races.raceFromPlan( 'double-fai32' ) );
		const want = [
			...Array( 8 ).fill( 'Round 1' ), ...Array( 4 ).fill( 'Quarterfinals' ),
			...Array( 4 ).fill( 'LB Round 1' ), ...Array( 4 ).fill( 'LB Round 2' ), ...Array( 2 ).fill( 'LB Round 3' ),
			'Semifinals', 'Semifinals', 'LB Round 4', 'LB Round 4', 'LB Round 5', 'Winners Final', 'LB Round 6', 'Grand Final',
		];
		check( 'FAI 32 double elimination, as DRSK names its rounds', JSON.stringify( names( bracket ) ) === JSON.stringify( want ), names( bracket ).join( ', ' ) );
		const bracket16 = build( races.raceFromPlan( 'single-fai16' ) );
		check( 'FAI 16 single elimination',
			JSON.stringify( names( bracket16 ) ) === JSON.stringify( [ 'Quarterfinals', 'Quarterfinals', 'Quarterfinals', 'Quarterfinals', 'Semifinals', 'Semifinals', 'Small Final', 'Final' ] ),
			names( bracket16 ).join( ', ' ) );
		const { sections } = model.layout( bracket );
		check( 'the winners section has a column header per round, the grand final last',
			sections[ 0 ].headers.map( ( h ) => h.label ).join( ', ' ) === 'Round 1, Quarterfinals, Semifinals, Winners Final, Grand Final',
			sections[ 0 ].headers.map( ( h ) => h.label ).join( ', ' ) );
		check( 'the losers section has LB Round 1 to 6', sections[ 1 ].headers.map( ( h ) => h.label ).join( ', ' ) === 'LB Round 1, LB Round 2, LB Round 3, LB Round 4, LB Round 5, LB Round 6',
			sections[ 1 ].headers.map( ( h ) => h.label ).join( ', ' ) );
	}

	section( 'No bracket' );
	for ( const key of [ 'ladder-12', 'ladder-7', 'ranked-fill-12' ] ) {
		const bracket = build( races.raceFromPlan( key ) );
		check( `${ key } is drawn as a row`, ! bracket.ok && bracket.type === 'none', `${ bracket.type } ${ bracket.reason }` );
	}
	{
		const data = races.raceFromPlan( 'single-fai16' );
		check( 'a class without heats', ! model.buildBracket( data, 99 ).ok );
	}

	section( 'Brackets the timer changed' );
	{
		const data = races.fly( races.raceFromPlan( 'double-fai16' ), { untilHeatId: 15 } );
		const heat = data.heat_data.heats.find( ( h ) => h.id === 16 );
		heat.slots[ 0 ] = { ...heat.slots[ 0 ], method: 0, pilot_id: 3, seed_id: null, seed_rank: null };
		const bracket = build( data );
		check( 'a pilot put into a later heat by hand is reported, the bracket still drawn',
			bracket.ok && bracket.irregular.some( ( r ) => /by hand/.test( r ) ), bracket.irregular.join( '; ' ) );
	}
	{
		const data = races.raceFromPlan( 'double-fai16' );
		data.heat_data.heats.push( { id: 99, displayname: 'Other', class_id: races.QUALIFYING, slots: [] } );
		data.heat_data.heats.find( ( h ) => h.id === 23 ).slots[ 0 ].seed_id = 99;
		const bracket = build( data );
		check( 'a seed from another class is reported', bracket.ok && bracket.irregular.some( ( r ) => /another class/.test( r ) ), bracket.irregular.join( '; ' ) );
	}
	{
		const data = races.raceFromPlan( 'single-fai16' );
		const [ a, b ] = data.heat_data.heats;
		a.slots[ 0 ] = { ...a.slots[ 0 ], method: 1, seed_id: b.id, seed_rank: 1 };
		b.slots[ 0 ] = { ...b.slots[ 0 ], method: 1, seed_id: a.id, seed_rank: 1 };
		let bracket;
		let thrown = null;
		try {
			bracket = build( data );
		} catch ( e ) {
			thrown = e.message;
		}
		check( 'heats seeding each other in a circle: a row, no throw', ! thrown && ! bracket.ok && /circle/.test( bracket.reason ), thrown || bracket.reason );
	}
	{
		const data = races.raceFromPlan( 'single-fai16' );
		const second = races.raceFromPlan( 'single-fai16', { firstHeatId: 40 } );
		data.heat_data.heats.push( ...second.heat_data.heats );
		const bracket = build( data );
		check( 'a generator run twice into one class: a row', ! bracket.ok && /more than one/.test( bracket.reason ), bracket.reason );
	}
	{
		const data = races.raceFromPlan( 'double-fai16' );
		data.class_data.classes[ 1 ].generate_args.standard = 'fai32';
		const bracket = build( data );
		check( "a generator's record that does not match the heats is reported", bracket.ok && bracket.irregular.some( ( r ) => /record/.test( r ) ), bracket.irregular.join( '; ' ) );
		const without = build( races.raceFromPlan( 'double-fai16', { record: false } ) );
		check( 'without a record, nothing to report', without.ok && ! without.irregular.length, without.irregular.join( '; ' ) );
	}

	section( 'Rulebook' );
	check( 'FAI by the record', build( races.raceFromPlan( 'double-fai16' ) ).rulebook === 'fai' );
	check( 'MultiGP by the record', build( races.raceFromPlan( 'double-multigp16' ) ).rulebook === 'multigp' );
	check( 'FAI without a record', build( races.raceFromPlan( 'double-multigp16', { record: false } ) ).rulebook === 'fai' );

	section( 'Seeds resolve by index' );
	{
		const data = races.fly( races.raceFromPlan( 'double-fai16' ), { untilHeatId: 10, dns: [ 9 ] } );
		// Heat 10 took P1, P8, P9, P16; P9 never started, so the board reads P1, P8, P16, P9.
		const r = model.resolveSeed( data, { method: 1, seed_id: 10, seed_rank: 4 } );
		check( 'rank 4 of a heat whose fourth entry never started is that entry', r.pilotId === 9, JSON.stringify( r ) );
		const q = model.resolveSeed( data, { method: 2, seed_id: races.QUALIFYING, seed_rank: 7 } );
		check( 'a class seed takes the class board', q.pilotId === 7, JSON.stringify( q ) );
		const open = model.resolveSeed( data, { method: 1, seed_id: 12, seed_rank: 2 } );
		check( 'a heat not yet flown gives its name and the rank', ! open.pilotId && open.label === 'Race 3 #2', JSON.stringify( open ) );
		check( 'the first round knows each pilot\'s qualifying rank', build( data ).qualRank.get( 16 ) === 16 );
	}

	section( 'Chase the Ace' );
	{
		const base = () => races.fly( races.raceFromPlan( 'double-fai16' ) );
		const bracket = build( base() );
		const finalId = bracket.finalId;
		const off = model.ctaState( base(), bracket );
		check( 'off without the Brackets ranking method', ! off.enabled );
		const undecided = races.chaseTheAce( base(), finalId, [ [ 1, 2, 3, 4 ], [ 2, 1, 3, 4 ] ] );
		let s = model.ctaState( undecided, build( undecided ) );
		check( 'two rounds, two winners: not decided', s.enabled && s.rounds === 2 && ! s.decided && s.wins.get( 1 ) === 1 && s.wins.get( 2 ) === 1, JSON.stringify( [ ...s.wins ] ) );
		const byRounds = races.chaseTheAce( base(), finalId, [ [ 1, 2, 3, 4 ], [ 2, 1, 3, 4 ], [ 2, 3, 1, 4 ] ] );
		s = model.ctaState( byRounds, build( byRounds ) );
		check( 'two wins decide without a ranking', s.decided && s.winnerId === 2 && s.source === 'rounds', JSON.stringify( s ) );
		// P1 has won twice after round 2; P4 wins a third round flown after that. Class Rank:
		// Brackets stops at the round that decided, and so does the count.
		const afterDecision = races.chaseTheAce( base(), finalId, [ [ 1, 2, 3, 4 ], [ 1, 3, 2, 4 ], [ 4, 2, 3, 1 ] ] );
		s = model.ctaState( afterDecision, build( afterDecision ) );
		check( 'a round flown after the decision does not count', s.decided && s.winnerId === 1 && s.rounds === 2 && s.wins.get( 1 ) === 2 && ! s.wins.has( 4 ),
			JSON.stringify( { rounds: s.rounds, wins: [ ...s.wins ] } ) );
		const byRanking = races.chaseTheAce( base(), finalId, [ [ 1, 2, 3, 4 ] ], { ranking: [ 1, 2, 3, 4 ] } );
		s = model.ctaState( byRanking, build( byRanking ) );
		check( "the timer's ranking decides (Iron Man: one round)", s.decided && s.winnerId === 1 && s.source === 'ranking', JSON.stringify( s ) );
		const switchedOff = races.chaseTheAce( base(), finalId, [ [ 1, 2, 3, 4 ] ], { settings: { chase_the_ace: false } } );
		check( 'switched off in the ranking settings', ! model.ctaState( switchedOff, build( switchedOff ) ).enabled );
	}

	section( 'The seat a pilot will likely get' );
	{
		// Four seats, R1 R2 F2 F4; the rounds of other heats say who flew where before.
		const profile = () => [
			{ band: 'R', channel: 1, frequency: 5658 },
			{ band: 'R', channel: 2, frequency: 5695 },
			{ band: 'F', channel: 2, frequency: 5760 },
			{ band: 'F', channel: 4, frequency: 5800 },
		];
		const round = ( time, seats ) => ( {
			start_time_formatted: `2025-06-01 ${ time }`,
			nodes: Object.entries( seats ).map( ( [ pilot, seat ] ) => ( { pilot_id: Number( pilot ), node_index: seat } ) ),
		} );
		const seatData = ( rounds, fdata = profile() ) => ( {
			frequency_data: { fdata },
			result_data: { heats: Object.fromEntries( Object.entries( rounds ).map( ( [ id, list ] ) => [ id, { heat_id: Number( id ), rounds: list } ] ) ) },
		} );
		// Asked only where they exist, so that against a model without them every check still runs.
		const has = typeof model.likelySeats === 'function' && typeof model.usedSeats === 'function';
		const seats = ( data, pilots ) => ( has ? [ ...model.likelySeats( data, pilots ) ].map( ( [ p, s ] ) => `${ p }@${ s }` ).sort().join( ',' ) : 'none' );

		const apart = { 60: [ round( '10:00:00.000', { 1: 2, 2: 0, 3: 3 } ) ] };
		let got = seats( seatData( apart ), [ 1, 2, 3 ] );
		check( 'each on a seat of their own before: that seat', got === '1@2,2@0,3@3', got );
		got = seats( seatData( { 60: [ round( '10:00:00.000', { 1: 1, 3: 3 } ), round( '10:05:00.000', { 2: 1 } ) ] } ), [ 1, 2, 3 ] );
		check( 'two from the same seat: lots for them, the third still told', got === '3@3', got );
		const rounds = {
			60: [ round( '11:00:00.000', { 2: 2 } ) ],
			61: [ round( '10:00:00.000', { 1: 1, 2: 0 } ), round( '10:30:00.000', { 1: 0, 3: 2 } ) ],
		};
		got = seats( seatData( rounds ), [ 1, 2, 3 ] );
		check( 'a seat only one of them flew on is theirs first, as RotorHazard gives them out', got === '1@1,2@0,3@2', got );
		const usedOf = ( data ) => ( has ? JSON.stringify( [ ...model.usedSeats( data ) ] ) : 'none' );
		const used = usedOf( seatData( rounds ) );
		check( 'the seats flown on go by start time, not by where the rounds are listed', used === '[[1,[1,0]],[2,[0,2]],[3,[2]]]', used );
		const again = usedOf( seatData( { 60: [ round( '09:00:00.000', { 1: 0 } ), round( '09:10:00.000', { 1: 1 } ), round( '09:20:00.000', { 1: 0 } ) ] } ) );
		check( 'a seat flown on again counts once, as the last', again === '[[1,[1,0]]]', again );
		got = seats( seatData( apart ), [ 1, 5 ] );
		check( 'a pilot who has not flown yet: none for them', got === '1@2', got );
		const off = profile();
		off[ 3 ].frequency = 0;
		got = seats( seatData( apart, off ), [ 1, 2, 3 ] );
		check( 'the seat a pilot came from switched off: none for them', got === '1@2,2@0', got );
	}

	section( 'The events on the local site' );
	const real = [ 32, 33, 34 ].map( ( id ) => path.join( REAL_RACES, `${ id }-data.json` ) ).filter( ( f ) => fs.existsSync( f ) );
	if ( ! real.length ) {
		process.stdout.write( '  (none here - skipped)\n' );
	}
	for ( const file of real ) {
		const data = JSON.parse( fs.readFileSync( file, 'utf8' ) );
		const cls = data.class_data.classes.find( ( c ) => c.name === 'Elimination' );
		const bracket = model.buildBracket( data, cls.id );
		check( `${ path.basename( file ) }: an FAI 32 double elimination`,
			bracket.ok && bracket.type === 'double' && pattern( bracket ) === expected[ 'double-fai32' ].pattern && ! bracket.irregular.length,
			`${ pattern( bracket ) } ${ bracket.irregular.join( '; ' ) }` );
		check( `${ path.basename( file ) }: laid out without overlap`, ! layoutProblems( model, bracket ).length, layoutProblems( model, bracket ).join( '; ' ) );

		// Each heat with automatic frequencies, before its first round, from the rounds flown before
		// it: the seats told against the seats flown. Race 32 was flown so; 81 of 82 on 2026-09-13.
		const heats = new Map( data.heat_data.heats.map( ( h ) => [ h.id, h ] ) );
		const flownRounds = [];
		for ( const [ id, heat ] of Object.entries( data.result_data.heats || {} ) ) {
			for ( const r of heat.rounds || [] ) {
				flownRounds.push( { heatId: Number( heat.heat_id ?? id ), r } );
			}
		}
		flownRounds.sort( ( a, b ) => ( a.r.start_time_formatted < b.r.start_time_formatted ? -1 : 1 ) );
		const first = new Set();
		let told = 0;
		let right = 0;
		flownRounds.forEach( ( { heatId, r }, n ) => {
			const flew = new Map( r.nodes.filter( ( x ) => x.pilot_id && Number.isInteger( x.node_index ) ).map( ( x ) => [ x.pilot_id, x.node_index ] ) );
			if ( typeof model.likelySeats === 'function' && heats.get( heatId ) && heats.get( heatId ).auto_frequency && ! first.has( heatId ) ) {
				const before = { ...data, result_data: { heats: { x: { rounds: flownRounds.slice( 0, n ).map( ( f ) => f.r ) } } } };
				for ( const [ pilot, seat ] of model.likelySeats( before, [ ...flew.keys() ] ) ) {
					told++;
					right += flew.get( pilot ) === seat ? 1 : 0;
				}
			}
			first.add( heatId );
		} );
		if ( told || flownRounds.some( ( { heatId } ) => heats.get( heatId ) && heats.get( heatId ).auto_frequency ) ) {
			check( `${ path.basename( file ) }: likely seats right for ${ right } of ${ told }`, told > 0 && right >= told * 0.95 );
		}
	}

	const failed = results.filter( ( r ) => ! r.ok ).length;
	process.stdout.write( `\n${ '-'.repeat( 62 ) }\n  ${ results.length - failed } passed, ${ failed } failed\n` );
	process.exit( failed ? 1 : 0 );
} )().catch( ( e ) => {
	process.stderr.write( `\nHARNESS ERROR: ${ e && e.stack ? e.stack : e }\n` );
	process.exit( 1 );
} );

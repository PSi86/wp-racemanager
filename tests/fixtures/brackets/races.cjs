/**
 * Races built from RotorHazard's own heat plans, in the shapes the upload carries.
 *
 * plans.json is RotorHazard 4.4.0's bundled generators, exported by the RotorHazard connector's
 * tools/export_bracket_plans.py - see README.md here. raceFromPlan() turns a plan into heats the
 * way HeatGeneratorManager.apply() does (HeatGenerator.py): heat ids in plan order, an INPUT slot
 * seeded from the input class's result (method 2), a HEAT_INDEX slot from the heat at that index
 * of the plan (method 1, Python's negative indexes included). fly() then fills and flies the heats
 * in order the way heat_automation.py fills a heat when it comes up: the entry at seed_rank - 1 of
 * the source's board. Results are deterministic: a lower pilot number is the faster pilot, unless a
 * heat's order is given.
 *
 * No real names: pilots are P1, P2, ...
 */

const fs = require( 'fs' );
const path = require( 'path' );

const PLANS = JSON.parse( fs.readFileSync( path.join( __dirname, 'plans.json' ), 'utf8' ) ).plans;
const METHOD = { NONE: -1, ASSIGN: 0, HEAT_RESULT: 1, CLASS_RESULT: 2 };
const QUALIFYING = 2;
const BRACKET = 3;
const GENERATOR_IDS = {
	'Regulation bracket, single elimination': 'Regulation_bracket__single_elimination',
	'Regulation bracket, double elimination': 'Regulation_bracket__double_elimination',
	Ladder: 'Ladder',
	'Ranked fill': 'Ranked_fill',
};

function entry( pilotId, position ) {
	return {
		pilot_id: pilotId,
		callsign: `P${ pilotId }`,
		team_name: '',
		position,
		laps: position ? 3 : 0,
		total_time: position ? `1:${ String( 10 + position ).padStart( 2, '0' ) }.000` : '0:00.000',
	};
}

function board( entries ) {
	return { by_race_time: entries, meta: { primary_leaderboard: 'by_race_time' } };
}

/**
 * An event: a Qualifying class that ranked `pilots` pilots P1..Pn in that order, and a bracket
 * class filled from it by the plan.
 *
 * @param {string} key      A plan of plans.json, e.g. "double-fai32".
 * @param {Object} options  pilots (default: as many as the plan seeds), firstHeatId (default 10),
 *                          record (send the generator's record, default true), nodes (slots per
 *                          heat; a timer with more nodes than the plan seats leaves the rest
 *                          empty, method -1, as RotorHazard 4.4.0 does).
 */
function raceFromPlan( key, { pilots, firstHeatId = 10, record = true, nodes = 0 } = {} ) {
	const plan = PLANS[ key ];
	if ( ! plan ) {
		throw new Error( `no plan ${ key }` );
	}
	const heatIds = plan.heats.map( ( _, i ) => firstHeatId + i );
	const at = ( index ) => heatIds[ index < 0 ? heatIds.length + index : index ];
	const seeded = plan.heats.flatMap( ( h ) => h.slots ).filter( ( s ) => s.method === 'INPUT' ).length;
	const count = pilots === undefined ? seeded : pilots;

	const heats = plan.heats.map( ( h, i ) => ( {
		id: heatIds[ i ],
		displayname: h.name,
		name: h.name,
		class_id: BRACKET,
		order: null,
		next_round: 0,
		slots: [
			...h.slots.map( ( s, n ) => ( {
				id: heatIds[ i ] * 10 + n,
				node_index: n,
				pilot_id: null,
				method: s.method === 'INPUT' ? METHOD.CLASS_RESULT : METHOD.HEAT_RESULT,
				seed_rank: s.seed_rank,
				seed_id: s.method === 'INPUT' ? QUALIFYING : at( s.seed_index ),
			} ) ),
			...Array.from( { length: Math.max( 0, nodes - h.slots.length ) }, ( _, n ) => ( {
				id: heatIds[ i ] * 10 + h.slots.length + n,
				node_index: h.slots.length + n,
				pilot_id: null,
				method: METHOD.NONE,
				seed_rank: null,
				seed_id: null,
			} ) ),
		],
	} ) );

	const qualifyingBoard = Array.from( { length: count }, ( _, i ) => entry( i + 1, i + 1 ) );
	const generateArgs = record
		? { input_class: QUALIFYING, output_class: BRACKET, available_seats: 4, ...plan.generate_args, generator: GENERATOR_IDS[ plan.generator ] }
		: null;

	return {
		race_name: `Fixture ${ key }`,
		current_heat: { current_heat: heatIds[ 0 ] },
		pilot_data: { pilots: Array.from( { length: count }, ( _, i ) => ( { pilot_id: i + 1, callsign: `P${ i + 1 }`, pilot_key: '' } ) ) },
		class_data: {
			classes: [
				{ id: QUALIFYING, name: 'Qualifying', displayname: 'Qualifying', win_condition: '', ranksettings: null, rounds: 1, order: 1, generate_args: null },
				{ id: BRACKET, name: 'Elimination', displayname: 'Elimination', win_condition: '', ranksettings: null, rounds: 1, order: 2, generate_args: generateArgs },
			],
		},
		heat_data: { heats },
		result_data: {
			heats: {},
			heats_by_class: { 0: [], [ QUALIFYING ]: [], [ BRACKET ]: heatIds },
			classes: {
				[ QUALIFYING ]: { id: QUALIFYING, name: 'Qualifying', leaderboard: board( qualifyingBoard ), ranking: false },
				[ BRACKET ]: { id: BRACKET, name: 'Elimination', leaderboard: board( [] ), ranking: false },
			},
			event_leaderboard: board( qualifyingBoard ),
		},
	};
}

/** The pilot a slot gets when its heat comes up (heat_automation.py). */
function fill( data, slot ) {
	let entries = null;
	if ( slot.method === METHOD.CLASS_RESULT ) {
		const cls = data.result_data.classes[ slot.seed_id ];
		entries = cls.ranking ? cls.ranking.ranking : cls.leaderboard[ cls.leaderboard.meta.primary_leaderboard ];
	} else if ( slot.method === METHOD.HEAT_RESULT ) {
		const result = data.result_data.heats[ slot.seed_id ];
		entries = result ? result.leaderboard.by_race_time : null;
	}
	const hit = entries && entries[ slot.seed_rank - 1 ];
	return hit ? hit.pilot_id : null;
}

/**
 * Fly the bracket's heats in id order up to and including `untilHeatId` (all by default).
 *
 * @param {Object} data     From raceFromPlan(); changed in place and returned.
 * @param {Object} options  untilHeatId; finish {heatId: [pilotIds in finishing order]} to set a
 *                          heat's order; dns [pilotIds] who never start (no position).
 */
function fly( data, { untilHeatId = Infinity, finish = {}, dns = [] } = {} ) {
	for ( const heat of data.heat_data.heats.filter( ( h ) => h.class_id === BRACKET ).sort( ( a, b ) => a.id - b.id ) ) {
		if ( heat.id > untilHeatId ) {
			break;
		}
		for ( const slot of heat.slots ) {
			slot.pilot_id = fill( data, slot );
		}
		const inHeat = heat.slots.map( ( s ) => s.pilot_id ).filter( Boolean );
		const order = finish[ heat.id ] || [ ...inHeat ].sort( ( a, b ) => a - b );
		const starters = order.filter( ( p ) => ! dns.includes( p ) );
		const nonStarters = order.filter( ( p ) => dns.includes( p ) );
		const entries = [ ...starters.map( ( p, i ) => entry( p, i + 1 ) ), ...nonStarters.map( ( p ) => entry( p, null ) ) ];
		const leaderboard = board( entries );
		data.result_data.heats[ heat.id ] = { heat_id: heat.id, displayname: heat.displayname, rounds: [ { id: 1, nodes: [], leaderboard } ], leaderboard };
		heat.next_round = 1;
		data.current_heat.current_heat = heat.id;
	}
	return data;
}

/**
 * Fly the final again, as Chase the Ace does: one round per list of pilots in finishing order.
 * With `ranking`, the class gets the Brackets method's ranking for the final four.
 */
function chaseTheAce( data, finalId, rounds, { ranking = null, settings = null } = {} ) {
	const cls = data.class_data.classes.find( ( c ) => c.id === BRACKET );
	cls.win_condition = 'Brackets';
	cls.rank_method_label = 'Brackets';
	cls.ranksettings = settings;
	const result = data.result_data.heats[ finalId ];
	result.rounds = rounds.map( ( order, i ) => ( { id: i + 1, nodes: [], leaderboard: board( order.map( ( p, n ) => entry( p, n + 1 ) ) ) } ) );
	if ( ranking ) {
		data.result_data.classes[ BRACKET ].ranking = {
			ranking: ranking.map( ( p, i ) => ( { pilot_id: p, callsign: `P${ p }`, team_name: '', position: i + 1, result: i ? `[${ i + 1 }]` : 'CTA [1] [1]' } ) ),
			meta: { method_label: 'Brackets', rank_fields: [ { name: 'result', label: 'Result' } ] },
		};
	}
	return data;
}

module.exports = { PLANS, METHOD, QUALIFYING, BRACKET, raceFromPlan, fly, chaseTheAce, entry, board };

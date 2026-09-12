// rm-m-bracketModel.js
//
// A race class's bracket, worked out from how its heats are seeded - not from a template, a class
// name or a pilot count. RotorHazard keeps each slot's seeding after the heat ran: a slot filled
// from a heat's result (method 1) names that heat and the rank it takes, so the heats of a class
// and those links are the bracket. That holds for every generator RotorHazard ships (FAI 16, 32
// and 64 single and double elimination, MultiGP 16) and for a bracket built by hand the same way.
//
// This file also runs on the timer: the RotorHazard connector's /bracketview takes it over byte
// for byte, next to rm-m-displayHeats.js. So it is pure - no DOM, no window, no imports - and its
// exports only ever grow: the importers use `import * as` and check for what they call, because a
// module reached through a relative import carries no version and a returning visitor may still
// have an older copy.
//
// Shapes read, all as the upload and RotorHazard's socket carry them:
//   class_data.classes[]   id, displayname, win_condition, ranksettings, generate_args (from the connector)
//   heat_data.heats[]      id, displayname, class_id, order, slots[] {pilot_id, method, seed_rank, seed_id}
//   result_data.heats      {heat_id: {rounds[] {leaderboard}, leaderboard}}, an object or a list
//   result_data.classes    {class_id: {leaderboard, ranking}}

export const MODEL_VERSION = 1;

// Database.ProgramMethod: how a slot gets its pilot.
export const METHOD = { NONE: -1, ASSIGN: 0, HEAT_RESULT: 1, CLASS_RESULT: 2 };

// Heats per regulation bracket, by the generator record's `standard` (rh_heatgenerator_standard).
const HEATS_BY_STANDARD = {
    single: { fai16: 8, fai32: 16, fai64: 32 },
    double: { fai16: 14, fai32: 30, fai64: 62, multigp16: 14 },
};
const BRACKET_GENERATORS = {
    Regulation_bracket__single_elimination: 'single',
    Regulation_bracket__double_elimination: 'double',
};

/* ------------------------------------------------------------------------------------------ *
 * Reading the data
 * ------------------------------------------------------------------------------------------ */

function listOf( value ) {
    if ( Array.isArray( value ) ) {
        return value;
    }
    return value && typeof value === 'object' ? Object.values( value ) : [];
}

/** The class entry of class_data, or null. */
export function classOf( data, classId ) {
    const classes = listOf( data && data.class_data && data.class_data.classes );
    return classes.find( ( c ) => c && c.id === classId ) || null;
}

/**
 * The heats of a class in the timer's order: by `order` when every heat has one, else by id,
 * which is the order a generator created them in.
 */
export function heatsOfClass( data, classId ) {
    const heats = listOf( data && data.heat_data && data.heat_data.heats ).filter( ( h ) => h && h.class_id === classId );
    const byId = ( a, b ) => a.id - b.id;
    if ( heats.length && heats.every( ( h ) => typeof h.order === 'number' ) ) {
        return heats.sort( ( a, b ) => a.order - b.order || byId( a, b ) );
    }
    return heats.sort( byId );
}

/** A heat's entry in result_data, or null. */
export function heatResult( data, heatId ) {
    const heats = data && data.result_data && data.result_data.heats;
    if ( ! heats ) {
        return null;
    }
    if ( ! Array.isArray( heats ) && heats[ heatId ] ) {
        return heats[ heatId ];
    }
    return listOf( heats ).find( ( h ) => h && h.heat_id === heatId ) || null;
}

/** The primary board of a leaderboard object, or null. */
export function primaryBoard( leaderboard ) {
    if ( ! leaderboard || typeof leaderboard !== 'object' ) {
        return null;
    }
    const key = ( leaderboard.meta && leaderboard.meta.primary_leaderboard ) || 'by_race_time';
    return Array.isArray( leaderboard[ key ] ) ? leaderboard[ key ] : null;
}

/** A heat's result as RotorHazard seeds from it: its primary board, or null before it ran. */
export function heatBoard( data, heatId ) {
    const result = heatResult( data, heatId );
    return result ? primaryBoard( result.leaderboard ) : null;
}

/** A class's entry in result_data (leaderboard, ranking), or null. */
export function classResult( data, classId ) {
    const classes = data && data.result_data && data.result_data.classes;
    if ( ! classes ) {
        return null;
    }
    return ( ! Array.isArray( classes ) && classes[ classId ] ) || listOf( classes ).find( ( c ) => c && c.id === classId ) || null;
}

/**
 * A class's result as RotorHazard seeds from it: its ranking when a ranking method produced one
 * (a method that produced nothing seeds nobody), else its primary board.
 */
export function classBoard( data, classId ) {
    const cls = classResult( data, classId );
    if ( ! cls ) {
        return null;
    }
    if ( cls.ranking ) {
        return Array.isArray( cls.ranking.ranking ) ? cls.ranking.ranking : null;
    }
    return primaryBoard( cls.leaderboard );
}

/**
 * The pilot a slot will get, as RotorHazard seeds it (heat_automation.py): the entry at
 * seed_rank - 1 of the seed heat's board (method 1) or of the seed class's board (method 2).
 * Until that entry exists, a label naming source and rank ("Race 3 #2", "Qualifying #7").
 *
 * @returns {{pilotId: number|null, callsign: string, label: string}}
 */
export function resolveSeed( data, slot ) {
    const rank = slot && slot.seed_rank;
    let entries = null;
    let source = '';
    if ( slot && slot.method === METHOD.HEAT_RESULT ) {
        const heat = listOf( data.heat_data && data.heat_data.heats ).find( ( h ) => h.id === slot.seed_id );
        source = heat ? heat.displayname : '';
        entries = heatBoard( data, slot.seed_id );
    } else if ( slot && slot.method === METHOD.CLASS_RESULT ) {
        const cls = classOf( data, slot.seed_id );
        source = cls ? cls.displayname : '';
        entries = classBoard( data, slot.seed_id );
    }
    const entry = entries && rank > 0 ? entries[ rank - 1 ] : null;
    if ( entry && entry.pilot_id ) {
        return { pilotId: entry.pilot_id, callsign: entry.callsign || '', label: '' };
    }
    return { pilotId: null, callsign: '', label: source && rank ? `${ source } #${ rank }` : '' };
}

/* ------------------------------------------------------------------------------------------ *
 * The bracket
 * ------------------------------------------------------------------------------------------ */

const W = 'W'; // winners bracket, and every round of a single elimination
const L = 'L'; // losers bracket
const F = 'F'; // grand final of a double elimination
const SF = 'SF'; // small final of a single elimination

function none( classId, reason, heats ) {
    return { classId, ok: false, reason, type: 'none', irregular: [], heats: [], byId: new Map(), finalId: null, smallFinalId: null, exits: [], qualRank: new Map(), rulebook: 'fai', count: heats.length };
}

/**
 * The bracket a class's heats form.
 *
 * Groups: a heat seeded from nobody's heat is a first round of the winners bracket. A heat that
 * takes only the upper ranks of winners-bracket heats - the ranks that go on together, the way
 * the heat's rank 1 goes - stays in the winners bracket. A heat that takes the lower ranks of a
 * winners heat, or anything of a losers heat, is in the losers bracket; the last heat, fed by the
 * winners and the losers side, is the grand final. A losers heat that feeds nothing further makes
 * a single elimination's small final. Rounds count along the longest path inside a group.
 *
 * Checked this way against every plan of rh_heatgenerator_standard and the events of 2025.
 *
 * @param {Object} data     The race data.
 * @param {number} classId  The class.
 * @returns {Object} classId, ok (false: draw the class as a row), type 'double'|'single'|'none',
 *   irregular (what makes a standing unreliable), heats [{id, group, round, depth, roundName,
 *   parents, drawnParents, size}] in the timer's order, byId, finalId, smallFinalId, exits
 *   [{heatId, rank}], qualRank (pilot -> seed rank of the class it came from), rulebook.
 */
export function buildBracket( data, classId ) {
    const heats = heatsOfClass( data, classId );
    if ( ! heats.length ) {
        return none( classId, 'no heats', heats );
    }
    const ids = new Set( heats.map( ( h ) => h.id ) );
    const irregular = [];
    const seedsOf = new Map(); // heat -> [{from, rank}]
    const children = new Map( heats.map( ( h ) => [ h.id, [] ] ) );
    let edges = 0;

    for ( const heat of heats ) {
        const seeds = [];
        for ( const slot of listOf( heat.slots ) ) {
            if ( slot.method !== METHOD.HEAT_RESULT ) {
                continue;
            }
            if ( ! ids.has( slot.seed_id ) ) {
                irregular.push( `${ heat.displayname } is seeded from a heat of another class` );
                continue;
            }
            if ( seeds.some( ( s ) => s.from === slot.seed_id && s.rank === slot.seed_rank ) ) {
                irregular.push( `${ heat.displayname } takes the same rank of a heat twice` );
            }
            seeds.push( { from: slot.seed_id, rank: slot.seed_rank } );
            edges++;
        }
        seedsOf.set( heat.id, seeds );
        for ( const from of new Set( seeds.map( ( s ) => s.from ) ) ) {
            children.get( from ).push( heat.id );
        }
    }
    if ( ! edges ) {
        return none( classId, 'no heat is seeded from another', heats );
    }

    // Topological order; a cycle is no bracket.
    const parentsOf = new Map( heats.map( ( h ) => [ h.id, [ ...new Set( seedsOf.get( h.id ).map( ( s ) => s.from ) ) ] ] ) );
    const pending = new Map( heats.map( ( h ) => [ h.id, parentsOf.get( h.id ).length ] ) );
    const order = [];
    const queue = heats.filter( ( h ) => ! pending.get( h.id ) ).map( ( h ) => h.id );
    while ( queue.length ) {
        const id = queue.shift();
        order.push( id );
        for ( const child of children.get( id ) ) {
            pending.set( child, pending.get( child ) - 1 );
            if ( ! pending.get( child ) ) {
                queue.push( child );
            }
        }
    }
    if ( order.length !== heats.length ) {
        return none( classId, 'the heats seed each other in a circle', heats );
    }

    // Several unconnected brackets in one class (a generator run twice into it) are drawn as rows.
    const component = new Map();
    let components = 0;
    for ( const heat of heats ) {
        if ( component.has( heat.id ) ) {
            continue;
        }
        components++;
        const stack = [ heat.id ];
        while ( stack.length ) {
            const id = stack.pop();
            if ( component.has( id ) ) {
                continue;
            }
            component.set( id, components );
            stack.push( ...parentsOf.get( id ), ...children.get( id ) );
        }
    }
    if ( components > 1 ) {
        return none( classId, 'the class holds more than one bracket', heats );
    }

    // Where a heat's rank 1 goes: the heat that continues it. Ranks going elsewhere drop.
    const continuesTo = new Map();
    for ( const heat of heats ) {
        for ( const seed of seedsOf.get( heat.id ) ) {
            if ( seed.rank === 1 ) {
                continuesTo.set( seed.from, heat.id );
            }
        }
    }
    const drops = ( heatId ) => seedsOf.get( heatId ).some( ( s ) => continuesTo.get( s.from ) !== heatId );

    const group = new Map();
    for ( const id of order ) {
        const parents = parentsOf.get( id );
        if ( ! parents.length ) {
            group.set( id, W );
            continue;
        }
        const parentGroups = new Set( parents.map( ( p ) => group.get( p ) ) );
        const fromWinnersOnly = [ ...parentGroups ].every( ( g ) => g === W );
        const dropsFromWinners = seedsOf.get( id ).some( ( s ) => group.get( s.from ) === W && continuesTo.get( s.from ) !== id );
        if ( fromWinnersOnly && ! dropsFromWinners ) {
            group.set( id, W );
        } else if ( ! children.get( id ).length && parentGroups.has( W ) && parentGroups.has( L ) && ! drops( id ) ) {
            group.set( id, F );
        } else {
            group.set( id, L );
        }
    }

    const losersFeed = heats.some( ( h ) => group.get( h.id ) === L && children.get( h.id ).length );
    const finals = heats.filter( ( h ) => group.get( h.id ) === F );
    let type = losersFeed || finals.length ? 'double' : 'single';
    if ( type === 'single' ) {
        // A losers heat of a single elimination is its small final.
        for ( const heat of heats ) {
            if ( group.get( heat.id ) === L ) {
                group.set( heat.id, SF );
            }
        }
    }

    // Rounds inside a group, depth across the whole bracket.
    const round = new Map();
    const depth = new Map();
    for ( const id of order ) {
        const parents = parentsOf.get( id );
        depth.set( id, 1 + Math.max( 0, ...parents.map( ( p ) => depth.get( p ) ) ) );
        const same = parents.filter( ( p ) => group.get( p ) === group.get( id ) );
        round.set( id, 1 + Math.max( 0, ...same.map( ( p ) => round.get( p ) ) ) );
    }

    // A ladder or a chain of single heats is no bracket to draw: one heat per round, nothing dropped.
    const winnersRounds = new Map();
    for ( const heat of heats ) {
        if ( group.get( heat.id ) === W ) {
            winnersRounds.set( round.get( heat.id ), ( winnersRounds.get( round.get( heat.id ) ) || 0 ) + 1 );
        }
    }
    if ( type === 'single' && ! heats.some( ( h ) => group.get( h.id ) === SF ) && [ ...winnersRounds.values() ].every( ( n ) => n === 1 ) ) {
        return none( classId, 'a chain of heats, not a bracket', heats );
    }

    // The generator's record, when the connector sent one, has to match what the heats say.
    const cls = classOf( data, classId );
    const record = cls && cls.generate_args && typeof cls.generate_args === 'object' ? cls.generate_args : null;
    let rulebook = 'fai';
    if ( record && BRACKET_GENERATORS[ record.generator ] ) {
        const kind = BRACKET_GENERATORS[ record.generator ];
        const expected = HEATS_BY_STANDARD[ kind ][ record.standard ];
        if ( kind !== type || ( expected && expected !== heats.length ) ) {
            irregular.push( `the generator's record (${ kind } ${ record.standard }) does not match the ${ heats.length } heats` );
        }
        if ( String( record.standard || '' ).startsWith( 'multigp' ) ) {
            rulebook = 'multigp';
        }
    } else if ( cls && cls.win_condition === 'Brackets' && cls.ranksettings && cls.ranksettings.bracket_type ) {
        rulebook = cls.ranksettings.bracket_type === 'FAI' ? 'fai' : 'multigp';
    }

    const maxWinners = Math.max( 0, ...heats.filter( ( h ) => group.get( h.id ) === W ).map( ( h ) => round.get( h.id ) ) );
    const roundName = ( id ) => {
        const g = group.get( id );
        if ( g === F ) {
            return 'Grand Final';
        }
        if ( g === SF ) {
            return 'Small Final';
        }
        if ( g === L ) {
            return `LB Round ${ round.get( id ) }`;
        }
        const fromEnd = maxWinners - round.get( id );
        if ( fromEnd === 0 ) {
            return type === 'double' ? 'Winners Final' : 'Final';
        }
        return [ null, 'Semifinals', 'Quarterfinals' ][ fromEnd ] || `Round ${ round.get( id ) }`;
    };

    // A later round whose slot was filled by hand no longer follows the seeding.
    for ( const heat of heats ) {
        if ( parentsOf.get( heat.id ).length && listOf( heat.slots ).some( ( s ) => s.method === METHOD.ASSIGN && s.pilot_id ) ) {
            irregular.push( `a pilot was put into ${ heat.displayname } by hand` );
        }
    }

    const out = heats.map( ( heat ) => {
        const id = heat.id;
        const g = group.get( id );
        const parents = parentsOf.get( id );
        return {
            id,
            group: g,
            round: round.get( id ),
            depth: depth.get( id ),
            roundName: roundName( id ),
            parents,
            // The lines drawn: along a group, and into a final from every side.
            drawnParents: g === F || g === SF ? parents : parents.filter( ( p ) => group.get( p ) === g ),
            // The seats a heat can fill: RotorHazard gives it a slot per node, and those beyond
            // what the generator seeded stay empty without a method (-1).
            size: listOf( heat.slots ).filter( ( s ) => s.method !== METHOD.NONE || s.pilot_id ).length,
        };
    } );
    const byId = new Map( out.map( ( h ) => [ h.id, h ] ) );

    // Every rank nobody is seeded from finishes in that heat.
    const taken = new Set();
    for ( const heat of heats ) {
        for ( const seed of seedsOf.get( heat.id ) ) {
            taken.add( `${ seed.from }:${ seed.rank }` );
        }
    }
    const exits = [];
    for ( const heat of out ) {
        for ( let rank = 1; rank <= heat.size; rank++ ) {
            if ( ! taken.has( `${ heat.id }:${ rank }` ) ) {
                exits.push( { heatId: heat.id, rank } );
            }
        }
    }

    // The qualifying standing, which orders pilots out in the same round: the class the first
    // round seeds from, its primary board as it stands now - what Class Rank: Brackets and this
    // plugin's ranking before 1.10.0 read. Not the rank a pilot was seeded with: in the events of
    // 2025 that differs for several pilots, qualifying having been corrected after the bracket was
    // filled. The seed rank stands in for a pilot the board does not list.
    const qualRank = new Map();
    const seedClasses = new Map();
    for ( const heat of heats ) {
        if ( parentsOf.get( heat.id ).length ) {
            continue;
        }
        for ( const slot of listOf( heat.slots ) ) {
            if ( slot.method === METHOD.CLASS_RESULT && slot.seed_id ) {
                seedClasses.set( slot.seed_id, ( seedClasses.get( slot.seed_id ) || 0 ) + 1 );
                if ( slot.pilot_id && slot.seed_rank ) {
                    qualRank.set( slot.pilot_id, slot.seed_rank );
                }
            }
        }
    }
    const qualifyingId = [ ...seedClasses ].sort( ( a, b ) => b[ 1 ] - a[ 1 ] ).map( ( [ id ] ) => id )[ 0 ];
    const qualifying = qualifyingId ? classResult( data, qualifyingId ) : null;
    const qualBoard = qualifying ? primaryBoard( qualifying.leaderboard ) : null;
    if ( qualBoard ) {
        // A pilot the board lists without a position has no qualifying result: nothing to rank
        // them by (race 34 of 2025 has two).
        qualRank.clear();
        qualBoard.forEach( ( entry, index ) => {
            if ( entry && entry.pilot_id && entry.position !== null && entry.position !== undefined ) {
                qualRank.set( entry.pilot_id, index + 1 );
            }
        } );
    }

    const finalHeat = type === 'double' ? finals[ 0 ] : heats.find( ( h ) => group.get( h.id ) === W && round.get( h.id ) === maxWinners );
    if ( type === 'double' && finals.length !== 1 ) {
        irregular.push( 'the bracket has no single grand final' );
    }
    const small = heats.find( ( h ) => group.get( h.id ) === SF );

    return {
        classId,
        ok: true,
        reason: null,
        type,
        irregular,
        heats: out,
        byId,
        finalId: finalHeat ? finalHeat.id : null,
        smallFinalId: small ? small.id : null,
        exits,
        qualRank,
        rulebook,
        count: heats.length,
    };
}

/* ------------------------------------------------------------------------------------------ *
 * Where each heat goes on the grid
 * ------------------------------------------------------------------------------------------ */

/**
 * Grid positions for a bracket's heats: a column per round of each section, the grand final one
 * column after the last, the small final under the final. A first round keeps the timer's order;
 * a later heat sits level with the mean row of its parents in the section, pushed down past the
 * heat above it, since losers rounds cross over (both LB Round 3 heats of an FAI 32 bracket take
 * from all four of LB Round 2). Rows and columns count from 1 inside each section.
 *
 * @param {Object} bracket     From buildBracket().
 * @param {Set|null} visibleIds  The heats shown (the pilot filter); all when null.
 * @returns {{sections: [{key, title, columns, headers: [{col, label}], cells: [{heatId, col, row}]}], edges: [{from, to}]}}
 */
export function layout( bracket, visibleIds = null ) {
    const shown = bracket.heats.filter( ( h ) => ! visibleIds || visibleIds.has( h.id ) );
    const double = bracket.type === 'double';
    const maxRound = ( group ) => Math.max( 0, ...bracket.heats.filter( ( h ) => h.group === group ).map( ( h ) => h.round ) );
    const maxW = maxRound( W );
    const maxL = maxRound( L );
    const finalCol = double ? Math.max( maxW, maxL ) + 1 : maxW;

    const sections = [];
    const rowOf = new Map();
    const place = ( key, title, members, colOf ) => {
        const cells = [];
        const columns = Math.max( 0, ...members.map( colOf ) );
        const byCol = new Map();
        for ( const heat of members ) {
            const col = colOf( heat );
            if ( ! byCol.has( col ) ) {
                byCol.set( col, [] );
            }
            byCol.get( col ).push( heat );
        }
        for ( const col of [ ...byCol.keys() ].sort( ( a, b ) => a - b ) ) {
            const keyed = byCol.get( col ).map( ( heat, index ) => {
                const rows = heat.drawnParents.filter( ( p ) => rowOf.has( p ) ).map( ( p ) => rowOf.get( p ) );
                return { heat, index, key: rows.length ? rows.reduce( ( a, b ) => a + b, 0 ) / rows.length : Infinity };
            } );
            keyed.sort( ( a, b ) => ( a.key - b.key ) || ( a.index - b.index ) );
            let previous = 0;
            for ( const { heat, key: mean } of keyed ) {
                let row = Number.isFinite( mean ) ? Math.max( Math.floor( mean ), previous + 1 ) : previous + 1;
                if ( heat.group === SF ) {
                    row = Math.max( row, ( rowOf.get( bracket.finalId ) || 0 ) + 1 );
                }
                rowOf.set( heat.id, row );
                cells.push( { heatId: heat.id, col, row } );
                previous = row;
            }
        }
        const headers = [];
        for ( let col = 1; col <= columns; col++ ) {
            const inCol = bracket.heats.find( ( h ) => members.includes( h ) && colOf( h ) === col && h.group !== SF );
            if ( inCol ) {
                headers.push( { col, label: inCol.roundName } );
            }
        }
        sections.push( { key, title, columns, headers, cells } );
    };

    const winners = shown.filter( ( h ) => h.group === W || h.group === SF || h.group === F );
    // Final after the small final's column: place the final before the small final in its column.
    winners.sort( ( a, b ) => ( a.group === SF ) - ( b.group === SF ) );
    const winnersCol = ( h ) => {
        if ( h.group === F ) {
            return finalCol;
        }
        return h.group === SF ? maxW : h.round;
    };
    place( W, double ? 'Winners Bracket' : 'Bracket', winners, winnersCol );
    if ( double ) {
        place( L, 'Losers Bracket', shown.filter( ( h ) => h.group === L ), ( h ) => h.round );
    }

    const visible = new Set( shown.map( ( h ) => h.id ) );
    const edges = [];
    for ( const heat of shown ) {
        for ( const parent of heat.drawnParents ) {
            if ( visible.has( parent ) ) {
                edges.push( { from: parent, to: heat.id } );
            }
        }
    }
    return { sections: sections.filter( ( s ) => s.cells.length ), edges };
}

/* ------------------------------------------------------------------------------------------ *
 * Chase the Ace
 * ------------------------------------------------------------------------------------------ */

/**
 * The final flown as Chase the Ace: rounds of the last heat until a pilot has won two. On the
 * timer that is the ranking method "Brackets" of the community plugin Class Rank: Brackets
 * (FiorixF1), switched on with its "Chase the Ace" setting - on by default, and RotorHazard sends
 * a class's ranksettings only as far as someone changed them, so a missing value counts as on.
 * The timer's ranking decides (it applies the Iron Man rule too); without one, two round wins do.
 * The round that decides is the last that counts, as in Class Rank: Brackets: a round flown after
 * it changes neither the rounds nor the wins shown.
 *
 * @returns {{enabled: boolean, ironMan: boolean, needed: number, rounds: number,
 *   wins: Map<number, number>, decided: boolean, winnerId: number|null, source: string|null}}
 */
export function ctaState( data, bracket ) {
    const state = { enabled: false, ironMan: false, needed: 2, rounds: 0, wins: new Map(), decided: false, winnerId: null, source: null };
    if ( ! bracket || ! bracket.ok || ! bracket.finalId ) {
        return state;
    }
    const cls = classOf( data, bracket.classId );
    if ( ! cls || cls.win_condition !== 'Brackets' ) {
        return state;
    }
    const settings = cls.ranksettings && typeof cls.ranksettings === 'object' ? cls.ranksettings : {};
    if ( settings.chase_the_ace === false || settings.chase_the_ace === '0' ) {
        return state;
    }
    state.enabled = true;
    state.ironMan = ! ( settings.iron_man === false || settings.iron_man === '0' );

    const result = heatResult( data, bracket.finalId );
    for ( const round of listOf( result && result.rounds ) ) {
        const board = primaryBoard( round.leaderboard );
        if ( ! board || ! board.length ) {
            continue;
        }
        state.rounds++;
        const winner = board[ 0 ] && board[ 0 ].pilot_id;
        if ( winner ) {
            state.wins.set( winner, ( state.wins.get( winner ) || 0 ) + 1 );
            if ( state.wins.get( winner ) >= state.needed ) {
                break;
            }
        }
    }

    const ranking = classRanking( data, cls.id );
    const first = ranking && ranking.find( ( e ) => e && e.position === 1 && e.pilot_id );
    if ( first ) {
        state.decided = true;
        state.winnerId = first.pilot_id;
        state.source = 'ranking';
        return state;
    }
    for ( const [ pilotId, wins ] of state.wins ) {
        if ( wins >= state.needed ) {
            state.decided = true;
            state.winnerId = pilotId;
            state.source = 'rounds';
        }
    }
    return state;
}

/** A class's ranking from the timer as a list, or null. */
export function classRanking( data, classId ) {
    const cls = classResult( data, classId );
    return cls && cls.ranking && Array.isArray( cls.ranking.ranking ) ? cls.ranking.ranking : null;
}

// rm-m-bracketStandings.js
//
// The final standing of a bracket: every pilot's place, or the range of places still open, and
// the round they went out in ("LB Round 6", "Grand Final"). WordPress only - the timer's screen
// shows the bracket, not the standing - and pure: no DOM.
//
// Where it comes from:
//   - The timer's own ranking, when the class ranks with "Brackets" (the community plugin Class
//     Rank: Brackets, which also runs Chase the Ace): its places as it gave them.
//   - Otherwise worked out here. Every rank of a heat that nobody is seeded from finishes in that
//     heat (bracketModel's exits). Pilots out in the same round share its range of places, counted
//     from the bottom - N pilots in the first round - so the places of those already out are right
//     while the bracket is still running. Once every heat of the round has flown, single places:
//     one heat decides by its own order; several heats by the rulebook - FAI by qualifying rank,
//     MultiGP by rank in the heat, then qualifying rank (what Class Rank: Brackets does, and what
//     this plugin's ranking did for FAI 32 before 1.10.0). Chase the Ace without the timer's
//     ranking: first to two round wins, places two to four by points (1, 2, 3, 4 per round),
//     ties by the last round.

import * as model from './rm-m-bracketModel.js';

const NOTICE_EDITED = 'This bracket was changed by hand on the timer, so no standing is worked out for it.';

function flownBoard( data, heatId ) {
    const board = model.heatBoard( data, heatId );
    return board && board.length ? board : null;
}

// Whether each heat has flown, can hold nobody - a bracket that is not full leaves heats empty for
// good, all of FAI 32's LB Round 1 with 12 pilots - or is still to come.
function heatStates( data, bracket ) {
    const raw = new Map( ( ( data.heat_data && data.heat_data.heats ) || [] ).map( ( h ) => [ h.id, h ] ) );
    const state = new Map();
    const settle = ( id ) => {
        if ( state.has( id ) ) {
            return state.get( id );
        }
        let result = 'flown';
        if ( ! flownBoard( data, id ) ) {
            const slots = ( raw.get( id ) && raw.get( id ).slots ) || [];
            const somebody = slots.some( ( slot ) => {
                if ( slot.pilot_id ) {
                    return true;
                }
                if ( slot.method === model.METHOD.HEAT_RESULT ) {
                    if ( settle( slot.seed_id ) === 'open' ) {
                        return true;
                    }
                    const board = flownBoard( data, slot.seed_id );
                    return !! ( board && board[ slot.seed_rank - 1 ] && board[ slot.seed_rank - 1 ].pilot_id );
                }
                if ( slot.method === model.METHOD.CLASS_RESULT ) {
                    const board = model.classBoard( data, slot.seed_id );
                    return !! ( board && board[ slot.seed_rank - 1 ] );
                }
                return false;
            } );
            result = somebody ? 'open' : 'empty';
        }
        state.set( id, result );
        return result;
    };
    bracket.heats.forEach( ( h ) => settle( h.id ) );
    return state;
}

// The final's order under Chase the Ace, from its rounds; null until a pilot has two wins. As in
// Class Rank: Brackets, the round that decides is the last that counts, and it breaks ties on
// points: a round flown after it changes nothing.
function chaseTheAceOrder( data, finalId ) {
    const result = model.heatResult( data, finalId );
    const rounds = ( result && Array.isArray( result.rounds ) ? result.rounds : [] )
        .map( ( r ) => model.primaryBoard( r.leaderboard ) )
        .filter( ( b ) => b && b.length );
    const wins = new Map();
    const points = new Map();
    const callsign = new Map();
    let deciding = null;
    for ( const board of rounds ) {
        board.forEach( ( entry, index ) => {
            if ( ! entry || ! entry.pilot_id ) {
                return;
            }
            callsign.set( entry.pilot_id, entry.callsign || '' );
            points.set( entry.pilot_id, ( points.get( entry.pilot_id ) || 0 ) + index + 1 );
            if ( index === 0 ) {
                wins.set( entry.pilot_id, ( wins.get( entry.pilot_id ) || 0 ) + 1 );
            }
        } );
        const first = board[ 0 ] && board[ 0 ].pilot_id;
        if ( first && wins.get( first ) >= 2 ) {
            deciding = board;
            break;
        }
    }
    if ( ! deciding ) {
        return null;
    }
    const winnerId = deciding[ 0 ].pilot_id;
    const decidingIndex = ( pilotId ) => deciding.findIndex( ( e ) => e && e.pilot_id === pilotId );
    const others = [ ...points.keys() ].filter( ( p ) => p !== winnerId )
        .sort( ( a, b ) => points.get( a ) - points.get( b ) || decidingIndex( a ) - decidingIndex( b ) );
    return [ winnerId, ...others ].map( ( pilotId ) => ( { pilot_id: pilotId, callsign: callsign.get( pilotId ) } ) );
}

/**
 * The standing of a class's bracket.
 *
 * @param {Object} data     The race data.
 * @param {Object} bracket  From model.buildBracket().
 * @returns {null|{source: 'timer'|'computed', notice: string|null, rows: Array<{placeFrom: number|null,
 *   placeTo: number|null, pilotId: number, callsign: string, roundLabel: string, heatId: number|null,
 *   result: string}>}} null when the class is no bracket.
 */
export function computeStandings( data, bracket ) {
    if ( ! bracket || ! bracket.ok || bracket.type === 'none' ) {
        return null;
    }
    if ( bracket.irregular.length ) {
        return { source: 'computed', notice: NOTICE_EDITED, rows: [] };
    }

    // Where each pilot went out, for the round label of a timer's ranking too.
    const outIn = new Map();
    for ( const exit of bracket.exits ) {
        const board = flownBoard( data, exit.heatId );
        const entry = board && board[ exit.rank - 1 ];
        if ( entry && entry.pilot_id ) {
            outIn.set( entry.pilot_id, { heatId: exit.heatId, rank: exit.rank } );
        }
    }
    const label = ( pilotId ) => {
        const out = outIn.get( pilotId );
        return out ? bracket.byId.get( out.heatId ).roundName : '';
    };

    const cls = model.classOf( data, bracket.classId );
    const ranking = cls && cls.win_condition === 'Brackets' ? model.classRanking( data, bracket.classId ) : null;
    if ( ranking && ranking.length ) {
        const rows = ranking
            .filter( ( e ) => e && e.pilot_id )
            .map( ( e ) => ( {
                placeFrom: e.position ?? null,
                placeTo: e.position ?? null,
                pilotId: e.pilot_id,
                callsign: e.callsign || '',
                roundLabel: label( e.pilot_id ),
                heatId: outIn.has( e.pilot_id ) ? outIn.get( e.pilot_id ).heatId : null,
                result: e.result || '',
            } ) );
        return { source: 'timer', notice: null, rows };
    }

    // The pilots of the first round: N, the bottom of the standing.
    const firstRound = new Set();
    for ( const heat of bracket.heats.filter( ( h ) => ! h.parents.length ) ) {
        for ( const entry of flownBoard( data, heat.id ) || [] ) {
            if ( entry && entry.pilot_id ) {
                firstRound.add( entry.pilot_id );
            }
        }
        const raw = ( data.heat_data.heats || [] ).find( ( h ) => h.id === heat.id );
        for ( const slot of ( raw && raw.slots ) || [] ) {
            if ( slot.pilot_id ) {
                firstRound.add( slot.pilot_id );
            }
        }
    }
    const total = firstRound.size;

    // Exits grouped by round, top first: the final, then a single elimination's small final, then
    // the rest by depth.
    const groups = new Map();
    for ( const exit of bracket.exits ) {
        const heat = bracket.byId.get( exit.heatId );
        let rank = 2;
        if ( heat.id === bracket.finalId ) {
            rank = 0;
        } else if ( heat.group === 'SF' ) {
            rank = 1;
        }
        const key = `${ heat.depth }:${ rank }`;
        if ( ! groups.has( key ) ) {
            groups.set( key, { depth: heat.depth, rank, exits: [], heats: new Set() } );
        }
        groups.get( key ).exits.push( exit );
        groups.get( key ).heats.add( heat.id );
    }
    const ordered = [ ...groups.values() ].sort( ( a, b ) => b.depth - a.depth || a.rank - b.rank );

    const cta = model.ctaState( data, bracket );
    const states = heatStates( data, bracket );
    const rows = [];
    let below = 0;
    for ( const group of ordered.reverse() ) {
        const complete = [ ...group.heats ].every( ( id ) => states.get( id ) !== 'open' );
        let members = [];
        for ( const exit of group.exits ) {
            const board = flownBoard( data, exit.heatId );
            const entry = board && board[ exit.rank - 1 ];
            if ( entry && entry.pilot_id ) {
                members.push( { pilotId: entry.pilot_id, callsign: entry.callsign || '', heatId: exit.heatId, rank: exit.rank } );
            }
        }
        if ( group.rank === 0 && cta.enabled ) {
            const order = chaseTheAceOrder( data, bracket.finalId );
            if ( ! order ) {
                // Chase the Ace still running: the final four have no places yet.
                members.forEach( ( m ) => rows.push( { ...m, placeFrom: null, placeTo: null, roundLabel: bracket.byId.get( m.heatId ).roundName, result: '' } ) );
                break;
            }
            members = order.map( ( e ) => ( { pilotId: e.pilot_id, callsign: e.callsign, heatId: bracket.finalId, rank: 0 } ) );
        }
        if ( ! complete ) {
            // Still running: those already out share the range of the round's places.
            const capacity = group.exits.length;
            const to = total - below;
            const from = Math.max( 1, to - capacity + 1 );
            members.forEach( ( m ) => rows.push( { ...m, placeFrom: from, placeTo: to, roundLabel: bracket.byId.get( m.heatId ).roundName, result: '' } ) );
            break;
        }
        const sorted = sortGroup( members, group, bracket );
        const to = total - below;
        let place = to - members.length + 1;
        sorted.forEach( ( m, index ) => {
            const shared = index && sameKey( sorted[ index - 1 ], m, group, bracket );
            const at = shared ? rows[ rows.length - 1 ].placeFrom : place;
            rows.push( { ...m, placeFrom: at, placeTo: at, roundLabel: bracket.byId.get( m.heatId ).roundName, result: '' } );
            place++;
        } );
        below += members.length;
    }
    rows.sort( ( a, b ) => ( a.placeFrom ?? 0 ) - ( b.placeFrom ?? 0 ) || ( a.placeTo ?? 0 ) - ( b.placeTo ?? 0 ) );
    return { source: 'computed', notice: null, rows };
}

function qual( bracket, member ) {
    return bracket.qualRank.has( member.pilotId ) ? bracket.qualRank.get( member.pilotId ) : Infinity;
}

// Order inside a round: one heat by its own order; several by the rulebook.
function sortGroup( members, group, bracket ) {
    const byRank = ( a, b ) => a.rank - b.rank;
    if ( group.heats.size <= 1 || group.rank === 0 ) {
        return [ ...members ].sort( group.rank === 0 ? () => 0 : byRank );
    }
    if ( bracket.rulebook === 'multigp' ) {
        return [ ...members ].sort( ( a, b ) => byRank( a, b ) || qual( bracket, a ) - qual( bracket, b ) );
    }
    return [ ...members ].sort( ( a, b ) => qual( bracket, a ) - qual( bracket, b ) || byRank( a, b ) );
}

// Two pilots nothing tells apart: several heats, no qualifying rank, the same rank in their heats.
function sameKey( a, b, group, bracket ) {
    if ( group.heats.size <= 1 || group.rank === 0 ) {
        return false;
    }
    return qual( bracket, a ) === Infinity && qual( bracket, b ) === Infinity && a.rank === b.rank;
}

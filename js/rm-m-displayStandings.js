// rm-m-displayStandings.js
//
// The standing of every bracket class: place - or the range still open, "17–24" - pilot, the
// round they went out in, and for a Chase the Ace final the wins. Under the brackets on the
// bracket view, live and archived, and as the "Final Ranking" of the next-up view. The places
// come from rm-m-bracketStandings.js.
//
// Config: RmJsConfig.displayStandings.containerId (default "standings-display").
// Exports const displayStandingsInstance = new DisplayStandings(); (at the bottom)

import { dataLoaderInstance } from './rm-m-dataLoader.js';
import * as bracketModel from './rm-m-bracketModel.js';
import { computeStandings } from './rm-m-bracketStandings.js';

const PODIUM = [ 'rm-place-first', 'rm-place-second', 'rm-place-third' ];

class DisplayStandings {
    constructor() {
        const configData = ( window.RmJsConfig && window.RmJsConfig.displayStandings ) || {};
        this.containerId = configData.containerId || 'standings-display';

        if ( document.readyState === 'complete' ) {
            this.initialize();
        } else {
            window.addEventListener( 'load', () => this.initialize() );
        }
    }

    initialize() {
        dataLoaderInstance.subscribe( this.handleDataLoaderEvent.bind( this ) );
    }

    handleDataLoaderEvent( data ) {
        const container = document.getElementById( this.containerId );
        if ( ! container ) {
            return;
        }
        container.innerHTML = '';
        const tables = this.standingsOf( data ).map( ( s ) => this.table( s ) );
        container.style.display = tables.length ? '' : 'none';
        tables.forEach( ( el ) => container.appendChild( el ) );
    }

    // One standing per class whose heats form a bracket, in the timer's class order.
    standingsOf( data ) {
        if ( ! data || ! data.class_data || ! data.heat_data ) {
            return [];
        }
        const classes = [ ...( data.class_data.classes || [] ) ];
        const ordered = classes.every( ( c ) => typeof c.order === 'number' )
            ? classes.sort( ( a, b ) => a.order - b.order || a.id - b.id )
            : classes.sort( ( a, b ) => a.id - b.id );
        const out = [];
        for ( const cls of ordered ) {
            const bracket = bracketModel.buildBracket( data, cls.id );
            const standing = computeStandings( data, bracket );
            if ( standing && ( standing.rows.length || standing.notice ) ) {
                out.push( { cls, bracket, standing, cta: bracketModel.ctaState( data, bracket ) } );
            }
        }
        return out;
    }

    table( { cls, bracket, standing, cta } ) {
        const wrap = document.createElement( 'section' );
        wrap.className = 'rm-standings';
        wrap.dataset.classId = String( cls.id );

        const heading = document.createElement( 'h2' );
        heading.textContent = `${ cls.displayname || cls.name || `Class ${ cls.id }` }: Standing`;
        wrap.appendChild( heading );

        if ( standing.notice ) {
            const p = document.createElement( 'p' );
            p.className = 'rm-standings-notice';
            p.textContent = standing.notice;
            wrap.appendChild( p );
            return wrap;
        }

        const table = document.createElement( 'table' );
        table.className = 'rm-standings-table';
        // The result column only where something stands in it: the timer's ranking, or wins.
        const withResult = standing.rows.some( ( row ) => this.resultText( row, bracket, cta ) );
        const columns = [ [ 'Place', 'place' ], [ 'Pilot', 'pilot' ], [ 'Out in', 'round' ] ];
        if ( withResult ) {
            columns.push( [ 'Result', 'result' ] );
        }
        const head = table.createTHead().insertRow();
        for ( const [ label, className ] of columns ) {
            const th = document.createElement( 'th' );
            th.className = className;
            th.textContent = label;
            head.appendChild( th );
        }
        const body = table.createTBody();
        for ( const row of standing.rows ) {
            const tr = body.insertRow();
            tr.className = `pilotid-${ row.pilotId }`;
            if ( row.placeFrom !== null && row.placeFrom === row.placeTo && row.placeFrom <= 3 ) {
                tr.classList.add( PODIUM[ row.placeFrom - 1 ] );
            }
            const cells = [
                [ 'place', this.placeText( row ) ],
                [ 'pilot', row.callsign ],
                [ 'round', row.roundLabel ],
            ];
            if ( withResult ) {
                cells.push( [ 'result', this.resultText( row, bracket, cta ) ] );
            }
            for ( const [ className, text ] of cells ) {
                const td = tr.insertCell();
                td.className = className;
                td.textContent = text;
            }
        }
        wrap.appendChild( table );
        return wrap;
    }

    placeText( row ) {
        if ( row.placeFrom === null ) {
            return '–';
        }
        return row.placeFrom === row.placeTo ? String( row.placeFrom ) : `${ row.placeFrom }–${ row.placeTo }`;
    }

    // What the timer's ranking says of a pilot; in a Chase the Ace final, the wins.
    resultText( row, bracket, cta ) {
        if ( cta && cta.enabled && row.heatId === bracket.finalId ) {
            return `${ cta.wins.get( row.pilotId ) || 0 }/${ cta.needed } wins`;
        }
        return row.result || '';
    }
}

export const displayStandingsInstance = new DisplayStandings();

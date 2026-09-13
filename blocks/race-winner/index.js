( function( wp ) {
    const { createElement, Fragment } = wp.element;
    const { __ } = wp.i18n;
    const { registerBlockType } = wp.blocks;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, RadioControl, SelectControl } = wp.components;

    // What the block stands for in the editor, by its setting. The winners come from the race's
    // results when the page is shown (includes/block-render-race-winner.php).
    const PLACEHOLDERS = {
        winner: [ '🏆', __( 'Winner of the race', 'wp-racemanager' ) ],
        podium: [ '🥇🥈🥉', __( 'Podium of the race', 'wp-racemanager' ) ],
        standing: [ '🥇🥈🥉', __( 'Podium and whole standing of the race - on its own page; elsewhere the podium', 'wp-racemanager' ) ],
    };
    // The link to the race's results (1.18.0): none, a line under the block, or the block itself.
    const LINKS = [ 'none', 'line', 'block' ];

    registerBlockType( 'wp-racemanager/race-winner', {
        edit: function( props ) {
            const { attributes, setAttributes } = props;
            const show = PLACEHOLDERS[ attributes.show ] ? attributes.show : 'winner';
            const link = LINKS.includes( attributes.link ) ? attributes.link : 'none';
            const blockProps = useBlockProps( { className: 'rm-race-winner' } );

            return createElement(
                Fragment,
                null,
                createElement(
                    InspectorControls,
                    null,
                    createElement(
                        PanelBody,
                        { title: __( 'Show', 'wp-racemanager' ) },
                        createElement( RadioControl, {
                            label: __( 'Per bracket class', 'wp-racemanager' ),
                            help: __( 'The standing is loaded on the race\'s own page; on the race list\'s cards the block shows the podium alone.', 'wp-racemanager' ),
                            selected: show,
                            options: [
                                { label: __( 'Winner', 'wp-racemanager' ), value: 'winner' },
                                { label: __( 'Podium (places 1–3)', 'wp-racemanager' ), value: 'podium' },
                                { label: __( 'Podium and whole standing', 'wp-racemanager' ), value: 'standing' },
                            ],
                            onChange: function( value ) { setAttributes( { show: value } ); },
                        } )
                    ),
                    createElement(
                        PanelBody,
                        { title: __( 'Link to the results', 'wp-racemanager' ) },
                        createElement( SelectControl, {
                            label: __( 'Link', 'wp-racemanager' ),
                            help: __( 'To the race\'s bracket view in the live area: the whole bracket, every heat\'s results and the standing - for a live race and an archived one alike.', 'wp-racemanager' ),
                            value: link,
                            options: [
                                { label: __( 'No link', 'wp-racemanager' ), value: 'none' },
                                { label: __( 'A line "Results" under it', 'wp-racemanager' ), value: 'line' },
                                { label: __( 'The whole block', 'wp-racemanager' ), value: 'block' },
                            ],
                            onChange: function( value ) { setAttributes( { link: value } ); },
                        } )
                    )
                ),
                createElement(
                    'div',
                    blockProps,
                    createElement(
                        'div',
                        null,
                        createElement( 'span', { className: 'rm-race-winner-cup', 'aria-hidden': 'true' }, PLACEHOLDERS[ show ][ 0 ] ),
                        ' ',
                        PLACEHOLDERS[ show ][ 1 ],
                        'block' === link ? ' - ' + __( 'a link to the results', 'wp-racemanager' ) : ''
                    ),
                    'line' === link ? createElement( 'div', { className: 'rm-race-winner-results' }, __( 'Results', 'wp-racemanager' ) + ' →' ) : null
                )
            );
        },
        save: function() {
            // Dynamic block: front-end output handled in PHP
            return null;
        }
    } );
} )( window.wp );

( function( wp ) {
    const { createElement, Fragment } = wp.element;
    const { __ } = wp.i18n;
    const { registerBlockType } = wp.blocks;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, RadioControl } = wp.components;

    // What the block stands for in the editor, by its setting. The winners come from the race's
    // results when the page is shown (includes/block-render-race-winner.php).
    const PLACEHOLDERS = {
        winner: [ '🏆', __( 'Winner of the race', 'wp-racemanager' ) ],
        podium: [ '🥇🥈🥉', __( 'Podium of the race', 'wp-racemanager' ) ],
        standing: [ '🥇🥈🥉', __( 'Podium and whole standing of the race - on its own page; elsewhere the podium', 'wp-racemanager' ) ],
    };

    registerBlockType( 'wp-racemanager/race-winner', {
        edit: function( props ) {
            const { attributes, setAttributes } = props;
            const show = PLACEHOLDERS[ attributes.show ] ? attributes.show : 'winner';
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
                    )
                ),
                createElement(
                    'div',
                    blockProps,
                    createElement( 'span', { className: 'rm-race-winner-cup', 'aria-hidden': 'true' }, PLACEHOLDERS[ show ][ 0 ] ),
                    ' ',
                    PLACEHOLDERS[ show ][ 1 ]
                )
            );
        },
        save: function() {
            // Dynamic block: front-end output handled in PHP
            return null;
        }
    } );
} )( window.wp );

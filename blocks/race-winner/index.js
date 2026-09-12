( function( wp ) {
    const { createElement } = wp.element;
    const { __ } = wp.i18n;
    const { registerBlockType } = wp.blocks;
    const { useBlockProps } = wp.blockEditor;

    // The winner comes from the race's results when the page is shown (includes/block-render-race-winner.php).
    registerBlockType( 'wp-racemanager/race-winner', {
        edit: function() {
            const blockProps = useBlockProps( { className: 'rm-race-winner' } );

            return createElement(
                'div',
                blockProps,
                createElement( 'span', { className: 'rm-race-winner-cup', 'aria-hidden': 'true' }, '🏆' ),
                ' ',
                __( 'Winner of the race', 'wp-racemanager' )
            );
        },
        save: function() {
            // Dynamic block: front-end output handled in PHP
            return null;
        }
    } );
} )( window.wp );

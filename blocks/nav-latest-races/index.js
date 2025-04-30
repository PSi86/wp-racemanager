( function( wp ) {
    const { createElement } = wp.element;
    const { __ } = wp.i18n;
    const { registerBlockType } = wp.blocks;
    const { useBlockProps } = wp.blockEditor;

    registerBlockType( 'wp-racemanager/nav-latest-races', {
        edit: function( props ) {
            // Grab the block props (including event handlers, className, etc.)
            const blockProps = useBlockProps();

            return createElement(
                'div',
                blockProps,
                __( 'RaceManager: Latest Races', 'wp-racemanager' )
            );
        },
        save: function() {
            // Dynamic block: front-end is rendered via PHP
            return null;
        }
    } );
} )( window.wp );

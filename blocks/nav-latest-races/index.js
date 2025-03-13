( function( wp ) {
    const { createElement } = wp.element;
    const { __ } = wp.i18n;

    wp.blocks.registerBlockType( 'wp-racemanager/nav-latest-races', {
        edit: function( props ) {
            return createElement(
                'div',
                { className: props.className },
                __( 'RaceManager: Latest Races', 'wp-racemanager' )
            );
        },
        save: function() {
            // This is a dynamic block so the save function returns null.
            return null;
        }
    } );
} )( window.wp );

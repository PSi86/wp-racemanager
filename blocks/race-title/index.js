( function( wp ) {
    const { createElement } = wp.element;
    const { __ } = wp.i18n;

    wp.blocks.registerBlockType( 'wp-racemanager/race-title', {
        edit: function( props ) {
            return createElement(
                'div',
                { className: props.className },
                __( 'This block will display the race title based on the URL parameter "race_id".', 'wp-racemanager' )
            );
        },
        save: function() {
            // This is a dynamic block so the save function returns null.
            return null;
        }
    } );
} )( window.wp );

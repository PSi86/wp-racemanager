( function( wp ) {
    const { createElement } = wp.element;
    const { __ } = wp.i18n;

    wp.blocks.registerBlockType( 'wp-racemanager/race-archive-toggle', {
        edit: function( props ) {
            return createElement(
                'div',
                { className: props.className },
                __( 'Display past or future events?', 'wp-racemanager' )
            );
        },
        save: function() {
            // This is a dynamic block so the save function returns null.
            return null;
        }
    } );
} )( window.wp );

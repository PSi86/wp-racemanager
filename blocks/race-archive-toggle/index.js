( function( wp ) {
    const { createElement } = wp.element;
    const { __ } = wp.i18n;
    const { registerBlockType } = wp.blocks;
    const { useBlockProps } = wp.blockEditor;

    registerBlockType( 'wp-racemanager/race-archive-toggle', {
        edit: function( props ) {
            const blockProps = useBlockProps();

            return createElement(
                'div',
                blockProps,
                __( 'Display past or future events?', 'wp-racemanager' )
            );
        },
        save: function() {
            return null; // dynamic block
        }
    } );
} )( window.wp );

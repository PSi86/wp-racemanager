( function( wp ) {
    const { createElement } = wp.element;
    const { __ } = wp.i18n;
    const { registerBlockType } = wp.blocks;
    const { useBlockProps } = wp.blockEditor;

    registerBlockType( 'wp-racemanager/race-title', {
        edit: function( props ) {
            const blockProps = useBlockProps();

            return createElement(
                'div',
                blockProps,
                __( 'Race Title', 'wp-racemanager' )
            );
        },
        save: function() {
            // Dynamic block: front-end output handled in PHP
            return null;
        }
    } );
} )( window.wp );

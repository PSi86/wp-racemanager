( function( wp ) {
    const { __ } = wp.i18n;
    const { registerBlockType } = wp.blocks;
    const { InspectorControls, useBlockProps } = wp.blockEditor;
    const { PanelBody, RangeControl, TextControl } = wp.components;
    
    registerBlockType( 'wp-racemanager/race-select', {
        edit: function( props ) {
            const { attributes, setAttributes } = props;
            const blockProps = useBlockProps();
            
            return wp.element.createElement(
                wp.element.Fragment,
                null,
                wp.element.createElement(
                    InspectorControls,
                    null,
                    wp.element.createElement(
                        PanelBody,
                        { title: __( 'Block Settings', 'wp-racemanager' ) },
                        wp.element.createElement( RangeControl, {
                            label: __( 'Posts Per Page', 'wp-racemanager' ),
                            value: attributes.postsPerPage,
                            onChange: function( value ) { setAttributes({ postsPerPage: value }); },
                            min: 1,
                            max: 20
                        } ),
                        wp.element.createElement( TextControl, {
                            label: __( 'Previous Text', 'wp-racemanager' ),
                            value: attributes.prevText,
                            onChange: function( value ) { setAttributes({ prevText: value }); }
                        } ),
                        wp.element.createElement( TextControl, {
                            label: __( 'Next Text', 'wp-racemanager' ),
                            value: attributes.nextText,
                            onChange: function( value ) { setAttributes({ nextText: value }); }
                        } )
                    )
                ),
                wp.element.createElement(
                    "div",
                    blockProps,
                    wp.element.createElement(
                        "p",
                        null,
                        __( 'Race Archive block will display race posts on the front-end.', 'wp-racemanager' )
                    )
                )
            );
        },
        save: function() {
            // Dynamic block: rendering is handled on the server.
            return null;
        }
    });
} )( window.wp );

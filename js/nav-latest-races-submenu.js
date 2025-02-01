( function( blocks, element ) {
    var el = element.createElement;

    blocks.registerBlockType( 'wp-racemanager/nav-latest-races-submenu', {
        title: 'Latest Races Submenu',
        icon: 'megaphone',
        category: 'navigation',
        parent: [ 'core/navigation-submenu' ],
        edit: function( props ) {
            return el(
                'div',
                { className: props.className },
                'RaceManager: Latest Races'
            );
        },
        save: function() {
            // Dynamic block – output is rendered via PHP.
            return null;
        },
    } );
} )( window.wp.blocks, window.wp.element );

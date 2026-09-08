// webpack.config.js
const fs = require( 'fs' );
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

// blocks/ is both the build output and the home of the six hand-written blocks
// that have no source under blocks-src/. wp-scripts empties its whole output
// directory before emit -- through webpack's own output.clean since v34, through
// CleanWebpackPlugin before that -- so without a keep rule every build silently
// deletes the hand-written blocks.
//
// The built blocks are exactly the directories under blocks-src/, so derive the
// rule from there rather than naming race-gallery here: adding a second source
// block then needs no change to this file.
const SOURCE_DIR = path.resolve( __dirname, 'blocks-src' );

const builtBlocks = fs.existsSync( SOURCE_DIR )
	? fs
			.readdirSync( SOURCE_DIR, { withFileTypes: true } )
			.filter( ( entry ) => entry.isDirectory() )
			.map( ( entry ) => entry.name )
	: [];

module.exports = {
	...defaultConfig,
	output: {
		...defaultConfig.output,
		clean: {
			// Called with each existing asset path relative to the output
			// directory; returning true keeps it. Everything outside a built
			// block's own folder survives, while stale files inside one are
			// still cleaned out.
			keep: ( asset ) =>
				! builtBlocks.some(
					( name ) =>
						asset === name || asset.startsWith( `${ name }/` )
				),
		},
	},
};

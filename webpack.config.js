// webpack.config.js
const defaultConfig          = require( '@wordpress/scripts/config/webpack.config' );
const { CleanWebpackPlugin } = require( 'clean-webpack-plugin' );

const entryNames = Object.keys( defaultConfig.entry );
// e.g. [ 'race-gallery', 'another-block', ... ]

module.exports = {
  ...defaultConfig,
  plugins: defaultConfig.plugins.map( ( plugin ) => {
    if ( plugin.constructor.name === 'CleanWebpackPlugin' ) {
      // only clean each block’s own folder under `blocks/`
      const cleanPatterns = entryNames.map( ( name ) => `${ name }/**/*` );
      return new CleanWebpackPlugin( {
        cleanOnceBeforeBuildPatterns: cleanPatterns,
        dangerouslyAllowCleanPatternsOutsideProject: false,
      } );
    }
    return plugin;
  } ),
};

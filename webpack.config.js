// webpack.config.js
const path = require('path');
const fs = require('fs');

const SRC_DIR  = path.resolve(__dirname, 'blocks-src');
const OUT_DIR  = path.resolve(__dirname, 'blocks');

// Scan blocks_src/* for index.js files and make one entry per folder
const entries = fs.readdirSync(SRC_DIR).reduce( (obj, name) => {
  const entryPath = path.join(SRC_DIR, name, 'index.js');
  if ( fs.existsSync(entryPath) ) {
    obj[name] = entryPath;
  }
  return obj;
}, {} );

module.exports = {
  entry: entries,
  output: {
    path: OUT_DIR,
    filename: '[name]/index.js',       // e.g. blocks/race-gallery/index.js
    libraryTarget: 'window',
  },
  module: {
    rules: [
      {
        test: /\.jsx?$/,
        exclude: /node_modules/,
        use: 'babel-loader',
      },
      // JSON import support (for block.json)
      {
        test: /\.json$/,
        type: 'json',
      },
    ],
  },
  resolve: {
    extensions: ['.js', '.jsx', '.json'],
  },
  externals: {
    react:           'React',
    'react-dom':     'ReactDOM',
    '@wordpress/blocks':      ['wp','blocks'],
    '@wordpress/element':     ['wp','element'],
    '@wordpress/block-editor':['wp','blockEditor'],
    '@wordpress/components':  ['wp','components'],
    '@wordpress/data':        ['wp','data'],
    '@wordpress/i18n':        ['wp','i18n'],
  },
};

const path = require('path');

module.exports = {
  entry: {
    'race-gallery': path.resolve(__dirname, 'blocks_src/race-gallery/index.js'),
  },
  output: {
    path: path.resolve(__dirname, 'blocks/race-gallery'),
    filename: 'index.js',
    libraryTarget: 'window',
  },
  module: {
    rules: [
      {
        test: /\.jsx?$/,
        exclude: /node_modules/,
        use: 'babel-loader',
      },
    ],
  },
  resolve: {
    extensions: ['.js', '.jsx', '.json'], // JSON import works out of the box
  },
  externals: {
    react: 'React',
    'react-dom': 'ReactDOM',
    '@wordpress/blocks': ['wp', 'blocks'],
    '@wordpress/element': ['wp', 'element'],
    '@wordpress/block-editor': ['wp', 'blockEditor'],
    '@wordpress/components': ['wp', 'components'],
    '@wordpress/data': ['wp', 'data'],
    '@wordpress/i18n': ['wp', 'i18n'],
  },
};

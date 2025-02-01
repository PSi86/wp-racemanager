module.exports = {
    env: {
      browser: true,
      es2021: true,
      node: true,
    },
    extends: [
      'eslint:recommended',
      'plugin:react/recommended',
      'plugin:@wordpress/eslint-plugin/recommended'
    ],
    parserOptions: {
      ecmaFeatures: {
        jsx: true
      },
      ecmaVersion: 12,
      sourceType: 'module',
    },
    plugins: [
      'react',
      '@wordpress'
    ],
    rules: {
      // Customize your rules here
    },
    settings: {
      react: {
        version: 'detect',
      },
    },
  };
  
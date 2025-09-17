import globals from 'globals';
import esLintBase from '../../eslint.config.js';

export default [
  { ignores: ['dist', 'eslint.config.js'] },
  ...esLintBase,
  {
    files: ['**/*.{ts,js}'],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
      parserOptions: {
        tsconfigRootDir: import.meta.dirname,
        project: ['./tsconfig.app.json', './tsconfig.node.json'],
      },
    },
  },
];

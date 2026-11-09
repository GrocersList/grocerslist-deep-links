import globals from 'globals';
import reactHooks from 'eslint-plugin-react-hooks';
import simpleImportSort from 'eslint-plugin-simple-import-sort';
import esLintBase from '../../eslint.config.js';

export default [
  // tests/harness is a page a browser opens from the file system, with the
  // fixtures theme-proof.test.ts writes into it.
  { ignores: ['dist', 'eslint.config.js', 'tests/harness'] },
  ...esLintBase,
  {
    files: ['**/*.{ts,tsx}'],
    languageOptions: {
      ecmaVersion: 2020,
      globals: globals.browser,
      parserOptions: {
        tsconfigRootDir: import.meta.dirname,
        project: [
          './tsconfig.app.json',
          './tsconfig.node.json',
          './tsconfig.test.json',
        ],
      },
    },
    plugins: {
      'simple-import-sort': simpleImportSort,
      'react-hooks': reactHooks,
    },
    rules: {
      '@typescript-eslint/no-explicit-any': 'off',
      'simple-import-sort/imports': [
        'error',
        {
          groups: [
            ['^react(-dom)?'],
            ['^@?\\w'],
            ['^(src|@app)(/.*|$)'],
            ['^\\u0000'],
            ['^\\.\\.(?!/?$)'],
            ['^\\.\\./?$'],
            ['^\\./(?=.*/)(?!/?$)'],
          ],
        },
      ],
      ...reactHooks.configs.recommended.rules,
    },
  },
];

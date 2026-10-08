import path from 'path';
import type { Plugin } from 'vite';
import { defineConfig } from 'vite';

const WORDPRESS_GLOBALS = {
  '@wordpress/api-fetch': 'wp.apiFetch',
  '@wordpress/block-editor': 'wp.blockEditor',
  '@wordpress/blocks': 'wp.blocks',
  '@wordpress/components': 'wp.components',
  '@wordpress/data': 'wp.data',
  '@wordpress/element': 'wp.element',
  '@wordpress/i18n': 'wp.i18n',
};

// Rollup cannot split an IIFE build, and WordPress enqueues each script on
// its own, so every entry is its own `vite build --mode <name>`. The first
// one in `yarn build` empties the output directory.
const ENTRIES: Record<string, { input: string; output: string }> = {
  editor: {
    input: 'src/form/editor.tsx',
    output: 'form/editor.js',
  },
  view: {
    input: 'src/form/view.ts',
    output: 'form/view.js',
  },
  'content-gate-editor': {
    input: 'src/content-gate/editor.tsx',
    output: 'content-gate/editor.js',
  },
};

// The start of an IIFE as Rollup writes one: (function(a,b){"use strict";
const IIFE_START = /\(function\([^()]*\)\{(?:"use strict";)?/;

// esbuild puts the helpers it needs at this target (object spread, say)
// before the IIFE, where they are globals: each bundle's minified names then
// overwrite another's (the form and content-gate editor scripts load side by
// side) and anything else on the page with the same name. Vite moves them
// into the IIFE only in library mode, so this does it here: after esbuild has
// run on the chunk, in generateBundle.
const helpersInsideIife = (): Plugin => ({
  name: 'grocerslist:helpers-inside-iife',
  apply: 'build',
  enforce: 'post',
  generateBundle(_options, bundle) {
    Object.values(bundle).forEach(output => {
      if (output.type !== 'chunk') {
        return;
      }

      const start = output.code.search(IIFE_START);
      if (start <= 0) {
        return;
      }

      const helpers = output.code.slice(0, start);
      output.code = output.code
        .slice(start)
        .replace(IIFE_START, iife => iife + helpers);
    });
  },
});

// Fails the build unless each script is one IIFE and nothing else: whatever
// sits beside it is a global on every page that loads the script. It checks
// the output, whatever put it there, so a Vite or Rollup whose IIFE the
// plugin above no longer finds cannot ship leaking scripts.
const oneIifePerScript = (): Plugin => ({
  name: 'grocerslist:one-iife-per-script',
  apply: 'build',
  enforce: 'post',
  generateBundle(_options, bundle) {
    Object.values(bundle).forEach(output => {
      if (output.type !== 'chunk') {
        return;
      }

      const { body } = this.parse(output.code);
      const statement = body.length === 1 ? body[0] : null;
      const iife =
        statement !== null &&
        statement.type === 'ExpressionStatement' &&
        statement.expression.type === 'CallExpression' &&
        /^(Arrow)?FunctionExpression$/.test(statement.expression.callee.type);

      if (!iife) {
        this.error(
          `${output.fileName} must be one IIFE with nothing beside it: ` +
            `${body.length} top-level statements (see helpersInsideIife)`
        );
      }
    });
  },
});

export default defineConfig(({ mode }) => {
  const entry = ENTRIES[mode];
  if (!entry) {
    throw new Error(
      `Unknown build mode "${mode}", expected one of: ${Object.keys(ENTRIES).join(', ')}`
    );
  }

  return {
    plugins: [helpersInsideIife(), oneIifePerScript()],
    esbuild: {
      jsx: 'transform',
      jsxFactory: 'createElement',
      jsxFragment: 'Fragment',
    },
    build: {
      outDir: path.resolve(
        process.cwd(),
        '../../build/grocerslist/blocks/dist'
      ),
      emptyOutDir: mode === Object.keys(ENTRIES)[0],
      manifest: false,
      target: 'es2017',

      rollupOptions: {
        input: entry.input,
        external: Object.keys(WORDPRESS_GLOBALS),

        output: {
          manualChunks: undefined,
          inlineDynamicImports: true,
          format: 'iife',
          globals: WORDPRESS_GLOBALS,
          entryFileNames: entry.output,
        },
      },
    },
  };
});

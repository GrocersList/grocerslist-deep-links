import preact from '@preact/preset-vite';
import path from 'path';
import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    outDir: path.resolve(
      process.cwd(),
      '../../build/grocerslist/admin-ui/dist'
    ),
    emptyOutDir: true,
    manifest: false,

    rollupOptions: {
      input: 'index.html',

      output: {
        manualChunks: undefined,
        inlineDynamicImports: true,
        entryFileNames: 'bundle.js',
      },
    },
  },
  plugins: [preact()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      react: 'preact/compat',
      'react-dom/client': 'preact/compat/client',
      'react-dom': 'preact/compat',
      'react/jsx-runtime': 'preact/jsx-runtime',
      'react/jsx-dev-runtime': 'preact/jsx-dev-runtime',
    },
  },
});

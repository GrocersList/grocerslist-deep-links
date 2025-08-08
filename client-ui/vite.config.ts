import path from 'path'
import { defineConfig } from 'vite'

export default defineConfig({
  build: {
    outDir: path.resolve(process.cwd(), '../../build/grocerslist/client-ui/dist'),
    emptyOutDir: true,
    manifest: false,

    rollupOptions: {
      input: 'src/main.ts',

      output: {
        manualChunks: undefined,
        inlineDynamicImports: true,
        entryFileNames: 'bundle.js',
      },
    },
  },
})
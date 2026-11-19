import { defineConfig } from 'vite';
import { resolve } from 'node:path';

/** The on-page editor (edit.js), built like extract.js: one IIFE the side panel injects on demand. */
export default defineConfig({
  publicDir: false,
  build: {
    outDir: process.env.SDT_E2E === '1' ? 'dist-e2e' : 'dist',
    emptyOutDir: false,
    lib: {
      entry: resolve(import.meta.dirname, 'src/edit/entry.ts'),
      name: 'SiteToDesignTokenEdit',
      formats: ['iife'],
      fileName: () => 'edit.js',
    },
  },
});

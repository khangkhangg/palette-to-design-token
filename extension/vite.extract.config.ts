import { defineConfig } from 'vite';
import { resolve } from 'node:path';

export default defineConfig({
  publicDir: false,
  build: {
    outDir: process.env.SDT_E2E === '1' ? 'dist-e2e' : 'dist',
    emptyOutDir: false,
    lib: {
      entry: resolve(import.meta.dirname, 'src/extract/entry.ts'),
      name: 'SiteToDesignTokenExtract',
      formats: ['iife'],
      fileName: () => 'extract.js',
    },
  },
});

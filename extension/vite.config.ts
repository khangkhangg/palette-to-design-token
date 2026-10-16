import { defineConfig } from 'vitest/config';
import type { Plugin } from 'vite';
import preact from '@preact/preset-vite';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** SDT_E2E=1 builds the test-only extension (Ruling R3) into dist-e2e; production builds are untouched. */
const E2E = process.env.SDT_E2E === '1';
const OUT_DIR = E2E ? 'dist-e2e' : 'dist';

/** Lets Playwright script the local fixture server without the user gesture activeTab needs. */
function e2eHostPermission(): Plugin {
  return {
    name: 'sdt-e2e-host-permission',
    apply: 'build',
    closeBundle() {
      const file = resolve(import.meta.dirname, OUT_DIR, 'manifest.json');
      const manifest = JSON.parse(readFileSync(file, 'utf8'));
      manifest.host_permissions = ['http://127.0.0.1/*'];
      writeFileSync(file, JSON.stringify(manifest, null, 2) + '\n');
    },
  };
}

export default defineConfig({
  plugins: [preact(), ...(E2E ? [e2eHostPermission()] : [])],
  build: {
    outDir: OUT_DIR,
    emptyOutDir: true,
    rollupOptions: {
      input: {
        popup: resolve(import.meta.dirname, 'popup.html'),
        sidepanel: resolve(import.meta.dirname, 'sidepanel.html'),
        background: resolve(import.meta.dirname, 'src/background.ts'),
      },
      output: {
        entryFileNames: (chunk) => (chunk.name === 'background' ? 'background.js' : 'assets/[name]-[hash].js'),
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
  test: { environment: 'node', include: ['test/unit/**/*.test.{ts,tsx}'] },
});

import { defineConfig } from '@playwright/test';

export const FIXTURE_PORT = 4517;

export default defineConfig({
  testDir: 'test/e2e',
  testIgnore: ['checkout.spec.ts', 'ai-router.spec.ts'], // need PHP + MariaDB: npm run e2e:checkout
  timeout: 30_000,
  workers: 1,
  reporter: 'list',
  webServer: {
    command: `node test/e2e/serve.mjs`,
    url: `http://127.0.0.1:${FIXTURE_PORT}/`,
    env: { PORT: String(FIXTURE_PORT) },
    reuseExistingServer: false,
    timeout: 10_000,
  },
});

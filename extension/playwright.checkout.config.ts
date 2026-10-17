import { defineConfig } from '@playwright/test';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';

/**
 * Checkout e2e (npm run e2e:checkout): the PHP pages under `php -S` with server/tests/dev-router.php,
 * on the local palette_test database; PayPal REST is stubbed server-side (PALETTE_DEV_PP_STUB) and the
 * PayPal JS SDK is replaced in the browser by the spec. PHP: $PHP_BIN, else the winget PHP 8.4, else `php`.
 */
export const CHECKOUT_PORT = 4518;

const winget = join(process.env.LOCALAPPDATA ?? '', 'Microsoft', 'WinGet', 'Packages', 'PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe', 'php.exe');
const php = process.env.PHP_BIN ?? (existsSync(winget) ? winget : 'php');
const router = resolve(import.meta.dirname, '../server/tests/dev-router.php');

export default defineConfig({
  testDir: 'test/e2e',
  // ai-router.spec.ts drives the built test extension (dist-e2e: run npm run e2e first) against action=analyse.
  testMatch: ['checkout.spec.ts', 'ai-router.spec.ts'],
  timeout: 30_000,
  workers: 1,
  reporter: 'list',
  use: { baseURL: `http://127.0.0.1:${CHECKOUT_PORT}` },
  webServer: {
    command: `"${php}" -q -S 127.0.0.1:${CHECKOUT_PORT} "${router}"`,
    url: `http://127.0.0.1:${CHECKOUT_PORT}/`,
    env: { PALETTE_DEV_PP_STUB: '1', PALETTE_DEV_AI_STUB: '1' },
    reuseExistingServer: false,
    timeout: 15_000,
  },
});

// npm run e2e:checkout: build the test-only extension (dist-e2e, which ai-router.spec.ts loads), then run the
// PHP-backed specs (checkout pages + AI insights against server/tests/dev-router.php) with Playwright.
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');

function run(cmd, args, env = {}) {
  const r = spawnSync([cmd, ...args].join(' '), { cwd: root, stdio: 'inherit', shell: true, env: { ...process.env, ...env } });
  if (r.status !== 0) process.exit(r.status ?? 1);
}

// Same test build as npm run e2e (fernleaf.example is the public-looking host the AI specs scan).
run('npx', ['vite', 'build'], { SDT_E2E: '1', SDT_E2E_HOSTS: 'fernleaf.example' });
run('npx', ['vite', 'build', '-c', 'vite.extract.config.ts'], { SDT_E2E: '1' });
run('npx', ['playwright', 'test', '-c', 'playwright.checkout.config.ts', ...process.argv.slice(2)]);

// npm run e2e: check the production build, then build the test-only extension (Ruling R3) and run Playwright.
import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');

function run(cmd, args, env = {}) {
  const r = spawnSync(cmd, args, { cwd: root, stdio: 'inherit', shell: true, env: { ...process.env, ...env } });
  if (r.status !== 0) process.exit(r.status ?? 1);
}

function files(dir) {
  return readdirSync(dir).flatMap((n) => {
    const p = join(dir, n);
    return statSync(p).isDirectory() ? files(p) : [p];
  });
}

// 1. Production build: no dev Pro toggle, no host permissions.
run('npm', ['run', 'build']);
const dist = join(root, 'dist');
const leaks = files(dist).filter((f) => /\.(js|html|json)$/.test(f) && readFileSync(f, 'utf8').includes('Dev: toggle Pro'));
if (leaks.length) {
  console.error(`Production dist contains "Dev: toggle Pro":\n  ${leaks.join('\n  ')}`);
  process.exit(1);
}
const manifest = JSON.parse(readFileSync(join(dist, 'manifest.json'), 'utf8'));
if ('host_permissions' in manifest) {
  console.error('Production dist/manifest.json has host_permissions');
  process.exit(1);
}
console.log('Production dist: no dev Pro toggle, no host_permissions.');

// 2. Test-only build into dist-e2e, then Playwright.
run('npx', ['vite', 'build'], { SDT_E2E: '1' });
run('npx', ['vite', 'build', '-c', 'vite.extract.config.ts'], { SDT_E2E: '1' });
if (!existsSync(join(root, 'dist-e2e', 'extract.js'))) {
  console.error('dist-e2e/extract.js missing');
  process.exit(1);
}
run('npx', ['playwright', 'test', ...process.argv.slice(2)]);

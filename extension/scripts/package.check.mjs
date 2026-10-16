// npm run package:check - runs `npm run package` and inspects the zip it produced.
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { unzipSync, strFromU8 } from 'fflate';

const root = resolve(import.meta.dirname, '..');
const version = JSON.parse(readFileSync(join(root, 'public/manifest.json'), 'utf8')).version;

test('npm run package builds a clean store zip', () => {
  const r = spawnSync('npm', ['run', 'package'], { cwd: root, shell: true, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stdout + r.stderr);
  const files = unzipSync(readFileSync(join(root, `site-to-design-token-${version}.zip`)));
  const names = Object.keys(files);
  assert.ok(names.includes('manifest.json'), 'manifest.json at zip root');
  assert.ok(names.includes('_locales/en/messages.json'));
  assert.ok(names.includes('icons/128.png'));
  assert.deepEqual(names.filter((n) => n.endsWith('.map')), []);
  assert.deepEqual(names.filter((n) => n.includes('dist-e2e')), []);
  const manifest = JSON.parse(strFromU8(files['manifest.json']));
  assert.equal(manifest.host_permissions, undefined);
  for (const n of names.filter((n) => /\.(js|html|json|css)$/.test(n))) {
    assert.ok(!strFromU8(files[n]).includes('Dev: toggle Pro'), n);
  }
});

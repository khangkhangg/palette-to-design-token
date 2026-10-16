// npm run package:check - runs `npm run package` and `npm run package:direct` and inspects the zips they produced.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { unzipSync, strFromU8 } from 'fflate';

const root = resolve(import.meta.dirname, '..');
const version = JSON.parse(readFileSync(join(root, 'public/manifest.json'), 'utf8')).version;
const PUBLIC_KEY = readFileSync(join(root, 'extension-public-key.txt'), 'utf8').trim();
const EXTENSION_ID = 'ldaogkdkcojplcghhpbhdbkdpdmgdcin';

/** Chrome's ID for an unpacked extension with a "key": sha256 of the DER key, first 16 bytes, hex 0-f mapped to a-p. */
function extensionId(base64Key) {
  const hex = createHash('sha256').update(Buffer.from(base64Key, 'base64')).digest('hex').slice(0, 32);
  return [...hex].map((h) => String.fromCharCode(97 + parseInt(h, 16))).join('');
}

function packageZip(script, file) {
  const r = spawnSync('npm', ['run', script], { cwd: root, shell: true, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stdout + r.stderr);
  return unzipSync(readFileSync(join(root, file)));
}

function assertClean(files) {
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
  return manifest;
}

test('the committed public key gives the published extension ID', () => {
  assert.equal(extensionId(PUBLIC_KEY), EXTENSION_ID);
});

test('npm run package builds a clean store zip without "key" (the Store rejects it)', () => {
  const manifest = assertClean(packageZip('package', `site-to-design-token-${version}.zip`));
  assert.equal(manifest.key, undefined);
});

test('npm run package:direct builds the same zip with "key", so a manual install gets the stable ID', () => {
  const files = packageZip('package:direct', `site-to-design-token-${version}-direct.zip`);
  const manifest = assertClean(files);
  assert.equal(manifest.key, PUBLIC_KEY);
  assert.equal(extensionId(manifest.key), EXTENSION_ID);
  const store = unzipSync(readFileSync(join(root, `site-to-design-token-${version}.zip`)));
  assert.deepEqual(Object.keys(files).sort(), Object.keys(store).sort());
});

test('the production build (dist) carries the key; public/manifest.json does not', () => {
  const dist = JSON.parse(readFileSync(join(root, 'dist/manifest.json'), 'utf8'));
  assert.equal(dist.key, PUBLIC_KEY);
  const pub = JSON.parse(readFileSync(join(root, 'public/manifest.json'), 'utf8'));
  assert.equal(pub.key, undefined);
});

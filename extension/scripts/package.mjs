// npm run package: production build, then dist/ -> site-to-design-token-<version>.zip (manifest.json at the zip root),
// for the Chrome Web Store: the manifest's "key" is stripped, because the Store rejects it.
// npm run package:direct (--direct): the same zip as site-to-design-token-<version>-direct.zip, keeping "key",
// so a manual "Load unpacked" install gets the stable extension ID.
import { spawnSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join, relative, resolve, sep } from 'node:path';
import { strFromU8, strToU8, zipSync } from 'fflate';

const root = resolve(import.meta.dirname, '..');
const dist = join(root, 'dist');
const direct = process.argv.includes('--direct');

const build = spawnSync('npm', ['run', 'build'], { cwd: root, stdio: 'inherit', shell: true });
if (build.status !== 0) process.exit(build.status ?? 1);

function walk(dir) {
  return readdirSync(dir).flatMap((n) => {
    const p = join(dir, n);
    return statSync(p).isDirectory() ? walk(p) : [p];
  });
}

const entries = {};
for (const f of walk(dist)) entries[relative(dist, f).split(sep).join('/')] = new Uint8Array(readFileSync(f));

const problems = [];
if (!entries['manifest.json']) problems.push('manifest.json is not at the zip root');
for (const name of Object.keys(entries)) {
  if (name.endsWith('.map')) problems.push(`source map: ${name}`);
  if (name.includes('dist-e2e')) problems.push(`dist-e2e content: ${name}`);
  if (/\.(js|html|json|css)$/.test(name) && strFromU8(entries[name]).includes('Dev: toggle Pro')) problems.push(`dev Pro toggle in ${name}`);
}
if (entries['manifest.json']) {
  const manifest = JSON.parse(strFromU8(entries['manifest.json']));
  if ('host_permissions' in manifest) problems.push('manifest.json has host_permissions');
  const key = readFileSync(join(root, 'extension-public-key.txt'), 'utf8').trim();
  if (direct) {
    if (manifest.key !== key) problems.push('direct zip: manifest.json lacks the key from extension-public-key.txt');
  } else {
    delete manifest.key;
    entries['manifest.json'] = strToU8(JSON.stringify(manifest, null, 2) + '\n');
  }
  if (!direct && 'key' in JSON.parse(strFromU8(entries['manifest.json']))) problems.push('store zip: manifest.json has "key"');
}
if (problems.length) {
  console.error(`Refusing to package:\n  ${problems.join('\n  ')}`);
  process.exit(1);
}

const { version } = JSON.parse(strFromU8(entries['manifest.json']));
const out = join(root, `site-to-design-token-${version}${direct ? '-direct' : ''}.zip`);
// Stored sorted with manifest.json first; level 9 deflate.
const ordered = Object.fromEntries(Object.entries(entries).sort(([a], [b]) => (a === 'manifest.json' ? -1 : b === 'manifest.json' ? 1 : a.localeCompare(b))));
writeFileSync(out, zipSync(ordered, { level: 9 }));
console.log(`${out} (${Object.keys(entries).length} files)`);

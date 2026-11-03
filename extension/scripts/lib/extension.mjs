// Shared harness for scripts that photograph the real extension UI (screenshots.mjs, promo.mjs):
// build dist-e2e, serve the fixtures, load the extension in Chromium and capture one view.
import { chromium } from '@playwright/test';
import { spawn, spawnSync } from 'node:child_process';
import { resolve } from 'node:path';

export const root = resolve(import.meta.dirname, '../..');
export const DIST = resolve(root, 'dist-e2e');
/** A friendly host for the scanned page: Chromium resolves it to the local fixture server (--host-resolver-rules). */
export const HOST = 'fernleaf.example';

function run(cmd, args, env = {}) {
  const r = spawnSync(cmd, args, { cwd: root, stdio: 'inherit', shell: true, env: { ...process.env, ...env } });
  if (r.status !== 0) process.exit(r.status ?? 1);
}

export function buildE2e() {
  run('npx', ['vite', 'build'], { SDT_E2E: '1', SDT_E2E_HOSTS: HOST });
  run('npx', ['vite', 'build', '-c', 'vite.extract.config.ts'], { SDT_E2E: '1', SDT_E2E_HOSTS: HOST });
}

/** Starts the fixture server and the extension. Returns what capture() needs, plus close(). */
export async function launch({ port, deviceScaleFactor = 1 }) {
  const server = spawn(process.execPath, ['test/e2e/serve.mjs'], { cwd: root, env: { ...process.env, PORT: String(port) }, stdio: 'ignore' });
  for (let i = 0; ; i++) {
    try { if ((await fetch(`http://127.0.0.1:${port}/`)).ok) break; } catch { /* not up yet */ }
    if (i > 50) { server.kill(); throw new Error('fixture server did not start'); }
    await new Promise((r) => setTimeout(r, 100));
  }
  const context = await chromium.launchPersistentContext('', {
    channel: 'chromium',
    deviceScaleFactor,
    args: [`--disable-extensions-except=${DIST}`, `--load-extension=${DIST}`, `--host-resolver-rules=MAP ${HOST} 127.0.0.1`],
  });
  const sw = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
  const extId = new URL(sw.url()).host;
  const site = `http://${HOST}:${port}`;
  return { context, sw, extId, site, close: async () => { await context.close(); server.kill(); } };
}

/**
 * Captures one extension view as a PNG buffer.
 * shot: { path, size: [w, h], theme, pro?, tab?, ai?: { result, lang }, export? }
 * lang: the extension's UI language ('en', 'zh-CN', 'ar', ...).
 */
export async function capture({ context, sw, extId, site }, shot, lang = 'en') {
  await sw.evaluate(async (settings) => { await chrome.storage.local.set({ settings }); },
    { theme: shot.theme, lang, pro: !!shot.pro });
  if (shot.ai) {
    const now = new Date().toISOString();
    await sw.evaluate(async (v) => { await chrome.storage.local.set(v); }, {
      aiResults: [{ host: HOST, result: shot.ai.result, usage: { used: 12, limit: 100, resetsOn: '2026-11-17' }, analysedAt: now, lang: shot.ai.lang, cached: false }],
      licenseAi: { key: 'SDT-ABCD-2345-6789', verifiedAt: now, emailMasked: null, plan: 'ai', paidThrough: new Date(Date.now() + 20 * 86_400_000).toISOString(),
        usage: { used: 12, limit: 100, resetsOn: '2026-11-17' }, subStatus: 'active', lastCheck: 'ok', lastAttemptAt: now },
    });
  } else {
    await sw.evaluate(async () => { await chrome.storage.local.remove(['aiResults', 'licenseAi']); });
  }
  // The fixture must be the active tab: the extension pages scan the active tab of the window.
  const url = `${site}/light-marketing.html`;
  const fixture = await context.newPage();
  await fixture.goto(url);
  await fixture.bringToFront();
  await sw.evaluate(async (u) => {
    const tab = (await chrome.tabs.query({})).find((t) => t.url === u);
    await chrome.tabs.update(tab.id, { active: true });
  }, url);

  const pending = context.waitForEvent('page', (p) => p.url().startsWith(`chrome-extension://${extId}/`));
  await sw.evaluate(async (u) => { await chrome.tabs.create({ url: u, active: false }); }, `chrome-extension://${extId}/${shot.path}`);
  const ext = await pending;
  try {
    await ext.setViewportSize({ width: shot.size[0], height: shot.size[1] });
    if (!shot.pro) await ext.locator('.swatch').first().or(ext.locator('.sp-header')).first().waitFor();
    if (shot.path.startsWith('sidepanel') && !shot.pro) await ext.locator('.status').waitFor({ state: 'detached' }).catch(() => {});
    if (shot.tab) { await ext.locator(shot.tab).click(); await ext.locator('.pair-colours').first().waitFor(); }
    if (shot.pro && !shot.ai) await ext.locator('.export-title').waitFor();
    if (shot.ai) await ext.locator('.ai-verdict').waitFor();
    // Background tabs freeze CSS transitions; bring the page forward only now that its scan is done.
    await ext.bringToFront();
    await ext.evaluate(() => document.fonts.ready);
    await ext.waitForTimeout(900);
    if (!shot.ai) return await ext.screenshot();
    const results = ext.locator('.ai-results');
    const buf = await results.screenshot();
    // Where each block of the results starts (CSS px from the top), so a caller can split the tall panel at a block edge.
    buf.blockTops = await results.evaluate((el) => {
      const top = el.getBoundingClientRect().top;
      return [...el.children].map((c) => Math.round(c.getBoundingClientRect().top - top));
    });
    buf.cssHeight = await results.evaluate((el) => Math.round(el.getBoundingClientRect().height));
    return buf;
  } finally {
    await ext.close();
    await fixture.close();
  }
}

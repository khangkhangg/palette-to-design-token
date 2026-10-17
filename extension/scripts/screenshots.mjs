// npm run screenshots: builds dist-e2e, loads it in Chromium, scans the fixtures and writes the five
// 1280x800 Chrome Web Store screenshots to docs/store/screenshots/. The sixth (AI results) uses a stubbed analysis, kept in the
// extension's storage as a finished result, so no server is needed. The Chrome Web Store takes at most five: upload 1, 2, 3, 5 and 6.
import { chromium } from '@playwright/test';
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { geistCss } from './lib/fonts.mjs';

const root = resolve(import.meta.dirname, '..');
const DIST = resolve(root, 'dist-e2e');
const OUT = resolve(root, '../docs/store/screenshots');
const PORT = 4518;
/** A friendly host for the scanned page: Chromium resolves it to the local fixture server (--host-resolver-rules). */
const HOST = 'fernleaf.example';
const BASE = `http://127.0.0.1:${PORT}`;
const SITE = `http://${HOST}:${PORT}`;

function run(cmd, args, env = {}) {
  const r = spawnSync(cmd, args, { cwd: root, stdio: 'inherit', shell: true, env: { ...process.env, ...env } });
  if (r.status !== 0) process.exit(r.status ?? 1);
}

run('npx', ['vite', 'build'], { SDT_E2E: '1', SDT_E2E_HOSTS: HOST });
run('npx', ['vite', 'build', '-c', 'vite.extract.config.ts'], { SDT_E2E: '1', SDT_E2E_HOSTS: HOST });

const server = spawn(process.execPath, ['test/e2e/serve.mjs'], { cwd: root, env: { ...process.env, PORT: String(PORT) }, stdio: 'ignore' });
for (let i = 0; ; i++) {
  try { if ((await fetch(`${BASE}/`)).ok) break; } catch { /* not up yet */ }
  if (i > 50) throw new Error('fixture server did not start');
  await new Promise((r) => setTimeout(r, 100));
}

const SHOTS = [
  { file: '1-popup-colours.png', caption: 'Every colour on the page, with its role', path: 'popup.html', size: [400, 600], theme: 'light' },
  { file: '2-contrast.png', caption: 'Contrast pass and fail, checked against WCAG AA', path: 'popup.html', size: [400, 600], theme: 'light', tab: '#tab-contrast' },
  { file: '3-side-panel-light.png', caption: 'The whole design system in the side panel', path: 'sidepanel.html', size: [440, 680], theme: 'light' },
  { file: '4-side-panel-dark.png', caption: 'Light and dark themes', path: 'sidepanel.html', size: [440, 680], theme: 'dark' },
  { file: '5-export.png', caption: 'Pro: export CSS, Tailwind, Figma, JSON and DESIGN.md', path: 'sidepanel.html?view=export', size: [440, 680], theme: 'light', pro: true },
  { file: '6-ai-insights.png', caption: 'Pro + AI: a verdict, what the colours mean, and similar sites', path: 'sidepanel.html', size: [440, 1500], theme: 'light', pro: true, ai: true },
];

/** The canned answer of the dev router's AI stub (same shape as the server's analyse result). */
const AI_RESULT = {
  sector: 'Gardening and home', tags: ['Online shop', 'Friendly, natural'], verdict: 'competitive',
  summary: 'A calm, green storefront that reads as a trusted garden brand. The palette is restrained and the type is easy to read.',
  colours: [
    { hex: '#1f5e4b', title: 'Deep green: grounded, trustworthy', meaning: 'Sets the natural, reliable tone of the brand.' },
    { hex: '#e0763a', title: 'Warm orange: friendly, energetic', meaning: 'Draws the eye to buttons and offers.' },
  ],
  fit: { level: 'strong', reason: 'The colours match what gardeners expect from a growing brand.' },
  works: ['Consistent palette', 'Readable body text'], improve: ['Raise contrast on muted labels'],
  sites: [
    { name: 'Greenhouse Supply', domain: 'greenhouse-supply.example', url: 'https://greenhouse-supply.example/', why: 'Same market, similar tone', relation: 'competitor', source: 'web' },
    { name: 'Leaf & Loam', domain: 'leafandloam.example', url: 'https://leafandloam.example/', why: 'Direct competitor online', relation: 'competitor', source: 'web' },
    { name: 'Sprout Studio', domain: 'sproutstudio.example', url: 'https://sproutstudio.example/', why: 'Similar visual style', relation: 'similar', source: 'ai' },
  ],
  search_unavailable: false,
};

const frame = (img, caption) => `<!doctype html><style>${geistCss()}
html,body{margin:0}
body{width:1280px;height:800px;box-sizing:border-box;background:linear-gradient(180deg,#F3F2EF,#E7E6E2);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;font-family:'Geist',sans-serif}
img{display:block;max-height:640px;border-radius:14px;box-shadow:0 24px 60px rgba(20,24,30,.22),0 0 0 1px rgba(20,24,30,.08)}
p{margin:0;font-size:28px;font-weight:600;letter-spacing:-.015em;color:#1B1E24}
</style><img src="${img}"><p>${caption}</p>`;

mkdirSync(OUT, { recursive: true });
const context = await chromium.launchPersistentContext('', {
  channel: 'chromium',
  args: [`--disable-extensions-except=${DIST}`, `--load-extension=${DIST}`, `--host-resolver-rules=MAP ${HOST} 127.0.0.1`],
});
try {
  const sw = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
  const extId = new URL(sw.url()).host;
  const composer = await context.newPage();
  await composer.setViewportSize({ width: 1280, height: 800 });

  for (const s of SHOTS) {
    await sw.evaluate(async (settings) => { await chrome.storage.local.set({ settings }); },
      { theme: s.theme, lang: 'en', pro: !!s.pro });
    if (s.ai) {
      const now = new Date().toISOString();
      await sw.evaluate(async (v) => { await chrome.storage.local.set(v); }, {
        aiResults: [{ host: HOST, result: AI_RESULT, usage: { used: 12, limit: 100, resetsOn: '2026-11-17' }, analysedAt: now, lang: 'en', cached: false }],
        licenseAi: { key: 'SDT-ABCD-2345-6789', verifiedAt: now, emailMasked: null, plan: 'ai', paidThrough: new Date(Date.now() + 20 * 86_400_000).toISOString(),
          usage: { used: 12, limit: 100, resetsOn: '2026-11-17' }, subStatus: 'active', lastCheck: 'ok', lastAttemptAt: now },
      });
    } else {
      await sw.evaluate(async () => { await chrome.storage.local.remove(['aiResults', 'licenseAi']); });
    }
    // The fixture must be the active tab: the extension pages scan the active tab of the window.
    const fixture = await context.newPage();
    await fixture.goto(`${SITE}/light-marketing.html`);
    await fixture.bringToFront();
    const url = `${SITE}/light-marketing.html`;
    await sw.evaluate(async (u) => {
      const tab = (await chrome.tabs.query({})).find((t) => t.url === u);
      await chrome.tabs.update(tab.id, { active: true });
    }, url);

    const pending = context.waitForEvent('page', (p) => p.url().startsWith(`chrome-extension://${extId}/`));
    await sw.evaluate(async (u) => { await chrome.tabs.create({ url: u, active: false }); }, `chrome-extension://${extId}/${s.path}`);
    const ext = await pending;
    await ext.setViewportSize({ width: s.size[0], height: s.size[1] });
    if (!s.pro) await ext.locator('.swatch').first().or(ext.locator('.sp-header')).first().waitFor();
    if (s.path.startsWith('sidepanel') && !s.pro) await ext.locator('.status').waitFor({ state: 'detached' }).catch(() => {});
    if (s.tab) { await ext.locator(s.tab).click(); await ext.locator('.pair-colours').first().waitFor(); }
    if (s.pro && !s.ai) await ext.getByRole('heading', { name: 'Export tokens' }).waitFor();
    if (s.ai) await ext.locator('.ai-verdict').waitFor();
    // Background tabs freeze CSS transitions; bring the page forward only now that its scan is done.
    await ext.bringToFront();
    await ext.waitForTimeout(900);
    const buf = s.ai ? await ext.locator('.ai-results').screenshot() : await ext.screenshot();

    await composer.setContent(frame(`data:image/png;base64,${buf.toString('base64')}`, s.caption));
    await composer.evaluate(() => document.fonts.ready);
    await composer.screenshot({ path: resolve(OUT, s.file) });
    await ext.close();
    await fixture.close();
    console.log('wrote', s.file);
  }
} finally {
  await context.close();
  server.kill();
}

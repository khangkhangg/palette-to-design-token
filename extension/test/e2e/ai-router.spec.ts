import { chromium, expect, test, type BrowserContext, type Page, type Worker } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { CHECKOUT_PORT } from '../../playwright.checkout.config';

/**
 * AI insights end to end (npm run e2e:checkout): the built test extension (dist-e2e, which npm run e2e:checkout builds first) posts
 * action=analyse to the real api.php under `php -S` with server/tests/dev-router.php, PALETTE_DEV_AI_STUB=1
 * (canned web search + model answer) on the palette_test database. Playwright forwards the extension's
 * https://vietnamcos.com/palette/* requests to the router and serves the scanned page under a public host.
 */
const DIST = resolve(import.meta.dirname, '../../dist-e2e');
const ROUTER = `http://127.0.0.1:${CHECKOUT_PORT}`;
const HOST = 'fernleaf.example'; // in dist-e2e's host_permissions (scripts/e2e.mjs)
const PAGE_HTML = readFileSync(resolve(import.meta.dirname, '../fixtures/light-marketing.html'), 'utf8');

let context: BrowserContext;
let sw: Worker;
let extId: string;
const opened: Page[] = [];

test.beforeAll(async () => {
  if (!existsSync(resolve(DIST, 'manifest.json'))) throw new Error('dist-e2e is missing: run it through npm run e2e:checkout');
  context = await chromium.launchPersistentContext('', {
    channel: 'chromium',
    args: [`--disable-extensions-except=${DIST}`, `--load-extension=${DIST}`],
  });
  sw = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
  extId = new URL(sw.url()).host;
  // The scanned site: the light-marketing fixture under a public host, or a page whose title makes the model fail.
  await context.route(`http://${HOST}/**`, (route) => {
    const fail = new URL(route.request().url()).pathname === '/fail.html';
    const html = fail ? PAGE_HTML.replace(/<title>[^<]*<\/title>/, '<title>Fernleaf dev-ai-fail</title>') : PAGE_HTML;
    return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
  });
  // The extension's API calls go to the dev router; its CORS answer (extension id allowed by the seed) comes back as is.
  await context.route('https://vietnamcos.com/palette/**', async (route) => {
    const url = new URL(route.request().url());
    const response = await route.fetch({ url: `${ROUTER}${url.pathname}${url.search}` });
    return route.fulfill({ response });
  });
});

test.afterEach(async () => {
  await Promise.all(opened.splice(0).map((p) => p.close().catch(() => {})));
});

test.afterAll(async () => {
  await context?.close();
});

/** Fresh palette tables with this extension allowed, and a Pro + AI key stored in the extension. */
async function seedWithKey(): Promise<void> {
  const seed = await context.request.get(`${ROUTER}/__dev/seed?ext=${extId}`);
  expect(await seed.text()).toBe('seeded');
  const key = (await (await context.request.get(`${ROUTER}/__dev/license?plan=ai&email=ai-e2e@studio.vn`)).text()).trim();
  expect(key).toMatch(/^SDT-/);
  const now = new Date().toISOString();
  await sw.evaluate(async (v) => { await chrome.storage.local.clear(); await chrome.storage.local.set(v); }, {
    settings: { theme: 'light', lang: 'en', pro: true },
    licenseAi: {
      key, verifiedAt: now, emailMasked: null, plan: 'ai', paidThrough: new Date(Date.now() + 29 * 86_400_000).toISOString(),
      usage: { used: 0, limit: 100, resetsOn: '2026-11-17' }, subStatus: 'active', lastCheck: 'ok', lastAttemptAt: now,
    },
  });
}

async function openPanelOn(path: string): Promise<Page> {
  const site = await context.newPage();
  opened.push(site);
  await site.goto(`http://${HOST}${path}`);
  await site.bringToFront();
  await sw.evaluate(async (u) => {
    const tab = (await chrome.tabs.query({})).find((t) => t.url === u);
    if (!tab?.id) throw new Error(`no tab for ${u}`);
    await chrome.tabs.update(tab.id, { active: true });
  }, site.url());
  const pending = context.waitForEvent('page', (p) => p.url().startsWith(`chrome-extension://${extId}/sidepanel.html`));
  await sw.evaluate(async (url) => { await chrome.tabs.create({ url, active: false }); }, `chrome-extension://${extId}/sidepanel.html`);
  const panel = await pending;
  opened.push(panel);
  await panel.setViewportSize({ width: 400, height: 900 });
  return panel;
}

test('Analyse against the dev router: results render with web and AI sources, and one analysis is used', async () => {
  test.setTimeout(60_000);
  await seedWithKey();
  const panel = await openPanelOn('/light-marketing.html?utm_source=x#top');
  await panel.getByRole('button', { name: `Analyse ${HOST}` }).click();

  await expect(panel.locator('.ai-verdict')).toHaveText('On par', { timeout: 30_000 });
  await expect(panel.locator('.ai-tags li')).toHaveText(['Specialty coffee', 'Online shop', 'Premium, craft']);
  await expect(panel.locator('.ai-meaning')).toHaveCount(2);
  const sites = panel.locator('.ai-site');
  await expect(sites).toHaveCount(3);
  // northwind / harbor-coffee came from the canned search results (links); brewlab only from the model (plain text).
  await expect(panel.locator('a.ai-site')).toHaveCount(2);
  await expect(sites.filter({ hasText: 'northwind.example' }).locator('.ai-badge')).toHaveText('Web');
  await expect(sites.filter({ hasText: 'brewlab.example' }).locator('.ai-badge')).toHaveText('AI · check it');
  await expect(panel.locator('a.ai-site', { hasText: 'brewlab.example' })).toHaveCount(0);
  await expect(sites.first()).toHaveAttribute('href', 'https://northwind.example/');
  await expect(panel.locator('.ai-footer').first()).toContainText('used 1 of 100');
  await expect(panel.locator('.ai-sub')).toContainText('99 analyses left');
});

test('a failing model gives a friendly error and charges nothing', async () => {
  test.setTimeout(60_000);
  await seedWithKey();
  const panel = await openPanelOn('/fail.html');
  await panel.getByRole('button', { name: `Analyse ${HOST}` }).click();
  await expect(panel.getByRole('alert')).toContainText("The AI isn't available right now. No analysis was used.", { timeout: 30_000 });
  await expect(panel.getByRole('button', { name: 'Try again' })).toBeVisible();
  // The licence still has all 100 analyses: verify reports usage straight from the server.
  const key = await sw.evaluate(async () => ((await chrome.storage.local.get('licenseAi')).licenseAi as { key: string }).key);
  const verify = await context.request.post(`${ROUTER}/palette/api.php?action=verify`, { data: { key } });
  expect((await verify.json()).usage).toMatchObject({ used: 0, limit: 100 });
});

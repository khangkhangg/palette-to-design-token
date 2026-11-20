import { chromium, expect, test, type BrowserContext, type Page, type Worker } from '@playwright/test';
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { CHECKOUT_PORT } from '../../playwright.checkout.config';

/**
 * Token directory end to end (npm run e2e:checkout): the built test extension takes a real
 * screenshot of a fixture page and shares it through api.php under `php -S`
 * (server/tests/dev-router.php), then the directory and admin pages show the item.
 */

const DIST = resolve(import.meta.dirname, '../../dist-e2e');
const ROUTER = `http://127.0.0.1:${CHECKOUT_PORT}`;
const HOST = 'fernleaf.example';
const PAGE_HTML = readFileSync(resolve(import.meta.dirname, '../fixtures/light-marketing.html'), 'utf8');
const T = JSON.parse(readFileSync(resolve(import.meta.dirname, '../../src/i18n/en.json'), 'utf8')).share;
const SHOTS = resolve(import.meta.dirname, '../../test-results/directory');

let context: BrowserContext;
let sw: Worker;
let extId: string;

test.beforeAll(async () => {
  if (!existsSync(resolve(DIST, 'manifest.json'))) throw new Error('dist-e2e is missing: run it through npm run e2e:checkout');
  context = await chromium.launchPersistentContext('', {
    channel: 'chromium',
    args: [`--disable-extensions-except=${DIST}`, `--load-extension=${DIST}`],
  });
  sw = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
  extId = new URL(sw.url()).host;
  await context.route(`http://${HOST}/**`, (route) =>
    route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: PAGE_HTML }));
  await context.route('https://vietnamcos.com/palette/**', async (route) => {
    const url = new URL(route.request().url());
    const response = await route.fetch({ url: `${ROUTER}${url.pathname.replace('//', '/')}${url.search}` });
    return route.fulfill({ response });
  });
});

test.afterAll(async () => {
  await context?.close();
});

/** Signs in as a member and connects the extension the way the W5 page does. */
async function connect(page: Page): Promise<string> {
  await page.goto(`${ROUTER}/__dev/login?user_id=5120`);
  // The page is not on vietnamcos.com, so stand in for chrome.runtime and keep the token it hands over.
  await page.addInitScript(() => {
    const w = window as unknown as { __sent: unknown[] };
    w.__sent = [];
    Object.defineProperty(window, 'chrome', {
      configurable: true,
      value: { runtime: { sendMessage(_id: string, msg: unknown, cb: (r: unknown) => void) { w.__sent.push(msg); setTimeout(() => cb({ ok: true }), 10); } } },
    });
  });
  await page.goto(`${ROUTER}/palette/directory/connect?ext=${extId}`);
  await page.screenshot({ path: `${SHOTS}/w5-connect.png`, fullPage: true });
  await page.getByRole('button', { name: /Connect/ }).click();
  const handle = await page.waitForFunction(() =>
    ((window as unknown as { __sent: { token?: string }[] }).__sent[0] ?? {}).token);
  return (await handle.jsonValue()) as string;
}

test('share a page from the side panel, then find, download and moderate it', async () => {
  test.setTimeout(120_000);
  const seed = await context.request.get(`${ROUTER}/__dev/seed?ext=${extId}`);
  expect(await seed.text()).toBe('seeded');

  const page = await context.newPage();
  const token = await connect(page);
  expect(token.length).toBeGreaterThan(20);
  await sw.evaluate(async (dirToken) => {
    await chrome.storage.local.clear();
    await chrome.storage.local.set({ settings: { theme: 'light', lang: 'en', pro: false, dirToken } });
  }, token);

  // The site must stay the active tab: the panel opens next to it, inactive.
  const site = await context.newPage();
  await site.goto(`http://${HOST}/light-marketing.html?utm_source=x#top`);
  await site.bringToFront();
  const pending = context.waitForEvent('page', (p) => p.url().startsWith(`chrome-extension://${extId}/sidepanel.html`));
  await sw.evaluate(async (url) => { await chrome.tabs.create({ url, active: false }); }, `chrome-extension://${extId}/sidepanel.html?view=export`);
  const panel = await pending;
  await panel.setViewportSize({ width: 400, height: 900 });

  await panel.getByRole('button', { name: T.open, exact: true }).click();
  await panel.getByRole('button', { name: T.takeShot }).click();
  await expect(panel.locator('.share-shot img')).toBeVisible({ timeout: 15_000 });
  await expect(panel.locator('.share-shot figcaption')).toContainText('KB');
  await expect(panel.locator('input[readonly]')).toHaveValue(`http://${HOST}/light-marketing.html`);
  const shareBtn = panel.getByRole('button', { name: T.sharePublicly });
  await expect(shareBtn).toBeDisabled();
  await panel.getByLabel('Editorial').check();
  await panel.getByLabel(T.consent).check();
  await panel.screenshot({ path: `${SHOTS}/e4-review.png` });
  await shareBtn.click();
  await expect(panel.getByText(T.sharedTitle, { exact: true })).toBeVisible({ timeout: 15_000 });
  await panel.screenshot({ path: `${SHOTS}/e6-shared.png`, fullPage: true });

  // The directory lists it under its style, and a signed-in member can download its files.
  await page.goto(`${ROUTER}/palette/directory?style=editorial`);
  const card = page.locator(`a[href*="/palette/directory/"]`).filter({ hasText: 'Plan your garden' }).first();
  await expect(card).toBeVisible();
  // The card shows the screenshot the extension just took.
  await expect.poll(() => card.locator('img').evaluate((i: HTMLImageElement) => i.complete ? i.naturalWidth : 0)).toBeGreaterThan(0);
  await page.screenshot({ path: `${SHOTS}/w1-list.png`, fullPage: true });
  await card.click();
  await expect(page.getByText('Editorial').first()).toBeVisible();
  await page.screenshot({ path: `${SHOTS}/w2-detail.png`, fullPage: true });
  const base = page.url().split('?')[0];
  const shot = await context.request.get(`${base}/shot.webp`);
  expect(shot.headers()['content-type']).toContain('image/webp');
  const tokens = await context.request.get(`${base}/tokens.json`);
  expect(tokens.status()).toBe(200);
  expect(await tokens.json()).toBeTruthy();

  // The admin New list holds it until an admin marks it as checked.
  await page.goto(`${ROUTER}/__dev/login?user_id=1&role=admin`);
  await page.goto(`${ROUTER}/palette/admin.php?tab=directory`);
  await expect(page.locator('tr', { hasText: HOST }).first()).toBeVisible();
  await page.screenshot({ path: `${SHOTS}/a1-new.png`, fullPage: true });
});

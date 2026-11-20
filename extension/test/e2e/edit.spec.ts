import { chromium, expect, test, type BrowserContext, type Page, type Worker } from '@playwright/test';
import { resolve } from 'node:path';
import { FIXTURE_PORT } from '../../playwright.config';

/** "Edit on page": the side panel drives the editor it injects into the fixture tab. */
const DIST = resolve(import.meta.dirname, '../../dist-e2e');
const URL_ = `http://127.0.0.1:${FIXTURE_PORT}/light-marketing.html`;
const GREEN = 'rgb(31, 94, 75)';
const PURPLE = 'rgb(122, 63, 196)';

let context: BrowserContext;
let sw: Worker;
let extId: string;
let page: Page;
let panel: Page;

const bg = (sel: string) => page.locator(sel).first().evaluate((el) => getComputedStyle(el).backgroundColor);
const css = (sel: string, prop: string) => page.locator(sel).first().evaluate((el, p) => getComputedStyle(el).getPropertyValue(p), prop);
const inspector = () => panel.locator('.ed-insp');
const row = (name: string) => inspector().locator('.ed-rows li', { hasText: name });
const scope = (name: string) => inspector().locator('.ed-scope label', { hasText: name }).click();

test.beforeAll(async () => {
  context = await chromium.launchPersistentContext('', {
    channel: 'chromium',
    viewport: { width: 1200, height: 900 },
    args: [`--disable-extensions-except=${DIST}`, `--load-extension=${DIST}`],
  });
  sw = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
  extId = new URL(sw.url()).host;
  await sw.evaluate(async () => { await chrome.storage.local.set({ settings: { theme: 'light', lang: 'en', pro: true } }); });
});

test.afterAll(async () => { await context?.close(); });

/** Opens a fixture as the active tab with the side panel beside it (a background tab, as the real panel never takes focus) and turns editing on. */
async function startEditing(url: string): Promise<void> {
  page = await context.newPage();
  await page.goto(url);
  await page.bringToFront();
  await sw.evaluate(async (u) => {
    const tab = (await chrome.tabs.query({})).find((t) => t.url === u);
    if (tab?.id) await chrome.tabs.update(tab.id, { active: true });
  }, url);
  const pending = context.waitForEvent('page', (p) => p.url().startsWith(`chrome-extension://${extId}/sidepanel.html`));
  await sw.evaluate(async (panelUrl) => { await chrome.tabs.create({ url: panelUrl, active: false }); }, `chrome-extension://${extId}/sidepanel.html`);
  panel = await pending;
  await expect(panel.getByRole('button', { name: 'Export design system' })).toBeVisible();
  await panel.getByRole('button', { name: 'Edit on page' }).click();
  await expect(panel.locator('.ed-empty')).toHaveText('Click anything on the page to see the tokens it uses.');
}

test.beforeEach(async ({}, info) => {
  if (!info.title.startsWith('every fixture')) await startEditing(URL_);
});

test.afterEach(async () => {
  await panel?.close().catch(() => {});
  await page?.close().catch(() => {});
});

test('a token edit repaints every element that uses it and reaches the export', async () => {
  await page.locator('.hero .btn-primary').click();
  await expect(inspector().locator('.ed-insp-head')).toContainText('Start free');
  expect(page.url()).toBe(URL_); // the click picked the link, it did not follow it

  await expect(row('Background').locator('.ed-meta')).toContainText('primary');
  await row('Background').locator('.ed-hex').fill('#7A3FC4');
  await expect.poll(() => bg('.hero .btn-primary')).toBe(PURPLE);
  expect(await bg('header .btn-primary')).toBe(PURPLE);
  expect(await bg('.plan .btn-primary')).toBe(PURPLE);
  expect(await css('.logo', 'color')).toBe(PURPLE);
  expect(await bg('.btn-accent')).toBe('rgb(224, 118, 58)');

  await expect(panel.locator('.ed-group', { hasText: 'Token changes' })).toContainText('#1F5E4B');
  await expect(panel.locator('.ed-group', { hasText: 'Token changes' })).toContainText('#7A3FC4');

  await panel.getByRole('button', { name: /Export design system/ }).click();
  await panel.locator('label.fmt', { has: panel.locator('input[value="css"]') }).click();
  await expect(panel.locator('pre.code')).toContainText('--color-primary: #7A3FC4;');
  await panel.getByRole('button', { name: 'Back to design system' }).click();

  // Undo puts the page back.
  await panel.locator('.ed-group', { hasText: 'Token changes' }).getByRole('button', { name: 'Undo' }).click();
  await expect.poll(() => bg('.hero .btn-primary')).toBe(GREEN);
  expect(await page.locator('.hero .btn-primary').getAttribute('style')).toBeNull();
});

test('a one-off edit changes one element, stays out of the export, and can become a new token', async () => {
  await page.locator('.hero .btn-primary').click();
  await scope('Only this element');
  await row('Radius').locator('.ed-num').fill('24');
  await expect.poll(() => css('.hero .btn-primary', 'border-top-left-radius')).toBe('24px');
  expect(await css('header .btn-primary', 'border-top-left-radius')).toBe('8px');
  const oneOffs = panel.locator('.ed-group', { hasText: 'One-off element edits' });
  await expect(oneOffs).toContainText('in tokens.json only');
  // tokens.json lists it as an element edit; tokens.css does not know about it
  await panel.getByRole('button', { name: /Export design system/ }).click();
  await panel.locator('label.fmt', { has: panel.locator('input[value="json"]') }).click();
  await expect(panel.locator('pre.code')).toContainText('"elementEdits"');
  await expect(panel.locator('pre.code')).toContainText('"property": "border-radius"');
  await expect(panel.locator('pre.code')).toContainText('"token": "radius.sm"');
  await panel.locator('label.fmt', { has: panel.locator('input[value="css"]') }).click();
  await expect(panel.locator('pre.code')).toContainText('--radius-sm: 8px;');
  await expect(panel.locator('pre.code')).not.toContainText('--radius-lg');
  await panel.getByRole('button', { name: 'Back to design system' }).click();
  await expect(oneOffs).toContainText('24px');

  await oneOffs.getByRole('button', { name: 'Save as new token' }).click();
  await expect(panel.locator('.ed-group', { hasText: 'Token changes' })).toContainText('radius-new');
  await expect(oneOffs).toHaveCount(0);
  expect(await css('.hero .btn-primary', 'border-top-left-radius')).toBe('24px');

  await panel.getByRole('button', { name: /Export design system/ }).click();
  await panel.locator('label.fmt', { has: panel.locator('input[value="css"]') }).click();
  await expect(panel.locator('pre.code')).toContainText('24px');
});

test('text, alignment and drag edits change the page and are listed in DESIGN.md', async () => {
  // Rewrite the headline in place.
  await page.locator('.hero h1').dblclick();
  await page.keyboard.type('Grow more with less work');
  await page.keyboard.press('Enter');
  await expect(page.locator('.hero h1')).toHaveText('Grow more with less work');
  const edits = panel.locator('.ed-group', { hasText: 'Page edits' });
  await expect(edits).toContainText('Grow more with less work');

  // Centre it, from the panel.
  await inspector().getByRole('group', { name: 'Horizontal alignment' }).getByRole('button', { name: 'Centre' }).click();
  await expect.poll(() => css('.hero h1', 'text-align')).toBe('center');

  // Vertical alignment of a flex row's contents.
  await page.locator('header .wrap').click({ position: { x: 300, y: 4 } });
  await expect(inspector().locator('.ed-insp-head')).toContainText('.wrap');
  await inspector().getByRole('group', { name: 'Vertical alignment' }).getByRole('button', { name: 'Bottom' }).click();
  await expect.poll(() => css('header .wrap', 'align-items')).toBe('flex-end');

  // Drag the third feature card in front of the first.
  const first = (await page.locator('.card').nth(0).boundingBox())!;
  const third = (await page.locator('.card').nth(2).boundingBox())!;
  await page.mouse.move(third.x + third.width / 2, third.y + 12);
  await page.mouse.down();
  await page.mouse.move(third.x + third.width / 2 - 40, third.y + 30, { steps: 5 });
  await page.mouse.move(first.x + 20, first.y + 30, { steps: 10 });
  await page.mouse.up();
  await expect(page.locator('.cards > .card h3')).toHaveText(['Harvest log', 'Bed planner', 'Sowing calendar']);
  await expect(edits).toContainText('Moved');

  await panel.getByRole('button', { name: /Export design system/ }).click();
  const md = panel.locator('pre.code');
  await expect(md).toContainText('## Layout adjustments');
  await expect(md).toContainText('to “Grow more with less work”.');
  await expect(md).toContainText('Align the h1 “Grow more with less work” centre.');
  await expect(md).toContainText('Align the div .wrap containing \u201cFernleaf\u201d to the bottom of its container.');
  await expect(md).toContainText('Move the article .card containing \u201cHarvest log\u201d before the article .card containing \u201cBed planner\u201d.');
  await panel.locator('label.fmt', { has: panel.locator('input[value="json"]') }).click();
  await expect(panel.locator('pre.code')).toContainText('"layoutAdjustments"');
  await expect(panel.locator('pre.code')).toContainText('"type": "vertical-align"');
  await panel.locator('label.fmt', { has: panel.locator('input[value="figma"]') }).click();
  await expect(panel.locator('pre.code')).toContainText('"textStyles"');
  await panel.getByRole('button', { name: 'Back to design system' }).click();

  // Reset all puts everything back.
  await panel.getByRole('button', { name: 'Reset all' }).click();
  await expect(page.locator('.hero h1')).toHaveText('Plan a garden that grows all year round');
  await expect(page.locator('.card h3').first()).toHaveText('Bed planner');
  expect(await css('header .wrap', 'align-items')).toBe('center');
});

test('closing the panel puts the page back and removes the editor', async () => {
  const before = await page.locator('body').evaluate((b) => b.innerHTML);
  await page.locator('.hero .btn-primary').click();
  await row('Background').locator('.ed-hex').fill('#7A3FC4');
  await expect.poll(() => bg('.hero .btn-primary')).toBe(PURPLE);
  await expect(page.locator('sdt-edit-overlay')).toHaveCount(1);

  await panel.close();
  await expect.poll(() => bg('.hero .btn-primary')).toBe(GREEN);
  await expect(page.locator('sdt-edit-overlay')).toHaveCount(0);
  expect(await page.locator('body').evaluate((b) => b.innerHTML)).toBe(before);
  expect(await page.locator('html').getAttribute('style')).toBeFalsy();
  // Links work again.
  await page.locator('nav a').first().click();
  expect(page.url()).toBe(`${URL_}#features`);
});

test('every fixture is put back exactly after its tokens were edited', async () => {
  test.setTimeout(60_000);
  // The page's own content and <html> attributes; the editor's overlay element is not part of the page.
  const snapshot = () => page.evaluate(() => `${document.documentElement.getAttribute('style')}|${document.head.innerHTML}|${document.body.outerHTML}`);
  for (const fixture of ['dark-app', 'tailwind', 'gradient-hero', 'slate', 'arabic-rtl', 'near-empty']) {
    await startEditing(`http://127.0.0.1:${FIXTURE_PORT}/${fixture}.html`);
    const before = await snapshot();
    // Change the first token of every kind the page has.
    for (const section of await panel.locator('.ed > section[aria-labelledby^="ed-kind-"]').all()) {
      const hex = section.locator('.ed-hex').first();
      if (await hex.count()) await hex.fill('#FF00AA');
      else await section.getByRole('button', { name: 'Increase' }).first().click();
    }
    const changes = panel.locator('.ed-group', { hasText: 'Token changes' }).locator('li');
    await expect(changes.first(), fixture).toBeVisible();
    expect(await snapshot(), fixture).not.toBe(before);
    await panel.close();
    await expect(page.locator('sdt-edit-overlay'), fixture).toHaveCount(0);
    expect(await snapshot(), fixture).toBe(before);
    await page.close();
  }
});

import { chromium, expect, test, type BrowserContext, type Page, type Worker } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Role, Tokens } from '../../src/model/tokens';
import { FIXTURE_PORT } from '../../playwright.config';

/** Hand-derived expectations per fixture (see the comments in each fixture's CSS). */
interface Expected {
  /** token name -> hex, e.g. { "primary": "#1F5E4B", "surface-2": "#161B22" } */
  colours: Record<string, string>;
  /** roles that must not appear at all */
  absentRoles?: Role[];
  /** hex values that must not appear as any token */
  absentHex?: string[];
  /** contrast pairs that must be present with this grade (bg 'none' = image/gradient) */
  contrast?: { fg: string; bg: string; grade: string }[];
  fonts?: Record<string, string>;
  spacingBase?: 4 | 8 | null;
  /** every spacing scale value is a multiple of this (the fixture's CSS uses no other px values) */
  spacingMultipleOf?: number;
  radius?: number[];
  minShadows?: number;
}

const DIST = resolve(import.meta.dirname, '../../dist-e2e');
const BASE = `http://127.0.0.1:${FIXTURE_PORT}`;
const EXPECTED: Record<string, Expected> = JSON.parse(
  readFileSync(resolve(import.meta.dirname, '../fixtures/expected.json'), 'utf8'),
);

let context: BrowserContext;
let sw: Worker;
let extId: string;


test.beforeAll(async () => {
  context = await chromium.launchPersistentContext('', {
    channel: 'chromium',
    args: [`--disable-extensions-except=${DIST}`, `--load-extension=${DIST}`],
  });
  sw = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
  extId = new URL(sw.url()).host;
});

test.afterAll(async () => {
  await context?.close();
});

/** Opens `url` in a new tab, makes it the active tab, and returns its page and chrome tab id. */
async function openTab(url: string): Promise<{ page: Page; tabId: number }> {
  const page = await context.newPage();
  await page.goto(url);
  await page.bringToFront();
  const tabId = await sw.evaluate(async (u) => {
    const tabs = await chrome.tabs.query({});
    const tab = tabs.find((t) => t.url === u);
    if (!tab?.id) throw new Error(`no tab for ${u}`);
    await chrome.tabs.update(tab.id, { active: true });
    return tab.id;
  }, url);
  return { page, tabId };
}

/** Exactly what src/scan.ts scanTab does, timed around the two executeScript calls. */
async function scan(tabId: number): Promise<{ tokens: Tokens; ms: number }> {
  return sw.evaluate(async (id) => {
    const t0 = performance.now();
    await chrome.scripting.executeScript({ target: { tabId: id }, files: ['extract.js'] });
    const [r] = await chrome.scripting.executeScript({
      target: { tabId: id },
      func: () => (globalThis as unknown as { __sdtScan: () => unknown }).__sdtScan(),
    });
    return { tokens: r.result as Tokens, ms: performance.now() - t0 };
  }, tabId);
}

/** Opens an extension page as a background tab, so the fixture tab stays the active tab of the window. */
async function openExtensionPage(path: string): Promise<Page> {
  const opened = context.waitForEvent('page', (p) => p.url().startsWith(`chrome-extension://${extId}/${path}`));
  await sw.evaluate(async (url) => { await chrome.tabs.create({ url, active: false }); }, `chrome-extension://${extId}/${path}`);
  return opened;
}

async function setStorage(values: Record<string, unknown>): Promise<void> {
  await sw.evaluate(async (v) => { await chrome.storage.local.set(v); }, values);
}

test.describe('extraction on fixtures', () => {
  for (const [fixture, exp] of Object.entries(EXPECTED)) {
    test(`${fixture} roles match expected.json`, async () => {
      const url = `${BASE}/${fixture}.html`;
      const { page, tabId } = await openTab(url);
      const { tokens } = await scan(tabId);
      const byName = Object.fromEntries(tokens.colours.map((c) => [c.name, c.hex]));
      const summary = JSON.stringify(tokens.colours.map((c) => `${c.name} ${c.hex} ${(c.weight * 100).toFixed(1)}%`));

      expect(tokens.site, 'Tokens.site is the tab hostname').toBe(new URL(url).hostname);
      for (const [name, hex] of Object.entries(exp.colours)) {
        expect(byName[name], `${name} in ${summary}`).toBe(hex);
      }
      for (const role of exp.absentRoles ?? []) {
        expect(tokens.colours.filter((c) => c.role === role), `${role} absent in ${summary}`).toEqual([]);
      }
      for (const hex of exp.absentHex ?? []) {
        expect(tokens.colours.map((c) => c.hex), `${hex} absent in ${summary}`).not.toContain(hex);
      }
      for (const want of exp.contrast ?? []) {
        expect(tokens.contrast, `contrast ${JSON.stringify(want)} in ${JSON.stringify(tokens.contrast)}`).toContainEqual(expect.objectContaining(want));
      }
      for (const [name, family] of Object.entries(exp.fonts ?? {})) {
        expect(tokens.fonts.find((f) => f.name === name)?.family, `font ${name}`).toContain(family);
      }
      if (exp.spacingBase !== undefined) expect(tokens.spacing.base, `spacing ${JSON.stringify(tokens.spacing)}`).toBe(exp.spacingBase);
      if (exp.spacingMultipleOf !== undefined) {
        const m = exp.spacingMultipleOf;
        expect(tokens.spacing.scale.filter((v) => v % m !== 0), `spacing ${JSON.stringify(tokens.spacing)}`).toEqual([]);
      }
      if (exp.radius) expect(tokens.radius).toEqual(exp.radius);
      if (exp.minShadows !== undefined) expect(tokens.shadows.length).toBeGreaterThanOrEqual(exp.minShadows);
      await page.close();
    });
  }

  test('light-marketing scans in under 1500 ms', async () => {
    const { page, tabId } = await openTab(`${BASE}/light-marketing.html`);
    const { ms } = await scan(tabId);
    expect(ms).toBeLessThan(1500);
    await page.close();
  });
});

test.describe('popup', () => {
  test('renders the Colours tab for the active fixture tab, and theme Dark persists across reload', async () => {
    await setStorage({ settings: { theme: 'system', lang: 'en', pro: false } });
    const { page: fixture } = await openTab(`${BASE}/light-marketing.html`);
    const popup = await openExtensionPage('popup.html');

    await expect(popup.getByRole('tab', { name: 'Colours' })).toHaveAttribute('aria-selected', 'true');
    await expect(popup.getByRole('heading', { name: 'Primary' })).toBeVisible();
    await expect(popup.getByRole('heading', { name: 'Accent' })).toBeVisible();
    await expect(popup.getByText('#1F5E4B').first()).toBeVisible();
    await expect(popup.getByText('#E0763A').first()).toBeVisible();

    await popup.getByRole('button', { name: 'Settings' }).click();
    await popup.locator('label.seg-opt', { hasText: 'Dark' }).click();
    await expect(popup.getByRole('radio', { name: 'Dark' })).toBeChecked();
    await expect(popup.locator('html')).toHaveAttribute('data-theme', 'dark');
    await popup.reload();
    await expect(popup.locator('html')).toHaveAttribute('data-theme', 'dark');
    await expect(popup.getByRole('heading', { name: 'Primary' })).toBeVisible();

    await popup.close();
    await fixture.close();
  });

  test('chrome://extensions shows the restricted state', async () => {
    const tabId = await sw.evaluate(async () => (await chrome.tabs.create({ url: 'chrome://extensions', active: true })).id!);
    const popup = await openExtensionPage('popup.html');
    await expect(popup.getByText("This page can't be scanned")).toBeVisible();
    await popup.close();
    await sw.evaluate(async (id) => { await chrome.tabs.remove(id); }, tabId);
  });
});

test.describe('export with Pro', () => {
  const FORMATS: { value: string; file: RegExp }[] = [
    { value: 'md', file: /^DESIGN\.md$/ },
    { value: 'css', file: /^tokens\.css$/ },
    { value: 'tailwind', file: /^tailwind\.css$/ },
    { value: 'figma', file: /^figma-variables\.json$/ },
    { value: 'json', file: /^tokens\.json$/ },
    { value: 'all', file: /-tokens\.zip$/ },
  ];

  test('every format downloads a non-empty file', async () => {
    test.setTimeout(60_000); // six downloads; each waits for the file to land on disk
    await setStorage({ settings: { theme: 'light', lang: 'en', pro: true } });
    const { page: fixture } = await openTab(`${BASE}/light-marketing.html`);
    const panel = await openExtensionPage('sidepanel.html?view=export');
    await expect(panel.getByRole('heading', { name: 'Export tokens' })).toBeVisible();

    for (const { value, file } of FORMATS) {
      await panel.locator('label.fmt', { has: panel.locator(`input[value="${value}"]`) }).click();
      await expect(panel.locator(`input[name="sdt-format"][value="${value}"]`)).toBeChecked();
      const [download] = await Promise.all([
        panel.waitForEvent('download'),
        panel.getByRole('button', { name: /^Download/ }).click(),
      ]);
      expect(download.suggestedFilename(), value).toMatch(file);
      const path = await download.path();
      expect(readFileSync(path).length, `${value} size`).toBeGreaterThan(0);
    }
    await panel.close();
    await fixture.close();
  });
});

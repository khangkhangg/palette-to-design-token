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
/** The fixture server under a public-looking host (AI insights refuse 127.0.0.1); see scripts/e2e.mjs. */
const PUBLIC_HOST = 'fernleaf.example';
const PUBLIC = `http://${PUBLIC_HOST}:${FIXTURE_PORT}`;
const EXPECTED: Record<string, Expected> = JSON.parse(
  readFileSync(resolve(import.meta.dirname, '../fixtures/expected.json'), 'utf8'),
);

let context: BrowserContext;
let sw: Worker;
let extId: string;
/** Pages and chrome tabs a test opened; closed after each test even when it fails. */
let opened: Page[] = [];
let openedTabIds: number[] = [];


test.beforeAll(async () => {
  context = await chromium.launchPersistentContext('', {
    channel: 'chromium',
    args: [`--disable-extensions-except=${DIST}`, `--load-extension=${DIST}`, `--host-resolver-rules=MAP ${PUBLIC_HOST} 127.0.0.1`],
  });
  sw = context.serviceWorkers()[0] ?? (await context.waitForEvent('serviceworker'));
  extId = new URL(sw.url()).host;
});

test.afterEach(async () => {
  await Promise.all(opened.map((p) => p.close().catch(() => {})));
  if (openedTabIds.length) await sw.evaluate(async (ids) => { await chrome.tabs.remove(ids).catch(() => {}); }, openedTabIds);
  opened = [];
  openedTabIds = [];
});

test.afterAll(async () => {
  await context?.close();
});

/** Opens `url` in a new tab, makes it the active tab, and returns its page and chrome tab id. */
async function openTab(url: string): Promise<{ page: Page; tabId: number }> {
  const page = await context.newPage();
  opened.push(page);
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
  const pending = context.waitForEvent('page', (p) => p.url().startsWith(`chrome-extension://${extId}/${path}`));
  await sw.evaluate(async (url) => { await chrome.tabs.create({ url, active: false }); }, `chrome-extension://${extId}/${path}`);
  const page = await pending;
  opened.push(page);
  return page;
}

async function setStorage(values: Record<string, unknown>): Promise<void> {
  await sw.evaluate(async (v) => { await chrome.storage.local.set(v); }, values);
}

test.describe('extraction on fixtures', () => {
  for (const [fixture, exp] of Object.entries(EXPECTED)) {
    test(`${fixture} roles match expected.json`, async () => {
      const url = `${BASE}/${fixture}.html`;
      const { tabId } = await openTab(url);
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
    });
  }

  test('light-marketing scans in under 1500 ms', async () => {
    const { tabId } = await openTab(`${BASE}/light-marketing.html`);
    const { ms } = await scan(tabId);
    expect(ms).toBeLessThan(1500);
  });
});

test.describe('popup', () => {
  test('renders the Colours tab for the active fixture tab, and theme Dark persists across reload', async () => {
    await setStorage({ settings: { theme: 'system', lang: 'en', pro: false } });
    await openTab(`${BASE}/light-marketing.html`);
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
  });

  test('chrome://extensions shows the restricted state', async () => {
    openedTabIds.push(await sw.evaluate(async () => (await chrome.tabs.create({ url: 'chrome://extensions', active: true })).id!));
    const popup = await openExtensionPage('popup.html');
    await expect(popup.getByText("This page can't be scanned")).toBeVisible();
  });
});

/** Elements inside the popup's rows whose content is wider than their box (deliberate ellipsis excepted). */
async function overflowing(popup: Page): Promise<string[]> {
  return popup.evaluate(() => {
    const out: string[] = [];
    const body = document.body;
    if (body.scrollWidth > body.clientWidth + 1) out.push(`body ${body.scrollWidth}>${body.clientWidth}`);
    const roots = document.querySelectorAll<HTMLElement>('.header, .tabs, .role-head, .swatches, .summary, .footer');
    for (const root of roots) {
      for (const el of [root, ...root.querySelectorAll('*')]) {
        if (!(el instanceof HTMLElement)) continue;
        if (getComputedStyle(el).textOverflow === 'ellipsis') continue;
        if (el.scrollWidth > el.clientWidth + 1) {
          out.push(`${el.tagName.toLowerCase()}.${el.className} "${el.textContent?.trim().slice(0, 40)}" ${el.scrollWidth}>${el.clientWidth}`);
        }
      }
    }
    return out;
  });
}

/**
 * True when the first occurrence of `lead` in the element's text is drawn to the left of the character after it:
 * a value such as "#F4F1EA" or "--color-primary" kept left-to-right inside right-to-left text.
 */
async function readsLeftToRight(popup: Page, selector: string, lead: string): Promise<boolean> {
  return popup.locator(selector).first().evaluate((el, lead) => {
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      const i = node.textContent!.indexOf(lead);
      if (i < 0) continue;
      const box = (at: number) => {
        const range = document.createRange();
        range.setStart(node!, at);
        range.setEnd(node!, at + 1);
        return range.getBoundingClientRect();
      };
      return box(i).left < box(i + lead.length).left;
    }
    throw new Error(`${lead} not in ${el.textContent}`);
  }, lead);
}

test.describe('languages', () => {
  test('Arabic popup is right-to-left and mirrors only directional icons', async () => {
    await setStorage({ settings: { theme: 'light', lang: 'ar', pro: true } });
    await openTab(`${BASE}/light-marketing.html`);
    const popup = await openExtensionPage('popup.html');
    await popup.setViewportSize({ width: 400, height: 600 });

    await expect(popup.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(popup.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(popup.locator('#tab-colours')).toHaveAttribute('aria-selected', 'true');
    // Header icons (settings, full view, re-scan) are not directional: they stay unmirrored.
    const headerTransforms = await popup.locator('.header svg.icon').evaluateAll((els) => els.map((e) => getComputedStyle(e).transform));
    expect(headerTransforms.length).toBeGreaterThan(0);
    expect(headerTransforms.every((tf) => tf === 'none'), JSON.stringify(headerTransforms)).toBe(true);
    expect(await overflowing(popup)).toEqual([]);
    // Hex values, CSS variable names and shares stay left-to-right inside the Arabic layout.
    expect(await readsLeftToRight(popup, '.swatch-hex:text-is("#E0763A")', '#'), 'swatch hex').toBe(true);
    expect(await readsLeftToRight(popup, '.role-var', '--'), 'css variable').toBe(true);
    // The subtitle reads site, then counts, from the right: the site's first digit is right of the first Arabic letter.
    expect(await popup.locator('.header-sub').evaluate((el) => {
      const text = el.firstChild!;
      const left = (at: number) => {
        const range = document.createRange();
        range.setStart(text, at);
        range.setEnd(text, at + 1);
        return range.getBoundingClientRect().left;
      };
      return left(text.textContent!.indexOf('127')) > left(text.textContent!.search(/[؀-ۿ]/));
    }), 'subtitle order').toBe(true);
    expect(await readsLeftToRight(popup, '.swatch-share:text("<1%")', '<'), 'share').toBe(true);
    await popup.screenshot({ path: test.info().outputPath('ar-colours.png') });

    // The before -> after arrow in a contrast fix points along the reading direction.
    await popup.locator('#tab-contrast').click();
    const arrow = popup.locator('.fix-body svg.icon-dir').first();
    await expect(arrow).toBeVisible();
    expect(await arrow.evaluate((e) => getComputedStyle(e).transform)).toBe('matrix(-1, 0, 0, 1, 0, 0)');
    expect(await readsLeftToRight(popup, '.pair-colours', '#'), 'contrast pair').toBe(true);
    await popup.screenshot({ path: test.info().outputPath('ar-contrast.png') });
  });

  for (const lang of ['fr', 'hi', 'ar']) {
    test(`${lang} popup fits 400 px: header, tabs, swatch rows and footer do not overflow`, async () => {
      await setStorage({ settings: { theme: 'light', lang, pro: false } });
      await openTab(`${BASE}/light-marketing.html`);
      const popup = await openExtensionPage('popup.html');
      await popup.setViewportSize({ width: 400, height: 600 });

      await expect(popup.locator('html')).toHaveAttribute('lang', lang);
      await expect(popup.locator('.swatch').first()).toBeVisible();
      await expect(popup.locator('.footer .badge')).toBeVisible();
      // The footer is pinned inside the 600 px popup; the tab panel scrolls instead.
      const footer = await popup.locator('.footer').boundingBox();
      expect(footer!.y + footer!.height, 'footer bottom').toBeLessThanOrEqual(600);
      expect(await overflowing(popup)).toEqual([]);
      await popup.screenshot({ path: test.info().outputPath(`${lang}-colours.png`) });
      await popup.locator('#tab-contrast').click();
      await expect(popup.locator('#tab-contrast')).toHaveAttribute('aria-selected', 'true');
      expect(await overflowing(popup), 'contrast tab').toEqual([]);
      await popup.screenshot({ path: test.info().outputPath(`${lang}-contrast.png`) });
    });
  }
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
    await openTab(`${BASE}/light-marketing.html`);
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
  });
});

test.describe('AI insights', () => {
  /** The canned answer the dev router's AI stub gives (server/tests/dev-router.php, PALETTE_DEV_AI_STUB=1). */
  const RESULT = {
    sector: 'Specialty coffee', tags: ['Online shop', 'Premium, craft'], verdict: 'on_par',
    summary: 'A clean, warm storefront that reads as a craft roaster. The palette is restrained and the type is legible.',
    colours: [{ hex: '#1f5e4b', title: 'Deep tone: grounded, trustworthy', meaning: 'Sets the tone for the brand.' },
      { hex: '#e0763a', title: 'Light tone: calm, open', meaning: 'Sets the tone for the brand.' }],
    fit: { level: 'strong', reason: 'The colours match what buyers expect from a craft coffee brand.' },
    works: ['Consistent palette', 'Readable body text'], improve: ['Raise contrast on muted labels'],
    sites: [
      { name: 'Northwind Roasters', domain: 'northwind.example', url: 'https://northwind.example/', why: 'Same market, similar tone', relation: 'competitor', source: 'web' },
      { name: 'Harbor Coffee Co.', domain: 'harbor-coffee.example', url: 'https://harbor-coffee.example/', why: 'Direct competitor online', relation: 'competitor', source: 'web' },
      { name: 'Brew Lab', domain: 'brewlab.example', url: 'https://brewlab.example/', why: 'Similar visual style', relation: 'similar', source: 'ai' },
    ],
    search_unavailable: false,
  };
  const ANALYSE = 'https://vietnamcos.com/palette/api.php?action=analyse';
  const CORS = { 'Access-Control-Allow-Origin': '*', 'Access-Control-Allow-Headers': 'Content-Type', 'Access-Control-Allow-Methods': 'POST' };

  async function seedAi(lang: string, extra: Record<string, unknown> = {}) {
    const now = new Date().toISOString();
    await setStorage({
      settings: { theme: 'light', lang, pro: true }, scans: [], aiResults: [], aiNoteHidden: false,
      licenseAi: {
        key: 'SDT-ABCD-2345-6789', verifiedAt: now, emailMasked: null, plan: 'ai',
        paidThrough: new Date(Date.now() + 20 * 86_400_000).toISOString(), usage: { used: 37, limit: 100, resetsOn: '2026-11-01' },
        subStatus: 'active', lastCheck: 'ok', lastAttemptAt: now,
      },
      ...extra,
    });
  }

  /** Elements inside the AI section or plan sheet whose content is wider than their box. */
  async function aiOverflow(panel: Page): Promise<string[]> {
    return panel.evaluate(() => {
      const out: string[] = [];
      const body = document.body;
      if (body.scrollWidth > body.clientWidth + 1) out.push(`body ${body.scrollWidth}>${body.clientWidth}`);
      for (const root of document.querySelectorAll<HTMLElement>('.ai-results, .ai-card, .plan-sheet')) {
        for (const el of [root, ...root.querySelectorAll('*')]) {
          if (!(el instanceof HTMLElement) || el.classList.contains('sr-only')) continue;
          if (el.scrollWidth > el.clientWidth + 1) out.push(`${el.tagName.toLowerCase()}.${el.className} "${el.textContent?.trim().slice(0, 40)}" ${el.scrollWidth}>${el.clientWidth}`);
        }
      }
      return out;
    });
  }

  test('Analyse sends the page without its query or fragment and renders the results; the result is kept', async () => {
    const sent: Record<string, unknown>[] = [];
    await context.route(ANALYSE, async (route) => {
      if (route.request().method() === 'OPTIONS') return route.fulfill({ status: 204, headers: CORS });
      sent.push(route.request().postDataJSON());
      return route.fulfill({
        status: 200, contentType: 'application/json', headers: CORS,
        body: JSON.stringify({ ok: true, result: RESULT, cached: false, usage: { used: 38, limit: 100, resets_on: '2026-11-01' } }),
      });
    });
    try {
      await seedAi('en');
      await openTab(`${PUBLIC}/light-marketing.html?utm_source=mail&id=42#pricing`);
      const panel = await openExtensionPage('sidepanel.html');
      await panel.setViewportSize({ width: 380, height: 900 });
      const analyse = panel.getByRole('button', { name: `Analyse ${PUBLIC_HOST}` });
      await expect(analyse).toBeVisible();
      await expect(panel.getByText('What gets sent')).toBeVisible();
      await expect(panel.getByText('63 of 100 analyses left')).toBeVisible();
      await analyse.scrollIntoViewIfNeeded();
      await panel.locator('.ai-card').screenshot({ path: test.info().outputPath('ai-ready.png') });
      await analyse.click();

      await expect(panel.locator('.ai-verdict')).toHaveText('On par');
      // The clicked button is gone: focus lands on the results heading, and the live region says so.
      await expect(panel.locator('.ai-results .ai-title')).toBeFocused();
      await expect(panel.locator('[aria-live="polite"]')).toHaveText('Results ready');
      expect(sent).toHaveLength(1);
      expect(Object.keys(sent[0]).sort()).toEqual(['description', 'design', 'key', 'lang', 'title', 'url']);
      expect(sent[0].url).toBe(`${PUBLIC}/light-marketing.html`);
      expect(sent[0].title).toBe('Fernleaf - Plan your garden');
      expect(sent[0].lang).toBe('en');
      expect(JSON.stringify(sent[0].design)).not.toContain('Fernleaf');

      await expect(panel.getByText('Deep tone: grounded, trustworthy')).toBeVisible();
      await expect(panel.locator('.ai-fit')).toContainText('Strong fit for Specialty coffee');
      // Only web-found sites are links; the model-only suggestion is plain text (ruling A13).
      await expect(panel.locator('.ai-site')).toHaveCount(3);
      const links = panel.locator('a.ai-site');
      await expect(links).toHaveCount(2);
      for (const a of await links.all()) {
        await expect(a).toHaveAttribute('target', '_blank');
        await expect(a).toHaveAttribute('rel', 'noopener noreferrer');
      }
      await expect(panel.locator('.ai-site').nth(2).locator('.ai-badge')).toHaveText('AI · check it');
      await expect(panel.locator('.ai-site').nth(2)).not.toHaveAttribute('href', /.*/);
      await expect(panel.locator('.ai-footer').first()).toContainText('used 38 of 100');
      expect(await aiOverflow(panel)).toEqual([]);
      await panel.locator('.ai-results').screenshot({ path: test.info().outputPath('ai-results.png') });

      // Opened again, the kept result shows without a request; Run again spends one.
      await panel.reload();
      await expect(panel.locator('.ai-verdict')).toHaveText('On par');
      expect(sent).toHaveLength(1);
      await panel.getByRole('button', { name: 'Run again' }).click();
      await expect.poll(() => sent.length).toBe(2);
    } finally {
      await context.unroute(ANALYSE);
    }
  });

  test('Arabic: results and the plan chooser are right-to-left and do not overflow 340 px', async () => {
    await seedAi('ar', {
      aiResults: [{ host: PUBLIC_HOST, result: RESULT, usage: { used: 38, limit: 100, resetsOn: '2026-11-01' }, analysedAt: new Date().toISOString(), lang: 'ar', cached: true }],
    });
    await openTab(`${PUBLIC}/light-marketing.html`);
    const panel = await openExtensionPage('sidepanel.html');
    await panel.setViewportSize({ width: 340, height: 900 });
    await expect(panel.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(panel.locator('.ai-results')).toBeVisible();
    // The verdict is text, in Arabic.
    await expect(panel.locator('.ai-verdict')).toHaveText(/[؀-ۿ]/);
    expect(await aiOverflow(panel)).toEqual([]);
    expect(await readsLeftToRight(panel, '.ai-site-domain', 'n'), 'domain').toBe(true);
    await panel.locator('.ai-results').screenshot({ path: test.info().outputPath('ar-ai-results.png') });

    await sw.evaluate(async () => { await chrome.storage.local.remove(['licenseAi', 'aiResults']); });
    await panel.reload();
    await panel.locator('.ai-card .ai-cta').click();
    await expect(panel.getByRole('dialog')).toBeVisible();
    expect(await aiOverflow(panel)).toEqual([]);
    await panel.getByRole('dialog').screenshot({ path: test.info().outputPath('ar-plans.png') });
  });
});

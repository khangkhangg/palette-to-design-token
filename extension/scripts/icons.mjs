// npm run icons: renders the product mark (theme.css .mark) to public/icons/{16,32,48,128}.png via Playwright screenshots.
// The store icon and promo tiles are made by scripts/promo.mjs (npm run promo).
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { MARK_CSS } from './lib/mark.mjs';

const root = resolve(import.meta.dirname, '..');
const iconDir = resolve(root, 'public/icons');

const iconPage = (size) =>
  `<!doctype html><style>html,body{margin:0;background:transparent}${MARK_CSS}.mark{width:${size}px;height:${size}px}</style><div class="mark"></div>`;

mkdirSync(iconDir, { recursive: true });
const browser = await chromium.launch({ channel: 'chromium' });
try {
  for (const size of [16, 32, 48, 128]) {
    const page = await browser.newPage({ viewport: { width: size, height: size } });
    await page.setContent(iconPage(size));
    await page.screenshot({ path: resolve(iconDir, `${size}.png`), omitBackground: true });
    await page.close();
  }
} finally {
  await browser.close();
}
console.log('icons written');

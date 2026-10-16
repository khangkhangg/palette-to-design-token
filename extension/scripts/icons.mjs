// npm run icons: renders the product mark (theme.css .mark) to public/icons/{16,32,48,128}.png
// and the 440x280 small promo tile to docs/store/promo-440x280.png, via Playwright screenshots.
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { geistCss } from './lib/fonts.mjs';

const root = resolve(import.meta.dirname, '..');
const iconDir = resolve(root, 'public/icons');
const storeDir = resolve(root, '../docs/store');

/** The mark: rounded square, conic gradient (#1F5E4B, #E0763A, #3B4BD8), soft inner highlight. No text. */
const MARK_CSS = `
.mark{position:relative;border-radius:22.5%;overflow:hidden;
  background:conic-gradient(from 200deg,#1F5E4B,#E0763A,#3B4BD8,#1F5E4B)}
.mark::after{content:'';position:absolute;inset:0;border-radius:inherit;
  background:linear-gradient(160deg,rgba(255,255,255,.34) 0%,rgba(255,255,255,0) 46%),
             radial-gradient(circle at 50% 120%,rgba(0,0,0,.18),rgba(0,0,0,0) 60%);
  box-shadow:inset 0 0 0 1px rgba(255,255,255,.22)}`;

const iconPage = (size) =>
  `<!doctype html><style>html,body{margin:0;background:transparent}${MARK_CSS}.mark{width:${size}px;height:${size}px}</style><div class="mark"></div>`;

const promoPage = () => `<!doctype html><style>${geistCss()}${MARK_CSS}
html,body{margin:0}
body{width:440px;height:280px;background:#FBFBFA;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;font-family:'Geist',sans-serif;color:#15171C}
.mark{width:104px;height:104px;box-shadow:0 12px 28px rgba(31,94,75,.28)}
h1{margin:0;font-size:34px;font-weight:600;letter-spacing:-.02em}
</style><div class="mark"></div><h1>Site to Design Token</h1>`;

mkdirSync(iconDir, { recursive: true });
mkdirSync(storeDir, { recursive: true });
const browser = await chromium.launch({ channel: 'chromium' });
try {
  for (const size of [16, 32, 48, 128]) {
    const page = await browser.newPage({ viewport: { width: size, height: size } });
    await page.setContent(iconPage(size));
    await page.screenshot({ path: resolve(iconDir, `${size}.png`), omitBackground: true });
    await page.close();
  }
  const page = await browser.newPage({ viewport: { width: 440, height: 280 } });
  await page.setContent(promoPage());
  await page.evaluate(() => document.fonts.ready);
  await page.screenshot({ path: resolve(storeDir, 'promo-440x280.png') });
  await page.close();
} finally {
  await browser.close();
}
console.log('icons and promo tile written');

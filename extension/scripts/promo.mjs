// npm run promo: the Chrome Web Store images that are not screenshots.
//   docs/store/promo-marquee-1400x560.png  marquee tile: mark, name, value prop and points beside the real popup and side panel
//   docs/store/promo-440x280.png           small tile: mark, name, tagline and the colour roles of the fixture page
//   docs/store/store-icon-128.png          store icon: the mark at 96x96 with 16px of transparent padding
// The UI in the marquee is photographed from dist-e2e scanning the fernleaf.example fixture, as in screenshots.mjs.
// Tiles are opaque RGB; the store icon keeps its alpha. public/icons is left to icons.mjs.
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { geistCss } from './lib/fonts.mjs';
import { MARK_CSS, TOKENS as T } from './lib/mark.mjs';
import { buildE2e, capture, launch, root } from './lib/extension.mjs';

const storeDir = resolve(root, '../docs/store');
const png = (buf) => `data:image/png;base64,${buf.toString('base64')}`;

const BACKDROP = `background:
  radial-gradient(900px 520px at 88% 18%, rgba(59,75,216,.10), transparent 70%),
  radial-gradient(700px 420px at 64% 110%, rgba(224,118,58,.13), transparent 70%),
  radial-gradient(640px 420px at -6% -10%, rgba(31,94,75,.10), transparent 70%),
  linear-gradient(180deg,#F6F5F2,#ECEBE7)`;

const UI_SHADOW = 'box-shadow:0 30px 70px rgba(20,24,30,.22),0 6px 18px rgba(20,24,30,.08),0 0 0 1px rgba(20,24,30,.08)';

const marqueePage = (popup, panel) => `<!doctype html><meta charset="utf-8"><style>${geistCss()}${MARK_CSS}
html,body{margin:0}
body{position:relative;width:1400px;height:560px;overflow:hidden;font-family:'Geist',sans-serif;color:${T.ink};${BACKDROP}}
.copy{position:absolute;left:84px;top:0;bottom:0;width:580px;display:flex;flex-direction:column;justify-content:center}
.brand{display:flex;align-items:center;gap:18px}
.brand .mark{width:60px;height:60px;flex:none;box-shadow:0 10px 24px rgba(31,94,75,.25)}
.brand span{font-size:32px;font-weight:600;letter-spacing:-.02em}
h1{margin:34px 0 0;font-size:46px;line-height:1.1;font-weight:600;letter-spacing:-.03em}
ul{margin:34px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:14px}
li{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:500;color:${T.ink2}}
li i{width:12px;height:12px;border-radius:3px;flex:none}
img{position:absolute;display:block;border-radius:14px;${UI_SHADOW}}
.panel{left:716px;top:84px;width:352px}
.popup{left:1026px;top:40px;width:320px}
</style>
<div class="copy">
  <div class="brand"><div class="mark"></div><span>Site to Design Token</span></div>
  <h1>Any website’s design system, ready for your code</h1>
  <ul>
    <li><i style="background:${T.green}"></i>Colours by role</li>
    <li><i style="background:${T.orange}"></i>Type, spacing, contrast</li>
    <li><i style="background:${T.accent}"></i>Export CSS, Tailwind, Figma, DESIGN.md</li>
  </ul>
</div>
<img class="panel" src="${panel}"><img class="popup" src="${popup}">`;

/** The roles the extension finds on the fixture page (test/fixtures/light-marketing.html). */
const ROLES = [['Primary', '#1F5E4B', '#FFFFFF'], ['Accent', '#E0763A', '#FFFFFF'], ['Surface', '#F4F1EA', '#3D3A33'], ['Text', '#1A1A1A', '#FFFFFF'], ['Border', '#E5E7EB', '#4A4F5C']];

const smallPage = () => `<!doctype html><meta charset="utf-8"><style>${geistCss()}${MARK_CSS}
html,body{margin:0}
body{width:440px;height:280px;box-sizing:border-box;padding:0 30px;display:flex;flex-direction:column;justify-content:center;font-family:'Geist',sans-serif;color:${T.ink};${BACKDROP}}
.brand{display:flex;align-items:center;gap:14px}
.brand .mark{width:52px;height:52px;flex:none;box-shadow:0 8px 18px rgba(31,94,75,.25)}
.brand span{font-size:27px;font-weight:600;letter-spacing:-.02em;white-space:nowrap}
p{margin:16px 0 0;font-size:19px;line-height:1.3;font-weight:500;color:${T.ink2};letter-spacing:-.01em}
.strip{display:flex;margin-top:22px;height:44px;border-radius:10px;overflow:hidden;box-shadow:0 0 0 1px rgba(20,24,30,.08),0 6px 16px rgba(20,24,30,.10)}
.strip div{flex:1;display:flex;align-items:center;justify-content:center;font-size:14px;font-weight:600}
</style>
<div class="brand"><div class="mark"></div><span>Site to Design Token</span></div>
<p>Any website’s design system,<br>ready for your code</p>
<div class="strip">${ROLES.map(([n, bg, fg]) => `<div style="background:${bg};color:${fg}">${n}</div>`).join('')}</div>`;

const iconPage = () =>
  `<!doctype html><style>html,body{margin:0;background:transparent}${MARK_CSS}.mark{position:absolute;left:16px;top:16px;width:96px;height:96px}</style><div class="mark"></div>`;

mkdirSync(storeDir, { recursive: true });

// The UI, photographed at 2x so it stays sharp when scaled down into the tile.
buildE2e();
const ext = await launch({ port: 4519, deviceScaleFactor: 2 });
let popup, panel;
try {
  popup = await capture(ext, { path: 'popup.html', size: [400, 600], theme: 'light' });
  panel = await capture(ext, { path: 'sidepanel.html', size: [440, 680], theme: 'light' });
} finally {
  await ext.close();
}

const browser = await chromium.launch({ channel: 'chromium' });
try {
  const shoot = async (w, h, html, file, alpha = false) => {
    const page = await browser.newPage({ viewport: { width: w, height: h } });
    await page.setContent(html);
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: resolve(storeDir, file), omitBackground: alpha });
    await page.close();
    console.log('wrote', file);
  };
  await shoot(1400, 560, marqueePage(png(popup), png(panel)), 'promo-marquee-1400x560.png');
  await shoot(440, 280, smallPage(), 'promo-440x280.png');
  await shoot(128, 128, iconPage(), 'store-icon-128.png', true);
} finally {
  await browser.close();
}

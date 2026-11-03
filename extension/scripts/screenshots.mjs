// npm run screenshots: builds dist-e2e, loads it in Chromium, scans the fixtures and writes the 1280x800 Chrome Web Store
// screenshots. The AI shot uses a stubbed analysis, kept in the extension's storage as a finished result, so no server is needed.
//
//   npm run screenshots                    English, all six shots, to docs/store/screenshots/
//   npm run screenshots -- --lang fr       the five store shots in French, to docs/store/screenshots/fr/
//   npm run screenshots -- --all-langs     English plus every other store locale
//
// --lang takes a Chrome Web Store locale (zh_CN) or the extension's language code (zh-CN). The extension UI renders in that
// language (right-to-left for ar) and the caption comes from store-l10n.json. The store takes at most five per language:
// upload 1, 2, 3, 5 and 6; 4-side-panel-dark is English only and used on the landing page.
import { mkdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { geistCss } from './lib/fonts.mjs';
import { buildE2e, capture, launch, root } from './lib/extension.mjs';

const OUT = resolve(root, '../docs/store/screenshots');
const L10N = JSON.parse(readFileSync(resolve(import.meta.dirname, 'store-l10n.json'), 'utf8'));
const LOCALES = Object.keys(L10N).filter((k) => !k.startsWith('_'));
const RTL = new Set(['ar']);

const SHOTS = [
  { file: '1-popup-colours', path: 'popup.html', size: [400, 600], theme: 'light' },
  { file: '2-contrast', path: 'popup.html', size: [400, 600], theme: 'light', tab: '#tab-contrast' },
  { file: '3-side-panel-light', path: 'sidepanel.html', size: [440, 680], theme: 'light' },
  { file: '4-side-panel-dark', path: 'sidepanel.html', size: [440, 680], theme: 'dark', englishOnly: true },
  { file: '5-export', path: 'sidepanel.html?view=export', size: [440, 680], theme: 'light', pro: true },
  { file: '6-ai-insights', path: 'sidepanel.html', size: [440, 1500], theme: 'light', pro: true, ai: true },
];

/** The canned answer of the dev router's AI stub (same shape as the server's analyse result), in one locale's words. */
function aiResult(locale) {
  const a = L10N[locale].ai;
  return {
    sector: a.sector, tags: a.tags, verdict: 'competitive', summary: a.summary,
    colours: [
      { hex: '#1f5e4b', title: a.colours[0][0], meaning: a.colours[0][1] },
      { hex: '#e0763a', title: a.colours[1][0], meaning: a.colours[1][1] },
    ],
    fit: { level: 'strong', reason: a.fit },
    works: a.works, improve: a.improve,
    sites: [
      { name: 'Greenhouse Supply', domain: 'greenhouse-supply.example', url: 'https://greenhouse-supply.example/', why: a.why[0], relation: 'competitor', source: 'web' },
      { name: 'Leaf & Loam', domain: 'leafandloam.example', url: 'https://leafandloam.example/', why: a.why[1], relation: 'competitor', source: 'web' },
      { name: 'Sprout Studio', domain: 'sproutstudio.example', url: 'https://sproutstudio.example/', why: a.why[2], relation: 'similar', source: 'ai' },
    ],
    search_unavailable: false,
  };
}

const esc = (s) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');

/** Geist for Latin; Noto (if installed) or the Windows/macOS system face for each other script. */
const CAPTION_FONTS = `'Geist','Noto Sans SC','Microsoft YaHei','PingFang SC','Noto Sans Devanagari','Noto Sans Bengali','Nirmala UI',
  'Kohinoor Devanagari','Kohinoor Bangla','Noto Sans Arabic','Segoe UI','Geeza Pro',sans-serif`;

const frame = (body, caption, lang) => `<!doctype html><html lang="${lang}"><meta charset="utf-8"><style>${geistCss()}
html,body{margin:0}
body{width:1280px;height:800px;box-sizing:border-box;background:linear-gradient(180deg,#F3F2EF,#E7E6E2);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:28px;font-family:${CAPTION_FONTS}}
img{display:block;max-height:640px;border-radius:14px;box-shadow:0 24px 60px rgba(20,24,30,.22),0 0 0 1px rgba(20,24,30,.08)}
p{margin:0;max-width:1180px;text-align:center;font-size:28px;line-height:1.3;font-weight:600;letter-spacing:${/^(en|es|fr)$/.test(lang) ? '-.015em' : '0'};color:#1B1E24}
${AI_CSS}</style>${body}<p dir="${RTL.has(lang) ? 'rtl' : 'ltr'}">${esc(caption)}</p></html>`;

/**
 * The AI results run to ~1150px; scaled into one 640px card the text is unreadable. Show them at full size instead,
 * as two cards side by side: the top blocks (verdict, colours) and the rest (works, sites), split at a block edge.
 */
function splitAi(buf) {
  const H = buf.cssHeight;
  const tops = buf.blockTops.filter((t) => t > 0 && t < H);
  const split = tops.reduce((best, t) => (Math.abs(t - H / 2) < Math.abs(best - H / 2) ? t : best), tops[0] ?? Math.round(H / 2));
  return { split, H, W: buf.readUInt32BE(16) };
}

const aiBody = (img, { split, H, W }, lang) => {
  const PAD = 14;
  const tallest = Math.max(split, H - split) + 2 * PAD;
  const scale = Math.min(1, 640 / tallest);
  const crop = (top, h) => `<div class="crop" style="padding:${PAD}px 20px"><div class="win" style="width:${W}px;height:${h}px"><img src="${img}" style="top:${-top}px;width:${W}px"></div></div>`;
  // Right-to-left languages read the right-hand card first.
  return `<div class="pair" dir="${RTL.has(lang) ? 'rtl' : 'ltr'}" style="zoom:${scale}">${crop(0, split)}${crop(split, H - split)}</div>`;
};

const AI_CSS = `.pair{display:flex;gap:36px;align-items:flex-start}
.crop{background:#FBFBFA;border-radius:14px;box-shadow:0 24px 60px rgba(20,24,30,.22),0 0 0 1px rgba(20,24,30,.08)}
.win{position:relative;overflow:hidden}
.win img{position:absolute;left:0;max-height:none;border-radius:0;box-shadow:none}`;

function parseArgs(argv) {
  if (argv.includes('--all-langs')) return LOCALES;
  const i = argv.indexOf('--lang');
  if (i === -1) return ['en'];
  const code = argv[i + 1] ?? '';
  const locale = LOCALES.find((l) => l.toLowerCase() === code.toLowerCase() || L10N[l].lang.toLowerCase() === code.toLowerCase());
  if (!locale) { console.error(`unknown --lang ${code}; use one of ${LOCALES.join(', ')}`); process.exit(2); }
  return [locale];
}

const locales = parseArgs(process.argv.slice(2));
buildE2e();
const ext = await launch({ port: 4518 });
try {
  const composer = await ext.context.newPage();
  await composer.setViewportSize({ width: 1280, height: 800 });
  for (const locale of locales) {
    const { lang, captions } = L10N[locale];
    const dir = locale === 'en' ? OUT : resolve(OUT, locale);
    mkdirSync(dir, { recursive: true });
    for (const s of SHOTS) {
      if (s.englishOnly && locale !== 'en') continue;
      const shot = { ...s, ai: s.ai ? { result: aiResult(locale), lang } : undefined };
      const buf = await capture(ext, shot, lang);
      const img = `data:image/png;base64,${buf.toString('base64')}`;
      await composer.setContent(frame(s.ai ? aiBody(img, splitAi(buf), lang) : `<img src="${img}">`, captions[s.file], lang));
      await composer.evaluate(() => document.fonts.ready);
      await composer.screenshot({ path: resolve(dir, `${s.file}.png`) });
      console.log('wrote', `${locale === 'en' ? '' : `${locale}/`}${s.file}.png`);
    }
  }
} finally {
  await ext.close();
}
